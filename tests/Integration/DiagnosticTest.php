<?php
/**
 * Diagnostic integration tests.
 *
 * @package ODSiteCheck
 */

use Opis\JsonSchema\Validator;

/**
 * Verifies the diagnostic contract against a real WordPress test installation.
 */
final class ODSC_Diagnostic_Test extends WP_UnitTestCase {
	/**
	 * Restores globals and plugin filters after each test.
	 *
	 * @return void
	 */
	public function tear_down() {
		remove_all_filters( 'odsc_collectors' );
		remove_all_filters( 'odsc_is_multisite' );
		remove_all_filters( 'odsc_site_health_test_result' );
		wp_set_current_user( 0 );
		$_POST    = array();
		$_REQUEST = array();
		unset( $_SERVER['REQUEST_METHOD'] );
		$this->set_admin_result( null );
		$this->set_admin_exporter( null );

		parent::tear_down();
	}

	/**
	 * Ensures every required ID appears exactly once and in stable order.
	 *
	 * @return void
	 */
	public function test_collector_returns_all_required_ids_once() {
		$payload = ( new ODSC_Collector() )->collect();
		$ids     = wp_list_pluck( $payload['results'], 'id' );

		$this->assertCount( 22, $ids );
		$this->assertSame( ODSC_Collector::expected_ids(), $ids );
		$this->assertSame( $ids, array_values( array_unique( $ids ) ) );
	}

	/**
	 * Ensures the generated document matches the shipped JSON Schema.
	 *
	 * @return void
	 */
	public function test_payload_matches_json_schema() {
		$payload   = ( new ODSC_Collector() )->collect();
		$schema    = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/schemas/diagnostic-result.schema.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local test fixture.
		$data      = json_decode( wp_json_encode( $payload ) );
		$validator = new Validator();
		$result    = $validator->validate( $data, $schema );

		$this->assertTrue( $result->isValid() );
	}

	/**
	 * Ensures labels and manual IDs cover the expected diagnostic contract.
	 *
	 * @return void
	 */
	public function test_item_labels_and_manual_ids_are_complete() {
		$this->assertSame( ODSC_Collector::expected_ids(), array_keys( ODSC_Collector::item_labels() ) );
		$this->assertSame( array( 'OPS-01', 'OPS-10', 'OPS-12', 'OPS-14', 'OPS-18', 'OPS-19' ), ODSC_Collector::manual_ids() );
	}

	/**
	 * Ensures the export filename contains a safe site domain and timestamp.
	 *
	 * @return void
	 */
	public function test_export_filename_identifies_the_site() {
		$host      = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
		$safe_host = trim( (string) preg_replace( '/[^a-z0-9.-]+/', '-', $host ), '.-' );
		$filename  = ( new ODSC_Exporter() )->get_filename();

		$this->assertMatchesRegularExpression( '/^od-site-check-' . preg_quote( $safe_host, '/' ) . '-\d{8}-\d{6}\.json$/', $filename );
	}

	/**
	 * Ensures one failing item does not interrupt other items.
	 *
	 * @return void
	 */
	public function test_item_failure_is_isolated() {
		add_filter(
			'odsc_collectors',
			static function ( $tasks ) {
				$tasks['WP-01'] = static function () {
					throw new RuntimeException( 'Sensitive internal detail.' );
				};

				return $tasks;
			}
		);

		$items   = ( new ODSC_Collector() )->collect()['results'];
		$results = array_combine( wp_list_pluck( $items, 'id' ), $items );

		$this->assertSame( 'error', $results['WP-01']['status'] );
		$this->assertSame( 'collector_failed', $results['WP-01']['error_code'] );
		$this->assertStringNotContainsString( 'Sensitive internal detail', wp_json_encode( $results['WP-01'] ) );
		$this->assertNotSame( 'error', $results['WP-02']['status'] );
	}

