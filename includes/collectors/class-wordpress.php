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
	 * Collects an allow-listed set of synchronous, read-only Site Health tests.
	 *
	 * @return array<string, mixed>
	 */
	public function collect_site_health() {
		if ( ! class_exists( 'WP_Site_Health' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-site-health.php';
		}

		$definitions        = $this->get_safe_site_health_tests();
		$tests              = array();
		$execution_failures = array();
		$summary            = array(
			'good'        => 0,
			'recommended' => 0,
			'critical'    => 0,
		);

		try {
			$reflection  = new ReflectionClass( 'WP_Site_Health' );
			$site_health = $reflection->newInstanceWithoutConstructor();
		} catch ( Throwable $throwable ) {
			return ODSC_Sanitizer::result(
				'WP-03',
				'unavailable',
				null,
				null,
				__( 'WordPressのサイトヘルス機能を初期化できませんでした。', 'od-site-check' ),
				'site_health_unavailable'
			);
		}

		foreach ( $definitions as $id => $definition ) {
			try {
				$method = $definition['method'];
				$result = is_callable( array( $site_health, $method ) ) ? call_user_func( array( $site_health, $method ) ) : null;
				$result = apply_filters( 'odsc_site_health_test_result', $result, $id );
				if (
					! is_array( $result ) ||
					! isset( $result['status'], $result['label'] ) ||
					! in_array( $result['status'], array( 'good', 'recommended', 'critical' ), true ) ||
					! is_string( $result['label'] )
				) {
					$execution_failures[] = array(
						'id'         => $id,
						'error_code' => 'test_failed',
					);
					continue;
				}

				++$summary[ $result['status'] ];
				$tests[] = array(
					'id'       => $id,
					'status'   => $result['status'],
					'label'    => wp_strip_all_tags( $result['label'] ),
					'category' => $definition['category'],
				);
			} catch ( Throwable $throwable ) {
				$execution_failures[] = array(
					'id'         => $id,
					'error_code' => 'test_failed',
				);
			}
		}

		$attempted = count( $definitions );
		$completed = count( $tests );
		$status    = empty( $execution_failures ) ? 'collected' : 'partial';
		$note      = empty( $execution_failures )
			? sprintf(
				/* translators: %d: Number of Site Health tests collected. */
				__( '外部通信や書き込みを行わない、安全なサイトヘルステスト%d件を取得しました。サイトヘルスの全項目ではありません。', 'od-site-check' ),
				$completed
			)
			: sprintf(
				/* translators: 1: Number of completed tests, 2: Number of attempted tests, 3: Comma-separated test IDs. */
				__( '安全なサイトヘルステスト%2$d件中%1$d件を取得しました。実行できなかったテスト（%3$s）があり、サイトヘルスの全項目でもありません。', 'od-site-check' ),
				$completed,
				$attempted,
				implode( ', ', wp_list_pluck( $execution_failures, 'id' ) )
			);

		return ODSC_Sanitizer::result(
			'WP-03',
			$status,
			'wordpress_site_health_allowlist',
			array(
				'environment_type'       => wp_get_environment_type(),
				'wp_debug'               => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'wp_debug_display'       => defined( 'WP_DEBUG_DISPLAY' ) && WP_DEBUG_DISPLAY,
				'wp_debug_log'           => defined( 'WP_DEBUG_LOG' ) && (bool) WP_DEBUG_LOG,
				'tests_attempted'        => $attempted,
				'tests_completed'        => $completed,
				'status_counts'          => $summary,
				'tests'                  => $tests,
				'execution_failed_tests' => $execution_failures,
			),
			$note
		);
	}

	/**
	 * Returns the fixed list of synchronous Site Health tests safe for diagnosis.
	 *
	 * Async, loopback, external-communication, write, and high-cost tests are
	 * intentionally excluded.
	 *
	 * @return array<string, array<string, string>>
	 */
	private function get_safe_site_health_tests() {
		return array(
			'php_extensions'           => array(
				'method'   => 'get_test_php_extensions',
				'category' => __( 'パフォーマンス', 'od-site-check' ),
			),
			'php_default_timezone'     => array(
				'method'   => 'get_test_php_default_timezone',
				'category' => __( 'パフォーマンス', 'od-site-check' ),
			),
			'php_sessions'             => array(
				'method'   => 'get_test_php_sessions',
				'category' => __( 'パフォーマンス', 'od-site-check' ),
			),
			'sql_server'               => array(
				'method'   => 'get_test_sql_server',
				'category' => __( 'パフォーマンス', 'od-site-check' ),
			),
			'ssl_support'              => array(
				'method'   => 'get_test_ssl_support',
				'category' => __( 'セキュリティ', 'od-site-check' ),
			),
			'http_requests'            => array(
				'method'   => 'get_test_http_requests',
				'category' => __( 'パフォーマンス', 'od-site-check' ),
			),
			'debug_enabled'            => array(
				'method'   => 'get_test_is_in_debug_mode',
				'category' => __( 'セキュリティ', 'od-site-check' ),
			),
			'file_uploads'             => array(
				'method'   => 'get_test_file_uploads',
				'category' => __( 'パフォーマンス', 'od-site-check' ),
			),
			'insecure_registration'    => array(
				'method'   => 'get_test_insecure_registration',
				'category' => __( 'セキュリティ', 'od-site-check' ),
			),
			'search_engine_visibility' => array(
				'method'   => 'get_test_search_engine_visibility',
				'category' => __( 'プライバシー', 'od-site-check' ),
			),
			'opcode_cache'             => array(
				'method'   => 'get_test_opcode_cache',
				'category' => __( 'パフォーマンス', 'od-site-check' ),
			),
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
