<?php
/**
 * Admin page HTML template.
 *
 * @package MediaCleanup
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap jemc-wrap">
    <h1><?php echo esc_html__( 'Media Cleanup', 'media-cleanup' ); ?></h1>

    <nav class="nav-tab-wrapper jemc-tabs">
        <a href="#unused-media" class="nav-tab nav-tab-active" data-tab="unused-media">
            <?php echo esc_html__( 'Unused Media', 'media-cleanup' ); ?>
        </a>
        <a href="#orphan-thumbnails" class="nav-tab" data-tab="orphan-thumbnails">
            <?php echo esc_html__( 'Orphan Thumbnails', 'media-cleanup' ); ?>
        </a>
    </nav>

    <!-- Unused Media Tab -->
    <div id="unused-media" class="jemc-tab-content jemc-tab-active">
        <div class="jemc-scan-section">
            <p class="jemc-description">
                <?php echo esc_html__( 'Scan your media library to find files that are not used anywhere on your site. The scanner checks post content, meta fields, widgets, options, and more.', 'media-cleanup' ); ?>
            </p>
            <button type="button" id="jemc-start-scan" class="button button-primary button-hero">
                <?php echo esc_html__( 'Scan for Unused Media', 'media-cleanup' ); ?>
            </button>
        </div>

        <!-- Progress Bar -->
        <div id="jemc-progress-wrap" class="jemc-progress-wrap" style="display:none;">
            <div class="jemc-progress-bar">
                <div id="jemc-progress-fill" class="jemc-progress-fill" style="width:0%;">
                    <span id="jemc-progress-text" class="jemc-progress-text">0%</span>
                </div>
            </div>
            <p id="jemc-progress-status" class="jemc-progress-status"></p>
        </div>

        <!-- Results Summary -->
        <div id="jemc-results-summary" class="jemc-results-summary" style="display:none;">
            <div class="jemc-summary-box">
                <span id="jemc-summary-text"></span>
            </div>
        </div>

        <!-- MIME Type Filter Chips -->
        <div id="jemc-mime-filters" class="jemc-mime-filters" style="display:none;"></div>

        <!-- Toolbar -->
        <div id="jemc-toolbar" class="jemc-toolbar" style="display:none;">
            <div class="jemc-toolbar-left">
                <label class="jemc-select-all-label">
                    <input type="checkbox" id="jemc-select-all" />
                    <?php echo esc_html__( 'Select All', 'media-cleanup' ); ?>
                </label>
                <button type="button" id="jemc-trash-selected" class="button button-secondary">
                    <?php echo esc_html__( 'Move to Trash', 'media-cleanup' ); ?>
                </button>
                <button type="button" id="jemc-delete-selected" class="button jemc-button-danger">
                    <?php echo esc_html__( 'Delete Permanently', 'media-cleanup' ); ?>
                </button>
                <button type="button" id="jemc-export-csv" class="button button-secondary">
                    <?php echo esc_html__( 'Export CSV', 'media-cleanup' ); ?>
                </button>
            </div>
            <div class="jemc-toolbar-right">
                <input type="search" id="jemc-search" class="jemc-search" placeholder="<?php echo esc_attr__( 'Search files...', 'media-cleanup' ); ?>" />
            </div>
        </div>

        <!-- Results Table -->
        <div id="jemc-results-table-wrap" style="display:none;">
            <table class="wp-list-table widefat striped jemc-results-table">
                <thead>
                    <tr>
                        <th class="jemc-col-check"><input type="checkbox" id="jemc-select-all-head" /></th>
                        <th class="jemc-col-thumb"><?php echo esc_html__( 'Preview', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-name"><?php echo esc_html__( 'File Name', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-type"><?php echo esc_html__( 'Type', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-size"><?php echo esc_html__( 'Size', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-date"><?php echo esc_html__( 'Date', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-dims"><?php echo esc_html__( 'Dimensions', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-path"><?php echo esc_html__( 'Path', 'media-cleanup' ); ?></th>
                    </tr>
                </thead>
                <tbody id="jemc-results-body"></tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div id="jemc-pagination" class="jemc-pagination" style="display:none;"></div>

        <!-- Delete Progress -->
        <div id="jemc-delete-progress" class="jemc-progress-wrap" style="display:none;">
            <div class="jemc-progress-bar">
                <div id="jemc-delete-progress-fill" class="jemc-progress-fill jemc-progress-danger" style="width:0%;">
                    <span id="jemc-delete-progress-text" class="jemc-progress-text">0%</span>
                </div>
            </div>
            <p id="jemc-delete-status" class="jemc-progress-status"></p>
        </div>
    </div>

    <!-- Orphan Thumbnails Tab -->
    <div id="orphan-thumbnails" class="jemc-tab-content">
        <div class="jemc-scan-section">
            <p class="jemc-description">
                <?php echo esc_html__( 'Scan the uploads directory for thumbnail files that belong to unregistered image sizes or whose original image no longer exists.', 'media-cleanup' ); ?>
            </p>
            <button type="button" id="jemc-scan-orphans" class="button button-primary button-hero">
                <?php echo esc_html__( 'Scan for Orphan Thumbnails', 'media-cleanup' ); ?>
            </button>
        </div>

        <!-- Orphan Progress -->
        <div id="jemc-orphan-progress" class="jemc-progress-wrap" style="display:none;">
            <div class="jemc-progress-bar">
                <div class="jemc-progress-fill jemc-progress-indeterminate"></div>
            </div>
            <p class="jemc-progress-status"><?php echo esc_html__( 'Scanning uploads directory...', 'media-cleanup' ); ?></p>
        </div>

        <!-- Orphan Summary -->
        <div id="jemc-orphan-summary" class="jemc-results-summary" style="display:none;">
            <div class="jemc-summary-box">
                <span id="jemc-orphan-summary-text"></span>
            </div>
        </div>

        <!-- Orphan Toolbar -->
        <div id="jemc-orphan-toolbar" class="jemc-toolbar" style="display:none;">
            <div class="jemc-toolbar-left">
                <label class="jemc-select-all-label">
                    <input type="checkbox" id="jemc-orphan-select-all" />
                    <?php echo esc_html__( 'Select All', 'media-cleanup' ); ?>
                </label>
                <button type="button" id="jemc-delete-orphans" class="button jemc-button-danger">
                    <?php echo esc_html__( 'Delete Selected', 'media-cleanup' ); ?>
                </button>
            </div>
        </div>

        <!-- Orphan Results Table -->
        <div id="jemc-orphan-table-wrap" style="display:none;">
            <table class="wp-list-table widefat striped jemc-results-table">
                <thead>
                    <tr>
                        <th class="jemc-col-check"><input type="checkbox" id="jemc-orphan-select-all-head" /></th>
                        <th class="jemc-col-name"><?php echo esc_html__( 'File Name', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-dims"><?php echo esc_html__( 'Dimensions', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-size"><?php echo esc_html__( 'Size', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-path"><?php echo esc_html__( 'Path', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-reason"><?php echo esc_html__( 'Reason', 'media-cleanup' ); ?></th>
                    </tr>
                </thead>
                <tbody id="jemc-orphan-body"></tbody>
            </table>
        </div>

        <!-- Orphan Delete Progress -->
        <div id="jemc-orphan-delete-progress" class="jemc-progress-wrap" style="display:none;">
            <div class="jemc-progress-bar">
                <div id="jemc-orphan-delete-fill" class="jemc-progress-fill jemc-progress-danger" style="width:0%;">
                    <span id="jemc-orphan-delete-text" class="jemc-progress-text">0%</span>
                </div>
            </div>
            <p id="jemc-orphan-delete-status" class="jemc-progress-status"></p>
        </div>
    </div>

    <!-- Confirmation Modal -->
    <div id="jemc-modal" class="jemc-modal" style="display:none;">
        <div class="jemc-modal-overlay"></div>
        <div class="jemc-modal-dialog">
            <div class="jemc-modal-header">
                <h2 id="jemc-modal-title"></h2>
                <button type="button" class="jemc-modal-close" aria-label="<?php echo esc_attr__( 'Close', 'media-cleanup' ); ?>">&times;</button>
            </div>
            <div id="jemc-modal-body" class="jemc-modal-body"></div>
            <div class="jemc-modal-footer">
                <button type="button" id="jemc-modal-cancel" class="button button-secondary">
                    <?php echo esc_html__( 'Cancel', 'media-cleanup' ); ?>
                </button>
                <button type="button" id="jemc-modal-confirm" class="button jemc-button-danger">
                    <?php echo esc_html__( 'Confirm', 'media-cleanup' ); ?>
                </button>
            </div>
        </div>
    </div>

    <!-- Admin Notices Area -->
    <div id="jemc-notices" class="jemc-notices"></div>
</div>
