<?php
/**
 * Image optimization: resize oversized images and strip EXIF metadata.
 *
 * @package MediaCleanup
 */

namespace MediaCleanup;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides image optimization capabilities including resizing and EXIF stripping.
 */
class Optimizer {

    /**
     * Default maximum dimension (width or height) for resizing.
     * Matches WordPress big_image_size_threshold.
     */
    const DEFAULT_MAX_DIMENSION = 2560;

    /**
     * Default JPEG quality for optimized images.
     */
    const DEFAULT_QUALITY = 82;

    /**
     * Supported MIME types for optimization.
     */
    const SUPPORTED_MIME_TYPES = array(
        'image/jpeg',
        'image/png',
        'image/webp',
    );

    /**
     * Find images that could be optimized.
     *
     * Returns images that exceed the maximum dimension threshold or
     * have EXIF data that could be stripped.
     *
     * @param int $max_dimension Maximum width/height threshold.
     * @return array Each entry contains:
     *   'id'                 => int attachment ID,
     *   'filename'           => string,
     *   'current_size'       => int bytes,
     *   'current_size_hr'    => string human-readable size,
     *   'current_dimensions' => string 'WxH',
     *   'potential_savings'  => int estimated bytes saved,
     *   'has_exif'           => bool,
     *   'is_oversized'       => bool,
     *   'thumbnail'          => string URL,
     *   'relative_path'      => string,
     *   'mime_type'          => string.
     */
    public function find_optimizable( int $max_dimension = self::DEFAULT_MAX_DIMENSION ): array {
        global $wpdb;

        $results    = array();
        $upload_dir = wp_upload_dir();
        $batch_size = 200;
        $offset     = 0;

        do {
            $ids = $wpdb->get_col(
                $wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts}
                     WHERE post_type = 'attachment'
                       AND post_status != 'trash'
                       AND post_mime_type IN ('image/jpeg', 'image/png', 'image/webp')
                     ORDER BY ID ASC
                     LIMIT %d OFFSET %d",
                    $batch_size,
                    $offset
                )
            );

            if ( empty( $ids ) ) {
                break;
            }

            foreach ( $ids as $id ) {
                $id        = (int) $id;
                $file_path = get_attached_file( $id );

                if ( ! $file_path || ! file_exists( $file_path ) ) {
                    continue;
                }

                $file_size = filesize( $file_path );
                if ( false === $file_size || 0 === $file_size ) {
                    continue;
                }

                $mime_type = get_post_mime_type( $id );

                // Get image dimensions.
                $image_size = wp_getimagesize( $file_path );
                if ( false === $image_size ) {
                    continue;
                }

                $width  = (int) $image_size[0];
                $height = (int) $image_size[1];

                $is_oversized = ( $width > $max_dimension || $height > $max_dimension );
                $has_exif     = $this->image_has_exif( $file_path, $mime_type );

                // Skip images that don't need optimization.
                if ( ! $is_oversized && ! $has_exif ) {
                    continue;
                }

                // Estimate potential savings.
                $potential_savings = $this->estimate_savings( $file_size, $width, $height, $max_dimension, $is_oversized, $has_exif );

                $thumbnail = '';
                $thumb     = wp_get_attachment_image_src( $id, 'thumbnail' );
                if ( $thumb ) {
                    $thumbnail = $thumb[0];
                }

                $results[] = array(
                    'id'                 => $id,
                    'filename'           => basename( $file_path ),
                    'current_size'       => $file_size,
                    'current_size_hr'    => size_format( $file_size ),
                    'current_dimensions' => $width . ' x ' . $height,
                    'potential_savings'  => $potential_savings,
                    'potential_savings_hr' => size_format( $potential_savings ),
                    'has_exif'           => $has_exif,
                    'is_oversized'       => $is_oversized,
                    'thumbnail'          => $thumbnail,
                    'relative_path'      => str_replace( $upload_dir['basedir'] . '/', '', $file_path ),
                    'mime_type'          => $mime_type,
                );
            }

