<?php
/**
 * Theme information collector.
 *
 * @package ODSiteCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects active theme details.
 */
final class ODSC_Collector_Themes {
	/**
	 * Collects active theme details and cached update state.
	 *
	 * @return array<string, mixed>
	 */
	public function collect_active_theme() {
		$theme           = wp_get_theme();
		$parent          = $theme->parent();
		$updates         = get_site_transient( 'update_themes' );
		$stylesheet      = $theme->get_stylesheet();
		$cache_available = is_object( $updates ) && isset( $updates->last_checked ) && is_numeric( $updates->last_checked );
		$update_details  = $cache_available && isset( $updates->response[ $stylesheet ] ) && is_array( $updates->response[ $stylesheet ] ) ? $updates->response[ $stylesheet ] : array();
		$note            = $cache_available
			? __( '更新情報は既存のキャッシュのみを参照しています。', 'od-site-check' )
			: __( '更新キャッシュを取得できないため、テーマの更新有無は確認できませんでした。外部への再確認は行っていません。', 'od-site-check' );

		$auto_updates = (array) get_site_option( 'auto_update_themes', array() );
		$installed    = array();
		foreach ( wp_get_themes() as $slug => $installed_theme ) {
			$installed[] = array(
				'name'                => $installed_theme->get( 'Name' ),
				'stylesheet'          => $slug,
				'version'             => $installed_theme->get( 'Version' ),
				'is_active'           => $slug === $stylesheet,
				'parent_stylesheet'   => $installed_theme->get_template() !== $slug ? $installed_theme->get_template() : null,
				'auto_update_enabled' => in_array( $slug, $auto_updates, true ),
			);
		}
		$note .= ' ' . __( '自動更新は保存された設定であり、フィルター等の上書きや更新の実行・成功を保証しません。', 'od-site-check' );

		return ODSC_Sanitizer::result(
			'WP-04',
			$cache_available ? 'collected' : 'partial',
			'wordpress_theme_api',
			array(
				'installed_themes'        => $installed,
				'name'                    => $theme->get( 'Name' ),
				'stylesheet'              => $stylesheet,
				'version'                 => $theme->get( 'Version' ),
				'is_child_theme'          => (bool) $parent,
				'parent_name'             => $parent ? $parent->get( 'Name' ) : null,
				'parent_version'          => $parent ? $parent->get( 'Version' ) : null,
				'update_cache_available'  => $cache_available,
				'update_cache_checked_at' => $cache_available ? wp_date( DATE_RFC3339, (int) $updates->last_checked ) : null,
				'update_available'        => $cache_available ? ! empty( $update_details ) : null,
				'new_version'             => isset( $update_details['new_version'] ) ? (string) $update_details['new_version'] : null,
			),
			$note
		);
	}
}
