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
	 * @return void
	 */
	private function request( $json, $action ) {
		$exporter    = new ODSC_Exporter();
		$nonce_field = '_odsc_' . $action . '_nonce';
		$_POST       = array(
			'odsc_payload'   => base64_encode( $json ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- 署名済みの転送形式。
			'odsc_signature' => $exporter->sign( $json ),
			$nonce_field     => wp_create_nonce( 'odsc_' . $action . '_result' ),
		); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- 署名済みフォームの転送形式。
		$rows        = array();
		foreach ( ODSC_Test_Hearing::answers() as $id => $answer ) {
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