	/**
	 * Ensures missing update caches are not reported as no updates.
	 *
	 * @return void
	 */
	public function test_missing_update_caches_are_reported_as_partial() {
		$theme_cache  = get_site_transient( 'update_themes' );
		$plugin_cache = get_site_transient( 'update_plugins' );

		delete_site_transient( 'update_themes' );
		delete_site_transient( 'update_plugins' );

		try {
			$payload = ( new ODSC_Collector() )->collect();
			$results = array_combine( wp_list_pluck( $payload['results'], 'id' ), $payload['results'] );
			$schema  = json_decode( file_get_contents( dirname( __DIR__, 2 ) . '/schemas/diagnostic-result.schema.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads a local test fixture.
			$valid   = ( new Validator() )->validate( json_decode( wp_json_encode( $payload ) ), $schema );

			$this->assertSame( 'partial', $results['WP-04']['status'] );
			$this->assertFalse( $results['WP-04']['value']['update_cache_available'] );
			$this->assertNull( $results['WP-04']['value']['update_cache_checked_at'] );
			$this->assertNull( $results['WP-04']['value']['update_available'] );
			$this->assertStringContainsString( '更新キャッシュを取得できない', $results['WP-04']['note'] );

			$this->assertSame( 'partial', $results['WP-07']['status'] );
			$this->assertFalse( $results['WP-07']['value']['update_cache_available'] );
			$this->assertNull( $results['WP-07']['value']['update_cache_checked_at'] );
			foreach ( $results['WP-07']['value']['plugins'] as $plugin ) {
				$this->assertNull( $plugin['update_available'] );
			}
			$this->assertStringContainsString( '更新キャッシュを取得できない', $results['WP-07']['note'] );
			foreach ( ODSC_Collector::manual_ids() as $manual_id ) {
				$this->assertSame( 'manual_required', $results[ $manual_id ]['status'] );
				$this->assertNull( $results[ $manual_id ]['value'] );
			}
			$this->assertTrue( $valid->isValid() );
		} finally {
			$this->restore_site_transient( 'update_themes', $theme_cache );
			$this->restore_site_transient( 'update_plugins', $plugin_cache );
		}
	}

	/**
	 * Ensures an available empty cache means updates were checked and none exist.
	 *
	 * @return void
	 */
	public function test_available_update_caches_can_report_no_updates() {
		$theme_cache    = get_site_transient( 'update_themes' );
		$plugin_cache   = get_site_transient( 'update_plugins' );
		$active_plugins = get_option( 'active_plugins', array() );
		$plugin_file    = 'od-site-check/od-site-check.php';
		$checked_at     = 1700000000;
		$empty_cache    = (object) array(
			'last_checked' => $checked_at,
			'response'     => array(),
		);

		update_option( 'active_plugins', array( $plugin_file ) );
		set_site_transient( 'update_themes', $empty_cache );
		set_site_transient( 'update_plugins', $empty_cache );

		try {
			$theme_result  = ( new ODSC_Collector_Themes() )->collect_active_theme();
			$plugin_result = ( new ODSC_Collector_Plugins() )->collect_active_plugins();

			$this->assertSame( 'collected', $theme_result['status'] );
			$this->assertTrue( $theme_result['value']['update_cache_available'] );
			$this->assertSame( wp_date( DATE_RFC3339, $checked_at ), $theme_result['value']['update_cache_checked_at'] );
			$this->assertFalse( $theme_result['value']['update_available'] );

			$this->assertSame( 'collected', $plugin_result['status'] );
			$this->assertTrue( $plugin_result['value']['update_cache_available'] );
			$this->assertSame( wp_date( DATE_RFC3339, $checked_at ), $plugin_result['value']['update_cache_checked_at'] );
			$this->assertCount( 1, $plugin_result['value']['plugins'] );
			foreach ( $plugin_result['value']['plugins'] as $plugin ) {
				$this->assertFalse( $plugin['update_available'] );
			}

			$theme_update_cache = clone $empty_cache;
			$theme_stylesheet   = $theme_result['value']['stylesheet'];

			$theme_update_cache->response[ $theme_stylesheet ] = array( 'new_version' => '99.0.0' );

			$plugin_update_cache = clone $empty_cache;

			$plugin_update_cache->response[ $plugin_file ] = (object) array( 'new_version' => '99.0.0' );
			set_site_transient( 'update_themes', $theme_update_cache );
			set_site_transient( 'update_plugins', $plugin_update_cache );

			$updated_theme  = ( new ODSC_Collector_Themes() )->collect_active_theme();
			$updated_plugin = ( new ODSC_Collector_Plugins() )->collect_active_plugins();

			$this->assertSame( $theme_result['value']['name'], $updated_theme['value']['name'] );
			$this->assertTrue( $updated_theme['value']['update_available'] );
			$this->assertSame( '99.0.0', $updated_theme['value']['new_version'] );
			$this->assertSame( $plugin_result['value']['plugins'][0]['name'], $updated_plugin['value']['plugins'][0]['name'] );
			$this->assertTrue( $updated_plugin['value']['plugins'][0]['update_available'] );
			$this->assertSame( '99.0.0', $updated_plugin['value']['plugins'][0]['new_version'] );
		} finally {
			update_option( 'active_plugins', $active_plugins );
			$this->restore_site_transient( 'update_themes', $theme_cache );
			$this->restore_site_transient( 'update_plugins', $plugin_cache );
		}
	}

	/**
	 * Ensures plugin lists expose their activation state without relying on result IDs.
	 *
	 * @return void
	 */
	public function test_plugin_lists_expose_activation_state() {
		$active_plugins = get_option( 'active_plugins', array() );
		$plugin_file    = 'od-site-check/od-site-check.php';

		try {
			update_option( 'active_plugins', array( $plugin_file ) );
			$active_result = ( new ODSC_Collector_Plugins() )->collect_active_plugins();

			$this->assertSame( count( $active_result['value']['plugins'] ), $active_result['value']['count'] );
			$this->assertNotEmpty( $active_result['value']['plugins'] );
			foreach ( $active_result['value']['plugins'] as $plugin ) {
				$this->assertSame( 'active', $plugin['activation_state'] );
			}

			update_option( 'active_plugins', array() );
			$inactive_result = ( new ODSC_Collector_Plugins() )->collect_inactive_plugins();

			$this->assertSame( count( $inactive_result['value']['plugins'] ), $inactive_result['value']['count'] );
			$this->assertNotEmpty( $inactive_result['value']['plugins'] );
			foreach ( $inactive_result['value']['plugins'] as $plugin ) {
				$this->assertSame( 'inactive', $plugin['activation_state'] );
			}
		} finally {
			update_option( 'active_plugins', $active_plugins );
		}
	}

	/**
	 * Ensures WP-03 exposes only the fixed safe Site Health result fields.
	 *
	 * @return void
	 */
	public function test_site_health_uses_the_fixed_safe_allowlist() {
		$cron_before  = _get_cron_array();
		$result       = ( new ODSC_Collector_WordPress() )->collect_site_health();
		$value        = $result['value'];
		$expected_ids = array(
			'php_extensions',
			'php_default_timezone',
			'php_sessions',
			'sql_server',
			'ssl_support',
			'http_requests',
			'debug_enabled',
			'file_uploads',
			'insecure_registration',
			'search_engine_visibility',
			'opcode_cache',
		);

		$this->assertSame( 'collected', $result['status'] );
		$this->assertSame( 'wordpress_site_health_allowlist', $result['source'] );
		$this->assertSame( 11, $value['tests_attempted'] );
		$this->assertSame( 11, $value['tests_completed'] );
		$this->assertSame( $expected_ids, wp_list_pluck( $value['tests'], 'id' ) );
		$this->assertSame( 11, array_sum( $value['status_counts'] ) );
		$this->assertSame( array(), $value['failed_tests'] );
		$this->assertSame( $cron_before, _get_cron_array() );

		foreach ( $value['tests'] as $test ) {
			$this->assertSame( array( 'id', 'status', 'label', 'category' ), array_keys( $test ) );
			$this->assertSame( wp_strip_all_tags( $test['label'] ), $test['label'] );
		}
	}

	/**
	 * Ensures one Site Health test failure is isolated and safely summarized.
	 *
	 * @return void
	 */
	public function test_site_health_test_failure_is_isolated() {
		add_filter(
			'odsc_site_health_test_result',
			static function ( $result, $id ) {
				if ( 'php_sessions' === $id ) {
					throw new RuntimeException( 'Sensitive Site Health detail.' );
				}

				return $result;
			},
			10,
			2
		);

		$result = ( new ODSC_Collector_WordPress() )->collect_site_health();

		$this->assertSame( 'partial', $result['status'] );
		$this->assertSame( 11, $result['value']['tests_attempted'] );
		$this->assertSame( 10, $result['value']['tests_completed'] );
		$this->assertSame(
			array(
				array(
					'id'         => 'php_sessions',
					'error_code' => 'test_failed',
				),
			),
			$result['value']['failed_tests']
		);
		$this->assertStringNotContainsString( 'Sensitive Site Health detail.', wp_json_encode( $result ) );
		$this->assertStringContainsString( 'php_sessions', $result['note'] );
		$this->assertContains( 'sql_server', wp_list_pluck( $result['value']['tests'], 'id' ) );
	}

	/**
	 * Ensures multisite is clearly reported without a fatal error.
	 *
	 * @return void
	 */
	public function test_multisite_is_reported_as_unsupported() {
		add_filter( 'odsc_is_multisite', '__return_true' );

		$payload = ( new ODSC_Collector() )->collect();
		$results = array_combine( wp_list_pluck( $payload['results'], 'id' ), $payload['results'] );

		$this->assertTrue( $payload['site']['multisite'] );
		$this->assertSame( 'unsupported', $results['WP-01']['status'] );
		$this->assertSame( 'manual_required', $results['OPS-01']['status'] );
	}

	/**
	 * Ensures personal credentials are not included in output.
	 *
	 * @return void
	 */
	public function test_personal_and_secret_values_are_not_exported() {
		$user_id = self::factory()->user->create(
			array(
				'user_login' => 'odsc-sensitive-login',
				'user_email' => 'odsc-sensitive@example.test',
				'user_pass'  => 'odsc-sensitive-password',
			)
		);
		$this->assertGreaterThan( 0, $user_id );

		$json = ( new ODSC_Exporter() )->encode( ( new ODSC_Collector() )->collect() );

		$this->assertStringNotContainsString( 'odsc-sensitive-login', $json );
		$this->assertStringNotContainsString( 'odsc-sensitive@example.test', $json );
		$this->assertStringNotContainsString( 'odsc-sensitive-password', $json );
		$this->assertStringNotContainsString( 'DB_PASSWORD', $json );
		$this->assertStringNotContainsString( 'AUTH_KEY', $json );
	}

	/**
	 * Ensures collection does not trigger external HTTP requests.
	 *
	 * @return void
	 */
	public function test_collection_does_not_make_external_requests() {
		$request_count = 0;
		$callback      = static function () use ( &$request_count ) {
			++$request_count;
			return new WP_Error( 'odsc_test_blocked_request', 'Unexpected HTTP request.' );
		};

		add_filter( 'pre_http_request', $callback );
		( new ODSC_Collector() )->collect();
		remove_filter( 'pre_http_request', $callback );

		$this->assertSame( 0, $request_count );
	}

	/**
	 * Ensures signed downloads reject modified JSON.
	 *
	 * @return void
	 */
	public function test_export_signature_rejects_tampering() {
		$exporter  = new ODSC_Exporter();
		$json      = $exporter->encode( ( new ODSC_Collector() )->collect() );
		$signature = $exporter->sign( $json );

		$this->assertTrue( $exporter->verify( $json, $signature ) );
		$this->assertFalse( $exporter->verify( $json . 'x', $signature ) );
	}

	/**
	 * Ensures a subscriber cannot execute the real download handler.
	 *
	 * @return void
	 */
	public function test_unauthorized_user_cannot_execute_download_handler() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$this->set_download_request( wp_create_nonce( 'odsc_download_result' ) );

		$this->expectException( WPDieException::class );
		$this->expectExceptionCode( 403 );
		ODSC_Admin::get_instance()->handle_download();
	}

	/**
	 * Ensures the real download handler rejects an invalid nonce.
	 *
	 * @return void
	 */
	public function test_download_handler_rejects_invalid_nonce() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator );
		$this->set_download_request( 'invalid' );

