<?php
/**
 * 統合出力の実ハンドラを検証する。
 *
 * @package ODSiteCheck
 */

/**
 * 署名と認可を維持した準備・ダウンロード。
 */
class ExportTest extends WP_UnitTestCase {
	/**
	 * テスト用出力を設定する。
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->set_exporter( new ODSC_Test_Exporter() );
	}

	/**
	 * リクエスト状態を戻す。
	 *
	 * @return void
	 */
	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();
		$this->set_exporter( null );
		wp_set_current_user( 0 );
		parent::tear_down();
	}

	/**
	 * 準備・再検証・ダウンロードが同じ完成バイト列を使う。
	 *
	 * @return void
	 */
	public function test_prepare_and_download_share_the_exact_snapshot() {
		$exporter = new ODSC_Exporter();
		$payload  = ( new ODSC_Collector() )->collect();
		$json     = $exporter->encode( $payload );
		$this->request( $json, 'prepare' );
		$prepared = json_decode( $this->capture( 'prepare' ), true );
		$complete = json_decode( $prepared['json'], true );
		$this->assertSame( json_decode( $json, true )['results'], $complete['results'] );
		$this->assertTrue( $exporter->is_valid_payload( $complete ) );
		$this->assertTrue( $exporter->verify( $prepared['json'], $prepared['signature'] ) );
		$this->request( $prepared['json'], 'prepare' );
		$again = json_decode( $this->capture( 'prepare' ), true );
		$this->assertSame( $prepared['json'], $again['json'] );
		$this->assertSame( $prepared['signature'], $again['signature'] );
		$this->request( $prepared['json'], 'download' );
		$this->assertSame( $prepared['json'], $this->capture( 'download' ) );
	}

	/**
	 * 旧フォームの署名済み診断だけではダウンロードできない。
	 *
	 * @return void
	 */
	public function test_signed_but_unanswered_download_is_rejected() {
		$this->request( ( new ODSC_Exporter() )->encode( ( new ODSC_Collector() )->collect() ), 'download' );
		$this->expectException( WPDieException::class );
		$this->expectExceptionCode( 400 );
		ODSC_Admin::get_instance()->handle_download();
	}

	/**
	 * 不正な回答・転送を実際の準備入口で拒否する。
	 *
	 * @dataProvider invalid_request_cases
	 * @param string $scenario 異常ケース.
	 * @return void
	 */
	public function test_prepare_rejects_invalid_requests( $scenario ) {
		$this->request( ( new ODSC_Exporter() )->encode( ( new ODSC_Collector() )->collect() ), 'prepare' );
		$rows = json_decode( wp_unslash( $_POST['odsc_answers'] ), true ); // phpcs:ignore WordPress.Security -- テストが用意したリクエストを改変する。
		switch ( $scenario ) {
			case 'duplicate':
				$rows[1] = $rows[0];
				break;
			case 'missing':
				array_pop( $rows );
				break;
			case 'extra':
				$rows[] = array( 'H-21', 'answered', 'unknown' );
				break;
			case 'code':
				$rows[0][2] = 'invalid';
				break;
			case 'branch':
				$rows[5] = array( 'H-06', 'answered', 'yes' );
				break;
			case 'null':
				$rows[0][2] = null;
				break;
			case 'signature':
				$_POST['odsc_signature'] = 'invalid';
				break;
		}
		$_POST['odsc_answers'] = wp_slash( wp_json_encode( $rows ) );
		$this->expectException( WPDieException::class );
		$this->expectExceptionCode( 400 );
		ODSC_Admin::get_instance()->handle_prepare();
	}

	/**
	 * 異常ケース。
	 *
	 * @return array
	 */
	public function invalid_request_cases() {
		return array_map(
			static function ( $scenario ) {
				return array( $scenario );
			},
			array( 'duplicate', 'missing', 'extra', 'code', 'branch', 'null', 'signature' )
		);
	}

	/**
	 * 準備もnonceを独立に確認する。
	 *
	 * @return void
	 */
	public function test_prepare_rejects_invalid_nonce() {
		$this->request( ( new ODSC_Exporter() )->encode( ( new ODSC_Collector() )->collect() ), 'prepare' );
		$_POST['_odsc_prepare_nonce']    = 'invalid';
		$_REQUEST['_odsc_prepare_nonce'] = 'invalid';
		$this->expectException( WPDieException::class );
		ODSC_Admin::get_instance()->handle_prepare();
	}

	/**
	 * 準備直前にlist_usersを失った場合も拒否する。
	 *
	 * @return void
	 */
	public function test_prepare_rechecks_user_inventory_permission() {
		$this->request( ( new ODSC_Exporter() )->encode( ( new ODSC_Collector() )->collect() ), 'prepare' );
		$user = wp_get_current_user();
		$user->add_cap( 'list_users', false );
		wp_set_current_user( 0 );
		wp_set_current_user( $user->ID );
		$this->expectException( WPDieException::class );
		$this->expectExceptionCode( 403 );
		ODSC_Admin::get_instance()->handle_prepare();
	}

	/**
	 * 署名を別利用者に持ち出せない。
	 *
	 * @return void
	 */
	public function test_signature_is_bound_to_the_current_user() {
		$exporter  = new ODSC_Exporter();
		$signature = $exporter->sign( 'test' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertFalse( $exporter->verify( 'test', $signature ) );
	}

	/**
	 * 旧版実コードから取得した固定22項目を統合しても内容が変わらない。
	 *
	 * @return void
	 */
	public function test_legacy_fixture_results_are_preserved() {
		$payload = json_decode( file_get_contents( ODSC_PLUGIN_DIR . 'tests/fixtures/diagnostic-0.3.0.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 旧版の固定fixture。
		$this->assertSame( '1.2', $payload['schema_version'] );
		$results                   = $payload['results'];
		$payload['schema_version'] = ODSC_SCHEMA_VERSION;
		$details                   = ( new ODSC_Collector_WordPress() )->collect_update_settings()['value']['core_auto_update_details'];
		foreach ( $payload['results'] as &$result ) {
			if ( 'WP-02' === $result['id'] ) {
				$result['value']['core_auto_update_details'] = $details; }
		}
		unset( $result );

		$this->request( ( new ODSC_Exporter() )->encode( $payload ), 'prepare' );
		$prepared = json_decode( $this->capture( 'prepare' ), true );
		$after    = json_decode( $prepared['json'], true )['results'];
		foreach ( $after as &$result ) {
			if ( 'WP-02' === $result['id'] ) {
				unset( $result['value']['core_auto_update_details'] ); }
		}
		unset( $result );
		$this->assertSame( $results, $after );
		$this->assertCount( 22, $results );
	}

	/**
	 * 部分取得・個別失敗やマルチサイトの未対応でも全体は出力可能。
	 *
	 * @return void
	 */
	public function test_individual_failures_and_multisite_allow_completed_output() {
		$schema = json_decode( file_get_contents( ODSC_PLUGIN_DIR . 'schemas/diagnostic-result.schema.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 同梱Schema。
		foreach ( array( false, true ) as $multisite ) {
			$filter = $multisite ? '__return_true' : '__return_false';
			add_filter( 'odsc_is_multisite', $filter );
			$payload = ( new ODSC_Collector() )->collect();
			remove_filter( 'odsc_is_multisite', $filter );
			if ( ! $multisite ) {
				$payload['results'][0] = ODSC_Sanitizer::result( 'WP-01', 'error', null, null, 'テスト用の取得失敗', 'collector_failed' );
				$payload['results'][2] = ODSC_Sanitizer::result( 'WP-03', 'unavailable', null, null, 'テスト用の取得不能', 'missing_api' );
			}
			$before = json_decode( wp_json_encode( $payload['results'] ), true );
			$this->request( ( new ODSC_Exporter() )->encode( $payload ), 'prepare' );
			$prepared = json_decode( $this->capture( 'prepare' ), true );
			$complete = json_decode( $prepared['json'] );
			$this->assertTrue( ( new Opis\JsonSchema\Validator() )->validate( $complete, $schema )->isValid() );
			$this->assertSame( $before, json_decode( $prepared['json'], true )['results'] );
		}
	}

	/**
	 * 完成後に回答が変わったリクエストには以前のスナップショットを返さない。
	 *
	 * @return void
	 */
	public function test_cached_snapshot_with_changed_answers_is_rejected() {
		$payload            = ( new ODSC_Collector() )->collect();
		$payload['hearing'] = ODSC_Hearing::create( ODSC_Test_Hearing::answers( 'yes' ) );
		$this->request( ( new ODSC_Exporter() )->encode( $payload ), 'prepare' );
		$this->expectException( WPDieException::class );
		$this->expectExceptionCode( 400 );
		ODSC_Admin::get_instance()->handle_prepare();
	}

	/**
	 * 管理権限なしでは準備を直接呼んでも出力できない。
	 *
	 * @return void
	 */
	public function test_prepare_requires_administrator_permission() {
		$this->request( ( new ODSC_Exporter() )->encode( ( new ODSC_Collector() )->collect() ), 'prepare' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->expectException( WPDieException::class );
		$this->expectExceptionCode( 403 );
		ODSC_Admin::get_instance()->handle_prepare();
	}

	/**
	 * 失敗・取り消し時に同じインスタンスの旧診断を残さない。
	 *
	 * @return void
	 */
	public function test_failed_or_cancelled_run_invalidates_previous_result() {
		$admin    = ODSC_Admin::get_instance();
		$property = new ReflectionProperty( ODSC_Admin::class, 'result' );
		$property->setAccessible( true );
		$old_method = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
		try {
			foreach ( array( 'invalid_nonce', 'no_consent', 'collection_failure' ) as $scenario ) {
				$property->setValue( $admin, ( new ODSC_Collector() )->collect() );
				$nonce                     = 'invalid_nonce' === $scenario ? 'invalid' : wp_create_nonce( 'odsc_run_diagnostic' );
				$_SERVER['REQUEST_METHOD'] = 'POST';
				$_POST                     = array(
					'odsc_action'  => 'run',
					'odsc_consent' => 'collection_failure' === $scenario ? '1' : '0',
					'_odsc_nonce'  => $nonce,
				);
				$_REQUEST                  = array( '_odsc_nonce' => $nonce );
				$failure                   = static function () {
					throw new RuntimeException( 'テスト用の診断全体失敗' );
				};
				if ( 'collection_failure' === $scenario ) {
					add_filter( 'odsc_collectors', $failure );
				}
				try {
					$admin->handle_run_request();
				} catch ( WPDieException $exception ) {
					$this->assertSame( 'invalid_nonce', $scenario );
				} finally {
					remove_filter( 'odsc_collectors', $failure );
				}
				$this->assertNull( $property->getValue( $admin ) );
			}
		} finally {
			$_SERVER['REQUEST_METHOD'] = $old_method;
			$property->setValue( $admin, null );
		}
	}

	/**
	 * 準備処理がDB書き込みや外部HTTPを実行しない。
	 *
	 * @return void
	 */
	public function test_prepare_does_not_persist_or_send_data() {
		$this->request( ( new ODSC_Exporter() )->encode( ( new ODSC_Collector() )->collect() ), 'prepare' );
		$writes       = array();
		$requests     = 0;
		$query_filter = static function ( $query ) use ( &$writes ) {
			if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|ALTER|DROP)\b/i', $query ) ) {
				$writes[] = $query;
			}
			return $query;
		};
		$http_filter  = static function () use ( &$requests ) {
			++$requests;
			return new WP_Error( 'blocked', 'テスト中は外部通信を許可しない。' );
		};
		add_filter( 'query', $query_filter );
		add_filter( 'pre_http_request', $http_filter );
		try {
			$this->capture( 'prepare' );
		} finally {
			remove_filter( 'query', $query_filter );
			remove_filter( 'pre_http_request', $http_filter );
		}
		$this->assertSame( array(), $writes );
		$this->assertSame( 0, $requests );
	}

	/**
	 * 新しい回答の3分岐をサーバーがそのまま確定する。
	 *
	 * @return void
	 */
	public function test_prepare_uses_current_explicit_answers() {
		$json = ( new ODSC_Exporter() )->encode( ( new ODSC_Collector() )->collect() );
		foreach ( array( 'no', 'unknown', 'yes' ) as $backup ) {
			$this->request( $json, 'prepare', $backup );
			$prepared = json_decode( $this->capture( 'prepare' ), true );
			$this->assertSame( ODSC_Test_Hearing::answers( $backup ), json_decode( $prepared['json'], true )['hearing']['answers'] );
		}
	}

	/**
	 * 1.3の固定入力を拡張しても、他21項目とhearingは変化しない。
	 *
	 * @return void
	 */
	public function test_schema_transition_preserves_other_results_and_hearing() {
		$schema = json_decode( file_get_contents( ODSC_PLUGIN_DIR . 'schemas/diagnostic-result.schema.json' ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 新Schema。
		foreach ( array( 'no', 'yes' ) as $backup ) {
			$legacy                          = json_decode( file_get_contents( ODSC_PLUGIN_DIR . 'tests/fixtures/diagnostic-0.4.0-' . $backup . '.json' ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- 旧版固定入力。
			$payload                         = $legacy;
			$payload['schema_version']       = ODSC_SCHEMA_VERSION;
			$payload['collector']['version'] = ODSC_VERSION;
			foreach ( $payload['results'] as &$result ) {
				if ( 'WP-02' === $result['id'] ) {
					$result['value']['core_auto_update_details'] = ( new ODSC_Collector_WordPress() )->collect_update_settings()['value']['core_auto_update_details'];
				}
			}
			unset( $result );
			$this->request( ( new ODSC_Exporter() )->encode( $payload ), 'prepare', $backup );
			$prepared = json_decode( $this->capture( 'prepare' ), true );
			$complete = json_decode( $prepared['json'], true );
			$this->assertTrue( ( new Opis\JsonSchema\Validator() )->validate( json_decode( $prepared['json'] ), $schema )->isValid() );
			$this->assertSame( $legacy['hearing'], $complete['hearing'] );
			foreach ( $legacy['results'] as $index => $before ) {
				$after = $complete['results'][ $index ];
				if ( 'WP-02' === $before['id'] ) {
					unset( $after['value']['core_auto_update_details'] ); }
				$this->assertSame( $before, $after );
			}
		}
	}

	/**
	 * ハンドラを実行し、テスト用終了を捕捉する。
	 *
	 * @param string $action 処理名.
	 * @return string
	 */
	private function capture( $action ) {
		ob_start();
		try {
			if ( 'prepare' === $action ) {
				ODSC_Admin::get_instance()->handle_prepare();
			} else {
				ODSC_Admin::get_instance()->handle_download();
			}
		} catch ( ODSC_Test_Download_Completed $exception ) {
			$this->assertTrue( true, 'JSON応答の終了を捕捉した。' );
		} finally {
			$body = (string) ob_get_clean();
		}
		return $body;
	}

	/**
	 * 署名とnonce付きリクエストを用意する。
	 *
	 * @param string $json JSON文字列.
	 * @param string $action 処理名.
	 * @param string $backup H-05の回答.
	 * @return void
	 */
	private function request( $json, $action, $backup = 'no' ) {
		$exporter    = new ODSC_Exporter();
		$nonce_field = '_odsc_' . $action . '_nonce';
		$_POST       = array(
			'odsc_payload'   => base64_encode( $json ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- 署名済みの転送形式。
			'odsc_signature' => $exporter->sign( $json ),
			$nonce_field     => wp_create_nonce( 'odsc_' . $action . '_result' ),
		); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- 署名済みフォームの転送形式。
		$rows        = array();
		foreach ( ODSC_Test_Hearing::answers( $backup ) as $id => $answer ) {
			$rows[] = array( $id, $answer['status'], $answer['answer'] );
		}
		$_POST['odsc_answers'] = wp_slash( wp_json_encode( $rows ) );
		$_REQUEST              = array( $nonce_field => $_POST[ $nonce_field ] ); // phpcs:ignore WordPress.Security -- テスト内で生成済みのnonce。
	}

	/**
	 * サーバーの出力を捕捉する。
	 *
	 * @param ODSC_Exporter|null $exporter 出力クラス.
	 * @return void
	 */
	private function set_exporter( $exporter ) {
		$property = new ReflectionProperty( ODSC_Admin::class, 'exporter' );
		$property->setAccessible( true );
		$property->setValue( ODSC_Admin::get_instance(), $exporter );
	}
}
