<?php
/**
 * Media scanning logic.
 *
 * @package MediaCleanup
 */

namespace MediaCleanup;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Scans the database and filesystem for used / unused media.
 */
class Scanner {

    /**
     * Cache for attachment_url_to_postid look-ups.
     *
     * @var array<string, int>
     */
    private array $url_id_cache = array();

    /**
     * Get every attachment ID in the media library.
     *
     * @return int[]
     */
    public function get_all_attachment_ids(): array {
        global $wpdb;

        $ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_status != 'trash'"
        );

        return array_map( 'intval', $ids );
    }

    /**
     * Aggregate all used attachment IDs from every source.
     *
     * @return int[]
     */
    public function get_used_attachment_ids(): array {
        $used = array_merge(
            $this->scan_post_content(),
            $this->scan_post_meta(),
            $this->scan_options(),
            $this->scan_widgets(),
            $this->scan_term_meta(),
            $this->scan_theme_css(),
            $this->scan_page_builders()
        );

        return array_unique( array_filter( array_map( 'intval', $used ) ) );
    }

    /**
     * Return unused attachments with meta, paginated.
     *
     * @param int $page     Current page (1-based).
     * @param int $per_page Items per page.
     * @return array{items: array, total: int, total_size: int}
     */
    public function get_unused_attachments( int $page = 1, int $per_page = 50 ): array {
        $cached = get_transient( 'jemc_scan_results' );

        if ( false === $cached || ! is_array( $cached ) ) {
            return array(
                'items'      => array(),
                'total'      => 0,
                'total_size' => 0,
            );
        }

        $unused_ids = $cached['unused_ids'] ?? array();
        $total      = count( $unused_ids );
        $offset     = ( $page - 1 ) * $per_page;
        $slice      = array_slice( $unused_ids, $offset, $per_page );

        $items      = array();
        $total_size = $cached['total_size'] ?? 0;

        foreach ( $slice as $id ) {
            $items[] = $this->build_attachment_data( (int) $id );
        }

        return array(
            'items'      => $items,
            'total'      => $total,
            'total_size' => $total_size,
        );
    }

    /**
     * Scan post_content across all post types for attachment references.
     *
     * @return int[]
     */
    public function scan_post_content(): array {
        global $wpdb;

        $uploads_url = $this->get_uploads_base_url();
        $used_ids    = array();

        $batch_size = 500;
        $offset     = 0;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT ID, post_content FROM {$wpdb->posts}
                     WHERE post_type != 'attachment'
                       AND post_type != 'revision'
                       AND post_content != ''
                     LIMIT %d OFFSET %d",
                    $batch_size,
                    $offset
                )
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $found = $this->extract_attachment_ids_from_content( $row->post_content, $uploads_url );
                if ( ! empty( $found ) ) {
                    array_push( $used_ids, ...$found );
                }
            }

            $offset += $batch_size;
        } while ( count( $rows ) === $batch_size );

        return $used_ids;
    }

    /**
     * Scan post meta for attachment references.
     *
     * @return int[]
     */
    public function scan_post_meta(): array {
        global $wpdb;

        $used_ids    = array();
        $uploads_url = $this->get_uploads_base_url();

        // 1. Featured images (_thumbnail_id).
        $thumb_ids = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_thumbnail_id' AND meta_value != '' AND meta_value != '0'"
        );
        $used_ids = array_merge( $used_ids, array_map( 'intval', $thumb_ids ) );

        // 2. WooCommerce product gallery.
        $galleries = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_product_image_gallery' AND meta_value != ''"
        );
        foreach ( $galleries as $gallery ) {
            $ids = array_filter( array_map( 'intval', explode( ',', $gallery ) ) );
            array_push( $used_ids, ...$ids );
        }

        // 3. All other meta values that look like they contain attachment references.
        $batch_size = 1000;
        $offset     = 0;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->postmeta}
                     WHERE meta_key NOT IN ('_thumbnail_id', '_product_image_gallery')
                       AND meta_value != ''
                       AND (
                           meta_value REGEXP '^[0-9]+$'
                           OR meta_value LIKE %s
                           OR meta_value LIKE '%%wp-image-%%'
                           OR meta_value LIKE '%%attachment_id%%'
                       )
                     LIMIT %d OFFSET %d",
                    '%' . $wpdb->esc_like( $uploads_url ) . '%',
                    $batch_size,
                    $offset
                )
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $value = $row->meta_value;

                // Pure numeric – could be an attachment ID.
                if ( is_numeric( $value ) && (int) $value > 0 ) {
                    $used_ids[] = (int) $value;
                    continue;
                }

                // Serialized or string content.
                $found = $this->extract_attachment_ids_from_content( $value, $uploads_url );
                if ( ! empty( $found ) ) {
                    array_push( $used_ids, ...$found );
                }
            }

            $offset += $batch_size;
        } while ( count( $rows ) === $batch_size );

        return $used_ids;
    }

    /**
     * Scan the options table for attachment references.
     *
     * @return int[]
     */
    public function scan_options(): array {
        global $wpdb;

        $used_ids    = array();
        $uploads_url = $this->get_uploads_base_url();

        // Site icon.
        $site_icon = (int) get_option( 'site_icon', 0 );
        if ( $site_icon > 0 ) {
            $used_ids[] = $site_icon;
        }

        // Custom logo (theme mod).
        $custom_logo = (int) get_theme_mod( 'custom_logo', 0 );
        if ( $custom_logo > 0 ) {
            $used_ids[] = $custom_logo;
        }

        // Header / background images.
        $header_image = get_theme_mod( 'header_image', '' );
        if ( ! empty( $header_image ) && 'remove-header' !== $header_image ) {
            $id = $this->url_to_attachment_id( $header_image );
            if ( $id ) {
                $used_ids[] = $id;
            }
        }

        $bg_image = get_theme_mod( 'background_image', '' );
        if ( ! empty( $bg_image ) ) {
            $id = $this->url_to_attachment_id( $bg_image );
            if ( $id ) {
                $used_ids[] = $id;
            }
        }

        // Scan all theme_mods for the active theme.
        $theme_mods = get_option( 'theme_mods_' . get_stylesheet(), array() );
        if ( is_array( $theme_mods ) ) {
            $serialized = maybe_serialize( $theme_mods );
            $found      = $this->extract_attachment_ids_from_content( $serialized, $uploads_url );
            if ( ! empty( $found ) ) {
                array_push( $used_ids, ...$found );
            }
        }

        // Scan options whose values contain uploads URL.
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options}
                 WHERE option_value LIKE %s
                   AND option_name NOT LIKE '%%transient%%'
                 LIMIT 500",
                '%' . $wpdb->esc_like( $uploads_url ) . '%'
            )
        );

        foreach ( $rows as $row ) {
            $found = $this->extract_attachment_ids_from_content( $row->option_value, $uploads_url );
            if ( ! empty( $found ) ) {
                array_push( $used_ids, ...$found );
            }
        }

        return $used_ids;
    }

    /**
     * Scan widget data for attachment references.
     *
     * @return int[]
     */
    public function scan_widgets(): array {
        global $wpdb;

        $used_ids    = array();
        $uploads_url = $this->get_uploads_base_url();

        $rows = $wpdb->get_results(
            "SELECT option_value FROM {$wpdb->options}
             WHERE option_name LIKE 'widget_%'"
        );

        foreach ( $rows as $row ) {
            if ( empty( $row->option_value ) ) {
                continue;
            }

            $found = $this->extract_attachment_ids_from_content( $row->option_value, $uploads_url );
            if ( ! empty( $found ) ) {
                array_push( $used_ids, ...$found );
            }

            // Also check for numeric 'attachment_id' or 'image_id' keys in serialized widget data.
            $data = maybe_unserialize( $row->option_value );
            if ( is_array( $data ) ) {
                array_walk_recursive(
                    $data,
                    function ( $value, $key ) use ( &$used_ids ) {
                        if ( is_numeric( $value ) && (int) $value > 0 ) {
                            $key_lower = strtolower( (string) $key );
                            if (
                                false !== strpos( $key_lower, 'attachment' ) ||
                                false !== strpos( $key_lower, 'image' ) ||
                                false !== strpos( $key_lower, 'media' ) ||
                                '_thumbnail_id' === $key_lower ||
                                'id' === $key_lower
                            ) {
                                $used_ids[] = (int) $value;
                            }
                        }
                    }
                );
            }
        }

        return $used_ids;
    }

    /**
     * Scan term meta for attachment references.
     *
     * @return int[]
     */
    public function scan_term_meta(): array {
        global $wpdb;

        $used_ids    = array();
        $uploads_url = $this->get_uploads_base_url();

        $rows = $wpdb->get_results(
            "SELECT meta_value FROM {$wpdb->termmeta} WHERE meta_value != ''"
        );

        foreach ( $rows as $row ) {
            $value = $row->meta_value;

            if ( is_numeric( $value ) && (int) $value > 0 ) {
                $used_ids[] = (int) $value;
                continue;
            }

            if ( false !== strpos( $value, $uploads_url ) || false !== strpos( $value, 'wp-image-' ) ) {
                $found = $this->extract_attachment_ids_from_content( $value, $uploads_url );
                if ( ! empty( $found ) ) {
                    array_push( $used_ids, ...$found );
                }
            }
        }

        return $used_ids;
    }

    /**
     * Scan for orphan thumbnail files on disk.
     *
     * @return array
     */
    public function get_orphan_thumbnails(): array {
        $upload_dir  = wp_upload_dir();
        $base_dir    = $upload_dir['basedir'];
        $orphans     = array();

        if ( ! is_dir( $base_dir ) ) {
            return $orphans;
        }

        // Get registered image sizes.
        $registered = $this->get_registered_size_dimensions();

        // Build a set of known thumbnail files from the media library.
        $known_thumbs = $this->get_known_thumbnail_files();

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( $base_dir, \FilesystemIterator::SKIP_DOTS ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $image_extensions = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'avif' );

        foreach ( $iterator as $file ) {
            if ( ! $file->isFile() ) {
                continue;
            }

            $filename  = $file->getFilename();
            $filepath  = $file->getPathname();
            $extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

            if ( ! in_array( $extension, $image_extensions, true ) ) {
                continue;
            }

            // Check if it matches a thumbnail pattern: name-WIDTHxHEIGHT.ext
            if ( ! preg_match( '/^(.+)-(\d+)x(\d+)\.(jpe?g|png|gif|webp|bmp|avif)$/i', $filename, $matches ) ) {
                continue;
            }

            $base_name = $matches[1];
            $width     = (int) $matches[2];
            $height    = (int) $matches[3];
            $ext       = $matches[4];

            $relative_path = str_replace( $base_dir . '/', '', $filepath );

            // Check if this thumbnail's parent/original exists.
            $dir           = dirname( $filepath );
            $original_glob = glob( $dir . '/' . $base_name . '.{jpg,jpeg,png,gif,webp,bmp,avif}', GLOB_BRACE );
            $parent_exists = ! empty( $original_glob );

            // Check if the size matches a registered image size.
            $size_registered = false;
            foreach ( $registered as $dimensions ) {
                if ( $dimensions['width'] === $width && $dimensions['height'] === $height ) {
                    $size_registered = true;
                    break;
                }
                // WP may crop proportionally, so also accept if width OR height matches with 0.
                if (
                    ( $dimensions['width'] === $width && 0 === $dimensions['height'] ) ||
                    ( 0 === $dimensions['width'] && $dimensions['height'] === $height )
                ) {
                    $size_registered = true;
                    break;
                }
            }

            // Check if this thumbnail is tracked in the media library metadata.
            $in_library = isset( $known_thumbs[ $relative_path ] );

            $is_orphan = false;
            $reason    = '';

            if ( ! $parent_exists ) {
                $is_orphan = true;
                $reason    = 'Parent image missing';
            } elseif ( ! $size_registered && ! $in_library ) {
                $is_orphan = true;
                $reason    = 'Unregistered size';
            }

            if ( $is_orphan ) {
                $file_size = filesize( $filepath );
                $orphans[] = array(
                    'path'          => $filepath,
                    'relative_path' => $relative_path,
                    'filename'      => $filename,
                    'width'         => $width,
                    'height'        => $height,
                    'size'          => $file_size ? $file_size : 0,
                    'reason'        => $reason,
                );
            }
        }

        return $orphans;
    }

    /**
     * Scan theme and child theme CSS files for background-image references.
     *
     * @return int[]
     */
    public function scan_theme_css(): array {
        $used_ids    = array();
        $uploads_url = $this->get_uploads_base_url();

        $stylesheets = array();

        // Active theme stylesheet.
        $theme_dir = get_stylesheet_directory();
        $stylesheets[] = $theme_dir . '/style.css';

        // Parent theme if child theme is active.
        $parent_dir = get_template_directory();
        if ( $parent_dir !== $theme_dir ) {
            $stylesheets[] = $parent_dir . '/style.css';
        }

        // Find all CSS files in both theme directories.
        $dirs_to_scan = array_unique( array( $theme_dir, $parent_dir ) );
        foreach ( $dirs_to_scan as $dir ) {
            if ( ! is_dir( $dir ) ) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );
            foreach ( $iterator as $file ) {
                if ( $file->isFile() && 'css' === strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) ) ) {
                    $path = $file->getPathname();
                    if ( ! in_array( $path, $stylesheets, true ) ) {
                        $stylesheets[] = $path;
                    }
                }
            }
        }

        foreach ( $stylesheets as $css_file ) {
            if ( ! file_exists( $css_file ) || ! is_readable( $css_file ) ) {
                continue;
            }

            $content = file_get_contents( $css_file );
            if ( empty( $content ) ) {
                continue;
            }

            // Extract URLs from background-image, background, url(), etc.
            if ( preg_match_all( '/url\s*\(\s*["\']?([^"\')\s]+)["\']?\s*\)/i', $content, $matches ) ) {
                foreach ( $matches[1] as $url ) {
                    // Skip data URIs and external URLs.
                    if ( 0 === strpos( $url, 'data:' ) || 0 === strpos( $url, '#' ) ) {
                        continue;
                    }

                    // Resolve relative URLs against the theme directory.
                    if ( false === strpos( $url, '://' ) ) {
                        $css_dir_url = '';
                        if ( 0 === strpos( $css_file, $theme_dir ) ) {
                            $css_dir_url = get_stylesheet_directory_uri() . '/' . dirname( str_replace( $theme_dir . '/', '', $css_file ) );
                        } elseif ( 0 === strpos( $css_file, $parent_dir ) ) {
                            $css_dir_url = get_template_directory_uri() . '/' . dirname( str_replace( $parent_dir . '/', '', $css_file ) );
                        }
                        if ( $css_dir_url ) {
                            $url = $css_dir_url . '/' . $url;
                        }
                    }

                    if ( false !== strpos( $url, $uploads_url ) ) {
                        $clean_url = preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', $url );
                        $id = $this->url_to_attachment_id( $clean_url );
                        if ( ! $id ) {
                            $id = $this->url_to_attachment_id( $url );
                        }
                        if ( $id ) {
                            $used_ids[] = $id;
                        }
                    }
                }
            }
        }

        // Also scan theme CSS stored in the customizer (Additional CSS).
        $custom_css = wp_get_custom_css();
        if ( ! empty( $custom_css ) ) {
            $found = $this->extract_attachment_ids_from_content( $custom_css, $uploads_url );
            if ( ! empty( $found ) ) {
                array_push( $used_ids, ...$found );
            }

            if ( preg_match_all( '/url\s*\(\s*["\']?([^"\')\s]+)["\']?\s*\)/i', $custom_css, $matches ) ) {
                foreach ( $matches[1] as $url ) {
                    if ( 0 === strpos( $url, 'data:' ) ) {
                        continue;
                    }
                    if ( false !== strpos( $url, $uploads_url ) ) {
                        $id = $this->url_to_attachment_id( preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', $url ) );
                        if ( ! $id ) {
                            $id = $this->url_to_attachment_id( $url );
                        }
                        if ( $id ) {
                            $used_ids[] = $id;
                        }
                    }
                }
            }
        }

        return $used_ids;
    }

    /**
     * Deep scan page builder data (Elementor, Beaver Builder, Divi, WPBakery).
     *
     * @return int[]
     */
    public function scan_page_builders(): array {
        global $wpdb;

        $used_ids    = array();
        $uploads_url = $this->get_uploads_base_url();

        // Elementor: stores data in _elementor_data postmeta as JSON.
        $elementor_rows = $wpdb->get_results(
            "SELECT post_id, meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_elementor_data' AND meta_value != ''"
        );

        foreach ( $elementor_rows as $row ) {
            if ( empty( $row->meta_value ) ) {
                continue;
            }

            // Extract IDs from Elementor JSON: "id":123 patterns and image URLs.
            if ( preg_match_all( '/"(?:id|image_id|attach_id|attachment_id)":\s*"?(\d+)"?/i', $row->meta_value, $matches ) ) {
                $used_ids = array_merge( $used_ids, array_map( 'intval', $matches[1] ) );
            }

            // URL references in Elementor data.
            $found = $this->extract_attachment_ids_from_content( $row->meta_value, $uploads_url );
            if ( ! empty( $found ) ) {
                array_push( $used_ids, ...$found );
            }
        }

        // Elementor CSS print method stores references.
        $elementor_css_ids = $wpdb->get_col(
            "SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = '_elementor_css' AND meta_value != ''"
        );
        foreach ( $elementor_css_ids as $css_data ) {
            $found = $this->extract_attachment_ids_from_content( $css_data, $uploads_url );
            if ( ! empty( $found ) ) {
                array_push( $used_ids, ...$found );
            }
        }

        // Beaver Builder: stores data in _fl_builder_data and _fl_builder_draft postmeta.
        $bb_meta_keys = array( '_fl_builder_data', '_fl_builder_draft' );
        foreach ( $bb_meta_keys as $meta_key ) {
            $bb_rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT meta_value FROM {$wpdb->postmeta}
                     WHERE meta_key = %s AND meta_value != ''",
                    $meta_key
                )
            );

            foreach ( $bb_rows as $row ) {
                $data = maybe_unserialize( $row->meta_value );
                if ( ! is_array( $data ) ) {
                    continue;
                }
                $serialized = maybe_serialize( $data );
                $found = $this->extract_attachment_ids_from_content( $serialized, $uploads_url );
                if ( ! empty( $found ) ) {
                    array_push( $used_ids, ...$found );
                }

                // Walk the array for photo/image keys.
                array_walk_recursive(
                    $data,
                    function ( $value, $key ) use ( &$used_ids ) {
                        if ( is_numeric( $value ) && (int) $value > 0 ) {
                            $key_lower = strtolower( (string) $key );
                            if ( in_array( $key_lower, array( 'photo', 'photo_id', 'image', 'image_id', 'id', 'attachment_id', 'bg_image', 'bg_video' ), true ) ) {
                                $used_ids[] = (int) $value;
                            }
                        }
                    }
                );
            }
        }

        // Divi / ET Builder: stores shortcode data in post_content (already scanned),
        // but also stores global modules in et_pb_* options.
        $divi_options = $wpdb->get_results(
            "SELECT option_value FROM {$wpdb->options}
             WHERE option_name LIKE 'et_pb_%' AND option_value != ''"
        );
        foreach ( $divi_options as $row ) {
            $found = $this->extract_attachment_ids_from_content( $row->option_value, $uploads_url );
            if ( ! empty( $found ) ) {
                array_push( $used_ids, ...$found );
            }
        }

        // WPBakery: uses shortcodes in post_content (already scanned),
        // but also check for image= and images= attributes.
        $wpbakery_rows = $wpdb->get_results(
            "SELECT post_content FROM {$wpdb->posts}
             WHERE post_type != 'attachment'
               AND post_type != 'revision'
               AND post_content LIKE '%[vc_%'"
        );
        foreach ( $wpbakery_rows as $row ) {
            // [vc_single_image image="123"], [vc_gallery images="1,2,3"]
            if ( preg_match_all( '/\[vc_[^\]]*\s(?:image|images)=["\']([^"\']+)["\']/i', $row->post_content, $matches ) ) {
                foreach ( $matches[1] as $val ) {
                    $ids = array_filter( array_map( 'intval', explode( ',', $val ) ) );
                    $used_ids = array_merge( $used_ids, $ids );
                }
            }
        }

        return $used_ids;
    }

    /**
     * Extract attachment IDs from a content string.
     *
     * @param string $content     The content to scan.
     * @param string $uploads_url The uploads base URL.
     * @return int[]
     */
    private function extract_attachment_ids_from_content( string $content, string $uploads_url = '' ): array {
        $ids = array();

        if ( empty( $content ) ) {
            return $ids;
        }

        if ( empty( $uploads_url ) ) {
            $uploads_url = $this->get_uploads_base_url();
        }

        // wp-image-{id} class pattern.
        if ( preg_match_all( '/wp-image-(\d+)/i', $content, $matches ) ) {
            $ids = array_merge( $ids, array_map( 'intval', $matches[1] ) );
        }

        // Gutenberg block pattern: wp:image {"id":X}.
        if ( preg_match_all( '/wp:image\s*\{[^}]*"id"\s*:\s*(\d+)/i', $content, $matches ) ) {
            $ids = array_merge( $ids, array_map( 'intval', $matches[1] ) );
        }

        // Gutenberg cover block and other media blocks.
        if ( preg_match_all( '/wp:(?:cover|media-text|video|audio|file)\s*\{[^}]*"id"\s*:\s*(\d+)/i', $content, $matches ) ) {
            $ids = array_merge( $ids, array_map( 'intval', $matches[1] ) );
        }

        // Gallery shortcode: [gallery ids="1,2,3"].
        if ( preg_match_all( '/\[gallery[^\]]*ids=["\']([^"\']+)["\']/i', $content, $matches ) ) {
            foreach ( $matches[1] as $id_list ) {
                $gallery_ids = array_filter( array_map( 'intval', explode( ',', $id_list ) ) );
                $ids         = array_merge( $ids, $gallery_ids );
            }
        }

        // Generic attribute pattern: attachment_id="123" or data-id="123".
        if ( preg_match_all( '/(?:attachment_id|data-id)[=:]\s*["\']?(\d+)/i', $content, $matches ) ) {
            $ids = array_merge( $ids, array_map( 'intval', $matches[1] ) );
        }

        // URL-based detection: find uploads URLs and resolve to IDs.
        if ( ! empty( $uploads_url ) && false !== strpos( $content, $uploads_url ) ) {
            $escaped_url = preg_quote( $uploads_url, '/' );
            if ( preg_match_all( '/' . $escaped_url . '\/([^\s"\'<>\)]+)/i', $content, $matches ) ) {
                foreach ( $matches[0] as $url ) {
                    // Strip size suffixes to get the original URL for better lookup.
                    $clean_url = preg_replace( '/-\d+x\d+(?=\.\w+$)/', '', $url );
                    $id        = $this->url_to_attachment_id( $clean_url );
                    if ( ! $id ) {
                        $id = $this->url_to_attachment_id( $url );
                    }
                    if ( $id ) {
                        $ids[] = $id;
                    }
                }
            }
        }

        return array_unique( array_filter( $ids ) );
    }

    /**
     * Resolve a URL to an attachment ID, with caching.
     *
     * @param string $url The URL to resolve.
     * @return int Attachment ID or 0.
     */
    private function url_to_attachment_id( string $url ): int {
        if ( empty( $url ) ) {
            return 0;
        }

        if ( isset( $this->url_id_cache[ $url ] ) ) {
            return $this->url_id_cache[ $url ];
        }

        $id                        = (int) attachment_url_to_postid( $url );
        $this->url_id_cache[ $url ] = $id;

        return $id;
    }

    /**
     * Get the base URL for the uploads directory.
     *
     * @return string
     */
    private function get_uploads_base_url(): string {
        $upload_dir = wp_upload_dir();
        return $upload_dir['baseurl'];
    }

    /**
     * Build attachment data array for display.
     *
     * @param int $id Attachment post ID.
     * @return array
     */
    public function build_attachment_data( int $id ): array {
        $file_path  = get_attached_file( $id );
        $file_size  = 0;
        $dimensions = '';

        if ( $file_path && file_exists( $file_path ) ) {
            $file_size = filesize( $file_path );
            if ( wp_attachment_is_image( $id ) ) {
                $image_data = wp_get_attachment_image_src( $id, 'full' );
                if ( $image_data ) {
                    $dimensions = $image_data[1] . ' x ' . $image_data[2];
                }
            }
        }

        $upload_dir   = wp_upload_dir();
        $relative_path = '';
        if ( $file_path ) {
            $relative_path = str_replace( $upload_dir['basedir'] . '/', '', $file_path );
        }

        $thumbnail = '';
        if ( wp_attachment_is_image( $id ) ) {
            $thumb = wp_get_attachment_image_src( $id, 'thumbnail' );
            if ( $thumb ) {
                $thumbnail = $thumb[0];
            }
        } else {
            $thumbnail = wp_mime_type_icon( $id );
        }

        $post = get_post( $id );

        return array(
            'id'            => $id,
            'title'         => get_the_title( $id ),
            'filename'      => $file_path ? basename( $file_path ) : '',
            'mime_type'     => get_post_mime_type( $id ),
            'file_size'     => $file_size ? $file_size : 0,
            'file_size_hr'  => $file_size ? size_format( $file_size ) : '0 B',
            'upload_date'   => $post ? $post->post_date : '',
            'dimensions'    => $dimensions,
            'relative_path' => $relative_path,
            'thumbnail'     => $thumbnail,
            'edit_url'      => get_edit_post_link( $id, 'raw' ),
        );
    }

    /**
     * Get all registered image size dimensions.
     *
     * @return array<string, array{width: int, height: int}>
     */
    private function get_registered_size_dimensions(): array {
        $sizes = array();

        // Core sizes via get_intermediate_image_sizes.
        foreach ( get_intermediate_image_sizes() as $size ) {
            $sizes[ $size ] = array(
                'width'  => (int) get_option( "{$size}_size_w", 0 ),
                'height' => (int) get_option( "{$size}_size_h", 0 ),
            );
        }

        // Additional registered subsizes.
        if ( function_exists( 'wp_get_registered_image_subsizes' ) ) {
            foreach ( wp_get_registered_image_subsizes() as $name => $data ) {
                $sizes[ $name ] = array(
                    'width'  => (int) ( $data['width'] ?? 0 ),
                    'height' => (int) ( $data['height'] ?? 0 ),
                );
            }
        }

        return $sizes;
    }

    /**
     * Build a set of all thumbnail file paths tracked in the media library.
     *
     * @return array<string, true>
     */
    private function get_known_thumbnail_files(): array {
        global $wpdb;

        $upload_dir = wp_upload_dir();
        $base_dir   = $upload_dir['basedir'];
        $known      = array();

        $batch_size = 500;
        $offset     = 0;

        do {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT pm.meta_value, p.guid
                     FROM {$wpdb->postmeta} pm
                     JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                     WHERE pm.meta_key = '_wp_attachment_metadata'
                       AND p.post_type = 'attachment'
                     LIMIT %d OFFSET %d",
                    $batch_size,
                    $offset
                )
            );

            if ( empty( $rows ) ) {
                break;
            }

            foreach ( $rows as $row ) {
                $meta = maybe_unserialize( $row->meta_value );
                if ( ! is_array( $meta ) || empty( $meta['sizes'] ) || empty( $meta['file'] ) ) {
                    continue;
                }

                $sub_dir = dirname( $meta['file'] );

                foreach ( $meta['sizes'] as $size_data ) {
                    if ( ! empty( $size_data['file'] ) ) {
                        $thumb_path            = $sub_dir . '/' . $size_data['file'];
                        $known[ $thumb_path ] = true;
                    }
                }
            }

            $offset += $batch_size;
        } while ( count( $rows ) === $batch_size );

        return $known;
    }
}
