<?php
/**
 * WP-CLI commands for Media Cleanup.
 *
 * @package MediaCleanup
 */

namespace MediaCleanup;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    return;
}

/**
 * Manage media library cleanup, duplicates, broken links, optimization, and more.
 *
 * ## EXAMPLES
 *
 *     # Scan for unused media
 *     wp media-cleanup scan
 *
 *     # Delete unused media
 *     wp media-cleanup delete-unused --yes
 *
 *     # Find duplicates
 *     wp media-cleanup duplicates
 *
 *     # Optimize oversized images
 *     wp media-cleanup optimize --max-dimension=1920 --yes
 */
class CLI extends \WP_CLI_Command {

    /**
     * Scan for unused media.
     *
     * Scans post content, meta, options, widgets, and term meta to find
     * media files that are not referenced anywhere on the site.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - count
     * ---
     *
     * ## EXAMPLES
     *
     *     wp media-cleanup scan
     *     wp media-cleanup scan --format=csv
     *     wp media-cleanup scan --format=count
     *
     * @subcommand scan
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function scan( $args, $assoc_args ) {
        $format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

        \WP_CLI::log( 'Scanning for unused media...' );

        $scanner  = new Scanner();
        $all_ids  = $scanner->get_all_attachment_ids();
        $used_ids = $scanner->get_used_attachment_ids();
        $used_set = array_flip( $used_ids );

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

        if ( empty( $unused_ids ) ) {
            \WP_CLI::success( 'No unused media files found. Your library is clean!' );
            return;
        }

        \WP_CLI::log( sprintf( 'Found %d unused media file(s) totaling %s.', count( $unused_ids ), size_format( $total_size ) ) );

        if ( 'count' === $format ) {
            \WP_CLI::log( (string) count( $unused_ids ) );
            return;
        }

        $items = array();
        foreach ( $unused_ids as $id ) {
            $data    = $scanner->build_attachment_data( (int) $id );
            $items[] = array(
                'ID'        => $data['id'],
                'Title'     => $data['title'],
                'Filename'  => $data['filename'],
                'MIME Type' => $data['mime_type'],
                'Size'      => $data['file_size_hr'],
                'Date'      => $data['upload_date'],
                'Path'      => $data['relative_path'],
            );
        }

        \WP_CLI\Utils\format_items( $format, $items, array( 'ID', 'Title', 'Filename', 'MIME Type', 'Size', 'Date', 'Path' ) );
    }

    /**
     * Delete unused media files.
     *
     * Runs a scan to identify unused media, then deletes them.
     *
     * ## OPTIONS
     *
     * [--force]
     * : Skip trash and permanently delete files.
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * ## EXAMPLES
     *
     *     wp media-cleanup delete-unused
     *     wp media-cleanup delete-unused --force --yes
     *
     * @subcommand delete-unused
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function delete_unused( $args, $assoc_args ) {
        $force = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );

        \WP_CLI::log( 'Scanning for unused media...' );

        $scanner  = new Scanner();
        $all_ids  = $scanner->get_all_attachment_ids();
        $used_ids = $scanner->get_used_attachment_ids();
        $used_set = array_flip( $used_ids );

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

        if ( empty( $unused_ids ) ) {
            \WP_CLI::success( 'No unused media files found.' );
            return;
        }

        $action = $force ? 'permanently delete' : 'trash';
        \WP_CLI::log( sprintf( 'Found %d unused media file(s) totaling %s.', count( $unused_ids ), size_format( $total_size ) ) );

        \WP_CLI::confirm(
            sprintf( 'Are you sure you want to %s %d media file(s)?', $action, count( $unused_ids ) ),
            $assoc_args
        );

        $cleaner  = new Cleaner();
        $progress = \WP_CLI\Utils\make_progress_bar( 'Deleting unused media', count( $unused_ids ) );
        $deleted  = 0;
        $failed   = 0;

        // Process in batches to avoid memory issues.
        $batches = array_chunk( $unused_ids, 50 );

        foreach ( $batches as $batch ) {
            $result  = $cleaner->delete_attachments( $batch, $force );
            $deleted += $result['deleted'];
            $failed  += $result['failed'];

            for ( $i = 0; $i < count( $batch ); $i++ ) {
                $progress->tick();
            }
        }

        $progress->finish();

        \WP_CLI::success( sprintf( 'Deleted %d media file(s). Failed: %d.', $deleted, $failed ) );
    }

    /**
     * Find duplicate media files.
     *
     * Groups media files by content hash (MD5) to identify duplicates.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - count
     * ---
     *
     * ## EXAMPLES
     *
     *     wp media-cleanup duplicates
     *     wp media-cleanup duplicates --format=json
     *
     * @subcommand duplicates
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function duplicates( $args, $assoc_args ) {
        $format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

        \WP_CLI::log( 'Scanning for duplicate media files...' );

        $duplicates = new Duplicates();
        $groups     = $duplicates->find_duplicates( true );
        $summary    = $duplicates->get_summary( $groups );

        if ( empty( $groups ) ) {
            \WP_CLI::success( 'No duplicate media files found.' );
            return;
        }

        \WP_CLI::log(
            sprintf(
                'Found %d duplicate group(s) with %d extra file(s) wasting %s.',
                $summary['total_groups'],
                $summary['total_duplicate_files'],
                $summary['total_wasted_size_hr']
            )
        );

        if ( 'count' === $format ) {
            \WP_CLI::log( (string) $summary['total_duplicate_files'] );
            return;
        }

        $items = array();
        foreach ( $groups as $group ) {
            foreach ( $group['files'] as $file ) {
                $items[] = array(
                    'Hash'     => substr( $group['hash'], 0, 12 ) . '...',
                    'ID'       => $file['id'],
                    'Filename' => $file['filename'],
                    'Size'     => $file['file_size_hr'],
                    'MIME'     => $file['mime_type'],
                    'Date'     => $file['upload_date'],
                    'Path'     => $file['relative_path'],
                );
            }
        }

        \WP_CLI\Utils\format_items( $format, $items, array( 'Hash', 'ID', 'Filename', 'Size', 'MIME', 'Date', 'Path' ) );
    }

    /**
     * Find broken media links.
     *
     * Scans post content, meta, and options for references to media files
     * that no longer exist on disk.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - count
     * ---
     *
     * ## EXAMPLES
     *
     *     wp media-cleanup broken-links
     *     wp media-cleanup broken-links --format=csv
     *
     * @subcommand broken-links
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function broken_links( $args, $assoc_args ) {
        $format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

        \WP_CLI::log( 'Scanning for broken media links...' );

        $broken  = new Broken_Links();
        $results = $broken->find_broken_links( true );
        $summary = $broken->get_summary( $results );

        if ( empty( $results ) ) {
            \WP_CLI::success( 'No broken media links found.' );
            return;
        }

        \WP_CLI::log(
            sprintf(
                'Found %d broken URL(s) across %d reference(s) in %d post(s).',
                $summary['total_broken_urls'],
                $summary['total_references'],
                $summary['affected_posts']
            )
        );

        if ( 'count' === $format ) {
            \WP_CLI::log( (string) $summary['total_broken_urls'] );
            return;
        }

        $items = array();
        foreach ( $results as $result ) {
            $items[] = array(
                'URL'    => $result['url'],
                'Source' => $result['source_type'],
                'ID'     => $result['source_id'],
                'Title'  => $result['source_title'],
            );
        }

        \WP_CLI\Utils\format_items( $format, $items, array( 'URL', 'Source', 'ID', 'Title' ) );
    }

    /**
     * Scan for orphan thumbnails.
     *
     * Finds thumbnail image files on disk that are no longer tracked by
     * the media library or whose parent images have been deleted.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - count
     * ---
     *
     * [--delete]
     * : Delete found orphan thumbnails.
     *
     * [--yes]
     * : Skip confirmation when deleting.
     *
     * ## EXAMPLES
     *
     *     wp media-cleanup orphan-thumbnails
     *     wp media-cleanup orphan-thumbnails --delete --yes
     *
     * @subcommand orphan-thumbnails
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function orphan_thumbnails( $args, $assoc_args ) {
        $format     = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
        $do_delete  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'delete', false );

        \WP_CLI::log( 'Scanning for orphan thumbnails...' );

        $scanner = new Scanner();
        $orphans = $scanner->get_orphan_thumbnails();

        if ( empty( $orphans ) ) {
            \WP_CLI::success( 'No orphan thumbnails found.' );
            return;
        }

        $total_size = array_sum( array_column( $orphans, 'size' ) );

        \WP_CLI::log(
            sprintf(
                'Found %d orphan thumbnail(s) totaling %s.',
                count( $orphans ),
                size_format( $total_size )
            )
        );

        if ( 'count' === $format && ! $do_delete ) {
            \WP_CLI::log( (string) count( $orphans ) );
            return;
        }

        if ( ! $do_delete ) {
            $items = array();
            foreach ( $orphans as $orphan ) {
                $items[] = array(
                    'Filename'   => $orphan['filename'],
                    'Size'       => size_format( $orphan['size'] ),
                    'Dimensions' => $orphan['width'] . 'x' . $orphan['height'],
                    'Reason'     => $orphan['reason'],
                    'Path'       => $orphan['relative_path'],
                );
            }

            \WP_CLI\Utils\format_items( $format, $items, array( 'Filename', 'Size', 'Dimensions', 'Reason', 'Path' ) );
            return;
        }

        // Delete orphan thumbnails.
        \WP_CLI::confirm(
            sprintf( 'Are you sure you want to delete %d orphan thumbnail(s)?', count( $orphans ) ),
            $assoc_args
        );

        $cleaner  = new Cleaner();
        $paths    = array_column( $orphans, 'path' );
        $result   = $cleaner->delete_orphan_files( $paths );

        \WP_CLI::success(
            sprintf( 'Deleted %d orphan thumbnail(s). Failed: %d.', $result['deleted'], $result['failed'] )
        );

        if ( ! empty( $result['errors'] ) ) {
            foreach ( $result['errors'] as $error ) {
                \WP_CLI::warning( $error );
            }
        }
    }

    /**
     * Optimize images (resize oversized, strip EXIF).
     *
     * Finds images that exceed the maximum dimension threshold or contain
     * EXIF metadata, and optimizes them.
     *
     * ## OPTIONS
     *
     * [--max-dimension=<pixels>]
     * : Maximum width/height in pixels.
     * ---
     * default: 2560
     * ---
     *
     * [--no-strip-exif]
     * : Do not strip EXIF metadata.
     *
     * [--yes]
     * : Skip confirmation prompt.
     *
     * [--dry-run]
     * : Show what would be optimized without making changes.
     *
     * ## EXAMPLES
     *
     *     wp media-cleanup optimize
     *     wp media-cleanup optimize --max-dimension=1920 --yes
     *     wp media-cleanup optimize --dry-run
     *
     * @subcommand optimize
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function optimize( $args, $assoc_args ) {
        $max_dimension = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'max-dimension', 2560 );
        $strip_exif    = ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'no-strip-exif', false );
        $dry_run       = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

        \WP_CLI::log( sprintf( 'Scanning for optimizable images (max dimension: %dpx)...', $max_dimension ) );

        $optimizer = new Optimizer();
        $items     = $optimizer->find_optimizable( $max_dimension );

        if ( empty( $items ) ) {
            \WP_CLI::success( 'No images need optimization.' );
            return;
        }

        $total_potential = array_sum( array_column( $items, 'potential_savings' ) );

        \WP_CLI::log(
            sprintf(
                'Found %d image(s) that can be optimized. Estimated savings: %s.',
                count( $items ),
                size_format( $total_potential )
            )
        );

        if ( $dry_run ) {
            $display = array();
            foreach ( $items as $item ) {
                $reasons = array();
                if ( $item['is_oversized'] ) {
                    $reasons[] = 'oversized';
                }
                if ( $item['has_exif'] ) {
                    $reasons[] = 'has EXIF';
                }

                $display[] = array(
                    'ID'         => $item['id'],
                    'Filename'   => $item['filename'],
                    'Size'       => $item['current_size_hr'],
                    'Dimensions' => $item['current_dimensions'],
                    'Est. Save'  => $item['potential_savings_hr'],
                    'Reason'     => implode( ', ', $reasons ),
                );
            }

            \WP_CLI\Utils\format_items( 'table', $display, array( 'ID', 'Filename', 'Size', 'Dimensions', 'Est. Save', 'Reason' ) );
            return;
        }

        \WP_CLI::confirm(
            sprintf( 'Are you sure you want to optimize %d image(s)?', count( $items ) ),
            $assoc_args
        );

        $ids      = array_column( $items, 'id' );
        $progress = \WP_CLI\Utils\make_progress_bar( 'Optimizing images', count( $ids ) );
        $total_saved = 0;
        $optimized   = 0;
        $failed      = 0;
        $errors      = array();

        foreach ( $ids as $id ) {
            $result = $optimizer->optimize_image( (int) $id, $max_dimension, $strip_exif );

            if ( $result['success'] ) {
                ++$optimized;
                $total_saved += $result['saved'];
            } else {
                ++$failed;
                if ( ! empty( $result['error'] ) ) {
                    $errors[] = sprintf( 'ID %d: %s', $id, $result['error'] );
                }
            }

            $progress->tick();
        }

        $progress->finish();

        \WP_CLI::success(
            sprintf(
                'Optimized %d image(s), saved %s. Failed: %d.',
                $optimized,
                size_format( $total_saved ),
                $failed
            )
        );

        if ( ! empty( $errors ) ) {
            foreach ( $errors as $error ) {
                \WP_CLI::warning( $error );
            }
        }
    }

    /**
     * Show quarantine status.
     *
     * Lists items currently in the quarantine folder with restore/delete options.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format.
     * ---
     * default: table
     * options:
     *   - table
     *   - csv
     *   - json
     *   - count
     * ---
     *
     * [--cleanup=<days>]
     * : Delete quarantine items older than specified days.
     *
     * ## EXAMPLES
     *
     *     wp media-cleanup quarantine
     *     wp media-cleanup quarantine --cleanup=30
     *
     * @subcommand quarantine
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function quarantine_status( $args, $assoc_args ) {
        $format  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
        $cleanup = \WP_CLI\Utils\get_flag_value( $assoc_args, 'cleanup', false );

        $quarantine = new Quarantine();

        if ( false !== $cleanup ) {
            $days   = (int) $cleanup;
            $result = $quarantine->cleanup_old( $days );
            \WP_CLI::success(
                sprintf( 'Cleaned up %d quarantine item(s) older than %d day(s). Failed: %d.', $result['deleted'], $days, $result['failed'] )
            );
            return;
        }

        $items   = $quarantine->get_items();
        $summary = $quarantine->get_summary();

        if ( empty( $items ) ) {
            \WP_CLI::success( 'Quarantine is empty.' );
            return;
        }

        \WP_CLI::log(
            sprintf(
                'Quarantine contains %d item(s) totaling %s.',
                $summary['total_items'],
                $summary['total_size_hr']
            )
        );

        if ( 'count' === $format ) {
            \WP_CLI::log( (string) $summary['total_items'] );
            return;
        }

        $display = array();
        foreach ( $items as $item ) {
            $display[] = array(
                'Key'      => $item['quarantine_key'],
                'Filename' => $item['filename'],
                'Size'     => $item['file_size_hr'],
                'MIME'     => $item['mime_type'],
                'Date'     => $item['date'],
                'Title'    => $item['title'],
            );
        }

        \WP_CLI\Utils\format_items( $format, $display, array( 'Key', 'Filename', 'Size', 'MIME', 'Date', 'Title' ) );
    }

    /**
     * Manage scheduled scans.
     *
     * Enable, disable, or check the status of scheduled media scans
     * with email reports.
     *
     * ## OPTIONS
     *
     * <action>
     * : Action to perform: enable, disable, or status.
     *
     * [--frequency=<freq>]
     * : Scan frequency when enabling.
     * ---
     * default: weekly
     * options:
     *   - daily
     *   - weekly
     *   - monthly
     * ---
     *
     * [--email=<email>]
     * : Email address for scan reports.
     *
     * ## EXAMPLES
     *
     *     wp media-cleanup schedule status
     *     wp media-cleanup schedule enable --frequency=weekly --email=admin@example.com
     *     wp media-cleanup schedule disable
     *
     * @subcommand schedule
     *
     * @param array $args       Positional arguments.
     * @param array $assoc_args Associative arguments.
     * @return void
     */
    public function schedule( $args, $assoc_args ) {
        if ( empty( $args[0] ) ) {
            \WP_CLI::error( 'Please specify an action: enable, disable, or status.' );
        }

        $action    = $args[0];
        $scheduler = new Scheduler();

        switch ( $action ) {
            case 'status':
                $settings = $scheduler->get_settings();
                $next     = $scheduler->get_next_scheduled();

                \WP_CLI::log( sprintf( 'Enabled:    %s', $settings['enabled'] ? 'Yes' : 'No' ) );
                \WP_CLI::log( sprintf( 'Frequency:  %s', $settings['frequency'] ) );
                \WP_CLI::log( sprintf( 'Email:      %s', $settings['email'] ) );
                \WP_CLI::log( sprintf( 'Last Run:   %s', $settings['last_run'] ? $settings['last_run'] : 'Never' ) );

                if ( $next ) {
                    \WP_CLI::log( sprintf( 'Next Run:   %s', gmdate( 'Y-m-d H:i:s', $next ) ) );
                } else {
                    \WP_CLI::log( 'Next Run:   Not scheduled' );
                }

                if ( ! empty( $settings['last_result'] ) ) {
                    $result = $settings['last_result'];
                    \WP_CLI::log( '' );
                    \WP_CLI::log( 'Last Result:' );
                    \WP_CLI::log( sprintf( '  Unused:     %d (%s)', $result['unused_count'] ?? 0, $result['unused_size_hr'] ?? '0 B' ) );
                    \WP_CLI::log( sprintf( '  Duplicates: %d groups (%s wasted)', $result['duplicate_groups'] ?? 0, $result['duplicate_wasted_hr'] ?? '0 B' ) );
                    \WP_CLI::log( sprintf( '  Broken:     %d links', $result['broken_links_count'] ?? 0 ) );
                }
                break;

            case 'enable':
                $update = array( 'enabled' => true );

                $frequency = \WP_CLI\Utils\get_flag_value( $assoc_args, 'frequency', false );
                if ( $frequency ) {
                    $update['frequency'] = $frequency;
                }

                $email = \WP_CLI\Utils\get_flag_value( $assoc_args, 'email', false );
                if ( $email ) {
                    if ( ! is_email( $email ) ) {
                        \WP_CLI::error( 'Invalid email address.' );
                    }
                    $update['email'] = $email;
                }

                $scheduler->update_settings( $update );

                $settings = $scheduler->get_settings();
                \WP_CLI::success(
                    sprintf(
                        'Scheduled scans enabled (%s). Reports will be sent to %s.',
                        $settings['frequency'],
                        $settings['email']
                    )
                );
                break;

            case 'disable':
                $scheduler->update_settings( array( 'enabled' => false ) );
                \WP_CLI::success( 'Scheduled scans disabled.' );
                break;

            default:
                \WP_CLI::error( 'Invalid action. Use: enable, disable, or status.' );
        }
    }
}

\WP_CLI::add_command( 'media-cleanup', __NAMESPACE__ . '\\CLI' );
