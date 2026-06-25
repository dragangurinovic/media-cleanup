<?php
/**
 * Quarantine system for media files.
 *
 * @package MediaCleanup
 */

namespace MediaCleanup;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Moves media files to a quarantine folder instead of deleting,
 * with the ability to restore or permanently delete later.
 */
class Quarantine {

    /**
     * Option key for quarantine metadata.
     */
    const OPTION_KEY = 'jemc_quarantine_items';

    /**
     * Get the quarantine directory path.
     *
     * Creates the directory with security files if it does not exist.
     *
     * @return string Absolute path to the quarantine directory with trailing slash.
     */
    public function get_quarantine_dir(): string {
        $dir = WP_CONTENT_DIR . '/jemc-quarantine/';

        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );

            // Deny direct access via .htaccess (Apache).
            $htaccess = $dir . '.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                file_put_contents( $htaccess, "Order deny,allow\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            }

            // Blank index.php to prevent directory listing.
            $index = $dir . 'index.php';
            if ( ! file_exists( $index ) ) {
                file_put_contents( $index, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
            }
        }

        return $dir;
    }

    /**
     * Move attachment files to quarantine.
     *
     * Copies all associated files (original + thumbnails) to the quarantine
     * directory, stores metadata, and then deletes the attachment from WordPress.
     *
     * @param int[] $ids Attachment IDs to quarantine.
     * @return array {
     *     @type int      $moved  Number of successfully quarantined items.
     *     @type int      $failed Number of items that failed.
     *     @type string[] $errors Error messages.
     * }
     */
    public function quarantine_attachments( array $ids ): array {
        $moved         = 0;
        $failed        = 0;
        $errors        = array();
        $quarantine_dir = $this->get_quarantine_dir();
        $items         = $this->get_items();

        foreach ( $ids as $id ) {
            $id = (int) $id;

            if ( $id <= 0 ) {
                ++$failed;
                $errors[] = sprintf( 'Invalid attachment ID: %d', $id );
                continue;
            }

            $post = get_post( $id );
            if ( ! $post || 'attachment' !== $post->post_type ) {
                ++$failed;
                $errors[] = sprintf( 'ID %d is not a valid attachment.', $id );
                continue;
            }

            $main_file = get_attached_file( $id );
            if ( ! $main_file || ! file_exists( $main_file ) ) {
                ++$failed;
                $errors[] = sprintf( 'File not found for attachment ID %d.', $id );
                continue;
            }

            // Gather all file paths: main file + thumbnails.
            $all_files     = $this->get_attachment_files( $id, $main_file );
            $upload_dir    = wp_upload_dir();
            $base_dir      = trailingslashit( $upload_dir['basedir'] );

            // Generate a unique key for this quarantine entry.
            $quarantine_key = 'q_' . $id . '_' . time() . '_' . wp_rand( 1000, 9999 );

            $original_paths    = array();
            $quarantine_paths  = array();
            $copy_failed       = false;

            foreach ( $all_files as $file_path ) {
                if ( ! file_exists( $file_path ) ) {
                    continue;
                }

                // Compute relative path from uploads base.
                $relative      = str_replace( $base_dir, '', $file_path );
                $dest_path     = $quarantine_dir . $quarantine_key . '/' . $relative;
                $dest_dir      = dirname( $dest_path );

                if ( ! is_dir( $dest_dir ) ) {
                    wp_mkdir_p( $dest_dir );
                }

                if ( copy( $file_path, $dest_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
                    $original_paths[]   = $file_path;
                    $quarantine_paths[] = $dest_path;
                } else {
                    $copy_failed = true;
                    $errors[]    = sprintf( 'Failed to copy file: %s', basename( $file_path ) );
                }
            }

            if ( $copy_failed || empty( $quarantine_paths ) ) {
                // Clean up any partially copied files.
                foreach ( $quarantine_paths as $qp ) {
                    if ( file_exists( $qp ) ) {
                        wp_delete_file( $qp );
                    }
                }
                ++$failed;
                continue;
            }

            // Get file info before deletion.
            $main_size = filesize( $main_file );

            // Store quarantine metadata.
            $items[ $quarantine_key ] = array(
                'id'               => $id,
                'quarantine_key'   => $quarantine_key,
                'filename'         => basename( $main_file ),
                'mime_type'        => get_post_mime_type( $id ),
                'file_size'        => $main_size ? $main_size : 0,
                'file_size_hr'     => $main_size ? size_format( $main_size ) : '0 B',
                'title'            => get_the_title( $id ),
                'original_paths'   => $original_paths,
                'quarantine_paths' => $quarantine_paths,
                'relative_path'    => str_replace( $base_dir, '', $main_file ),
                'date'             => current_time( 'mysql' ),
                'post_data'        => array(
                    'post_title'     => $post->post_title,
                    'post_content'   => $post->post_content,
                    'post_excerpt'   => $post->post_excerpt,
                    'post_mime_type' => $post->post_mime_type,
                    'post_status'    => 'inherit',
                ),
                'attachment_meta'  => wp_get_attachment_metadata( $id ),
                'alt_text'         => get_post_meta( $id, '_wp_attachment_image_alt', true ),
            );

            // Delete the attachment from WordPress (force delete, skip trash).
            wp_delete_attachment( $id, true );

            ++$moved;
        }

        // Save updated quarantine items.
        update_option( self::OPTION_KEY, $items, false );

        return array(
            'moved'  => $moved,
            'failed' => $failed,
            'errors' => $errors,
        );
    }

    /**
     * Restore a quarantined item back to the media library.
     *
     * Copies files back from quarantine to their original upload locations
     * and re-creates the attachment post.
     *
     * @param string $quarantine_key Unique key for the quarantined item.
     * @return array {
     *     @type int      $restored Number of restored items (0 or 1).
     *     @type int      $failed   Number of failures (0 or 1).
     *     @type string[] $errors   Error messages.
     * }
     */
    public function restore_item( string $quarantine_key ): array {
        $items = $this->get_items();

        if ( ! isset( $items[ $quarantine_key ] ) ) {
            return array(
                'restored' => 0,
                'failed'   => 1,
                'errors'   => array( 'Quarantine item not found.' ),
            );
        }

        $item       = $items[ $quarantine_key ];
        $upload_dir = wp_upload_dir();
        $base_dir   = trailingslashit( $upload_dir['basedir'] );
        $errors     = array();

        // Copy files back from quarantine to original locations.
        $restore_failed = false;

        foreach ( $item['quarantine_paths'] as $index => $qpath ) {
            if ( ! file_exists( $qpath ) ) {
                $errors[]       = sprintf( 'Quarantine file missing: %s', basename( $qpath ) );
                $restore_failed = true;
                continue;
            }

            $original_path = isset( $item['original_paths'][ $index ] ) ? $item['original_paths'][ $index ] : '';

            if ( empty( $original_path ) ) {
                $restore_failed = true;
                continue;
            }

            $dest_dir = dirname( $original_path );
            if ( ! is_dir( $dest_dir ) ) {
                wp_mkdir_p( $dest_dir );
            }

            if ( ! copy( $qpath, $original_path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy
                $errors[]       = sprintf( 'Failed to restore file: %s', basename( $original_path ) );
                $restore_failed = true;
            }
        }

        if ( $restore_failed ) {
            return array(
                'restored' => 0,
                'failed'   => 1,
                'errors'   => $errors,
            );
        }

        // Re-create the attachment post.
        $main_file     = isset( $item['original_paths'][0] ) ? $item['original_paths'][0] : '';
        $relative_path = $item['relative_path'];
        $post_data     = $item['post_data'];

        $attachment_id = wp_insert_attachment(
            array(
                'post_title'     => $post_data['post_title'],
                'post_content'   => $post_data['post_content'],
                'post_excerpt'   => $post_data['post_excerpt'],
                'post_mime_type' => $post_data['post_mime_type'],
                'post_status'    => 'inherit',
                'guid'           => $upload_dir['baseurl'] . '/' . $relative_path,
            ),
            $main_file
        );

        if ( is_wp_error( $attachment_id ) || 0 === $attachment_id ) {
            return array(
                'restored' => 0,
                'failed'   => 1,
                'errors'   => array( 'Failed to re-create attachment post.' ),
            );
        }

        // Restore attachment metadata.
        if ( ! empty( $item['attachment_meta'] ) ) {
            wp_update_attachment_metadata( $attachment_id, $item['attachment_meta'] );
        } else {
            // Generate fresh metadata if original is not available.
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $metadata = wp_generate_attachment_metadata( $attachment_id, $main_file );
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }

        // Restore alt text.
        if ( ! empty( $item['alt_text'] ) ) {
            update_post_meta( $attachment_id, '_wp_attachment_image_alt', $item['alt_text'] );
        }

        // Clean up quarantine files and directory.
        $this->delete_quarantine_files( $quarantine_key, $item );

        // Remove from quarantine registry.
        unset( $items[ $quarantine_key ] );
        update_option( self::OPTION_KEY, $items, false );

        return array(
            'restored' => 1,
            'failed'   => 0,
            'errors'   => array(),
        );
    }

    /**
     * Permanently delete quarantined items.
     *
     * @param string[] $keys Quarantine keys to delete.
     * @return array {
     *     @type int      $deleted Number of successfully deleted items.
     *     @type int      $failed  Number of items that failed to delete.
     *     @type string[] $errors  Error messages.
     * }
     */
    public function delete_permanently( array $keys ): array {
        $deleted = 0;
        $failed  = 0;
        $errors  = array();
        $items   = $this->get_items();

        foreach ( $keys as $key ) {
            $key = sanitize_text_field( $key );

            if ( ! isset( $items[ $key ] ) ) {
                ++$failed;
                $errors[] = sprintf( 'Quarantine item not found: %s', $key );
                continue;
            }

            $item = $items[ $key ];

            // Delete quarantine files.
            $this->delete_quarantine_files( $key, $item );

            unset( $items[ $key ] );
            ++$deleted;
        }

        update_option( self::OPTION_KEY, $items, false );

        return array(
            'deleted' => $deleted,
            'failed'  => $failed,
            'errors'  => $errors,
        );
    }

    /**
     * Get all quarantined items.
     *
     * @return array Associative array keyed by quarantine key.
     */
    public function get_items(): array {
        $items = get_option( self::OPTION_KEY, array() );
        return is_array( $items ) ? $items : array();
    }

    /**
     * Get quarantine summary statistics.
     *
     * @return array {
     *     @type int    $total_items   Number of quarantined items.
     *     @type int    $total_size    Total size in bytes.
     *     @type string $total_size_hr Human-readable total size.
     * }
     */
    public function get_summary(): array {
        $items      = $this->get_items();
        $total_size = 0;

        foreach ( $items as $item ) {
            $total_size += isset( $item['file_size'] ) ? (int) $item['file_size'] : 0;
        }

        return array(
            'total_items'   => count( $items ),
            'total_size'    => $total_size,
            'total_size_hr' => size_format( $total_size ),
        );
    }

    /**
     * Clean up quarantine items older than a specified number of days.
     *
     * @param int $days Number of days after which items should be removed.
     * @return array {
     *     @type int      $deleted Number of items cleaned up.
     *     @type int      $failed  Number of items that failed to clean up.
     *     @type string[] $errors  Error messages.
     * }
     */
    public function cleanup_old( int $days = 30 ): array {
        $items   = $this->get_items();
        $deleted = 0;
        $failed  = 0;
        $errors  = array();
        $cutoff  = strtotime( sprintf( '-%d days', $days ) );

        $keys_to_delete = array();

        foreach ( $items as $key => $item ) {
            $item_date = isset( $item['date'] ) ? strtotime( $item['date'] ) : 0;

            if ( $item_date > 0 && $item_date < $cutoff ) {
                $keys_to_delete[] = $key;
            }
        }

        if ( ! empty( $keys_to_delete ) ) {
            $result  = $this->delete_permanently( $keys_to_delete );
            $deleted = $result['deleted'];
            $failed  = $result['failed'];
            $errors  = $result['errors'];
        }

        return array(
            'deleted' => $deleted,
            'failed'  => $failed,
            'errors'  => $errors,
        );
    }

    /**
     * Get all file paths associated with an attachment (original + thumbnails).
     *
     * @param int    $id        Attachment ID.
     * @param string $main_file Path to the main file.
     * @return string[] Array of file paths.
     */
    private function get_attachment_files( int $id, string $main_file ): array {
        $files   = array( $main_file );
        $meta    = wp_get_attachment_metadata( $id );
        $dir     = dirname( $main_file );

        if ( is_array( $meta ) && ! empty( $meta['sizes'] ) ) {
            foreach ( $meta['sizes'] as $size_data ) {
                if ( ! empty( $size_data['file'] ) ) {
                    $thumb_path = $dir . '/' . $size_data['file'];
                    if ( file_exists( $thumb_path ) && ! in_array( $thumb_path, $files, true ) ) {
                        $files[] = $thumb_path;
                    }
                }
            }
        }

        // Check for the original image backup created by WP (for scaled images).
        if ( is_array( $meta ) && ! empty( $meta['original_image'] ) ) {
            $original_path = $dir . '/' . $meta['original_image'];
            if ( file_exists( $original_path ) && ! in_array( $original_path, $files, true ) ) {
                $files[] = $original_path;
            }
        }

        return $files;
    }

    /**
     * Delete quarantine files and the quarantine subdirectory for an item.
     *
     * @param string $key  Quarantine key.
     * @param array  $item Quarantine item data.
     * @return void
     */
    private function delete_quarantine_files( string $key, array $item ): void {
        $quarantine_dir = $this->get_quarantine_dir();

        if ( ! empty( $item['quarantine_paths'] ) ) {
            foreach ( $item['quarantine_paths'] as $qpath ) {
                if ( file_exists( $qpath ) ) {
                    wp_delete_file( $qpath );
                }
            }
        }

        // Remove the quarantine subdirectory.
        $item_dir = $quarantine_dir . $key;
        if ( is_dir( $item_dir ) ) {
            $this->remove_directory_recursive( $item_dir );
        }
    }

    /**
     * Recursively remove a directory and its contents.
     *
     * @param string $dir Directory path.
     * @return void
     */
    private function remove_directory_recursive( string $dir ): void {
        if ( ! is_dir( $dir ) ) {
            return;
        }

        // Safety check: only remove directories within the quarantine folder.
        $quarantine_dir = $this->get_quarantine_dir();
        if ( 0 !== strpos( realpath( $dir ), realpath( $quarantine_dir ) ) ) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ( $iterator as $file ) {
            if ( $file->isDir() ) {
                rmdir( $file->getPathname() ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
            } else {
                wp_delete_file( $file->getPathname() );
            }
        }

        rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
    }
}
