<?php
/**
 * Plugin information collector.
 *
 * @package ODSiteCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects plugin inventories and presence-based indicators.
 */
final class ODSC_Collector_Plugins {
	/**
	 * Collects active plugins.
	 *
	 * @return array<string, mixed>
	 */
	public function collect_active_plugins() {
		$all_plugins     = $this->get_all_plugins();
		$active          = array_flip( (array) get_option( 'active_plugins', array() ) );
		$updates         = get_site_transient( 'update_plugins' );
		$cache_available = is_object( $updates ) && isset( $updates->last_checked ) && is_numeric( $updates->last_checked );
		$note            = $cache_available
			? __( '更新情報は既存のキャッシュのみを参照しています。', 'od-site-check' )
			: __( '更新キャッシュを取得できないため、有効プラグインの更新有無は確認できませんでした。外部への再確認は行っていません。', 'od-site-check' );

		return ODSC_Sanitizer::result(
			'WP-07',
			$cache_available ? 'collected' : 'partial',
			'wordpress_plugin_api',
			array(
				'count'                   => count( $active ),
				'update_cache_available'  => $cache_available,
				'update_cache_checked_at' => $cache_available ? wp_date( DATE_RFC3339, (int) $updates->last_checked ) : null,
				'plugins'                 => $this->format_plugins( array_intersect_key( $all_plugins, $active ), true, $updates, $cache_available ),
			),
			$note
		);
	}

	/**
	 * Collects inactive plugins.
	 *
	 * @return array<string, mixed>
	 */
	public function collect_inactive_plugins() {
		$all_plugins = $this->get_all_plugins();
		$active      = array_flip( (array) get_option( 'active_plugins', array() ) );
		$inactive    = array_diff_key( $all_plugins, $active );

		return ODSC_Sanitizer::result(
			'WP-08',
			'collected',
			'wordpress_plugin_api',
			array(
				'count'   => count( $inactive ),
				'plugins' => $this->format_plugins( $inactive, false ),
			)
		);
	}

	/**
	 * Collects indicators of custom code without scanning source files.
	 *
	 * @return array<string, mixed>
	 */
	public function collect_custom_code_indicators() {
		$mu_plugins = $this->get_mu_plugins();
		$dropins    = $this->get_dropins();
		$theme      = wp_get_theme();

		return ODSC_Sanitizer::result(
			'WP-09',
			'partial',
			'wordpress_registered_components',
			array(
				'active_theme_stylesheet' => $theme->get_stylesheet(),
				'active_theme_is_child'   => (bool) $theme->parent(),
				'mu_plugin_count'         => count( $mu_plugins ),
				'mu_plugin_identifiers'   => array_keys( $mu_plugins ),
				'dropin_count'            => count( $dropins ),
				'dropin_identifiers'      => array_keys( $dropins ),
				'source_code_scanned'     => false,
			),
			__( '子テーマ、MUプラグイン、ドロップインの有無だけを確認しています。コードの内容確認や、独自開発かどうかの断定はしていません。', 'od-site-check' )
		);
	}

	/**
	 * Collects known login-protection plugin presence.
	 *
	 * @return array<string, mixed>
	 */
	public function collect_login_protection_plugins() {
		$known = array(
			'wordfence'                           => 'Wordfence',
			'better-wp-security'                  => 'Solid Security',
			'ithemes-security-pro'                => 'Solid Security Pro',
			'two-factor'                          => 'Two Factor',
			'wp-2fa'                              => 'WP 2FA',
			'limit-login-attempts-reloaded'       => 'Limit Login Attempts Reloaded',
			'all-in-one-wp-security-and-firewall' => 'All-In-One Security',
		);

		return ODSC_Sanitizer::result(
			'WP-12',
			'collected',
			'wordpress_plugin_api',
			array( 'detected_plugins' => $this->detect_known_plugins( $known ) ),
			__( 'プラグインの存在だけを確認しています。MFAやログイン保護の適用状況は別途確認が必要です。', 'od-site-check' )
		);
	}

	/**
	 * Collects known backup plugin presence.
	 *
	 * @return array<string, mixed>
	 */
	public function collect_backup_plugins() {
		$known = array(
			'updraftplus'             => 'UpdraftPlus',
			'backwpup'                => 'BackWPup',
			'duplicator'              => 'Duplicator',
			'jetpack'                 => 'Jetpack',
			'all-in-one-wp-migration' => 'All-in-One WP Migration',
			'wp-staging'              => 'WP STAGING',
			'backupbuddy'             => 'Solid Backups',
		);

		return ODSC_Sanitizer::result(
			'OPS-11',
			'partial',
			'wordpress_plugin_api',
			array( 'detected_plugins' => $this->detect_known_plugins( $known ) ),
			__( 'バックアップ関連プラグインの有無だけを確認しています。設定内容、実行履歴、復元できるかどうかは確認していません。', 'od-site-check' )
		);
	}

	/**
	 * Gets installed plugins using the core API.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function get_all_plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return get_plugins();
	}

	/**
	 * Gets must-use plugins without scanning arbitrary directories.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function get_mu_plugins() {
		if ( ! function_exists( 'get_mu_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return get_mu_plugins();
	}

	/**
	 * Gets registered drop-ins.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function get_dropins() {
		if ( ! function_exists( 'get_dropins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return get_dropins();
	}

	/**
	 * Formats plugin data using an explicit output allow list.
	 *
	 * @param array<string, array<string, string>> $plugins        Plugin data keyed by file.
	 * @param bool                                 $include_update Whether to include cached update details.
	 * @param object|null                          $updates         Cached update data.
	 * @param bool                                 $cache_available Whether the update cache is usable.
	 * @return array<int, array<string, mixed>>
	 */
	private function format_plugins( $plugins, $include_update = true, $updates = null, $cache_available = false ) {
		$output = array();

		foreach ( $plugins as $file => $data ) {
			$update = $include_update && $cache_available && is_object( $updates ) && isset( $updates->response[ $file ] ) ? $updates->response[ $file ] : null;

			$output[] = array(
				'name'             => isset( $data['Name'] ) ? $data['Name'] : '',
				'identifier'       => $file,
				'version'          => isset( $data['Version'] ) ? $data['Version'] : '',
				'update_available' => $include_update && ! $cache_available ? null : null !== $update,
				'new_version'      => is_object( $update ) && isset( $update->new_version ) ? (string) $update->new_version : null,
			);
		}

		return $output;
	}

	/**
	 * Finds installed plugins whose directory slug is in an allow list.
	 *
	 * @param array<string, string> $known Known slug-to-label map.
	 * @return array<int, array<string, mixed>>
	 */
	private function detect_known_plugins( $known ) {
		$all_plugins = $this->get_all_plugins();
		$active      = array_flip( (array) get_option( 'active_plugins', array() ) );
		$detected    = array();

		foreach ( $all_plugins as $file => $data ) {
			$slug = dirname( $file );
			$slug = '.' === $slug ? basename( $file, '.php' ) : $slug;

			if ( ! isset( $known[ $slug ] ) ) {
				continue;
			}

			$detected[] = array(
				'identifier' => $slug,
				'name'       => $known[ $slug ],
				'version'    => isset( $data['Version'] ) ? $data['Version'] : '',
				'active'     => isset( $active[ $file ] ),
			);
		}

		return $detected;
	}
}
