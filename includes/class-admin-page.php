<?php
/**
 * Admin menu and page rendering.
 *
 * @package MediaCleanup
 */

namespace MediaCleanup;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the admin menu item and enqueues assets.
 */
class Admin_Page {

    /**
     * Menu slug.
     */
    private const MENU_SLUG = 'media-cleanup';

    /**
     * Constructor – register hooks.
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Register the Tools > Media Cleanup menu item.
     *
     * @return void
     */
    public function register_menu(): void {
        add_management_page(
            __( 'Media Cleanup', 'media-cleanup' ),
            __( 'Media Cleanup', 'media-cleanup' ),
            'manage_options',
            self::MENU_SLUG,
            array( $this, 'render_page' )
        );
    }

    /**
     * Enqueue admin CSS and JS on the plugin page only.
     *
     * @param string $hook_suffix The current admin page hook suffix.
     * @return void
     */
    public function enqueue_assets( string $hook_suffix ): void {
        if ( 'tools_page_' . self::MENU_SLUG !== $hook_suffix ) {
            return;
        }

        wp_enqueue_style(
            'jemc-admin',
            JEMC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            JEMC_VERSION
        );

        wp_enqueue_script(
            'jemc-admin',
            JEMC_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            JEMC_VERSION,
            true
        );

        wp_localize_script(
            'jemc-admin',
            'jemcData',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'jemc_nonce' ),
                'i18n'    => array(
                    'scanning'          => __( 'Scanning...', 'media-cleanup' ),
                    'scanComplete'      => __( 'Scan complete.', 'media-cleanup' ),
                    'noUnused'          => __( 'No unused media files found. Your library is clean!', 'media-cleanup' ),
                    'confirmTrash'      => __( 'Are you sure you want to move %d item(s) (%s) to the trash?', 'media-cleanup' ),
                    'confirmDelete'     => __( 'Are you sure you want to PERMANENTLY DELETE %d item(s) (%s)? This cannot be undone!', 'media-cleanup' ),
                    'deleting'          => __( 'Deleting...', 'media-cleanup' ),
                    'deleteComplete'    => __( 'Deletion complete.', 'media-cleanup' ),
                    'error'             => __( 'An error occurred. Please try again.', 'media-cleanup' ),
                    'selectItems'       => __( 'Please select at least one item.', 'media-cleanup' ),
                    'exportingCsv'      => __( 'Exporting CSV...', 'media-cleanup' ),
                    'scanningOrphans'   => __( 'Scanning for orphan thumbnails...', 'media-cleanup' ),
                    'noOrphans'         => __( 'No orphan thumbnails found.', 'media-cleanup' ),
                    'confirmOrphanDel'  => __( 'Are you sure you want to delete %d orphan thumbnail file(s) (%s)? This cannot be undone!', 'media-cleanup' ),
                ),
            )
        );
    }

    /**
     * Render the admin page.
     *
     * @return void
     */
    public function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'media-cleanup' ) );
        }

        include JEMC_PLUGIN_DIR . 'templates/admin-page.php';
    }
}
