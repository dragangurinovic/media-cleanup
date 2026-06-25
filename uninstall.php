<?php
/**
 * Uninstall handler – clean up plugin data.
 *
 * @package MediaCleanup
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'jemc_version' );
delete_option( 'jemc_activity_log' );
delete_option( 'jemc_schedule_settings' );
delete_option( 'jemc_quarantine_items' );
delete_transient( 'jemc_scan_results' );
delete_transient( 'jemc_scan_progress' );
delete_transient( 'jemc_orphan_results' );
delete_transient( 'jemc_duplicates_results' );
delete_transient( 'jemc_broken_links_results' );

wp_unschedule_hook( 'jemc_scheduled_scan' );

// Remove quarantine directory.
$quarantine_dir = WP_CONTENT_DIR . '/jemc-quarantine';
if ( is_dir( $quarantine_dir ) ) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator( $quarantine_dir, FilesystemIterator::SKIP_DOTS ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ( $iterator as $item ) {
        if ( $item->isDir() ) {
            rmdir( $item->getPathname() );
        } else {
            unlink( $item->getPathname() );
        }
    }
    rmdir( $quarantine_dir );
}
