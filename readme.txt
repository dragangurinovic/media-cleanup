=== Media Cleanup ===
Contributors: jemediacleanup
Tags: media, cleanup, unused, images, optimization, duplicates
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find and remove unused media files from your WordPress media library. Deep-scans post content, meta fields, widgets, options, theme CSS, page builders, and more.

== Description ==

Media Cleanup helps you identify and safely remove unused media files that are taking up space in your WordPress installation.

**Deep Scanning**

The plugin scans all of the following locations to determine whether a media file is in use:

* Post and page content (all post types)
* Post meta fields (featured images, ACF fields, page builder data, WooCommerce galleries)
* Options table (site icon, theme mods, customizer settings)
* Widget data
* Term meta (category and tag images)
* Gutenberg block attributes (image, cover, media-text, video, audio, file)
* Gallery shortcodes
* Theme CSS files (style.css, all CSS in theme/child-theme directories)
* Custom CSS (Additional CSS in the Customizer)
* Page builder data (Elementor, Beaver Builder, Divi, WPBakery)
* URL-based detection with reverse lookup

**Orphan Thumbnail Scanner**

A separate scanner finds thumbnail files on disk that:

* Belong to image sizes that are no longer registered in WordPress
* Have no parent/original image in the media library

**Duplicate File Detection**

Finds duplicate media files by comparing file content (MD5 hash). Shows duplicate groups with wasted space calculations and lets you keep one copy while deleting the rest.

**Broken Media Link Finder**

Scans your content for references to media files that no longer exist on disk, helping you find and fix missing images across your site.

**Quarantine System**

Instead of permanently deleting, move files to a quarantine folder where they can be restored later or permanently deleted when you are confident they are not needed.

**Image Optimizer**

Find oversized images and strip EXIF metadata to reclaim disk space:

* Resize images exceeding a configurable max dimension (default 2560px)
* Strip EXIF metadata from JPEG files
* Uses WordPress Image Editor (GD/Imagick)
* Regenerates thumbnails after optimization

**Scheduled Scans**

Set up automatic daily, weekly, or monthly scans with email reports summarizing unused media, duplicates, and potential space savings.

**WP-CLI Support**

Full command-line interface for scripted and automated cleanup:

* `wp media-cleanup scan` -- Scan for unused media
* `wp media-cleanup delete-unused` -- Delete unused media
* `wp media-cleanup duplicates` -- Find duplicate files
* `wp media-cleanup broken-links` -- Find broken media links
* `wp media-cleanup orphan-thumbnails` -- Find orphan thumbnails
* `wp media-cleanup optimize` -- Optimize oversized images
* `wp media-cleanup quarantine` -- View quarantine status
* `wp media-cleanup schedule` -- Manage scheduled scans

**Safety First**

* Quarantine system with restore capability
* Deleted media can go to WordPress trash first
* Confirmation dialog before every deletion
* Batch processing to avoid timeouts
* Activity log of all deletions

**Features**

* Filter results by file type (JPEG, PNG, GIF, WebP, SVG, PDF, Video, Audio)
* Bulk select/deselect by file type
* Search and pagination
* Export results to CSV
* Clean, responsive admin interface with 7 feature tabs

== Installation ==

1. Upload the `media-cleanup` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu in WordPress
3. Go to Tools > Media Cleanup to start scanning

== Frequently Asked Questions ==

= Is it safe to delete the files found by the scanner? =

The scanner checks all common locations where media can be referenced, including page builders, theme CSS, and custom fields. We recommend using the quarantine feature first -- it removes files from the media library but keeps them recoverable.

= How long does a scan take? =

The scan processes in batches to avoid server timeouts. A site with a few thousand media files typically completes in under a minute. The duplicate scanner may take longer on sites with large media libraries since it computes file hashes.

= Does this work with page builders? =

Yes. The scanner specifically checks Elementor's `_elementor_data`, Beaver Builder's `_fl_builder_data` and `_fl_builder_draft`, Divi's `et_pb_*` options, and WPBakery's `[vc_*]` shortcodes.

= Does this work with WooCommerce? =

Yes. The scanner checks for WooCommerce product gallery images (`_product_image_gallery`) in addition to featured images.

= What does the quarantine do? =

Quarantine moves files to `wp-content/jemc-quarantine/` and removes them from the media library. You can restore them at any time, or permanently delete them when you are confident they are not needed.

= Does the optimizer reduce image quality? =

The optimizer resizes images larger than the configured maximum dimension (default 2560px, same as WordPress core) and saves at quality 82, which provides a good balance of quality and file size. EXIF stripping removes metadata without affecting visual quality.

== Changelog ==

= 1.1.0 =
* Added duplicate file detection (MD5 hash comparison)
* Added broken media link finder
* Added quarantine system with restore capability
* Added image optimizer (resize oversized + strip EXIF)
* Added scheduled scans with email reports (daily/weekly/monthly)
* Added WP-CLI commands for all features
* Added theme CSS scanning (style.css + all CSS files in theme directories)
* Added Additional CSS (Customizer) scanning
* Added deep page builder scanning (Elementor, Beaver Builder, Divi, WPBakery)
* Added quarantine button to unused media toolbar
* Expanded admin interface to 7 feature tabs

= 1.0.0 =
* Initial release
* Deep media scanning across posts, meta, options, widgets, and term meta
* Orphan thumbnail scanner
* Batch deletion with trash support
* File type filter chips
* CSV export
* Activity logging
