<?php
/**
 * Deletion / trash logic.
 *
 * @package MediaCleanup
 */

namespace MediaCleanup;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles media deletion and activity logging.
 */
class Cleaner {

    /**
     * Maximum number of log entries to retain.
     */
    private const LOG_MAX = 100;

    /**
     * Trash or permanently delete a list of attachment IDs.
     *
     * @param int[] $ids          Attachment IDs to delete.
     * @param bool  $force_delete True to permanently delete, false to trash.
     * @return array{deleted: int, failed: int, errors: string[]}
     */
    public function delete_attachments( array $ids, bool $force_delete = false ): array {
        $deleted = 0;
        $failed  = 0;
        $errors  = array();

        foreach ( $ids as $id ) {
            $id = (int) $id;

            if ( $id <= 0 ) {
                ++$failed;
                continue;
            }

            $post = get_post( $id );
            if ( ! $post || 'attachment' !== $post->post_type ) {
                ++$failed;
                $errors[] = sprintf( 'ID %d is not a valid attachment.', $id );
                continue;
            }

            if ( $force_delete ) {
                $result = wp_delete_attachment( $id, true );
            } else {
                // Move to trash: set post_status to 'trash'.
                $result = wp_trash_post( $id );
            }

            if ( $result ) {
                ++$deleted;
            } else {
                ++$failed;
                $errors[] = sprintf( 'Failed to delete attachment ID %d.', $id );
            }
        }

        $action = $force_delete ? 'permanently deleted' : 'trashed';
        $this->log_activity(
            sprintf( '%d media item(s) %s.', $deleted, $action ),
            $ids
        );

        return array(
            'deleted' => $deleted,
            'failed'  => $failed,
            'errors'  => $errors,
        );
    }

    /**
     * Delete orphan thumbnail files from disk.
     *
     * @param string[] $file_paths Absolute file paths to delete.
     * @return array{deleted: int, failed: int, errors: string[]}
     */
    public function delete_orphan_files( array $file_paths ): array {
        $deleted = 0;
        $failed  = 0;
        $errors  = array();

        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( $upload_dir['basedir'] );

        foreach ( $file_paths as $path ) {
            // Security: ensure the file is within the uploads directory.
            $real_path = realpath( $path );
            $real_base = realpath( $base_dir );

            if ( false === $real_path || false === $real_base ) {
                ++$failed;
                $errors[] = sprintf( 'Invalid path: %s', $path );
                continue;
            }

            if ( 0 !== strpos( $real_path, $real_base ) ) {
                ++$failed;
                $errors[] = sprintf( 'Path outside uploads directory: %s', $path );
                continue;
            }

            if ( ! file_exists( $real_path ) ) {
                ++$failed;
                $errors[] = sprintf( 'File not found: %s', $path );
                continue;
            }

            if ( wp_delete_file_from_directory( $real_path, $base_dir ) ) {
                ++$deleted;
            } else {
                // Fallback: try direct unlink.
                if ( @unlink( $real_path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                    ++$deleted;
                } else {
                    ++$failed;
                    $errors[] = sprintf( 'Could not delete: %s', $path );
                }
            }
        }

        $this->log_activity(
            sprintf( '%d orphan thumbnail file(s) deleted from disk.', $deleted ),
            $file_paths
        );

        return array(
            'deleted' => $deleted,
            'failed'  => $failed,
            'errors'  => $errors,
        );
    }

    /**
     * Log an activity entry.
     *
     * @param string $message   Human-readable message.
     * @param array  $details   Related IDs or paths.
     * @return void
     */
    private function log_activity( string $message, array $details = array() ): void {
        $log = get_option( 'jemc_activity_log', array() );

        if ( ! is_array( $log ) ) {
            $log = array();
        }

        array_unshift(
            $log,
            array(
                'time'    => current_time( 'mysql' ),
                'user'    => get_current_user_id(),
                'message' => $message,
                'count'   => count( $details ),
            )
        );

        // Keep only the last 100 entries.
        $log = array_slice( $log, 0, self::LOG_MAX );

        update_option( 'jemc_activity_log', $log, false );
    }

    /**
     * Get the activity log.
     *
     * @return array
     */
    public function get_activity_log(): array {
        $log = get_option( 'jemc_activity_log', array() );
        return is_array( $log ) ? $log : array();
    }
}
