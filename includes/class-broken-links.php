<?php
/**
 * Broken media link detection.
 *
 * @package MediaCleanup
 */

namespace MediaCleanup;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Finds references to media files that no longer exist on disk or in the media library.
 */
class Broken_Links {

    /**
     * Transient key for cached results.
     */
    const TRANSIENT_KEY = 'jemc_broken_links_results';

    /**
     * Batch size for database queries.
     */
    const BATCH_SIZE = 500;

    /**
     * Scan all post content, meta, and options for media URLs that point to
     * files that no longer exist on disk or in the media library.
     *
     * @param bool $force_refresh Whether to bypass the cache.
     * @return array Each entry: [
     *   'url'             => string the broken URL,
     *   'source_type'     => 'post_content' | 'post_meta' | 'option',
     *   'source_id'       => int post ID or 0,
     *   'source_title'    => string post title or option name,
     *   'source_edit_url' => string URL to edit the source,
     * ]
     */
    public function find_broken_links( bool $force_refresh = false ): array {
        if ( ! $force_refresh ) {
            $cached = get_transient( self::TRANSIENT_KEY );
            if ( false !== $cached && is_array( $cached ) ) {
                return $cached;
            }
        }

        $upload_dir  = wp_upload_dir();
        $uploads_url = $upload_dir['baseurl'];
        $uploads_dir = $upload_dir['basedir'];

        $results = array();

        // Scan post content.
        $results = array_merge( $results, $this->scan_post_content( $uploads_url, $uploads_dir ) );

        // Scan post meta.
        $results = array_merge( $results, $this->scan_post_meta( $uploads_url, $uploads_dir ) );

        // Scan options.
        $results = array_merge( $results, $this->scan_options( $uploads_url, $uploads_dir ) );

        // Deduplicate: group by URL but keep all source references.
        $results = $this->deduplicate_results( $results );

        // Cache results for 12 hours.
        set_transient( self::TRANSIENT_KEY, $results, 12 * HOUR_IN_SECONDS );

        return $results;
    }

    /**
     * Scan post content for broken media URLs.
     *
     * @param string $uploads_url Base URL for uploads.
     * @param string $uploads_dir Base directory for uploads.
     * @return array
     */
    private function scan_post_content( string $uploads_url, string $uploads_dir ): array {
        global $wpdb;

        $results     = array();
        $escaped_url = $wpdb->esc_like( $uploads_url );
        $offset      = 0;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_title, post_content FROM {$wpdb->posts}
                     WHERE post_type != 'attachment'
                       AND post_type != 'revision'
                       AND post_content LIKE %s
                     LIMIT %d OFFSET %d",
                    '%' . $escaped_url . '%',
                    self::BATCH_SIZE,
                    $offset
                )
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $urls = $this->extract_upload_urls( $row->post_content, $uploads_url );

