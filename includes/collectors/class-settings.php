<?php
/**
 * WordPress settings collector.
 *
 * @package ODSiteCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects explicitly approved, non-secret settings.
 */
final class ODSC_Collector_Settings {
	/**
	 * Collects general site settings.
	 *
	 * @return array<string, mixed>
	 */
	public function collect_general_settings() {
		return ODSC_Sanitizer::result(
			'WP-13',
			'collected',
			'wordpress_options_allowlist',
			array(
				'home_url'            => home_url( '/' ),
				'site_url'            => site_url( '/' ),
				'timezone_string'     => (string) get_option( 'timezone_string', '' ),
				'gmt_offset'          => (float) get_option( 'gmt_offset', 0 ),
				'locale'              => get_locale(),
				'permalink_structure' => (string) get_option( 'permalink_structure', '' ),
			),
			__( '管理者メールアドレスなど、診断に不要な設定は収集していません。', 'od-site-check' )
		);
	}

	/**
	 * Collects reading settings without page titles or content.
	 *
	 * @return array<string, mixed>
	 */
	public function collect_reading_settings() {
		$show_on_front = (string) get_option( 'show_on_front', 'posts' );

		return ODSC_Sanitizer::result(
			'WP-15',
			'collected',
			'wordpress_options_allowlist',
			array(
				'show_on_front'         => $show_on_front,
				'front_page_configured' => 'page' === $show_on_front && 0 < (int) get_option( 'page_on_front', 0 ),
				'posts_page_configured' => 'page' === $show_on_front && 0 < (int) get_option( 'page_for_posts', 0 ),
				'search_engine_visible' => (bool) get_option( 'blog_public', true ),
			)
		);
	}
}
