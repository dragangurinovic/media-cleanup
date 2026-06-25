<?php
/**
 * AJAX endpoints.
 *
 * @package MediaCleanup
 */

namespace MediaCleanup;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers and handles all AJAX actions.
 */
class Ajax_Handler {

    /**
     * Scanner instance.
     *
     * @var Scanner
     */
    private Scanner $scanner;

    /**
     * Cleaner instance.
     *
     * @var Cleaner
     */
    private Cleaner $cleaner;

    /**
     * Constructor – register AJAX hooks.
     */
    public function __construct() {
        $this->scanner = new Scanner();
        $this->cleaner = new Cleaner();

        $actions = array(
            'jemc_start_scan',
            'jemc_scan_batch',
            'jemc_get_results',
            'jemc_delete_media',
            'jemc_scan_orphan_thumbnails',
            'jemc_delete_orphan_thumbnails',
            'jemc_export_csv',
            'jemc_scan_duplicates',
            'jemc_scan_broken_links',
            'jemc_quarantine_media',
            'jemc_get_quarantine',
            'jemc_restore_quarantine',
            'jemc_delete_quarantine',
            'jemc_scan_optimizable',
            'jemc_optimize_images',
            'jemc_get_schedule',
            'jemc_save_schedule',
        );

        foreach ( $actions as $action ) {
            add_action( 'wp_ajax_' . $action, array( $this, $action ) );
        }
    }

