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
        <a href="#duplicates" class="nav-tab" data-tab="duplicates">
            <?php echo esc_html__( 'Duplicates', 'media-cleanup' ); ?>
        </a>
        <a href="#broken-links" class="nav-tab" data-tab="broken-links">
            <?php echo esc_html__( 'Broken Links', 'media-cleanup' ); ?>
        </a>
        <a href="#quarantine" class="nav-tab" data-tab="quarantine">
            <?php echo esc_html__( 'Quarantine', 'media-cleanup' ); ?>
        </a>
        <a href="#optimizer" class="nav-tab" data-tab="optimizer">
            <?php echo esc_html__( 'Optimizer', 'media-cleanup' ); ?>
        </a>
        <a href="#settings" class="nav-tab" data-tab="settings">
            <?php echo esc_html__( 'Settings', 'media-cleanup' ); ?>
        </a>
    </nav>

    <!-- ============================================================ -->
    <!-- Unused Media Tab -->
    <!-- ============================================================ -->
    <div id="unused-media" class="jemc-tab-content jemc-tab-active">
        <div class="jemc-scan-section">
            <p class="jemc-description">
                <?php echo esc_html__( 'Scan your media library to find files that are not used anywhere on your site. The scanner checks post content, meta fields, widgets, options, theme CSS, page builder data, and more.', 'media-cleanup' ); ?>
            </p>
            <button type="button" id="jemc-start-scan" class="button button-primary button-hero">
                <?php echo esc_html__( 'Scan for Unused Media', 'media-cleanup' ); ?>
            </button>
        </div>

        <div id="jemc-progress-wrap" class="jemc-progress-wrap" style="display:none;">
            <div class="jemc-progress-bar">
                <div id="jemc-progress-fill" class="jemc-progress-fill" style="width:0%;">
                    <span id="jemc-progress-text" class="jemc-progress-text">0%</span>
                </div>
            </div>
            <p id="jemc-progress-status" class="jemc-progress-status"></p>
        </div>

        <div id="jemc-results-summary" class="jemc-results-summary" style="display:none;">
            <div class="jemc-summary-box">
                <span id="jemc-summary-text"></span>
            </div>
        </div>

        <div id="jemc-mime-filters" class="jemc-mime-filters" style="display:none;"></div>

        <div id="jemc-toolbar" class="jemc-toolbar" style="display:none;">
            <div class="jemc-toolbar-left">
                <label class="jemc-select-all-label">
                    <input type="checkbox" id="jemc-select-all" />
                    <?php echo esc_html__( 'Select All', 'media-cleanup' ); ?>
                </label>
                <button type="button" id="jemc-quarantine-selected" class="button button-secondary">
                    <?php echo esc_html__( 'Quarantine', 'media-cleanup' ); ?>
                </button>
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

        <div id="jemc-pagination" class="jemc-pagination" style="display:none;"></div>

        <div id="jemc-delete-progress" class="jemc-progress-wrap" style="display:none;">
            <div class="jemc-progress-bar">
                <div id="jemc-delete-progress-fill" class="jemc-progress-fill jemc-progress-danger" style="width:0%;">
                    <span id="jemc-delete-progress-text" class="jemc-progress-text">0%</span>
                </div>
            </div>
            <p id="jemc-delete-status" class="jemc-progress-status"></p>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- Orphan Thumbnails Tab -->
    <!-- ============================================================ -->
    <div id="orphan-thumbnails" class="jemc-tab-content">
        <div class="jemc-scan-section">
            <p class="jemc-description">
                <?php echo esc_html__( 'Scan the uploads directory for thumbnail files that belong to unregistered image sizes or whose original image no longer exists.', 'media-cleanup' ); ?>
            </p>
            <button type="button" id="jemc-scan-orphans" class="button button-primary button-hero">
                <?php echo esc_html__( 'Scan for Orphan Thumbnails', 'media-cleanup' ); ?>
            </button>
        </div>

        <div id="jemc-orphan-progress" class="jemc-progress-wrap" style="display:none;">
            <div class="jemc-progress-bar">
                <div class="jemc-progress-fill jemc-progress-indeterminate"></div>
            </div>
            <p class="jemc-progress-status"><?php echo esc_html__( 'Scanning uploads directory...', 'media-cleanup' ); ?></p>
        </div>

        <div id="jemc-orphan-summary" class="jemc-results-summary" style="display:none;">
            <div class="jemc-summary-box">
                <span id="jemc-orphan-summary-text"></span>
            </div>
        </div>

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

        <div id="jemc-orphan-delete-progress" class="jemc-progress-wrap" style="display:none;">
            <div class="jemc-progress-bar">
                <div id="jemc-orphan-delete-fill" class="jemc-progress-fill jemc-progress-danger" style="width:0%;">
                    <span id="jemc-orphan-delete-text" class="jemc-progress-text">0%</span>
                </div>
            </div>
            <p id="jemc-orphan-delete-status" class="jemc-progress-status"></p>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- Duplicates Tab -->
    <!-- ============================================================ -->
    <div id="duplicates" class="jemc-tab-content">
        <div class="jemc-scan-section">
            <p class="jemc-description">
                <?php echo esc_html__( 'Find duplicate media files by comparing file content. Duplicates waste disk space and can be safely removed (keeping one copy).', 'media-cleanup' ); ?>
            </p>
            <button type="button" id="jemc-scan-duplicates" class="button button-primary button-hero">
                <?php echo esc_html__( 'Scan for Duplicates', 'media-cleanup' ); ?>
            </button>
        </div>

        <div id="jemc-dup-progress" class="jemc-progress-wrap" style="display:none;">
            <div class="jemc-progress-bar">
                <div class="jemc-progress-fill jemc-progress-indeterminate"></div>
            </div>
            <p class="jemc-progress-status"><?php echo esc_html__( 'Scanning files for duplicates...', 'media-cleanup' ); ?></p>
        </div>

        <div id="jemc-dup-summary" class="jemc-results-summary" style="display:none;">
            <div class="jemc-summary-box">
                <span id="jemc-dup-summary-text"></span>
            </div>
        </div>

        <div id="jemc-dup-results" style="display:none;"></div>
    </div>

    <!-- ============================================================ -->
    <!-- Broken Links Tab -->
    <!-- ============================================================ -->
    <div id="broken-links" class="jemc-tab-content">
        <div class="jemc-scan-section">
            <p class="jemc-description">
                <?php echo esc_html__( 'Scan your content for references to media files that no longer exist. These broken links can cause missing images on your site.', 'media-cleanup' ); ?>
            </p>
            <button type="button" id="jemc-scan-broken" class="button button-primary button-hero">
                <?php echo esc_html__( 'Scan for Broken Links', 'media-cleanup' ); ?>
            </button>
        </div>

        <div id="jemc-broken-progress" class="jemc-progress-wrap" style="display:none;">
            <div class="jemc-progress-bar">
                <div class="jemc-progress-fill jemc-progress-indeterminate"></div>
            </div>
            <p class="jemc-progress-status"><?php echo esc_html__( 'Scanning for broken media references...', 'media-cleanup' ); ?></p>
        </div>

        <div id="jemc-broken-summary" class="jemc-results-summary" style="display:none;">
            <div class="jemc-summary-box">
                <span id="jemc-broken-summary-text"></span>
            </div>
        </div>

        <div id="jemc-broken-table-wrap" style="display:none;">
            <table class="wp-list-table widefat striped jemc-results-table">
                <thead>
                    <tr>
                        <th class="jemc-col-name"><?php echo esc_html__( 'Broken URL', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-type"><?php echo esc_html__( 'Source Type', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-name"><?php echo esc_html__( 'Source', 'media-cleanup' ); ?></th>
                    </tr>
                </thead>
                <tbody id="jemc-broken-body"></tbody>
            </table>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- Quarantine Tab -->
    <!-- ============================================================ -->
    <div id="quarantine" class="jemc-tab-content">
        <div class="jemc-scan-section">
            <p class="jemc-description">
                <?php echo esc_html__( 'Quarantined files have been removed from the media library but kept on disk for recovery. You can restore them or permanently delete them.', 'media-cleanup' ); ?>
            </p>
            <button type="button" id="jemc-load-quarantine" class="button button-primary button-hero">
                <?php echo esc_html__( 'Load Quarantine', 'media-cleanup' ); ?>
            </button>
        </div>

        <div id="jemc-quarantine-summary" class="jemc-results-summary" style="display:none;">
            <div class="jemc-summary-box">
                <span id="jemc-quarantine-summary-text"></span>
            </div>
        </div>

        <div id="jemc-quarantine-toolbar" class="jemc-toolbar" style="display:none;">
            <div class="jemc-toolbar-left">
                <label class="jemc-select-all-label">
                    <input type="checkbox" id="jemc-quarantine-select-all" />
                    <?php echo esc_html__( 'Select All', 'media-cleanup' ); ?>
                </label>
                <button type="button" id="jemc-restore-quarantine" class="button button-primary">
                    <?php echo esc_html__( 'Restore Selected', 'media-cleanup' ); ?>
                </button>
                <button type="button" id="jemc-delete-quarantine" class="button jemc-button-danger">
                    <?php echo esc_html__( 'Delete Permanently', 'media-cleanup' ); ?>
                </button>
            </div>
        </div>

        <div id="jemc-quarantine-table-wrap" style="display:none;">
            <table class="wp-list-table widefat striped jemc-results-table">
                <thead>
                    <tr>
                        <th class="jemc-col-check"><input type="checkbox" id="jemc-quarantine-select-all-head" /></th>
                        <th class="jemc-col-name"><?php echo esc_html__( 'File Name', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-type"><?php echo esc_html__( 'Type', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-size"><?php echo esc_html__( 'Size', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-date"><?php echo esc_html__( 'Quarantined', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-path"><?php echo esc_html__( 'Original Path', 'media-cleanup' ); ?></th>
                    </tr>
                </thead>
                <tbody id="jemc-quarantine-body"></tbody>
            </table>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- Optimizer Tab -->
    <!-- ============================================================ -->
    <div id="optimizer" class="jemc-tab-content">
        <div class="jemc-scan-section">
            <p class="jemc-description">
                <?php echo esc_html__( 'Find oversized images and strip unnecessary EXIF metadata to reduce disk usage. Images larger than the maximum dimension will be resized while preserving aspect ratio.', 'media-cleanup' ); ?>
            </p>
            <div class="jemc-optimizer-options">
                <label>
                    <?php echo esc_html__( 'Max dimension (px):', 'media-cleanup' ); ?>
                    <input type="number" id="jemc-max-dimension" value="2560" min="100" max="10000" step="10" class="small-text" />
                </label>
                <label>
                    <input type="checkbox" id="jemc-strip-exif" checked />
                    <?php echo esc_html__( 'Strip EXIF metadata', 'media-cleanup' ); ?>
                </label>
            </div>
            <br />
            <button type="button" id="jemc-scan-optimizable" class="button button-primary button-hero">
                <?php echo esc_html__( 'Scan for Optimizable Images', 'media-cleanup' ); ?>
            </button>
        </div>

        <div id="jemc-opt-progress" class="jemc-progress-wrap" style="display:none;">
            <div class="jemc-progress-bar">
                <div class="jemc-progress-fill jemc-progress-indeterminate"></div>
            </div>
            <p class="jemc-progress-status"><?php echo esc_html__( 'Scanning images...', 'media-cleanup' ); ?></p>
        </div>

        <div id="jemc-opt-summary" class="jemc-results-summary" style="display:none;">
            <div class="jemc-summary-box">
                <span id="jemc-opt-summary-text"></span>
            </div>
        </div>

        <div id="jemc-opt-toolbar" class="jemc-toolbar" style="display:none;">
            <div class="jemc-toolbar-left">
                <label class="jemc-select-all-label">
                    <input type="checkbox" id="jemc-opt-select-all" />
                    <?php echo esc_html__( 'Select All', 'media-cleanup' ); ?>
                </label>
                <button type="button" id="jemc-optimize-selected" class="button button-primary">
                    <?php echo esc_html__( 'Optimize Selected', 'media-cleanup' ); ?>
                </button>
            </div>
        </div>

        <div id="jemc-opt-table-wrap" style="display:none;">
            <table class="wp-list-table widefat striped jemc-results-table">
                <thead>
                    <tr>
                        <th class="jemc-col-check"><input type="checkbox" id="jemc-opt-select-all-head" /></th>
                        <th class="jemc-col-thumb"><?php echo esc_html__( 'Preview', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-name"><?php echo esc_html__( 'File Name', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-size"><?php echo esc_html__( 'Current Size', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-dims"><?php echo esc_html__( 'Dimensions', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-size"><?php echo esc_html__( 'Est. Savings', 'media-cleanup' ); ?></th>
                        <th class="jemc-col-type"><?php echo esc_html__( 'Reason', 'media-cleanup' ); ?></th>
                    </tr>
                </thead>
                <tbody id="jemc-opt-body"></tbody>
            </table>
        </div>

        <div id="jemc-opt-action-progress" class="jemc-progress-wrap" style="display:none;">
            <div class="jemc-progress-bar">
                <div id="jemc-opt-action-fill" class="jemc-progress-fill" style="width:0%;">
                    <span id="jemc-opt-action-text" class="jemc-progress-text">0%</span>
                </div>
            </div>
            <p id="jemc-opt-action-status" class="jemc-progress-status"></p>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- Settings Tab -->
    <!-- ============================================================ -->
    <div id="settings" class="jemc-tab-content">
        <div class="jemc-scan-section">
            <h2><?php echo esc_html__( 'Scheduled Scans', 'media-cleanup' ); ?></h2>
            <p class="jemc-description">
                <?php echo esc_html__( 'Schedule automatic scans and receive email reports with a summary of unused media, duplicates, and potential space savings.', 'media-cleanup' ); ?>
            </p>
            <table class="form-table jemc-settings-table">
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Enable scheduled scans', 'media-cleanup' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="jemc-schedule-enabled" />
                            <?php echo esc_html__( 'Run scans automatically', 'media-cleanup' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Frequency', 'media-cleanup' ); ?></th>
                    <td>
                        <select id="jemc-schedule-frequency">
                            <option value="daily"><?php echo esc_html__( 'Daily', 'media-cleanup' ); ?></option>
                            <option value="weekly" selected><?php echo esc_html__( 'Weekly', 'media-cleanup' ); ?></option>
                            <option value="monthly"><?php echo esc_html__( 'Monthly', 'media-cleanup' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Report email', 'media-cleanup' ); ?></th>
                    <td>
                        <input type="email" id="jemc-schedule-email" class="regular-text" value="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>" />
                        <p class="description"><?php echo esc_html__( 'Email address to receive scan reports.', 'media-cleanup' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php echo esc_html__( 'Last scan', 'media-cleanup' ); ?></th>
                    <td>
                        <span id="jemc-schedule-last-run"><?php echo esc_html__( 'Never', 'media-cleanup' ); ?></span>
                    </td>
                </tr>
            </table>
            <p>
                <button type="button" id="jemc-save-schedule" class="button button-primary">
                    <?php echo esc_html__( 'Save Settings', 'media-cleanup' ); ?>
                </button>
            </p>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- Confirmation Modal -->
    <!-- ============================================================ -->
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

    <div id="jemc-notices" class="jemc-notices"></div>
</div>
