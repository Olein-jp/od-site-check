<?php
/**
 * Installed ZIP smoke test for WP-CLI.
 *
 * @package ODSiteCheck
 */

/**
 * Verify that the packaged plugin can run its primary administrator workflow.
 *
 * @return void
 */
function odsc_run_zip_smoke_test() {
	global $wp_version;

	if ( 0 !== strpos( $wp_version, '7.1' ) ) {
		WP_CLI::error( 'Expected WordPress 7.1, got ' . $wp_version );
	}

	if ( 0 !== strpos( PHP_VERSION, '7.4' ) ) {
		WP_CLI::error( 'Expected PHP 7.4, got ' . PHP_VERSION );
	}

	require_once ABSPATH . 'wp-admin/includes/plugin.php';

	if ( ! is_plugin_active( 'od-site-check/od-site-check.php' ) ) {
		WP_CLI::error( 'OD Site Check is not active.' );
	}

	$odsc_admin_user = get_user_by( 'login', 'admin' );
	if ( ! $odsc_admin_user ) {
		WP_CLI::error( 'Administrator user was not found.' );
	}

	wp_set_current_user( $odsc_admin_user->ID );

	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST['odsc_action']      = 'run';
	$_POST['odsc_consent']     = '1';
	$odsc_nonce                = wp_create_nonce( 'odsc_run_diagnostic' );
	$_POST['_odsc_nonce']      = $odsc_nonce;
	$_REQUEST                  = array( '_odsc_nonce' => $odsc_nonce );

	$odsc_admin = ODSC_Admin::get_instance();
	$odsc_admin->handle_run_request();

	ob_start();
	$odsc_admin->render_page();
	$odsc_html = ob_get_clean();

	if ( false === strpos( $odsc_html, 'OD サイト診断' ) ) {
		WP_CLI::error( 'The administrator page did not render.' );
	}

	if ( false === strpos( $odsc_html, 'サイトの状態確認が完了しました。' ) ) {
		WP_CLI::error( 'The diagnostic run did not complete.' );
	}

	if ( false === strpos( $odsc_html, 'odsc-json-preview' ) ) {
		WP_CLI::error( 'The generated JSON preview was not rendered.' );
	}

	// 終了とヘッダーのみを捕捉し、インストールしたZIPの実ハンドラを検査する.
	require_once __DIR__ . '/../support/class-odsc-test-download-completed.php';
	require_once __DIR__ . '/../support/class-odsc-test-exporter.php';
	$odsc_dom      = new DOMDocument();
	$odsc_previous = libxml_use_internal_errors( true );
	$odsc_dom->loadHTML( $odsc_html );
	libxml_clear_errors();
	libxml_use_internal_errors( $odsc_previous );
	$odsc_xpath = new DOMXPath( $odsc_dom );
	if ( 20 !== $odsc_xpath->query( '//fieldset' )->length ||
		0 !== $odsc_xpath->query( '//input[@type="radio"][@checked]' )->length ||
		2 !== $odsc_xpath->query( '//button[@id="odsc-copy-json" or @id="odsc-download-json"][@disabled]' )->length ) {
		WP_CLI::error( '固定20問・未選択・出力ボタンの初期無効化を確認できません。' );
	}
	$odsc_exporter = new ODSC_Test_Exporter();
	$odsc_property = new ReflectionProperty( ODSC_Admin::class, 'exporter' );
	$odsc_property->setAccessible( true );
	$odsc_property->setValue( $odsc_admin, $odsc_exporter );
	$odsc_result_property = new ReflectionProperty( ODSC_Admin::class, 'result' );
	$odsc_result_property->setAccessible( true );
	$odsc_result = $odsc_result_property->getValue( $odsc_admin );
	$odsc_json   = $odsc_exporter->encode( $odsc_result );
	if ( '1.4' !== $odsc_result['schema_version'] || ! file_exists( ODSC_PLUGIN_DIR . 'schemas/diagnostic-result-1.3.schema.json' ) ) {
		WP_CLI::error( '新Schemaと旧1.3原本の同梱を確認できません。' );
	}
	$odsc_wp02 = array_values(
		array_filter(
			$odsc_result['results'],
			static function ( $item ) {
				return 'WP-02' === $item['id'];
			}
		)
	)[0];
	if ( ! isset( $odsc_wp02['value']['core_auto_update_details'] ) ||
		false !== $odsc_wp02['value']['core_auto_update_details']['effective_behavior_verified'] ||
		false === strpos( $odsc_html, 'メジャー更新の保存設定' ) ||
		false === strpos( $odsc_html, '自動更新の最終動作' ) ) {
		WP_CLI::error( 'WP-02の新詳細と未検証の画面表示を確認できません。' );
	}

	$odsc_definition = ODSC_Hearing::definition();
	if ( ! ODSC_Hearing::has_definitions() ) {
		WP_CLI::error( '同梱の質問定義を読み込めません。' );
	}
	foreach ( array( 'no', 'yes' ) as $odsc_backup ) {
		$odsc_rows = array();
		foreach ( $odsc_definition['questions'] as $odsc_question ) {
			$odsc_applicable = null === $odsc_question['condition'] || 'yes' === $odsc_backup;
			$odsc_rows[]     = array(
				$odsc_question['id'],
				$odsc_applicable ? 'answered' : 'not_applicable',
				$odsc_applicable ? ( 'H-05' === $odsc_question['id'] ? $odsc_backup : 'unknown' ) : null,
			);
		}
		$odsc_prepare_nonce = wp_create_nonce( 'odsc_prepare_result' );
		$_POST              = array(
			'odsc_payload'        => base64_encode( $odsc_json ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- 署名済みJSONの転送。
			'odsc_signature'      => $odsc_exporter->sign( $odsc_json ),
			'odsc_answers'        => wp_slash( wp_json_encode( $odsc_rows ) ),
			'_odsc_prepare_nonce' => $odsc_prepare_nonce,
		);
		$_REQUEST           = array( '_odsc_prepare_nonce' => $odsc_prepare_nonce );
		ob_start();
		try {
			$odsc_admin->handle_prepare();
		} catch ( ODSC_Test_Download_Completed $odsc_exception ) {
			$odsc_response = json_decode( ob_get_contents(), true );
		} finally {
			ob_end_clean();
		}
		$odsc_complete = json_decode( $odsc_response['json'], true );
		if ( ! $odsc_exporter->is_valid_payload( $odsc_complete ) ||
			20 !== count( $odsc_complete['hearing']['answers'] ) ||
			json_decode( $odsc_json, true )['results'] !== $odsc_complete['results'] ) {
			WP_CLI::error( '統合JSONの形式または既存22項目の維持を確認できません。' );
		}
		$odsc_download_nonce = wp_create_nonce( 'odsc_download_result' );
		$_POST               = array(
			'odsc_payload'         => base64_encode( $odsc_response['json'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- 署名済みJSONの転送。
			'odsc_signature'       => $odsc_response['signature'],
			'_odsc_download_nonce' => $odsc_download_nonce,
		);
		$_REQUEST            = array( '_odsc_download_nonce' => $odsc_download_nonce );
		ob_start();
		try {
			$odsc_admin->handle_download();
		} catch ( ODSC_Test_Download_Completed $odsc_exception ) {
			$odsc_downloaded = ob_get_contents();
		} finally {
			ob_end_clean();
		}
		if ( $odsc_response['json'] !== $odsc_downloaded ) {
			WP_CLI::error( '確定したJSONとダウンロードが一致しません。' );
		}
	}

	WP_CLI::success( '配布ZIPの有効化・診断・13問／20問回答の統合出力とダウンロード一致を確認しました。' );
}

odsc_run_zip_smoke_test();
