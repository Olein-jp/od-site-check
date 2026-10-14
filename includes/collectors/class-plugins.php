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
		$note           .= ' ' . $this->compatibility_note();

		return ODSC_Sanitizer::result(
			'WP-07',
			$cache_available ? 'collected' : 'partial',
			'wordpress_plugin_api',
			array(
				'count'                   => count( $active ),
				'update_cache_available'  => $cache_available,
				'update_cache_checked_at' => $cache_available ? wp_date( DATE_RFC3339, (int) $updates->last_checked ) : null,
				'plugins'                 => $this->format_plugins( array_intersect_key( $all_plugins, $active ), 'active', true, $updates, $cache_available ),
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
		$all_plugins     = $this->get_all_plugins();
		$active          = array_flip( (array) get_option( 'active_plugins', array() ) );
		$inactive        = array_diff_key( $all_plugins, $active );
		$updates         = get_site_transient( 'update_plugins' );
		$cache_available = is_object( $updates ) && isset( $updates->last_checked ) && is_numeric( $updates->last_checked );
		$note            = $cache_available
			? __( '更新情報は既存のキャッシュのみを参照しています。', 'od-site-check' )
			: __( '更新キャッシュを取得できないため、無効プラグインの更新有無は確認できませんでした。外部への再確認は行っていません。', 'od-site-check' );
		$note           .= ' ' . $this->compatibility_note();

		return ODSC_Sanitizer::result(
			'WP-08',
			$cache_available ? 'collected' : 'partial',
			'wordpress_plugin_api',
			array(
				'count'                   => count( $inactive ),
				'update_cache_available'  => $cache_available,
				'update_cache_checked_at' => $cache_available ? wp_date( DATE_RFC3339, (int) $updates->last_checked ) : null,
				'plugins'                 => $this->format_plugins( $inactive, 'inactive', true, $updates, $cache_available ),
			),
			$note
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
	 * @param array<string, array<string, string>> $plugins         Plugin data keyed by file.
	 * @param string                               $activation_state Plugin activation state.
	 * @param bool                                 $include_update  Whether to include cached update details.
	 * @param object|null                          $updates          Cached update data.
	 * @param bool                                 $cache_available Whether the update cache is usable.
	 * @return array<int, array<string, mixed>>
	 */
	private function format_plugins( $plugins, $activation_state, $include_update = true, $updates = null, $cache_available = false ) {
		$output = array();

		foreach ( $plugins as $file => $data ) {
			$update               = $include_update && $cache_available && is_object( $updates ) && isset( $updates->response[ $file ] ) ? $updates->response[ $file ] : null;
			$update_compatibility = $this->get_update_compatibility( $update, $updates );

			$output[] = array(
				'name'             => isset( $data['Name'] ) ? $data['Name'] : '',
				'identifier'       => $file,
				'version'          => isset( $data['Version'] ) ? $data['Version'] : '',
				'activation_state' => $activation_state,
				'update_available' => $include_update && ! $cache_available ? null : null !== $update,
				'new_version'      => null !== $update_compatibility ? $update_compatibility['version'] : null,
				'compatibility'    => array(
					'installed' => array(
						'version'      => isset( $data['Version'] ) ? $data['Version'] : '',
						'requires_wp'  => $this->normalize_requirement( $data['RequiresWP'] ?? null ),
						'requires_php' => $this->normalize_requirement( $data['RequiresPHP'] ?? null ),
						'tested_wp'    => $this->get_installed_tested_wp( $file, $data['Version'] ?? '' ),
						'tested_php'   => null,
						'source'       => 'installed_plugin_headers_and_readme',
						'checked_at'   => null,
					),
					'update'    => $update_compatibility,
				),
			);
		}

		return $output;
	}

	/**
	 * 最低要件と動作確認情報の解釈を説明する。
	 *
	 * @return string
	 */
	private function compatibility_note() {
		return __( '対応情報は作者の記載に基づき、動作保証ではありません。最低要件は対応上限ではなく、WPの動作確認済みバージョンを超える場合は事前検証が必要です。PHPの動作確認上限は不明のため提供元へ確認してください。更新先は保存済みキャッシュの情報であり、最新公開版の確認を意味しません。', 'od-site-check' );
	}

	/**
	 * 明示された数値形式の要件・動作確認バージョンだけを採用する。
	 *
	 * @param mixed $value メタデータの値.
	 * @return string|null
	 */
	private function normalize_requirement( $value ) {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$value = trim( $value );
		return preg_match( '/^[0-9]+(?:\.[0-9]+)*$/D', $value ) ? $value : null;
	}

	/**
	 * インストール済みプラグインの同梱readmeから作者の記載を取得する。
	 *
	 * @param string $file    プラグイン識別子.
	 * @param string $version インストール済みバージョン.
	 * @return string|null
	 */
	private function get_installed_tested_wp( $file, $version ) {
		$directory = dirname( $file );
		if ( '.' === $directory || 0 !== validate_file( $file ) ) {
			return null;
		}

		$readme = WP_PLUGIN_DIR . '/' . $directory . '/readme.txt';
		if ( ! is_file( $readme ) || ! is_readable( $readme ) ) {
			return null;
		}

		$headers = get_file_data(
			$readme,
			array(
				'tested' => 'Tested up to',
				'stable' => 'Stable tag',
			)
		);
		$stable  = trim( $headers['stable'] );
		if ( '' !== $stable && 'trunk' !== strtolower( $stable ) && $stable !== $version ) {
			return null;
		}

		return $this->normalize_requirement( $headers['tested'] );
	}

	/**
	 * 更新先の版を特定できる場合だけ、キャッシュの対応情報を返す。
	 *
	 * @param mixed       $update  プラグインの更新情報.
	 * @param object|null $updates 更新キャッシュ.
	 * @return array<string, mixed>|null
	 */
	private function get_update_compatibility( $update, $updates ) {
		if ( ! is_object( $update ) || ! isset( $update->new_version ) || ! is_string( $update->new_version ) ) {
			return null;
		}

		$version = trim( $update->new_version );
		if ( ! preg_match( '/^[0-9]+(?:\.[0-9]+)*(?:[-+][0-9A-Za-z.-]+)?$/D', $version ) ) {
			return null;
		}

		return array(
			'version'      => $version,
			'requires_wp'  => $this->normalize_requirement( $update->requires ?? null ),
			'requires_php' => $this->normalize_requirement( $update->requires_php ?? null ),
			'tested_wp'    => $this->normalize_requirement( $update->tested ?? null ),
			'tested_php'   => null,
			'source'       => 'wordpress_update_cache',
			'checked_at'   => wp_date( DATE_RFC3339, (int) $updates->last_checked ),
		);
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
