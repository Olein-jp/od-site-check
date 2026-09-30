<?php
/**
 * WordPress core information collector.
 *
 * @package ODSiteCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects WordPress core and update state without forcing network requests.
 */
final class ODSC_Collector_WordPress {
	/**
	 * Collects the installed WordPress version.
	 *
	 * @return array<string, mixed>
	 */
	public function collect_version() {
		return ODSC_Sanitizer::result(
			'WP-01',
			'collected',
			'wordpress_core',
			array( 'version' => get_bloginfo( 'version' ) )
		);
	}

	/**
	 * Collects automatic-update configuration and cached update metadata.
	 *
	 * @return array<string, mixed>
	 */
	public function collect_update_settings() {
		$core_updates = get_site_transient( 'update_core' );
		$last_checked = is_object( $core_updates ) && isset( $core_updates->last_checked ) ? (int) $core_updates->last_checked : null;
		$setting      = 'wordpress_default';

		if ( defined( 'AUTOMATIC_UPDATER_DISABLED' ) && AUTOMATIC_UPDATER_DISABLED ) {
			$setting = 'disabled';
		} elseif ( defined( 'WP_AUTO_UPDATE_CORE' ) ) {
			if ( true === WP_AUTO_UPDATE_CORE ) {
				$setting = 'all';
			} elseif ( false === WP_AUTO_UPDATE_CORE ) {
				$setting = 'disabled';
			} elseif ( 'minor' === WP_AUTO_UPDATE_CORE ) {
				$setting = 'minor';
			} else {
				$setting = 'custom';
			}
		}

		return ODSC_Sanitizer::result(
			'WP-02',
			null === $last_checked ? 'partial' : 'collected',
			'wordpress_configuration',
			array(
				'core_auto_update'         => $setting,
				'file_modifications_off'   => defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS,
				'cached_update_checked'    => null !== $last_checked,
				'cached_update_checked_at' => $last_checked ? wp_date( DATE_RFC3339, $last_checked ) : null,
			),
			__( '更新情報は既存のキャッシュのみを参照し、外部への再確認は行っていません。', 'od-site-check' )
		);
	}

	/**
	 * Collects a safe subset of environment-related Site Health facts.
	 *
	 * @return array<string, mixed>
	 */
	public function collect_site_health() {
		return ODSC_Sanitizer::result(
			'WP-03',
			'partial',
			'wordpress_site_health_subset',
			array(
				'environment_type' => wp_get_environment_type(),
				'wp_debug'         => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'wp_debug_display' => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
				'wp_debug_log'     => defined( 'WP_DEBUG_LOG' ) && (bool) WP_DEBUG_LOG,
			),
			__( '負荷と機密性に配慮し、サイトヘルス情報のうち安全な一部だけを収集しています。', 'od-site-check' )
		);
	}

	/**
	 * Collects counts from existing cached update data.
	 *
	 * @return array<string, mixed>
	 */
	public function collect_pending_updates() {
		$core    = get_site_transient( 'update_core' );
		$plugins = get_site_transient( 'update_plugins' );
		$themes  = get_site_transient( 'update_themes' );

		$core_count = 0;
		if ( is_object( $core ) && ! empty( $core->updates ) && is_array( $core->updates ) ) {
			foreach ( $core->updates as $update ) {
				if ( is_object( $update ) && isset( $update->response ) && 'upgrade' === $update->response ) {
					++$core_count;
				}
			}
		}

		$available = is_object( $core ) && is_object( $plugins ) && is_object( $themes );

		return ODSC_Sanitizer::result(
			'WP-10',
			$available ? 'collected' : 'partial',
			'wordpress_update_cache',
			array(
				'core_updates_pending'   => $core_count,
				'plugin_updates_pending' => is_object( $plugins ) && isset( $plugins->response ) && is_array( $plugins->response ) ? count( $plugins->response ) : null,
				'theme_updates_pending'  => is_object( $themes ) && isset( $themes->response ) && is_array( $themes->response ) ? count( $themes->response ) : null,
				'cache_complete'         => $available,
			),
			__( '保留件数は既存の更新キャッシュに基づきます。更新理由や互換性は判定していません。', 'od-site-check' )
		);
	}
}
