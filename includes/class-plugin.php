<?php
/**
 * Plugin bootstrap singleton.
 *
 * @package MediaCleanup
 */

namespace MediaCleanup;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Main plugin class – singleton.
 */
final class Plugin {

    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Admin page handler.
     *
     * @var Admin_Page
     */
    private Admin_Page $admin_page;

    /**
     * AJAX handler.
     *
     * @var Ajax_Handler
     */
    private Ajax_Handler $ajax_handler;

    /**
     * Get (or create) the singleton instance.
     *
     * @return self
     */
    public static function get_instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor – wire up hooks.
     */
    private function __construct() {
        $this->admin_page   = new Admin_Page();
        $this->ajax_handler = new Ajax_Handler();
    }

    /**
     * Activation callback.
     *
     * @return void
     */
    public static function activate(): void {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        update_option( 'jemc_version', JEMC_VERSION );
        update_option( 'jemc_activity_log', array() );
    }

    /**
     * Deactivation callback.
     *
     * @return void
     */
    public static function deactivate(): void {
        if ( ! current_user_can( 'activate_plugins' ) ) {
            return;
        }
        delete_transient( 'jemc_scan_results' );
        delete_transient( 'jemc_orphan_results' );
    }
}
