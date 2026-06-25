<?php
/**
 * Plugin Name:       Media Cleanup
 * Plugin URI:        https://example.com/media-cleanup
 * Description:       Find and remove unused media files from your WordPress media library. Deep-scans post content, meta fields, widgets, options, and more.
 * Version:           1.1.0
 * Author:            JE Media Cleanup
 * Author URI:        https://example.com
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       media-cleanup
 * Domain Path:       /languages
 * Requires PHP:      7.4
 * Requires at least: 5.8
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'JEMC_PLUGIN_FILE', __FILE__ );
define( 'JEMC_VERSION', '1.1.0' );
define( 'JEMC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'JEMC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once JEMC_PLUGIN_DIR . 'includes/class-plugin.php';
require_once JEMC_PLUGIN_DIR . 'includes/class-scanner.php';
require_once JEMC_PLUGIN_DIR . 'includes/class-cleaner.php';
require_once JEMC_PLUGIN_DIR . 'includes/class-admin-page.php';
require_once JEMC_PLUGIN_DIR . 'includes/class-ajax-handler.php';
require_once JEMC_PLUGIN_DIR . 'includes/class-duplicates.php';
require_once JEMC_PLUGIN_DIR . 'includes/class-broken-links.php';
require_once JEMC_PLUGIN_DIR . 'includes/class-scheduler.php';
require_once JEMC_PLUGIN_DIR . 'includes/class-quarantine.php';
require_once JEMC_PLUGIN_DIR . 'includes/class-optimizer.php';

if ( defined( 'WP_CLI' ) && WP_CLI ) {
    require_once JEMC_PLUGIN_DIR . 'includes/class-cli.php';
}

register_activation_hook( __FILE__, array( 'MediaCleanup\\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MediaCleanup\\Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'MediaCleanup\\Plugin', 'get_instance' ) );
