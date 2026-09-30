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

	WP_CLI::success( 'The installed ZIP activated and completed the administrator diagnostic workflow.' );
}

odsc_run_zip_smoke_test();