                foreach ( $urls as $url ) {
                    if ( $this->is_broken_url( $url, $uploads_url, $uploads_dir ) ) {
                        $results[] = array(
                            'url'             => $url,
                            'source_type'     => 'post_content',
                            'source_id'       => (int) $row->ID,
                            'source_title'    => $row->post_title,
                            'source_edit_url' => get_edit_post_link( (int) $row->ID, 'raw' ),
                        );
                    }
                }
            }

            $offset += self::BATCH_SIZE;
        } while ( count( $rows ) === self::BATCH_SIZE );

        return $results;
    }

    /**
     * Scan post meta for broken media URLs.
     *
     * @param string $uploads_url Base URL for uploads.
     * @param string $uploads_dir Base directory for uploads.
     * @return array
     */
    private function scan_post_meta( string $uploads_url, string $uploads_dir ): array {
        global $wpdb;

        $results     = array();
        $escaped_url = $wpdb->esc_like( $uploads_url );
        $offset      = 0;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT pm.post_id, pm.meta_value, p.post_title
                     FROM {$wpdb->postmeta} pm
                     LEFT JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_value LIKE %s
                       AND pm.meta_key NOT LIKE '%%_transient%%'
                     LIMIT %d OFFSET %d",
                    '%' . $escaped_url . '%',
                    self::BATCH_SIZE,
                    $offset
                )
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $value = $row->meta_value;

                // Handle serialized data.
                $unserialized = maybe_unserialize( $value );
                if ( is_array( $unserialized ) || is_object( $unserialized ) ) {
                    $value = maybe_serialize( $unserialized );
                }

                $urls = $this->extract_upload_urls( $value, $uploads_url );

                foreach ( $urls as $url ) {
                    if ( $this->is_broken_url( $url, $uploads_url, $uploads_dir ) ) {
                        $post_id = (int) $row->post_id;
                        $results[] = array(
                            'url'             => $url,
                            'source_type'     => 'post_meta',
                            'source_id'       => $post_id,
                            'source_title'    => $row->post_title ? $row->post_title : sprintf( 'Post #%d', $post_id ),
                            'source_edit_url' => get_edit_post_link( $post_id, 'raw' ),
                        );
                    }
                }
            }

            $offset += self::BATCH_SIZE;
        } while ( count( $rows ) === self::BATCH_SIZE );

        return $results;
    }

    /**
     * Scan options for broken media URLs.
     *
     * @param string $uploads_url Base URL for uploads.
     * @param string $uploads_dir Base directory for uploads.
     * @return array
     */
    private function scan_options( string $uploads_url, string $uploads_dir ): array {
        global $wpdb;

        $results     = array();
        $escaped_url = $wpdb->esc_like( $uploads_url );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options}
                 WHERE option_value LIKE %s
                   AND option_name NOT LIKE '%%transient%%'
                   AND option_name NOT LIKE '%%_session_%%'
                 LIMIT 1000",
                '%' . $escaped_url . '%'
            )
        );

        foreach ( $rows as $row ) {
            $value = $row->option_value;

            // Handle serialized data.
            $unserialized = maybe_unserialize( $value );
            if ( is_array( $unserialized ) || is_object( $unserialized ) ) {
                $value = maybe_serialize( $unserialized );
            }

            $urls = $this->extract_upload_urls( $value, $uploads_url );

            foreach ( $urls as $url ) {
                if ( $this->is_broken_url( $url, $uploads_url, $uploads_dir ) ) {
                    $results[] = array(
                        'url'             => $url,
                        'source_type'     => 'option',
                        'source_id'       => 0,
                        'source_title'    => $row->option_name,
                        'source_edit_url' => admin_url( 'options.php' ),
                    );
                }
            }
        }

        return $results;
    }

    /**
     * Extract upload URLs from a content string.
     *
     * @param string $content     The content to scan.
     * @param string $uploads_url The uploads base URL.
     * @return string[] Array of unique URLs found.
     */
    private function extract_upload_urls( string $content, string $uploads_url ): array {
        $urls = array();

        if ( empty( $content ) || false === strpos( $content, $uploads_url ) ) {
            return $urls;
        }

        $escaped_url = preg_quote( $uploads_url, '/' );

        if ( preg_match_all( '/' . $escaped_url . '\/([^\s"\'<>\)\]\,]+)/i', $content, $matches ) ) {
            foreach ( $matches[0] as $url ) {
                // Clean trailing punctuation that might have been captured.
                $url = rtrim( $url, '.,;:!?)]\'"' );

                // Skip query strings and fragments for cleanliness.
                $url = strtok( $url, '?' );
                $url = strtok( $url, '#' );

                if ( ! empty( $url ) ) {
                    $urls[] = $url;
                }
            }
        }

        return array_unique( $urls );
    }

    /**
     * Check if a media URL points to a file that no longer exists.
     *
     * @param string $url         The full URL to check.
     * @param string $uploads_url The uploads base URL.
     * @param string $uploads_dir The uploads base directory.
     * @return bool True if the link is broken.
     */
    private function is_broken_url( string $url, string $uploads_url, string $uploads_dir ): bool {
        // Convert URL to local file path.
        $relative_path = str_replace( $uploads_url . '/', '', $url );

        // Sanitize the path to prevent directory traversal.
        $relative_path = ltrim( $relative_path, '/' );
        if ( false !== strpos( $relative_path, '..' ) ) {
            return false; // Skip suspicious paths.
        }

        $file_path = $uploads_dir . '/' . $relative_path;

        // Check if the file exists on disk.
        if ( file_exists( $file_path ) ) {
            return false;
        }

        // Also check if this might be a sized variant (e.g., image-300x200.jpg).
        // Try the original file (strip size suffix).
        $original_path = preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', $file_path );
        if ( $original_path !== $file_path && file_exists( $original_path ) ) {
            return false;
        }

        return true;
    }

    /**
     * Deduplicate results, keeping unique URL + source combinations.
     *
     * @param array $results Raw results array.
     * @return array Deduplicated results.
     */
    private function deduplicate_results( array $results ): array {
        $seen       = array();
        $deduped    = array();

        foreach ( $results as $result ) {
            $key = $result['url'] . '|' . $result['source_type'] . '|' . $result['source_id'];

            if ( isset( $seen[ $key ] ) ) {
                continue;
            }

            $seen[ $key ] = true;
            $deduped[]    = $result;
        }

        return $deduped;
    }

    /**
     * Get summary statistics for broken links results.
     *
     * @param array $results Result from find_broken_links().
     * @return array {
     *     @type int $total_broken_urls  Number of unique broken URLs.
     *     @type int $total_references   Total number of references to broken URLs.
     *     @type int $affected_posts     Number of unique posts affected.
     * }
     */
    public function get_summary( array $results ): array {
        $unique_urls   = array();
        $affected_post_ids = array();

        foreach ( $results as $result ) {
            $unique_urls[ $result['url'] ] = true;

            if ( $result['source_id'] > 0 ) {
                $affected_post_ids[ $result['source_id'] ] = true;
            }
        }

        return array(
            'total_broken_urls' => count( $unique_urls ),
            'total_references'  => count( $results ),
            'affected_posts'    => count( $affected_post_ids ),
        );
    }

    /**
     * Clear the cached broken links results.
     *
     * @return void
     */
    public function clear_cache(): void {
        delete_transient( self::TRANSIENT_KEY );
    }
}
