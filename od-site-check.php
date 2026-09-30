<?php
/**
 * Plugin Name:       OD Site Check
 * Description:       WordPressサイトの構成・運用環境を確認し、診断用JSONを生成します。
 * Version:           0.1.0
 * Requires at least: 7.1
 * Requires PHP:      7.4
 * Author:            Koji Kuno
 * Author URI:        https://olein-design.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       od-site-check
 *
 * @package ODSiteCheck
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ODSC_VERSION', '0.1.0' );
define( 'ODSC_SCHEMA_VERSION', '1.0' );
define( 'ODSC_MINIMUM_WP_VERSION', '7.1' );
define( 'ODSC_MINIMUM_PHP_VERSION', '7.4' );
define( 'ODSC_PLUGIN_FILE', __FILE__ );
define( 'ODSC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'ODSC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Displays an environment compatibility notice.
 *
 * @return void
 */
function odsc_render_compatibility_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$message = sprintf(
		/* translators: 1: Minimum WordPress version, 2: Minimum PHP version. */
		__( 'OD Site Checkを利用するには、WordPress %1$s以上かつPHP %2$s以上が必要です。', 'od-site-check' ),
		ODSC_MINIMUM_WP_VERSION,
		ODSC_MINIMUM_PHP_VERSION
	);

	printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
}

global $wp_version;

if ( version_compare( PHP_VERSION, ODSC_MINIMUM_PHP_VERSION, '<' ) || version_compare( $wp_version, ODSC_MINIMUM_WP_VERSION, '<' ) ) {
	add_action( 'admin_notices', 'odsc_render_compatibility_notice' );
	return;
}

require_once ODSC_PLUGIN_DIR . 'includes/class-sanitizer.php';
require_once ODSC_PLUGIN_DIR . 'includes/collectors/class-wordpress.php';
require_once ODSC_PLUGIN_DIR . 'includes/collectors/class-themes.php';
require_once ODSC_PLUGIN_DIR . 'includes/collectors/class-plugins.php';
require_once ODSC_PLUGIN_DIR . 'includes/collectors/class-users.php';
require_once ODSC_PLUGIN_DIR . 'includes/collectors/class-settings.php';
require_once ODSC_PLUGIN_DIR . 'includes/collectors/class-environment.php';
require_once ODSC_PLUGIN_DIR . 'includes/class-collector.php';
require_once ODSC_PLUGIN_DIR . 'includes/class-exporter.php';
require_once ODSC_PLUGIN_DIR . 'includes/class-admin.php';

ODSC_Admin::get_instance()->register_hooks();