            $offset += $batch_size;
        } while ( count( $ids ) === $batch_size );

        // Sort by potential savings descending.
        usort(
            $results,
            function ( $a, $b ) {
                return $b['potential_savings'] <=> $a['potential_savings'];
            }
        );

        return $results;
    }

    /**
     * Optimize a single image.
     *
     * Resizes if larger than the maximum dimension (preserving aspect ratio)
     * and optionally strips EXIF metadata. Uses WP_Image_Editor.
     *
     * @param int  $id            Attachment ID.
     * @param int  $max_dimension Maximum width/height in pixels.
     * @param bool $strip_exif    Whether to strip EXIF data.
     * @return array {
     *     @type bool   $success       Whether optimization succeeded.
     *     @type int    $original_size Original file size in bytes.
     *     @type int    $new_size      New file size in bytes.
     *     @type int    $saved         Bytes saved.
     *     @type string $saved_hr      Human-readable bytes saved.
     *     @type string $error         Error message if failed.
     * }
     */
    public function optimize_image( int $id, int $max_dimension = self::DEFAULT_MAX_DIMENSION, bool $strip_exif = true ): array {
        $file_path = get_attached_file( $id );

        if ( ! $file_path || ! file_exists( $file_path ) ) {
            return array(
                'success'       => false,
                'original_size' => 0,
                'new_size'      => 0,
                'saved'         => 0,
                'saved_hr'      => '0 B',
                'error'         => 'File not found.',
            );
        }

        $mime_type = get_post_mime_type( $id );
        if ( ! in_array( $mime_type, self::SUPPORTED_MIME_TYPES, true ) ) {
            return array(
                'success'       => false,
                'original_size' => 0,
                'new_size'      => 0,
                'saved'         => 0,
                'saved_hr'      => '0 B',
                'error'         => sprintf( 'Unsupported MIME type: %s', $mime_type ),
            );
        }

        $original_size = filesize( $file_path );
        if ( false === $original_size ) {
            $original_size = 0;
        }

        // Load the image with WP_Image_Editor.
        $editor = wp_get_image_editor( $file_path );

        if ( is_wp_error( $editor ) ) {
            return array(
                'success'       => false,
                'original_size' => $original_size,
                'new_size'      => $original_size,
                'saved'         => 0,
                'saved_hr'      => '0 B',
                'error'         => $editor->get_error_message(),
            );
        }

        $current_size_data = $editor->get_size();
        $width             = (int) $current_size_data['width'];
        $height            = (int) $current_size_data['height'];
        $modified          = false;

        // Resize if oversized.
        if ( $width > $max_dimension || $height > $max_dimension ) {
            $new_width  = $max_dimension;
            $new_height = $max_dimension;

            // Maintain aspect ratio: resize to fit within max_dimension box.
            if ( $width > $height ) {
                $new_height = 0; // Auto-calculate.
            } else {
                $new_width = 0; // Auto-calculate.
            }

            $result = $editor->resize( $new_width, $new_height );

            if ( is_wp_error( $result ) ) {
                return array(
                    'success'       => false,
                    'original_size' => $original_size,
                    'new_size'      => $original_size,
                    'saved'         => 0,
                    'saved_hr'      => '0 B',
                    'error'         => $result->get_error_message(),
                );
            }

            $modified = true;
        }

        // Set quality.
        $editor->set_quality( self::DEFAULT_QUALITY );

        // Strip EXIF by saving through the editor (GD inherently strips EXIF;
        // Imagick needs explicit stripping).
        if ( $strip_exif ) {
            $this->strip_exif_from_editor( $editor );
            $modified = true;
        }

        if ( ! $modified ) {
            return array(
                'success'       => true,
                'original_size' => $original_size,
                'new_size'      => $original_size,
                'saved'         => 0,
                'saved_hr'      => '0 B',
                'error'         => '',
            );
        }

        // Save the optimized image back to the same path.
        $saved = $editor->save( $file_path );

        if ( is_wp_error( $saved ) ) {
            return array(
                'success'       => false,
                'original_size' => $original_size,
                'new_size'      => $original_size,
                'saved'         => 0,
                'saved_hr'      => '0 B',
                'error'         => $saved->get_error_message(),
            );
        }

        // The editor may save to a different path (appending dimensions).
        // If so, rename it back.
        if ( $saved['path'] !== $file_path ) {
            if ( file_exists( $saved['path'] ) ) {
                // Remove the original, then rename.
                if ( file_exists( $file_path ) ) {
                    wp_delete_file( $file_path );
                }
                rename( $saved['path'], $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
            }
        }

        $new_size = file_exists( $file_path ) ? filesize( $file_path ) : $original_size;
        if ( false === $new_size ) {
            $new_size = $original_size;
        }

        $bytes_saved = max( 0, $original_size - $new_size );

        // Regenerate thumbnails.
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $metadata = wp_generate_attachment_metadata( $id, $file_path );
        wp_update_attachment_metadata( $id, $metadata );

        return array(
            'success'       => true,
            'original_size' => $original_size,
            'new_size'      => $new_size,
            'saved'         => $bytes_saved,
            'saved_hr'      => size_format( $bytes_saved ),
            'error'         => '',
        );
    }

    /**
     * Batch optimize multiple images.
     *
     * @param int[] $ids           Attachment IDs to optimize.
     * @param int   $max_dimension Maximum width/height in pixels.
     * @param bool  $strip_exif    Whether to strip EXIF data.
     * @return array {
     *     @type int      $optimized     Number of successfully optimized images.
     *     @type int      $failed        Number of images that failed.
     *     @type int      $total_saved   Total bytes saved.
     *     @type string   $total_saved_hr Human-readable total bytes saved.
     *     @type string[] $errors        Error messages.
     * }
     */
    public function batch_optimize( array $ids, int $max_dimension = self::DEFAULT_MAX_DIMENSION, bool $strip_exif = true ): array {
        $optimized   = 0;
        $failed      = 0;
        $total_saved = 0;
        $errors      = array();

        foreach ( $ids as $id ) {
            $id     = (int) $id;
            $result = $this->optimize_image( $id, $max_dimension, $strip_exif );

            if ( $result['success'] ) {
                ++$optimized;
                $total_saved += $result['saved'];
            } else {
                ++$failed;
                if ( ! empty( $result['error'] ) ) {
                    $errors[] = sprintf( 'ID %d: %s', $id, $result['error'] );
                }
            }
        }

        return array(
            'optimized'     => $optimized,
            'failed'        => $failed,
            'total_saved'   => $total_saved,
            'total_saved_hr' => size_format( $total_saved ),
            'errors'        => $errors,
        );
    }

    /**
     * Check if an image file contains EXIF data.
     *
     * @param string $file_path Path to the image file.
     * @param string $mime_type MIME type of the image.
     * @return bool
     */
    private function image_has_exif( string $file_path, string $mime_type ): bool {
        // EXIF is only relevant for JPEG and TIFF.
        if ( 'image/jpeg' !== $mime_type ) {
            return false;
        }

        if ( ! function_exists( 'exif_read_data' ) ) {
            return false;
        }

        try {
            $exif = @exif_read_data( $file_path, 'ANY_TAG', false ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

            if ( false === $exif || ! is_array( $exif ) ) {
                return false;
            }

            // Check for meaningful EXIF data beyond basic file info.
            $meaningful_keys = array(
                'Make', 'Model', 'DateTime', 'DateTimeOriginal',
                'ExposureTime', 'FNumber', 'ISOSpeedRatings',
                'FocalLength', 'GPSLatitude', 'GPSLongitude',
                'Software', 'Artist', 'Copyright', 'UserComment',
            );

            foreach ( $meaningful_keys as $key ) {
                if ( isset( $exif[ $key ] ) && ! empty( $exif[ $key ] ) ) {
                    return true;
                }
            }
        } catch ( \Exception $e ) {
            return false;
        }

        return false;
    }

    /**
     * Estimate potential file size savings from optimization.
     *
     * @param int  $file_size     Current file size in bytes.
     * @param int  $width         Current image width.
     * @param int  $height        Current image height.
     * @param int  $max_dimension Maximum dimension target.
     * @param bool $is_oversized  Whether the image is oversized.
     * @param bool $has_exif      Whether the image has EXIF data.
     * @return int Estimated bytes that could be saved.
     */
    private function estimate_savings( int $file_size, int $width, int $height, int $max_dimension, bool $is_oversized, bool $has_exif ): int {
        $savings = 0;

        if ( $is_oversized ) {
            // Estimate based on pixel ratio reduction.
            $current_pixels = $width * $height;

            if ( $width > $height ) {
                $new_width  = $max_dimension;
                $new_height = (int) round( $height * ( $max_dimension / $width ) );
            } else {
                $new_height = $max_dimension;
                $new_width  = (int) round( $width * ( $max_dimension / $height ) );
            }

            $new_pixels = $new_width * $new_height;

            if ( $current_pixels > 0 ) {
                $ratio   = $new_pixels / $current_pixels;
                $savings = (int) ( $file_size * ( 1 - $ratio ) );
            }
        }

        if ( $has_exif ) {
            // EXIF data typically adds 10-100KB.
            $savings += min( (int) ( $file_size * 0.05 ), 102400 );
        }

        return max( 0, $savings );
    }

    /**
     * Strip EXIF metadata from the image editor instance.
     *
     * @param \WP_Image_Editor $editor The image editor instance.
     * @return void
     */
    private function strip_exif_from_editor( $editor ): void {
        // If using Imagick, we can explicitly strip profiles.
        if ( $editor instanceof \WP_Image_Editor_Imagick ) {
            try {
                $reflection = new \ReflectionProperty( $editor, 'image' );
                $reflection->setAccessible( true );
                $imagick = $reflection->getValue( $editor );

                if ( $imagick instanceof \Imagick ) {
                    $imagick->stripImage();
                }
            } catch ( \ReflectionException $e ) {
                // Silently fail; the save operation with GD will strip EXIF anyway.
            }
        }

        // GD-based editor inherently strips EXIF when re-saving, so no extra
        // action is needed for WP_Image_Editor_GD.
    }
}
