/**
 * Media Cleanup – Admin JavaScript
 *
 * @package MediaCleanup
 */

/* global jQuery, jemcData */
(function ($) {
    'use strict';

    var state = {
        currentPage: 1,
        totalPages: 1,
        total: 0,
        totalSizeRaw: 0,
        mimeSummary: [],
        activeMimeFilters: [],
        allItems: [],
        searchQuery: '',
        selectedIds: [],
        orphanResults: [],
        selectedOrphanPaths: [],
        isScanning: false,
        isDeleting: false
    };

    /**
     * Initialize the plugin UI.
     */
    function init() {
        bindTabs();
        bindScanButton();
        bindToolbar();
        bindModal();
        bindOrphanTab();
    }

    // -------------------------------------------------------------------------
    // Tabs
    // -------------------------------------------------------------------------

    function bindTabs() {
        $('.jemc-tabs .nav-tab').on('click', function (e) {
            e.preventDefault();
            var tab = $(this).data('tab');
            $('.jemc-tabs .nav-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            $('.jemc-tab-content').removeClass('jemc-tab-active');
            $('#' + tab).addClass('jemc-tab-active');
        });
    }

    // -------------------------------------------------------------------------
    // Scanning
    // -------------------------------------------------------------------------

    function bindScanButton() {
        $('#jemc-start-scan').on('click', function () {
            if (state.isScanning) {
                return;
            }
            startScan();
        });
    }

    function startScan() {
        state.isScanning = true;
        state.selectedIds = [];
        state.activeMimeFilters = [];

        $('#jemc-start-scan').prop('disabled', true).text(jemcData.i18n.scanning);
        $('#jemc-progress-wrap').show();
        $('#jemc-results-summary, #jemc-mime-filters, #jemc-toolbar, #jemc-results-table-wrap, #jemc-pagination').hide();
        updateProgress(0, 'Starting scan...');

        $.post(jemcData.ajaxUrl, {
            action: 'jemc_start_scan',
            nonce: jemcData.nonce
        })
        .done(function (response) {
            if (response.success) {
                updateProgress(5, 'Found ' + response.data.total + ' media files. Scanning usage...');
                processScanBatch(response.data.totalSteps);
            } else {
                scanError(response.data ? response.data.message : jemcData.i18n.error);
            }
        })
        .fail(function () {
            scanError(jemcData.i18n.error);
        });
    }

    function processScanBatch(totalSteps) {
        $.post(jemcData.ajaxUrl, {
            action: 'jemc_scan_batch',
            nonce: jemcData.nonce
        })
        .done(function (response) {
            if (!response.success) {
                scanError(response.data ? response.data.message : jemcData.i18n.error);
                return;
            }

            var data = response.data;

            if (data.complete && data.unusedCount !== undefined) {
                // Scan finished.
                updateProgress(100, jemcData.i18n.scanComplete);
                state.total = data.unusedCount;
                state.totalSizeRaw = data.totalSizeRaw;
                state.mimeSummary = data.mimeSummary || [];

                setTimeout(function () {
                    $('#jemc-progress-wrap').slideUp();
                    showResults(data);
                }, 500);
                return;
            }

            // Still processing.
            var pct = Math.round((data.step / totalSteps) * 90) + 5;
            var stepLabels = {
                'post_content': 'Scanning post content...',
                'post_meta': 'Scanning post meta fields...',
                'options': 'Scanning options table...',
                'widgets': 'Scanning widget data...',
                'term_meta': 'Scanning term meta...',
                'finalize': 'Calculating results...'
            };
            var label = stepLabels[data.stepName] || 'Processing...';
            updateProgress(pct, label);

            processScanBatch(totalSteps);
        })
        .fail(function () {
            scanError(jemcData.i18n.error);
        });
    }

    function updateProgress(percent, text) {
        $('#jemc-progress-fill').css('width', percent + '%');
        $('#jemc-progress-text').text(percent + '%');
        $('#jemc-progress-status').text(text);
    }

    function scanError(message) {
        state.isScanning = false;
        $('#jemc-start-scan').prop('disabled', false).text('Scan for Unused Media');
        $('#jemc-progress-wrap').hide();
        showNotice(message, 'error');
    }

    function showResults(data) {
        state.isScanning = false;
        $('#jemc-start-scan').prop('disabled', false).text('Scan for Unused Media');

        if (data.unusedCount === 0) {
            $('#jemc-results-summary').show();
            $('#jemc-summary-text').text(jemcData.i18n.noUnused);
            return;
        }

        // Summary.
        $('#jemc-summary-text').text(
            'Found ' + data.unusedCount + ' unused media files (' + data.totalSize + ' total)'
        );
        $('#jemc-results-summary').show();

        // MIME chips.
        renderMimeChips(data.mimeSummary);

        // Show toolbar.
        $('#jemc-toolbar').show();

        // Load first page.
        loadResultsPage(1);
    }

    // -------------------------------------------------------------------------
    // MIME Filter Chips
    // -------------------------------------------------------------------------

    function renderMimeChips(summary) {
        var $container = $('#jemc-mime-filters').empty();

        if (!summary || !summary.length) {
            $container.hide();
            return;
        }

        summary.sort(function (a, b) {
            return b.count - a.count;
        });

        $.each(summary, function (i, item) {
            var $chip = $('<button type="button" class="jemc-mime-chip"></button>');
            $chip.attr('data-mime', item.label);
            $chip.html(
                '<span class="jemc-chip-label">' + escHtml(item.label) + '</span>' +
                '<span class="jemc-chip-count">' + item.count + '</span>' +
                '<span class="jemc-chip-size">' + formatBytes(item.size) + '</span>'
            );
            $chip.on('click', function () {
                toggleMimeFilter(item.label, $chip);
            });
            $container.append($chip);
        });

        $container.show();
    }

    function toggleMimeFilter(mime, $chip) {
        var idx = state.activeMimeFilters.indexOf(mime);
        if (idx > -1) {
            state.activeMimeFilters.splice(idx, 1);
            $chip.removeClass('active');
        } else {
            state.activeMimeFilters.push(mime);
            $chip.addClass('active');
        }

        // Select/deselect matching items in the current table view.
        applyFiltersToSelection();
    }

    function applyFiltersToSelection() {
        if (state.activeMimeFilters.length === 0) {
            // Deselect all.
            state.selectedIds = [];
            $('#jemc-results-body .jemc-row-check').prop('checked', false);
            $('#jemc-results-body tr').removeClass('jemc-row-selected');
            syncSelectAll();
            return;
        }

        $('#jemc-results-body tr').each(function () {
            var $row = $(this);
            var mime = $row.data('mime-group');
            var id = parseInt($row.data('id'), 10);
            var shouldSelect = state.activeMimeFilters.indexOf(mime) > -1;

            $row.find('.jemc-row-check').prop('checked', shouldSelect);
            $row.toggleClass('jemc-row-selected', shouldSelect);

            var selIdx = state.selectedIds.indexOf(id);
            if (shouldSelect && selIdx === -1) {
                state.selectedIds.push(id);
            } else if (!shouldSelect && selIdx > -1) {
                state.selectedIds.splice(selIdx, 1);
            }
        });

        syncSelectAll();
    }

    // -------------------------------------------------------------------------
    // Results Table
    // -------------------------------------------------------------------------

    function loadResultsPage(page) {
        state.currentPage = page;

        $.post(jemcData.ajaxUrl, {
            action: 'jemc_get_results',
            nonce: jemcData.nonce,
            page: page,
            per_page: 50
        })
        .done(function (response) {
            if (!response.success) {
                showNotice(response.data ? response.data.message : jemcData.i18n.error, 'error');
                return;
            }

            var data = response.data;
            state.allItems = data.items;
            state.totalPages = data.totalPages;
            state.total = data.total;

            renderTable(data.items);
            renderPagination(data.page, data.totalPages, data.total);
            $('#jemc-results-table-wrap').show();
        })
        .fail(function () {
            showNotice(jemcData.i18n.error, 'error');
        });
    }

    function renderTable(items) {
        var $body = $('#jemc-results-body').empty();

        if (!items || !items.length) {
            $body.append(
                '<tr><td colspan="8" style="text-align:center;padding:20px;">' +
                escHtml(jemcData.i18n.noUnused) +
                '</td></tr>'
            );
            return;
        }

        $.each(items, function (i, item) {
            var isSelected = state.selectedIds.indexOf(item.id) > -1;
            var mimeGroup = getMimeGroup(item.mime_type);
            var hidden = false;

            // Apply search filter.
            if (state.searchQuery) {
                var q = state.searchQuery.toLowerCase();
                var searchable = (item.filename + ' ' + item.mime_type + ' ' + item.relative_path).toLowerCase();
                if (searchable.indexOf(q) === -1) {
                    hidden = true;
                }
            }

            var thumbHtml;
            if (item.thumbnail && item.mime_type && item.mime_type.indexOf('image/') === 0) {
                thumbHtml = '<img src="' + escAttr(item.thumbnail) + '" class="jemc-thumb-img" alt="" />';
            } else if (item.thumbnail) {
                thumbHtml = '<img src="' + escAttr(item.thumbnail) + '" class="jemc-thumb-icon" alt="" />';
            } else {
                thumbHtml = '<span class="dashicons dashicons-media-default" style="font-size:36px;width:36px;height:36px;color:#c3c4c7;"></span>';
            }

            var editLink = item.edit_url
                ? '<a href="' + escAttr(item.edit_url) + '" class="jemc-filename-link" target="_blank">' + escHtml(item.filename) + '</a>'
                : escHtml(item.filename);

            var $row = $(
                '<tr data-id="' + item.id + '" data-mime-group="' + escAttr(mimeGroup) + '"' +
                (isSelected ? ' class="jemc-row-selected"' : '') +
                (hidden ? ' style="display:none;"' : '') +
                '>' +
                '<td class="jemc-col-check"><input type="checkbox" class="jemc-row-check" value="' + item.id + '"' + (isSelected ? ' checked' : '') + ' /></td>' +
                '<td class="jemc-col-thumb">' + thumbHtml + '</td>' +
                '<td class="jemc-col-name">' + editLink + '</td>' +
                '<td class="jemc-col-type">' + escHtml(item.mime_type) + '</td>' +
                '<td class="jemc-col-size">' + escHtml(item.file_size_hr) + '</td>' +
                '<td class="jemc-col-date">' + escHtml(formatDate(item.upload_date)) + '</td>' +
                '<td class="jemc-col-dims">' + escHtml(item.dimensions || '-') + '</td>' +
                '<td class="jemc-col-path jemc-path-cell">' + escHtml(item.relative_path) + '</td>' +
                '</tr>'
            );

            $body.append($row);
        });

        // Bind row checkboxes.
        $('#jemc-results-body .jemc-row-check').on('change', function () {
            var $row = $(this).closest('tr');
            var id = parseInt($row.data('id'), 10);
            var checked = $(this).is(':checked');

            $row.toggleClass('jemc-row-selected', checked);

            if (checked && state.selectedIds.indexOf(id) === -1) {
                state.selectedIds.push(id);
            } else if (!checked) {
                var idx = state.selectedIds.indexOf(id);
                if (idx > -1) {
                    state.selectedIds.splice(idx, 1);
                }
            }

            syncSelectAll();
        });
    }

    function renderPagination(current, total, totalItems) {
        var $container = $('#jemc-pagination').empty();

        if (total <= 1) {
            $container.hide();
            return;
        }

        var prevDisabled = current <= 1 ? ' disabled' : '';
        var nextDisabled = current >= total ? ' disabled' : '';

        $container.append(
            '<button type="button" class="jemc-page-btn' + (current <= 1 ? ' disabled' : '') + '" data-page="' + (current - 1) + '">&laquo; Prev</button>'
        );

        var start = Math.max(1, current - 3);
        var end = Math.min(total, current + 3);

        if (start > 1) {
            $container.append('<button type="button" class="jemc-page-btn" data-page="1">1</button>');
            if (start > 2) {
                $container.append('<span class="jemc-page-info">...</span>');
            }
        }

        for (var p = start; p <= end; p++) {
            $container.append(
                '<button type="button" class="jemc-page-btn' + (p === current ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>'
            );
        }

        if (end < total) {
            if (end < total - 1) {
                $container.append('<span class="jemc-page-info">...</span>');
            }
            $container.append('<button type="button" class="jemc-page-btn" data-page="' + total + '">' + total + '</button>');
        }

        $container.append(
            '<button type="button" class="jemc-page-btn' + (current >= total ? ' disabled' : '') + '" data-page="' + (current + 1) + '">Next &raquo;</button>'
        );

        $container.append(
            '<span class="jemc-page-info">(' + totalItems + ' items)</span>'
        );

        $container.show();

        $container.find('.jemc-page-btn').not('.disabled').on('click', function () {
            var pg = parseInt($(this).data('page'), 10);
            if (pg >= 1 && pg <= total) {
                loadResultsPage(pg);
                $('html, body').animate({ scrollTop: $('#jemc-results-table-wrap').offset().top - 40 }, 200);
            }
        });
    }

    // -------------------------------------------------------------------------
    // Toolbar
    // -------------------------------------------------------------------------

    function bindToolbar() {
        // Select all.
        $('#jemc-select-all, #jemc-select-all-head').on('change', function () {
            var checked = $(this).is(':checked');
            $('#jemc-select-all, #jemc-select-all-head').prop('checked', checked);

            $('#jemc-results-body .jemc-row-check:visible').prop('checked', checked).each(function () {
                var $row = $(this).closest('tr');
                var id = parseInt($row.data('id'), 10);
                $row.toggleClass('jemc-row-selected', checked);

                if (checked && state.selectedIds.indexOf(id) === -1) {
                    state.selectedIds.push(id);
                } else if (!checked) {
                    var idx = state.selectedIds.indexOf(id);
                    if (idx > -1) {
                        state.selectedIds.splice(idx, 1);
                    }
                }
            });
        });

        // Trash selected.
        $('#jemc-trash-selected').on('click', function () {
            if (state.selectedIds.length === 0) {
                showNotice(jemcData.i18n.selectItems, 'info');
                return;
            }
            var size = calculateSelectedSize();
            showModal(
                'Move to Trash',
                jemcData.i18n.confirmTrash.replace('%d', state.selectedIds.length).replace('%s', formatBytes(size)),
                function () {
                    deleteSelected(false);
                }
            );
        });

        // Permanently delete.
        $('#jemc-delete-selected').on('click', function () {
            if (state.selectedIds.length === 0) {
                showNotice(jemcData.i18n.selectItems, 'info');
                return;
            }
            var size = calculateSelectedSize();
            showModal(
                'Permanently Delete',
                jemcData.i18n.confirmDelete.replace('%d', state.selectedIds.length).replace('%s', formatBytes(size)),
                function () {
                    deleteSelected(true);
                }
            );
        });

        // Export CSV.
        $('#jemc-export-csv').on('click', function () {
            exportCsv();
        });

        // Search.
        var searchTimer;
        $('#jemc-search').on('input', function () {
            clearTimeout(searchTimer);
            var val = $(this).val();
            searchTimer = setTimeout(function () {
                state.searchQuery = val;
                filterTableBySearch();
            }, 300);
        });
    }

    function syncSelectAll() {
        var visible = $('#jemc-results-body .jemc-row-check:visible');
        var checked = $('#jemc-results-body .jemc-row-check:visible:checked');
        var allChecked = visible.length > 0 && visible.length === checked.length;
        $('#jemc-select-all, #jemc-select-all-head').prop('checked', allChecked);
    }

    function filterTableBySearch() {
        var q = state.searchQuery.toLowerCase();
        $('#jemc-results-body tr').each(function () {
            var $row = $(this);
            if (!q) {
                $row.show();
                return;
            }
            var text = $row.text().toLowerCase();
            $row.toggle(text.indexOf(q) > -1);
        });
    }

    function calculateSelectedSize() {
        var size = 0;
        $.each(state.allItems, function (i, item) {
            if (state.selectedIds.indexOf(item.id) > -1) {
                size += item.file_size;
            }
        });
        return size;
    }

    // -------------------------------------------------------------------------
    // Deletion
    // -------------------------------------------------------------------------

    function deleteSelected(forceDelete) {
        if (state.isDeleting || state.selectedIds.length === 0) {
            return;
        }

        state.isDeleting = true;
        var ids = state.selectedIds.slice();
        var batchSize = 10;
        var totalToDelete = ids.length;
        var deletedCount = 0;
        var failedCount = 0;
        var errors = [];

        $('#jemc-delete-progress').show();
        $('#jemc-toolbar button').prop('disabled', true);

        function deleteBatch() {
            if (ids.length === 0) {
                // Done.
                state.isDeleting = false;
                state.selectedIds = [];

                var pct = 100;
                $('#jemc-delete-progress-fill').css('width', pct + '%');
                $('#jemc-delete-progress-text').text(pct + '%');
                $('#jemc-delete-status').text(jemcData.i18n.deleteComplete);
                $('#jemc-toolbar button').prop('disabled', false);

                showNotice(
                    deletedCount + ' item(s) deleted.' + (failedCount > 0 ? ' ' + failedCount + ' failed.' : ''),
                    failedCount > 0 ? 'error' : 'success'
                );

                setTimeout(function () {
                    $('#jemc-delete-progress').slideUp();
                    // Reload results.
                    loadResultsPage(1);
                    // Update summary.
                    state.total -= deletedCount;
                    if (state.total <= 0) {
                        $('#jemc-results-summary, #jemc-mime-filters, #jemc-toolbar, #jemc-results-table-wrap, #jemc-pagination').hide();
                        $('#jemc-summary-text').text(jemcData.i18n.noUnused);
                        $('#jemc-results-summary').show();
                    }
                }, 1000);
                return;
            }

            var batch = ids.splice(0, batchSize);

            $.post(jemcData.ajaxUrl, {
                action: 'jemc_delete_media',
                nonce: jemcData.nonce,
                ids: batch,
                force_delete: forceDelete ? 1 : 0
            })
            .done(function (response) {
                if (response.success) {
                    deletedCount += response.data.deleted;
                    failedCount += response.data.failed;
                    if (response.data.errors) {
                        errors = errors.concat(response.data.errors);
                    }
                } else {
                    failedCount += batch.length;
                }

                var pct = Math.round(((totalToDelete - ids.length) / totalToDelete) * 100);
                $('#jemc-delete-progress-fill').css('width', pct + '%');
                $('#jemc-delete-progress-text').text(pct + '%');
                $('#jemc-delete-status').text(
                    jemcData.i18n.deleting + ' ' + (totalToDelete - ids.length) + '/' + totalToDelete
                );

                deleteBatch();
            })
            .fail(function () {
                failedCount += batch.length;
                deleteBatch();
            });
        }

        deleteBatch();
    }

    // -------------------------------------------------------------------------
    // CSV Export
    // -------------------------------------------------------------------------

    function exportCsv() {
        $.post(jemcData.ajaxUrl, {
            action: 'jemc_export_csv',
            nonce: jemcData.nonce
        })
        .done(function (response) {
            if (!response.success) {
                showNotice(response.data ? response.data.message : jemcData.i18n.error, 'error');
                return;
            }

            var rows = response.data.csv;
            var csvContent = '';

            $.each(rows, function (i, row) {
                var line = row.map(function (cell) {
                    var str = String(cell).replace(/"/g, '""');
                    return '"' + str + '"';
                }).join(',');
                csvContent += line + '\r\n';
            });

            var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            var url = URL.createObjectURL(blob);
            var link = document.createElement('a');
            link.setAttribute('href', url);
            link.setAttribute('download', 'unused-media-' + new Date().toISOString().slice(0, 10) + '.csv');
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);

            showNotice('CSV exported successfully.', 'success');
        })
        .fail(function () {
            showNotice(jemcData.i18n.error, 'error');
        });
    }

    // -------------------------------------------------------------------------
    // Orphan Thumbnails
    // -------------------------------------------------------------------------

    function bindOrphanTab() {
        $('#jemc-scan-orphans').on('click', function () {
            scanOrphans();
        });

        $('#jemc-orphan-select-all, #jemc-orphan-select-all-head').on('change', function () {
            var checked = $(this).is(':checked');
            $('#jemc-orphan-select-all, #jemc-orphan-select-all-head').prop('checked', checked);
            $('#jemc-orphan-body .jemc-orphan-check').prop('checked', checked);
            $('#jemc-orphan-body tr').toggleClass('jemc-row-selected', checked);

            state.selectedOrphanPaths = [];
            if (checked) {
                $.each(state.orphanResults, function (i, o) {
                    state.selectedOrphanPaths.push(o.path);
                });
            }
        });

        $('#jemc-delete-orphans').on('click', function () {
            if (state.selectedOrphanPaths.length === 0) {
                showNotice(jemcData.i18n.selectItems, 'info');
                return;
            }

            var size = 0;
            $.each(state.orphanResults, function (i, o) {
                if (state.selectedOrphanPaths.indexOf(o.path) > -1) {
                    size += o.size;
                }
            });

            showModal(
                'Delete Orphan Thumbnails',
                jemcData.i18n.confirmOrphanDel
                    .replace('%d', state.selectedOrphanPaths.length)
                    .replace('%s', formatBytes(size)),
                function () {
                    deleteOrphans();
                }
            );
        });
    }

    function scanOrphans() {
        $('#jemc-scan-orphans').prop('disabled', true);
        $('#jemc-orphan-progress').show();
        $('#jemc-orphan-summary, #jemc-orphan-toolbar, #jemc-orphan-table-wrap').hide();

        $.post(jemcData.ajaxUrl, {
            action: 'jemc_scan_orphan_thumbnails',
            nonce: jemcData.nonce
        })
        .done(function (response) {
            $('#jemc-orphan-progress').hide();
            $('#jemc-scan-orphans').prop('disabled', false);

            if (!response.success) {
                showNotice(response.data ? response.data.message : jemcData.i18n.error, 'error');
                return;
            }

            var data = response.data;
            state.orphanResults = data.orphans;
            state.selectedOrphanPaths = [];

            if (data.total === 0) {
                $('#jemc-orphan-summary').show();
                $('#jemc-orphan-summary-text').text(jemcData.i18n.noOrphans);
                return;
            }

            $('#jemc-orphan-summary-text').text(
                'Found ' + data.total + ' orphan thumbnail files (' + data.totalSize + ' total)'
            );
            $('#jemc-orphan-summary').show();
            $('#jemc-orphan-toolbar').show();

            renderOrphanTable(data.orphans);
            $('#jemc-orphan-table-wrap').show();
        })
        .fail(function () {
            $('#jemc-orphan-progress').hide();
            $('#jemc-scan-orphans').prop('disabled', false);
            showNotice(jemcData.i18n.error, 'error');
        });
    }

    function renderOrphanTable(orphans) {
        var $body = $('#jemc-orphan-body').empty();

        $.each(orphans, function (i, item) {
            var $row = $(
                '<tr data-path="' + escAttr(item.path) + '">' +
                '<td class="jemc-col-check"><input type="checkbox" class="jemc-orphan-check" value="' + escAttr(item.path) + '" /></td>' +
                '<td class="jemc-col-name">' + escHtml(item.filename) + '</td>' +
                '<td class="jemc-col-dims">' + item.width + ' x ' + item.height + '</td>' +
                '<td class="jemc-col-size">' + formatBytes(item.size) + '</td>' +
                '<td class="jemc-col-path jemc-path-cell">' + escHtml(item.relative_path) + '</td>' +
                '<td class="jemc-col-reason">' + escHtml(item.reason) + '</td>' +
                '</tr>'
            );

            $body.append($row);
        });

        $body.find('.jemc-orphan-check').on('change', function () {
            var path = $(this).val();
            var checked = $(this).is(':checked');
            $(this).closest('tr').toggleClass('jemc-row-selected', checked);

            if (checked && state.selectedOrphanPaths.indexOf(path) === -1) {
                state.selectedOrphanPaths.push(path);
            } else if (!checked) {
                var idx = state.selectedOrphanPaths.indexOf(path);
                if (idx > -1) {
                    state.selectedOrphanPaths.splice(idx, 1);
                }
            }

            var allVisible = $body.find('.jemc-orphan-check').length;
            var allChecked = $body.find('.jemc-orphan-check:checked').length;
            $('#jemc-orphan-select-all, #jemc-orphan-select-all-head').prop('checked', allVisible === allChecked);
        });
    }

    function deleteOrphans() {
        if (state.selectedOrphanPaths.length === 0) {
            return;
        }

        var paths = state.selectedOrphanPaths.slice();
        var batchSize = 10;
        var total = paths.length;
        var deletedCount = 0;
        var failedCount = 0;

        $('#jemc-orphan-delete-progress').show();
        $('#jemc-orphan-toolbar button').prop('disabled', true);

        function nextBatch() {
            if (paths.length === 0) {
                state.selectedOrphanPaths = [];
                $('#jemc-orphan-delete-fill').css('width', '100%');
                $('#jemc-orphan-delete-text').text('100%');
                $('#jemc-orphan-delete-status').text(jemcData.i18n.deleteComplete);
                $('#jemc-orphan-toolbar button').prop('disabled', false);

                showNotice(
                    deletedCount + ' file(s) deleted.' + (failedCount > 0 ? ' ' + failedCount + ' failed.' : ''),
                    failedCount > 0 ? 'error' : 'success'
                );

                setTimeout(function () {
                    $('#jemc-orphan-delete-progress').slideUp();
                    scanOrphans();
                }, 1000);
                return;
            }

            var batch = paths.splice(0, batchSize);

            $.post(jemcData.ajaxUrl, {
                action: 'jemc_delete_orphan_thumbnails',
                nonce: jemcData.nonce,
                paths: batch
            })
            .done(function (response) {
                if (response.success) {
                    deletedCount += response.data.deleted;
                    failedCount += response.data.failed;
                } else {
                    failedCount += batch.length;
                }

                var pct = Math.round(((total - paths.length) / total) * 100);
                $('#jemc-orphan-delete-fill').css('width', pct + '%');
                $('#jemc-orphan-delete-text').text(pct + '%');
                $('#jemc-orphan-delete-status').text(
                    jemcData.i18n.deleting + ' ' + (total - paths.length) + '/' + total
                );

                nextBatch();
            })
            .fail(function () {
                failedCount += batch.length;
                nextBatch();
            });
        }

        nextBatch();
    }

    // -------------------------------------------------------------------------
    // Modal
    // -------------------------------------------------------------------------

    function bindModal() {
        $('#jemc-modal-cancel, .jemc-modal-close, .jemc-modal-overlay').on('click', function () {
            hideModal();
        });
    }

    function showModal(title, message, onConfirm) {
        $('#jemc-modal-title').text(title);
        $('#jemc-modal-body').html('<p>' + escHtml(message) + '</p>');
        $('#jemc-modal').show();

        $('#jemc-modal-confirm').off('click').on('click', function () {
            hideModal();
            if (typeof onConfirm === 'function') {
                onConfirm();
            }
        });
    }

    function hideModal() {
        $('#jemc-modal').hide();
    }

    // -------------------------------------------------------------------------
    // Notices
    // -------------------------------------------------------------------------

    function showNotice(message, type) {
        type = type || 'info';
        var $notice = $(
            '<div class="jemc-notice jemc-notice-' + type + '">' + escHtml(message) + '</div>'
        );
        $('#jemc-notices').prepend($notice);

        setTimeout(function () {
            $notice.fadeOut(300, function () {
                $notice.remove();
            });
        }, 6000);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function escHtml(str) {
        if (str === null || str === undefined) {
            return '';
        }
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function escAttr(str) {
        return escHtml(str).replace(/"/g, '&quot;');
    }

    function formatBytes(bytes) {
        if (!bytes || bytes === 0) {
            return '0 B';
        }
        var units = ['B', 'KB', 'MB', 'GB'];
        var i = Math.floor(Math.log(bytes) / Math.log(1024));
        i = Math.min(i, units.length - 1);
        return (bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
    }

    function formatDate(dateStr) {
        if (!dateStr) {
            return '-';
        }
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) {
            return dateStr;
        }
        return d.toLocaleDateString();
    }

    function getMimeGroup(mime) {
        if (!mime) {
            return 'Other';
        }
        var map = {
            'image/jpeg': 'JPEG',
            'image/jpg': 'JPEG',
            'image/png': 'PNG',
            'image/gif': 'GIF',
            'image/webp': 'WebP',
            'image/svg+xml': 'SVG',
            'image/avif': 'AVIF',
            'application/pdf': 'PDF'
        };
        if (map[mime]) {
            return map[mime];
        }
        if (mime.indexOf('video/') === 0) {
            return 'Video';
        }
        if (mime.indexOf('audio/') === 0) {
            return 'Audio';
        }
        if (mime.indexOf('image/') === 0) {
            return 'Image';
        }
        return 'Other';
    }

    // -------------------------------------------------------------------------
    // DOM Ready
    // -------------------------------------------------------------------------

    $(document).ready(init);

})(jQuery);
