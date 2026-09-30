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

		return ODSC_Sanitizer::result(
			'WP-04',
			$cache_available ? 'collected' : 'partial',
			'wordpress_theme_api',
			array(
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
