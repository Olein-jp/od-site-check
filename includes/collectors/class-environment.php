<?php
/**
 * Runtime environment collector.
 *
 * @package ODSiteCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects non-secret PHP and database details.
 */
final class ODSC_Collector_Environment {
	/**
	 * Collects the PHP version and interface.
	 *
	 * @return array<string, mixed>
	 */
	public function collect_php() {
		return ODSC_Sanitizer::result(
			'OPS-03',
			'collected',
			'php_runtime',
			array(
				'version' => PHP_VERSION,
				'sapi'    => PHP_SAPI,
			)
		);
	}

	/**
	 * Collects database type and server version without connection details.
	 *
	 * @global wpdb $wpdb WordPress database abstraction object.
	 * @return array<string, mixed>
	 */
	public function collect_database() {
		global $wpdb;

		$server_info = method_exists( $wpdb, 'db_server_info' ) ? $wpdb->db_server_info() : '';
		$type        = false !== stripos( $server_info, 'mariadb' ) ? 'MariaDB' : 'MySQL';

		return ODSC_Sanitizer::result(
			'OPS-04',
			'collected',
			'wordpress_database_api',
			array(
				'type'    => $type,
				'version' => $wpdb->db_version(),
			),
			__( 'データベース名、接続先、ユーザー名、パスワードは収集していません。', 'od-site-check' )
		);
	}

	/**
	 * Collects PHP and WordPress resource limits.
	 *
	 * @return array<string, mixed>
	 */
	public function collect_resources() {
		return ODSC_Sanitizer::result(
			'OPS-05',
			'collected',
			'php_runtime',
			array(
				'php_memory_limit'    => (string) ini_get( 'memory_limit' ),
				'wp_memory_limit'     => defined( 'WP_MEMORY_LIMIT' ) ? (string) WP_MEMORY_LIMIT : null,
				'wp_max_memory_limit' => defined( 'WP_MAX_MEMORY_LIMIT' ) ? (string) WP_MAX_MEMORY_LIMIT : null,
				'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
				'upload_max_filesize' => (string) ini_get( 'upload_max_filesize' ),
				'post_max_size'       => (string) ini_get( 'post_max_size' ),
				'server_disk_checked' => false,
			),
			__( '表示値はPHPとWordPressの制限です。サーバー全体の空き容量は確認していません。', 'od-site-check' )
		);
	}
}
