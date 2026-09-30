<?php
/**
 * WordPress integration test bootstrap.
 *
 * @package ODSiteCheck
 */

$odsc_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $odsc_tests_dir ) {
	fwrite( STDERR, "WP_TESTS_DIR is not set. Run tests through wp-env.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Test bootstrap runs before WordPress loads.
	exit( 1 );
}

require dirname( __DIR__ ) . '/vendor/autoload.php';
require_once $odsc_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () {
		require dirname( __DIR__ ) . '/od-site-check.php';
		require __DIR__ . '/support/class-odsc-test-download-completed.php';
		require __DIR__ . '/support/class-odsc-test-exporter.php';
	}
);

require $odsc_tests_dir . '/includes/bootstrap.php';
