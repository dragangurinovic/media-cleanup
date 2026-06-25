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
delete_transient( 'jemc_scan_results' );
delete_transient( 'jemc_scan_progress' );
delete_transient( 'jemc_orphan_results' );
