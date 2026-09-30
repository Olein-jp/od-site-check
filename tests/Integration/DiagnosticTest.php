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
}
