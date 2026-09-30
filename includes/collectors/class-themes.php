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
		$theme          = wp_get_theme();
		$parent         = $theme->parent();
		$updates        = get_site_transient( 'update_themes' );
		$stylesheet     = $theme->get_stylesheet();
		$update_details = is_object( $updates ) && isset( $updates->response[ $stylesheet ] ) && is_array( $updates->response[ $stylesheet ] ) ? $updates->response[ $stylesheet ] : array();

		return ODSC_Sanitizer::result(
			'WP-04',
			'collected',
			'wordpress_theme_api',
			array(
				'name'             => $theme->get( 'Name' ),
				'stylesheet'       => $stylesheet,
				'version'          => $theme->get( 'Version' ),
				'is_child_theme'   => (bool) $parent,
				'parent_name'      => $parent ? $parent->get( 'Name' ) : null,
				'parent_version'   => $parent ? $parent->get( 'Version' ) : null,
				'update_available' => ! empty( $update_details ),
				'new_version'      => isset( $update_details['new_version'] ) ? (string) $update_details['new_version'] : null,
			),
			__( '更新情報は既存のキャッシュのみを参照しています。', 'od-site-check' )
		);
	}
}