    /**
     * Verify the request nonce and capability.
     *
     * @return void Sends JSON error and dies on failure.
     */
    private function verify_request(): void {
        check_ajax_referer( 'jemc_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Insufficient permissions.' ), 403 );
        }
    }

    /**
     * Start a scan – return total attachment count.
     *
     * @return void
     */
    public function jemc_start_scan(): void {
        $this->verify_request();

        // Clear previous results.
        delete_transient( 'jemc_scan_results' );

        $all_ids = $this->scanner->get_all_attachment_ids();

        // Store all IDs for batch processing.
        set_transient(
            'jemc_scan_progress',
            array(
                'all_ids'  => $all_ids,
                'used_ids' => array(),
                'step'     => 0,
                'steps'    => array(
                    'post_content',
                    'post_meta',
                    'options',
                    'widgets',
                    'term_meta',
                    'theme_css',
                    'page_builders',
                    'finalize',
                ),
            ),
            HOUR_IN_SECONDS
        );

        wp_send_json_success(
            array(
                'total'      => count( $all_ids ),
                'totalSteps' => 8,
            )
        );
    }

    /**
     * Process one batch/step of the scan.
     *
     * @return void
     */
    public function jemc_scan_batch(): void {
        $this->verify_request();

        $progress = get_transient( 'jemc_scan_progress' );

        if ( false === $progress || ! is_array( $progress ) ) {
            wp_send_json_error( array( 'message' => 'No scan in progress. Please start a new scan.' ) );
        }

        $step     = (int) ( $progress['step'] ?? 0 );
        $steps    = $progress['steps'];
        $used_ids = $progress['used_ids'] ?? array();
        $all_ids  = $progress['all_ids'] ?? array();

        if ( $step >= count( $steps ) ) {
            wp_send_json_success(
                array(
                    'complete' => true,
                    'step'     => $step,
                )
            );
            return;
        }

        $current_step = $steps[ $step ];
        $new_ids      = array();

        switch ( $current_step ) {
            case 'post_content':
                $new_ids = $this->scanner->scan_post_content();
                break;
            case 'post_meta':
                $new_ids = $this->scanner->scan_post_meta();
                break;
            case 'options':
                $new_ids = $this->scanner->scan_options();
                break;
            case 'widgets':
                $new_ids = $this->scanner->scan_widgets();
                break;
            case 'term_meta':
                $new_ids = $this->scanner->scan_term_meta();
                break;
            case 'theme_css':
                $new_ids = $this->scanner->scan_theme_css();
                break;
            case 'page_builders':
                $new_ids = $this->scanner->scan_page_builders();
                break;
            case 'finalize':
                // Calculate unused IDs and store results.
                $used_set   = array_flip( array_unique( array_filter( array_map( 'intval', $used_ids ) ) ) );
                $unused_ids = array();
                $total_size = 0;

                foreach ( $all_ids as $id ) {
                    if ( ! isset( $used_set[ $id ] ) ) {
                        $unused_ids[] = $id;
                        $file_path    = get_attached_file( $id );
                        if ( $file_path && file_exists( $file_path ) ) {
                            $size = filesize( $file_path );
                            if ( $size ) {
                                $total_size += $size;
                            }
                        }
                    }
                }

                // Build MIME type summary.
                $mime_summary = array();
                foreach ( $unused_ids as $id ) {
                    $mime = get_post_mime_type( $id );
                    if ( empty( $mime ) ) {
                        $mime = 'other';
                    }

                    $type_key = $this->get_mime_group( $mime );

                    if ( ! isset( $mime_summary[ $type_key ] ) ) {
                        $mime_summary[ $type_key ] = array(
                            'count' => 0,
                            'size'  => 0,
                            'label' => $type_key,
                        );
                    }

                    ++$mime_summary[ $type_key ]['count'];

                    $file_path = get_attached_file( $id );
                    if ( $file_path && file_exists( $file_path ) ) {
                        $size = filesize( $file_path );
                        if ( $size ) {
                            $mime_summary[ $type_key ]['size'] += $size;
                        }
                    }
                }

                set_transient(
                    'jemc_scan_results',
                    array(
                        'unused_ids'   => $unused_ids,
                        'total_size'   => $total_size,
                        'mime_summary' => $mime_summary,
                        'scanned_at'   => current_time( 'mysql' ),
                    ),
                    DAY_IN_SECONDS
                );

                delete_transient( 'jemc_scan_progress' );

                wp_send_json_success(
                    array(
                        'complete'    => true,
                        'step'        => $step + 1,
                        'totalSteps'  => count( $steps ),
                        'unusedCount' => count( $unused_ids ),
                        'totalSize'   => size_format( $total_size ),
                        'totalSizeRaw' => $total_size,
                        'mimeSummary' => array_values( $mime_summary ),
                    )
                );
                return;
        }

        if ( ! empty( $new_ids ) ) {
            $used_ids = array_merge( $used_ids, $new_ids );
        }

        $progress['used_ids'] = $used_ids;
        $progress['step']     = $step + 1;

        set_transient( 'jemc_scan_progress', $progress, HOUR_IN_SECONDS );

        wp_send_json_success(
            array(
                'complete'   => false,
                'step'       => $step + 1,
                'totalSteps' => count( $steps ),
                'stepName'   => $current_step,
            )
        );
    }

    /**
     * Get paginated results.
     *
     * @return void
     */
    public function jemc_get_results(): void {
        $this->verify_request();

        $page     = max( 1, (int) ( $_POST['page'] ?? 1 ) );
        $per_page = max( 1, min( 100, (int) ( $_POST['per_page'] ?? 50 ) ) );

        $results = $this->scanner->get_unused_attachments( $page, $per_page );
        $cached  = get_transient( 'jemc_scan_results' );

        wp_send_json_success(
            array(
                'items'        => $results['items'],
                'total'        => $results['total'],
                'totalSize'    => size_format( $results['total_size'] ),
                'totalSizeRaw' => $results['total_size'],
                'page'         => $page,
                'perPage'      => $per_page,
                'totalPages'   => (int) ceil( $results['total'] / $per_page ),
                'mimeSummary'  => $cached ? array_values( $cached['mime_summary'] ?? array() ) : array(),
            )
        );
    }

    /**
     * Batch-delete selected media.
     *
     * @return void
     */
    public function jemc_delete_media(): void {
        $this->verify_request();

        $ids = array();
        if ( isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ) {
            $ids = array_map( 'intval', $_POST['ids'] );
        }

        $force = ! empty( $_POST['force_delete'] );

        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => 'No items selected.' ) );
        }

        $result = $this->cleaner->delete_attachments( $ids, $force );

        // Update cached scan results: remove deleted IDs.
        $cached = get_transient( 'jemc_scan_results' );
        if ( false !== $cached && is_array( $cached ) ) {
            $cached['unused_ids'] = array_values( array_diff( $cached['unused_ids'], $ids ) );

            // Recalculate total size.
            $total_size = 0;
            foreach ( $cached['unused_ids'] as $id ) {
                $file_path = get_attached_file( $id );
                if ( $file_path && file_exists( $file_path ) ) {
                    $size = filesize( $file_path );
                    if ( $size ) {
                        $total_size += $size;
                    }
                }
            }
            $cached['total_size'] = $total_size;

            set_transient( 'jemc_scan_results', $cached, DAY_IN_SECONDS );
        }

        wp_send_json_success(
            array(
                'deleted' => $result['deleted'],
                'failed'  => $result['failed'],
                'errors'  => $result['errors'],
            )
        );
    }

    /**
     * Scan for orphan thumbnails.
     *
     * @return void
     */
    public function jemc_scan_orphan_thumbnails(): void {
        $this->verify_request();

        $orphans = $this->scanner->get_orphan_thumbnails();

        $total_size = 0;
        foreach ( $orphans as $orphan ) {
            $total_size += $orphan['size'];
        }

        set_transient(
            'jemc_orphan_results',
            array(
                'orphans'    => $orphans,
                'total_size' => $total_size,
            ),
            DAY_IN_SECONDS
        );

        wp_send_json_success(
            array(
                'orphans'      => $orphans,
                'total'        => count( $orphans ),
                'totalSize'    => size_format( $total_size ),
                'totalSizeRaw' => $total_size,
            )
        );
    }

    /**
     * Delete orphan thumbnail files.
     *
     * @return void
     */
    public function jemc_delete_orphan_thumbnails(): void {
        $this->verify_request();

        $paths = array();
        if ( isset( $_POST['paths'] ) && is_array( $_POST['paths'] ) ) {
            $paths = array_map( 'sanitize_text_field', $_POST['paths'] );
        }

        if ( empty( $paths ) ) {
            wp_send_json_error( array( 'message' => 'No files selected.' ) );
        }

        $result = $this->cleaner->delete_orphan_files( $paths );

        // Update cached orphan results.
        $cached = get_transient( 'jemc_orphan_results' );
        if ( false !== $cached && is_array( $cached ) ) {
            $deleted_set             = array_flip( $paths );
            $cached['orphans']       = array_values(
                array_filter(
                    $cached['orphans'],
                    function ( $o ) use ( $deleted_set ) {
                        return ! isset( $deleted_set[ $o['path'] ] );
                    }
                )
            );
            $cached['total_size'] = array_sum( array_column( $cached['orphans'], 'size' ) );
            set_transient( 'jemc_orphan_results', $cached, DAY_IN_SECONDS );
        }

        wp_send_json_success(
            array(
                'deleted' => $result['deleted'],
                'failed'  => $result['failed'],
                'errors'  => $result['errors'],
            )
        );
    }

    /**
     * Export scan results as CSV.
     *
     * @return void
     */
    public function jemc_export_csv(): void {
        $this->verify_request();

        $cached = get_transient( 'jemc_scan_results' );

        if ( false === $cached || ! is_array( $cached ) ) {
            wp_send_json_error( array( 'message' => 'No scan results available. Run a scan first.' ) );
        }

        $unused_ids = $cached['unused_ids'] ?? array();
        $rows       = array();

        $rows[] = array( 'ID', 'Title', 'Filename', 'MIME Type', 'File Size', 'Upload Date', 'Dimensions', 'Path' );

        foreach ( $unused_ids as $id ) {
            $data   = $this->scanner->build_attachment_data( (int) $id );
            $rows[] = array(
                $data['id'],
                $data['title'],
                $data['filename'],
                $data['mime_type'],
                $data['file_size_hr'],
                $data['upload_date'],
                $data['dimensions'],
                $data['relative_path'],
            );
        }

        wp_send_json_success( array( 'csv' => $rows ) );
    }

    /**
     * Scan for duplicate media files.
     *
     * @return void
     */
    public function jemc_scan_duplicates(): void {
        $this->verify_request();

        $duplicates = new Duplicates();
        $groups     = $duplicates->find_duplicates();
        $summary    = $duplicates->get_summary( $groups );

        set_transient( 'jemc_duplicates_results', $groups, DAY_IN_SECONDS );

        wp_send_json_success(
            array(
                'groups'  => $groups,
                'summary' => $summary,
            )
        );
    }

    /**
     * Scan for broken media links.
     *
     * @return void
     */
    public function jemc_scan_broken_links(): void {
        $this->verify_request();

        $broken  = new Broken_Links();
        $results = $broken->find_broken_links();

        wp_send_json_success(
            array(
                'links' => $results,
                'total' => count( $results ),
            )
        );
    }

    /**
     * Move media to quarantine.
     *
     * @return void
     */
    public function jemc_quarantine_media(): void {
        $this->verify_request();

        $ids = array();
        if ( isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ) {
            $ids = array_map( 'intval', $_POST['ids'] );
        }

        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => 'No items selected.' ) );
        }

        $quarantine = new Quarantine();
        $result     = $quarantine->quarantine_attachments( $ids );

        // Update cached scan results.
        $cached = get_transient( 'jemc_scan_results' );
        if ( false !== $cached && is_array( $cached ) ) {
            $cached['unused_ids'] = array_values( array_diff( $cached['unused_ids'], $ids ) );
            $total_size = 0;
            foreach ( $cached['unused_ids'] as $id ) {
                $file_path = get_attached_file( $id );
                if ( $file_path && file_exists( $file_path ) ) {
                    $size = filesize( $file_path );
                    if ( $size ) {
                        $total_size += $size;
                    }
                }
            }
            $cached['total_size'] = $total_size;
            set_transient( 'jemc_scan_results', $cached, DAY_IN_SECONDS );
        }

        wp_send_json_success( $result );
    }

    /**
     * Get quarantined items.
     *
     * @return void
     */
    public function jemc_get_quarantine(): void {
        $this->verify_request();

        $quarantine = new Quarantine();
        $items      = $quarantine->get_items();
        $summary    = $quarantine->get_summary();

        wp_send_json_success(
            array(
                'items'   => $items,
                'summary' => $summary,
            )
        );
    }

    /**
     * Restore quarantined items.
     *
     * @return void
     */
    public function jemc_restore_quarantine(): void {
        $this->verify_request();

        $keys = array();
        if ( isset( $_POST['keys'] ) && is_array( $_POST['keys'] ) ) {
            $keys = array_map( 'sanitize_text_field', $_POST['keys'] );
        }

        if ( empty( $keys ) ) {
            wp_send_json_error( array( 'message' => 'No items selected.' ) );
        }

        $quarantine = new Quarantine();
        $restored   = 0;
        $failed     = 0;
        $errors     = array();

        foreach ( $keys as $key ) {
            $result = $quarantine->restore_item( $key );
            $restored += $result['restored'];
            $failed   += $result['failed'];
            $errors    = array_merge( $errors, $result['errors'] );
        }

        wp_send_json_success(
            array(
                'restored' => $restored,
                'failed'   => $failed,
                'errors'   => $errors,
            )
        );
    }

    /**
     * Permanently delete quarantined items.
     *
     * @return void
     */
    public function jemc_delete_quarantine(): void {
        $this->verify_request();

        $keys = array();
        if ( isset( $_POST['keys'] ) && is_array( $_POST['keys'] ) ) {
            $keys = array_map( 'sanitize_text_field', $_POST['keys'] );
        }

        if ( empty( $keys ) ) {
            wp_send_json_error( array( 'message' => 'No items selected.' ) );
        }

        $quarantine = new Quarantine();
        $result     = $quarantine->delete_permanently( $keys );

        wp_send_json_success( $result );
    }

    /**
     * Scan for optimizable images.
     *
     * @return void
     */
    public function jemc_scan_optimizable(): void {
        $this->verify_request();

        $optimizer = new Optimizer();
        $results   = $optimizer->find_optimizable();

        $total_potential = 0;
        foreach ( $results as $item ) {
            $total_potential += $item['potential_savings'];
        }

        wp_send_json_success(
            array(
                'items'          => $results,
                'total'          => count( $results ),
                'totalPotential' => size_format( $total_potential ),
                'totalPotentialRaw' => $total_potential,
            )
        );
    }

    /**
     * Optimize selected images.
     *
     * @return void
     */
    public function jemc_optimize_images(): void {
        $this->verify_request();

        $ids = array();
        if ( isset( $_POST['ids'] ) && is_array( $_POST['ids'] ) ) {
            $ids = array_map( 'intval', $_POST['ids'] );
        }

        $max_dim    = isset( $_POST['max_dimension'] ) ? max( 100, (int) $_POST['max_dimension'] ) : 2560;
        $strip_exif = ! isset( $_POST['strip_exif'] ) || ! empty( $_POST['strip_exif'] );

        if ( empty( $ids ) ) {
            wp_send_json_error( array( 'message' => 'No items selected.' ) );
        }

        $optimizer = new Optimizer();
        $result    = $optimizer->batch_optimize( $ids, $max_dim, $strip_exif );

        wp_send_json_success( $result );
    }

    /**
     * Get schedule settings.
     *
     * @return void
     */
    public function jemc_get_schedule(): void {
        $this->verify_request();

        $scheduler = new Scheduler();
        wp_send_json_success( $scheduler->get_settings() );
    }

    /**
     * Save schedule settings.
     *
     * @return void
     */
    public function jemc_save_schedule(): void {
        $this->verify_request();

        $settings = array(
            'enabled'   => ! empty( $_POST['enabled'] ),
            'frequency' => sanitize_text_field( $_POST['frequency'] ?? 'weekly' ),
            'email'     => sanitize_email( $_POST['email'] ?? '' ),
        );

        $scheduler = new Scheduler();
        $scheduler->update_settings( $settings );

        wp_send_json_success( $scheduler->get_settings() );
    }

    /**
     * Map a MIME type to a user-friendly group label.
     *
     * @param string $mime The full MIME type.
     * @return string
     */
    private function get_mime_group( string $mime ): string {
        $map = array(
            'image/jpeg'    => 'JPEG',
            'image/jpg'     => 'JPEG',
            'image/png'     => 'PNG',
            'image/gif'     => 'GIF',
            'image/webp'    => 'WebP',
            'image/svg+xml' => 'SVG',
            'image/avif'    => 'AVIF',
            'image/bmp'     => 'BMP',
            'application/pdf' => 'PDF',
        );

        if ( isset( $map[ $mime ] ) ) {
            return $map[ $mime ];
        }

        if ( 0 === strpos( $mime, 'video/' ) ) {
            return 'Video';
        }

        if ( 0 === strpos( $mime, 'audio/' ) ) {
            return 'Audio';
        }

        if ( 0 === strpos( $mime, 'image/' ) ) {
            return 'Image';
        }

        return 'Other';
    }
}
