=== Media Cleanup ===
Contributors: jemediacleanup
Tags: media, cleanup, unused, images, optimization
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Find and remove unused media files from your WordPress media library. Deep-scans post content, meta fields, widgets, options, and more.

== Description ==

Media Cleanup helps you identify and safely remove unused media files that are taking up space in your WordPress installation.

**Deep Scanning**

The plugin scans all of the following locations to determine whether a media file is in use:

* Post and page content (all post types)
* Post meta fields (featured images, ACF fields, page builder data, WooCommerce galleries)
* Options table (site icon, theme mods, customizer settings)
* Widget data
* Term meta (category and tag images)
* Gutenberg block attributes
* Gallery shortcodes
* URL-based detection with reverse lookup

**Orphan Thumbnail Scanner**

A separate scanner finds thumbnail files on disk that:

* Belong to image sizes that are no longer registered in WordPress
* Have no parent/original image in the media library

**Safety First**

* Deleted media goes to WordPress trash first (with option to permanently delete)
* Confirmation dialog before every deletion
* Batch processing to avoid timeouts
* Activity log of all deletions

**Features**

* Filter results by file type (JPEG, PNG, GIF, WebP, SVG, PDF, Video, Audio)
* Bulk select/deselect by file type
* Search and pagination
* Export results to CSV
* Clean, responsive admin interface

== Installation ==

1. Upload the `media-cleanup` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu in WordPress
3. Go to Tools > Media Cleanup to start scanning

== Frequently Asked Questions ==

= Is it safe to delete the files found by the scanner? =

The scanner checks all common locations where media can be referenced. However, some custom themes or plugins may reference media in unusual ways. We recommend reviewing the results and using the trash feature first before permanently deleting.

= How long does a scan take? =

The scan processes in batches to avoid server timeouts. Scanning time depends on the size of your media library and database. A site with a few thousand media files typically completes in under a minute.

= Does this work with page builders? =

The scanner checks post meta fields where page builders (Elementor, Beaver Builder, etc.) store their data. URL-based detection also catches references in serialized page builder content.

= Does this work with WooCommerce? =

Yes. The scanner specifically checks for WooCommerce product gallery images (`_product_image_gallery` meta key) in addition to featured images.

== Screenshots ==

1. Unused media scan results with file type filters
2. Orphan thumbnail scanner
3. Confirmation dialog before deletion

== Changelog ==

= 1.0.0 =
* Initial release
* Deep media scanning across posts, meta, options, widgets, and term meta
* Orphan thumbnail scanner
* Batch deletion with trash support
* File type filter chips
* CSV export
* Activity logging
