<?php
/**
 * Duplicate media detection by content hash.
 *
 * @package MediaCleanup
 */

namespace MediaCleanup;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Detects duplicate media files by comparing MD5 hashes of file content.
 */
class Duplicates {

    /**
     * Transient key for cached results.
     */
    const TRANSIENT_KEY = 'jemc_duplicates_results';

    /**
     * Maximum file size to hash (500 MB).
     */
    const MAX_FILE_SIZE = 524288000;

    /**
     * Batch size for processing attachments.
     */
    const BATCH_SIZE = 200;

    /**
     * Find duplicate media files.
     *
     * Groups attachments by MD5 hash of their file content.
     * Returns only groups with 2+ files (actual duplicates).
     * Results are cached in a transient for performance.
     *
     * @param bool $force_refresh Whether to bypass the cache.
     * @return array Array of duplicate groups, each with:
     *   'hash'        => string MD5,
     *   'files'       => array of file data arrays,
     *   'count'       => int number of duplicates,
     *   'wasted_size' => int bytes wasted (total minus one copy).
     */
    public function find_duplicates( bool $force_refresh = false ): array {
        if ( ! $force_refresh ) {
            $cached = get_transient( self::TRANSIENT_KEY );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }

        global $wpdb;

        $hash_groups = array();
        $total_count = (int) $wpdb->get_var(
            "SELECT COUNT(ID) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status != 'trash'"
        );

        $offset = 0;

        while ( $offset < $total_count ) {
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_type = 'attachment' AND post_status != 'trash'
                     ORDER BY ID ASC
                     LIMIT %d OFFSET %d",
                    self::BATCH_SIZE,
                    $offset
                )
            );

            if ( empty( $ids ) ) {
                break;
            }

            foreach ( $ids as $id ) {
                $id   = (int) $id;
                $file = get_attached_file( $id );

                if ( ! $file || ! file_exists( $file ) ) {
                    continue;
                }

                $size = filesize( $file );

                // Skip files larger than 500 MB to avoid memory issues.
                if ( false === $size || $size > self::MAX_FILE_SIZE ) {
                    continue;
                }

                // Skip empty files.
                if ( 0 === $size ) {
                    continue;
                }

                $hash = md5_file( $file );

                if ( false === $hash ) {
                    continue;
                }

                if ( ! isset( $hash_groups[ $hash ] ) ) {
                    $hash_groups[ $hash ] = array();
                }

                $hash_groups[ $hash ][] = $id;
            }

            $offset += self::BATCH_SIZE;
        }

        // Filter to only groups with actual duplicates.
        $results = array();

        foreach ( $hash_groups as $hash => $group_ids ) {
            if ( count( $group_ids ) < 2 ) {
                continue;
            }

            $files       = array();
            $total_size  = 0;
            $single_size = 0;

            foreach ( $group_ids as $index => $gid ) {
                $file_path = get_attached_file( $gid );
                $fsize     = 0;

                if ( $file_path && file_exists( $file_path ) ) {
                    $fsize = filesize( $file_path );
                    if ( false === $fsize ) {
                        $fsize = 0;
                    }
                }

                if ( 0 === $index ) {
                    $single_size = $fsize;
                }

                $total_size += $fsize;

                $thumbnail = '';
                if ( wp_attachment_is_image( $gid ) ) {
                    $thumb = wp_get_attachment_image_src( $gid, 'thumbnail' );
                    if ( $thumb ) {
                        $thumbnail = $thumb[0];
                    }
                } else {
                    $thumbnail = wp_mime_type_icon( $gid );
                }

                $post       = get_post( $gid );
                $upload_dir = wp_upload_dir();

                $files[] = array(
                    'id'            => $gid,
                    'filename'      => $file_path ? basename( $file_path ) : '',
                    'file_size'     => $fsize,
                    'file_size_hr'  => $fsize ? size_format( $fsize ) : '0 B',
                    'mime_type'     => get_post_mime_type( $gid ),
                    'upload_date'   => $post ? $post->post_date : '',
                    'relative_path' => $file_path ? str_replace( $upload_dir['basedir'] . '/', '', $file_path ) : '',
                    'thumbnail'     => $thumbnail,
                    'edit_url'      => get_edit_post_link( $gid, 'raw' ),
                );
            }

            $results[] = array(
                'hash'        => $hash,
                'files'       => $files,
                'count'       => count( $group_ids ),
                'wasted_size' => $total_size - $single_size,
            );
        }

        // Sort by wasted size descending so biggest savings appear first.
        usort(
            $results,
            function ( $a, $b ) {
                return $b['wasted_size'] <=> $a['wasted_size'];
            }
        );

        // Cache results for 12 hours.
        set_transient( self::TRANSIENT_KEY, $results, 12 * HOUR_IN_SECONDS );

        return $results;
    }

    /**
     * Get summary statistics for duplicate groups.
     *
     * @param array $groups Result from find_duplicates().
     * @return array {
     *     @type int    $total_groups          Number of duplicate groups.
     *     @type int    $total_duplicate_files  Total number of duplicate files (excluding originals).
     *     @type int    $total_wasted_size      Total wasted bytes.
     *     @type string $total_wasted_size_hr   Human-readable wasted size.
     * }
     */
    public function get_summary( array $groups ): array {
        $total_files  = 0;
        $total_wasted = 0;

        foreach ( $groups as $group ) {
            $total_files  += $group['count'] - 1; // Minus the original.
            $total_wasted += $group['wasted_size'];
        }

        return array(
            'total_groups'         => count( $groups ),
            'total_duplicate_files' => $total_files,
            'total_wasted_size'    => $total_wasted,
            'total_wasted_size_hr' => size_format( $total_wasted ),
        );
    }

    /**
     * Clear the cached duplicates results.
     *
     * @return void
     */
    public function clear_cache(): void {
        delete_transient( self::TRANSIENT_KEY );
    }
}