		$this->expectException( WPDieException::class );
		ODSC_Admin::get_instance()->handle_download();
	}

	/**
	 * Ensures a valid real download response contains JSON and safe headers.
	 *
	 * @return void
	 */
	public function test_download_handler_outputs_json_and_download_headers() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator );

		$exporter = new ODSC_Test_Exporter();
		$json     = $this->set_download_request( wp_create_nonce( 'odsc_download_result' ), $exporter );
		$this->set_admin_exporter( $exporter );

		$completed = false;
		$body      = '';
		ob_start();
		try {
			ODSC_Admin::get_instance()->handle_download();
		} catch ( ODSC_Test_Download_Completed $exception ) {
			$completed = true;
		} finally {
			$body = (string) ob_get_clean();
		}

		$host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );

		$this->assertTrue( $completed );
		$this->assertSame( $json, $body );
		$this->assertContains( 'Content-Type: application/json; charset=utf-8', $exporter->download_headers );
		$this->assertContains( 'X-Content-Type-Options: nosniff', $exporter->download_headers );
		$this->assertContains( 'Content-Length: ' . strlen( $json ), $exporter->download_headers );
		$this->assertMatchesRegularExpression(
			'/Content-Disposition: attachment; filename="od-site-check-' . preg_quote( $host, '/' ) . '-\d{8}-\d{6}\.json"/',
			implode( "\n", $exporter->download_headers )
		);
	}

	/**
	 * Ensures diagnosis does not change representative site content or settings.
	 *
	 * @return void
	 */
	public function test_diagnosis_does_not_change_site_data() {
		$post_id = self::factory()->post->create(
			array(
				'post_title'   => '診断前のタイトル',
				'post_content' => '診断前の本文',
				'post_status'  => 'publish',
			)
		);

		$options_before = array(
			'blogname'       => get_option( 'blogname' ),
			'show_on_front'  => get_option( 'show_on_front' ),
			'posts_per_page' => get_option( 'posts_per_page' ),
		);
		$post_before    = get_post( $post_id, ARRAY_A );
		$cron_before    = _get_cron_array();

		( new ODSC_Collector() )->collect();

		$this->assertSame( $options_before['blogname'], get_option( 'blogname' ) );
		$this->assertSame( $options_before['show_on_front'], get_option( 'show_on_front' ) );
		$this->assertSame( $options_before['posts_per_page'], get_option( 'posts_per_page' ) );
		$this->assertSame( $post_before, get_post( $post_id, ARRAY_A ) );
		$this->assertSame( $cron_before, _get_cron_array() );
	}

	/**
	 * Ensures diagnosis does not persist results or temporary plugin data.
	 *
	 * @return void
	 */
	public function test_diagnosis_does_not_persist_plugin_data() {
		global $wpdb;

		$like   = '%' . $wpdb->esc_like( 'odsc' ) . '%';
		$query  = $wpdb->prepare( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_name", $like ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The table name is a trusted WordPress property.
		$before = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test verifies that no options are persisted.

		( new ODSC_Collector() )->collect();

		$after = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test verifies that no options are persisted.
		$this->assertSame( $before, $after );
	}

	/**
	 * Ensures deactivation and uninstall preserve unrelated site data.
	 *
	 * @return void
	 */
	public function test_deactivation_and_uninstall_are_safe() {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin         = 'od-site-check/od-site-check.php';
		$active_before  = get_option( 'active_plugins', array() );
		$sentinel_value = '保持される設定値';
		$post_id        = self::factory()->post->create( array( 'post_title' => '保持される投稿' ) );

		update_option( 'odsc_test_unrelated_option', $sentinel_value );
		update_option( 'active_plugins', array_unique( array_merge( $active_before, array( $plugin ) ) ) );

		try {
			deactivate_plugins( $plugin );
			$this->assertFalse( is_plugin_active( $plugin ) );
			$this->assertTrue( uninstall_plugin( $plugin ) );
			$this->assertSame( $sentinel_value, get_option( 'odsc_test_unrelated_option' ) );
			$this->assertSame( '保持される投稿', get_post( $post_id )->post_title );
		} finally {
			update_option( 'active_plugins', $active_before );
			delete_option( 'odsc_test_unrelated_option' );
		}
	}

	/**
	 * Ensures WordPress capability checks distinguish administrators from subscribers.
	 *
	 * @return void
	 */
	public function test_only_administrators_have_required_capability() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$subscriber    = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		wp_set_current_user( $administrator );
		$this->assertTrue( current_user_can( 'manage_options' ) );

		wp_set_current_user( $subscriber );
		$this->assertFalse( current_user_can( 'manage_options' ) );
	}

	/**
	 * Ensures run and download nonces are action-specific.
	 *
	 * @return void
	 */
	public function test_nonces_reject_invalid_values() {
		$this->assertFalse( wp_verify_nonce( 'invalid', 'odsc_run_diagnostic' ) );
		$this->assertFalse( wp_verify_nonce( 'invalid', 'odsc_download_result' ) );
		$this->assertNotFalse( wp_verify_nonce( wp_create_nonce( 'odsc_run_diagnostic' ), 'odsc_run_diagnostic' ) );
		$this->assertNotFalse( wp_verify_nonce( wp_create_nonce( 'odsc_download_result' ), 'odsc_download_result' ) );
	}

	/**
	 * Ensures an administrator can execute the real run handler with consent.
	 *
	 * @return void
	 */
	public function test_administrator_can_execute_run_handler() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator );
		$nonce = wp_create_nonce( 'odsc_run_diagnostic' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'odsc_action'  => 'run',
			'odsc_consent' => '1',
			'_odsc_nonce'  => $nonce,
		);
		$_REQUEST                  = array( '_odsc_nonce' => $nonce );

		$admin = ODSC_Admin::get_instance();
		$admin->handle_run_request();

		$property = new ReflectionProperty( ODSC_Admin::class, 'result' );
		$property->setAccessible( true );
		$result = $property->getValue( $admin );

		$this->assertIsArray( $result );
		$this->assertCount( 22, $result['results'] );
	}

	/**
	 * Ensures a subscriber cannot execute the real run handler.
	 *
	 * @return void
	 */
	public function test_unauthorized_user_cannot_execute_run_handler() {
		$subscriber = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		wp_set_current_user( $subscriber );
		$nonce = wp_create_nonce( 'odsc_run_diagnostic' );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'odsc_action'  => 'run',
			'odsc_consent' => '1',
			'_odsc_nonce'  => $nonce,
		);
		$_REQUEST                  = array( '_odsc_nonce' => $nonce );

		$this->expectException( WPDieException::class );
		$this->expectExceptionCode( 403 );
		ODSC_Admin::get_instance()->handle_run_request();
	}

	/**
	 * Ensures the real run handler rejects an invalid nonce.
	 *
	 * @return void
	 */
	public function test_run_handler_rejects_invalid_nonce() {
		$administrator = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $administrator );

		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST                     = array(
			'odsc_action'  => 'run',
			'odsc_consent' => '1',
			'_odsc_nonce'  => 'invalid',
		);
		$_REQUEST                  = array( '_odsc_nonce' => 'invalid' );

		$this->expectException( WPDieException::class );
		ODSC_Admin::get_instance()->handle_run_request();
	}

	/**
	 * Sets the current-request admin result for test isolation.
	 *
	 * @param array<string, mixed>|null $value Result value.
	 * @return void
	 */
	private function set_admin_result( $value ) {
		$property = new ReflectionProperty( ODSC_Admin::class, 'result' );
		$property->setAccessible( true );
		$property->setValue( ODSC_Admin::get_instance(), $value );
	}

	/**
	 * Sets the request-local exporter for handler tests.
	 *
	 * @param ODSC_Exporter|null $value Exporter instance.
	 * @return void
	 */
	private function set_admin_exporter( $value ) {
		$property = new ReflectionProperty( ODSC_Admin::class, 'exporter' );
		$property->setAccessible( true );
		$property->setValue( ODSC_Admin::get_instance(), $value );
	}

	/**
	 * Prepares a signed download request.
	 *
	 * @param string             $nonce    Submitted nonce.
	 * @param ODSC_Exporter|null $exporter Exporter used to encode and sign the payload.
	 * @return string Encoded JSON body.
	 */
	private function set_download_request( $nonce, $exporter = null ) {
		$exporter = $exporter ? $exporter : new ODSC_Exporter();
		$json     = $exporter->encode( ( new ODSC_Collector() )->collect() );

		$_POST    = array(
			'odsc_payload'         => base64_encode( $json ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Mirrors the signed form transport.
			'odsc_signature'       => $exporter->sign( $json ),
			'_odsc_download_nonce' => $nonce,
		);
		$_REQUEST = array( '_odsc_download_nonce' => $nonce );

		return $json;
	}

	/**
	 * Restores a site transient changed by a test.
	 *
	 * @param string $key   Transient name.
	 * @param mixed  $value Previous value, or false if absent.
	 * @return void
	 */
	private function restore_site_transient( $key, $value ) {
		if ( false === $value ) {
			delete_site_transient( $key );
			return;
		}

		set_site_transient( $key, $value );
	}
}
