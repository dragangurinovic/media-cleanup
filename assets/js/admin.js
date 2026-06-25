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
        dupGroups: [],
        brokenLinks: [],
        quarantineItems: [],
        selectedQuarantineKeys: [],
        optItems: [],
        selectedOptIds: [],
        isScanning: false,
        isDeleting: false
    };

    function init() {
        bindTabs();
        bindScanButton();
        bindToolbar();
        bindModal();
        bindOrphanTab();
        bindDuplicatesTab();
        bindBrokenLinksTab();
        bindQuarantineTab();
        bindOptimizerTab();
        bindSettingsTab();
    }

    // =========================================================================
    // Tabs
    // =========================================================================

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

    // =========================================================================
    // Scanning (Unused Media)
    // =========================================================================

    function bindScanButton() {
        $('#jemc-start-scan').on('click', function () {
            if (state.isScanning) return;
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

            var pct = Math.round((data.step / totalSteps) * 90) + 5;
            var stepLabels = {
                'post_content': 'Scanning post content...',
                'post_meta': 'Scanning post meta fields...',
                'options': 'Scanning options table...',
                'widgets': 'Scanning widget data...',
                'term_meta': 'Scanning term meta...',
                'theme_css': 'Scanning theme CSS files...',
                'page_builders': 'Scanning page builder data...',
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

        $('#jemc-summary-text').text(
            'Found ' + data.unusedCount + ' unused media files (' + data.totalSize + ' total)'
        );
        $('#jemc-results-summary').show();
        renderMimeChips(data.mimeSummary);
        $('#jemc-toolbar').show();
        loadResultsPage(1);
    }

    // =========================================================================
    // MIME Filter Chips
    // =========================================================================

    function renderMimeChips(summary) {
        var $container = $('#jemc-mime-filters').empty();

        if (!summary || !summary.length) {
            $container.hide();
            return;
        }

        summary.sort(function (a, b) { return b.count - a.count; });

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
        applyFiltersToSelection();
    }

    function applyFiltersToSelection() {
        if (state.activeMimeFilters.length === 0) {
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

    // =========================================================================
    // Results Table
    // =========================================================================

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
                escHtml(jemcData.i18n.noUnused) + '</td></tr>'
            );
            return;
        }

        $.each(items, function (i, item) {
            var isSelected = state.selectedIds.indexOf(item.id) > -1;
            var mimeGroup = getMimeGroup(item.mime_type);
            var hidden = false;

            if (state.searchQuery) {
                var q = state.searchQuery.toLowerCase();
                var searchable = (item.filename + ' ' + item.mime_type + ' ' + item.relative_path).toLowerCase();
                if (searchable.indexOf(q) === -1) hidden = true;
            }

            var thumbHtml = buildThumbHtml(item);
            var editLink = item.edit_url
                ? '<a href="' + escAttr(item.edit_url) + '" class="jemc-filename-link" target="_blank">' + escHtml(item.filename) + '</a>'
                : escHtml(item.filename);

            var $row = $(
                '<tr data-id="' + item.id + '" data-mime-group="' + escAttr(mimeGroup) + '"' +
                (isSelected ? ' class="jemc-row-selected"' : '') +
                (hidden ? ' style="display:none;"' : '') + '>' +
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

        $('#jemc-results-body .jemc-row-check').on('change', function () {
            var $row = $(this).closest('tr');
            var id = parseInt($row.data('id'), 10);
            var checked = $(this).is(':checked');

            $row.toggleClass('jemc-row-selected', checked);

            if (checked && state.selectedIds.indexOf(id) === -1) {
                state.selectedIds.push(id);
            } else if (!checked) {
                var idx = state.selectedIds.indexOf(id);
                if (idx > -1) state.selectedIds.splice(idx, 1);
            }
            syncSelectAll();
        });
    }

    function renderPagination(current, total, totalItems) {
        var $container = $('#jemc-pagination').empty();
        if (total <= 1) { $container.hide(); return; }

        $container.append(
            '<button type="button" class="jemc-page-btn' + (current <= 1 ? ' disabled' : '') + '" data-page="' + (current - 1) + '">&laquo; Prev</button>'
        );

        var start = Math.max(1, current - 3);
        var end = Math.min(total, current + 3);

        if (start > 1) {
            $container.append('<button type="button" class="jemc-page-btn" data-page="1">1</button>');
            if (start > 2) $container.append('<span class="jemc-page-info">...</span>');
        }

        for (var p = start; p <= end; p++) {
            $container.append(
                '<button type="button" class="jemc-page-btn' + (p === current ? ' active' : '') + '" data-page="' + p + '">' + p + '</button>'
            );
        }

        if (end < total) {
            if (end < total - 1) $container.append('<span class="jemc-page-info">...</span>');
            $container.append('<button type="button" class="jemc-page-btn" data-page="' + total + '">' + total + '</button>');
        }

        $container.append(
            '<button type="button" class="jemc-page-btn' + (current >= total ? ' disabled' : '') + '" data-page="' + (current + 1) + '">Next &raquo;</button>'
        );
        $container.append('<span class="jemc-page-info">(' + totalItems + ' items)</span>');
        $container.show();

        $container.find('.jemc-page-btn').not('.disabled').on('click', function () {
            var pg = parseInt($(this).data('page'), 10);
            if (pg >= 1 && pg <= total) {
                loadResultsPage(pg);
                $('html, body').animate({ scrollTop: $('#jemc-results-table-wrap').offset().top - 40 }, 200);
            }
        });
    }

    // =========================================================================
    // Toolbar
    // =========================================================================

    function bindToolbar() {
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
                    if (idx > -1) state.selectedIds.splice(idx, 1);
                }
            });
        });

        $('#jemc-trash-selected').on('click', function () {
            if (state.selectedIds.length === 0) { showNotice(jemcData.i18n.selectItems, 'info'); return; }
            var size = calculateSelectedSize();
            showModal('Move to Trash',
                jemcData.i18n.confirmTrash.replace('%d', state.selectedIds.length).replace('%s', formatBytes(size)),
                function () { deleteSelected(false); }
            );
        });

        $('#jemc-delete-selected').on('click', function () {
            if (state.selectedIds.length === 0) { showNotice(jemcData.i18n.selectItems, 'info'); return; }
            var size = calculateSelectedSize();
            showModal('Permanently Delete',
                jemcData.i18n.confirmDelete.replace('%d', state.selectedIds.length).replace('%s', formatBytes(size)),
                function () { deleteSelected(true); }
            );
        });

        $('#jemc-quarantine-selected').on('click', function () {
            if (state.selectedIds.length === 0) { showNotice(jemcData.i18n.selectItems, 'info'); return; }
            var size = calculateSelectedSize();
            showModal('Move to Quarantine',
                jemcData.i18n.confirmQuarantine.replace('%d', state.selectedIds.length).replace('%s', formatBytes(size)),
                function () { quarantineSelected(); }
            );
        });

        $('#jemc-export-csv').on('click', function () { exportCsv(); });

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
            if (!q) { $row.show(); return; }
            $row.toggle($row.text().toLowerCase().indexOf(q) > -1);
        });
    }

    function calculateSelectedSize() {
        var size = 0;
        $.each(state.allItems, function (i, item) {
            if (state.selectedIds.indexOf(item.id) > -1) size += item.file_size;
        });
        return size;
    }

    // =========================================================================
    // Deletion
    // =========================================================================

    function deleteSelected(forceDelete) {
        if (state.isDeleting || state.selectedIds.length === 0) return;

        state.isDeleting = true;
        var ids = state.selectedIds.slice();
        var batchSize = 10;
        var totalToDelete = ids.length;
        var deletedCount = 0;
        var failedCount = 0;

        $('#jemc-delete-progress').show();
        $('#jemc-toolbar button').prop('disabled', true);

        function deleteBatch() {
            if (ids.length === 0) {
                state.isDeleting = false;
                state.selectedIds = [];
                $('#jemc-delete-progress-fill').css('width', '100%');
                $('#jemc-delete-progress-text').text('100%');
                $('#jemc-delete-status').text(jemcData.i18n.deleteComplete);
                $('#jemc-toolbar button').prop('disabled', false);

                showNotice(
                    deletedCount + ' item(s) deleted.' + (failedCount > 0 ? ' ' + failedCount + ' failed.' : ''),
                    failedCount > 0 ? 'error' : 'success'
                );

                setTimeout(function () {
                    $('#jemc-delete-progress').slideUp();
                    loadResultsPage(1);
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
                } else {
                    failedCount += batch.length;
                }

                var pct = Math.round(((totalToDelete - ids.length) / totalToDelete) * 100);
                $('#jemc-delete-progress-fill').css('width', pct + '%');
                $('#jemc-delete-progress-text').text(pct + '%');
                $('#jemc-delete-status').text(jemcData.i18n.deleting + ' ' + (totalToDelete - ids.length) + '/' + totalToDelete);

                deleteBatch();
            })
            .fail(function () {
                failedCount += batch.length;
                deleteBatch();
            });
        }

        deleteBatch();
    }

    // =========================================================================
    // Quarantine from Unused Media tab
    // =========================================================================

    function quarantineSelected() {
        if (state.selectedIds.length === 0) return;

        var ids = state.selectedIds.slice();
        var batchSize = 10;
        var totalCount = ids.length;
        var movedCount = 0;
        var failedCount = 0;

        $('#jemc-delete-progress').show();
        $('#jemc-toolbar button').prop('disabled', true);

        function quarantineBatch() {
            if (ids.length === 0) {
                state.selectedIds = [];
                $('#jemc-delete-progress-fill').css('width', '100%');
                $('#jemc-delete-progress-text').text('100%');
                $('#jemc-delete-status').text(jemcData.i18n.quarantineComplete);
                $('#jemc-toolbar button').prop('disabled', false);

                showNotice(movedCount + ' item(s) quarantined.' + (failedCount > 0 ? ' ' + failedCount + ' failed.' : ''), 'success');

                setTimeout(function () {
                    $('#jemc-delete-progress').slideUp();
                    loadResultsPage(1);
                    state.total -= movedCount;
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
                action: 'jemc_quarantine_media',
                nonce: jemcData.nonce,
                ids: batch
            })
            .done(function (response) {
                if (response.success) {
                    movedCount += response.data.moved;
                    failedCount += response.data.failed;
                } else {
                    failedCount += batch.length;
                }

                var pct = Math.round(((totalCount - ids.length) / totalCount) * 100);
                $('#jemc-delete-progress-fill').css('width', pct + '%');
                $('#jemc-delete-progress-text').text(pct + '%');
                $('#jemc-delete-status').text('Quarantining... ' + (totalCount - ids.length) + '/' + totalCount);

                quarantineBatch();
            })
            .fail(function () {
                failedCount += batch.length;
                quarantineBatch();
            });
        }

        quarantineBatch();
    }

    // =========================================================================
    // CSV Export
    // =========================================================================

    function exportCsv() {
        $.post(jemcData.ajaxUrl, { action: 'jemc_export_csv', nonce: jemcData.nonce })
        .done(function (response) {
            if (!response.success) {
                showNotice(response.data ? response.data.message : jemcData.i18n.error, 'error');
                return;
            }

            var rows = response.data.csv;
            var csvContent = '';
            $.each(rows, function (i, row) {
                csvContent += row.map(function (cell) {
                    return '"' + String(cell).replace(/"/g, '""') + '"';
                }).join(',') + '\r\n';
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
        .fail(function () { showNotice(jemcData.i18n.error, 'error'); });
    }

    // =========================================================================
    // Orphan Thumbnails
    // =========================================================================

    function bindOrphanTab() {
        $('#jemc-scan-orphans').on('click', function () { scanOrphans(); });

        $('#jemc-orphan-select-all, #jemc-orphan-select-all-head').on('change', function () {
            var checked = $(this).is(':checked');
            $('#jemc-orphan-select-all, #jemc-orphan-select-all-head').prop('checked', checked);
            $('#jemc-orphan-body .jemc-orphan-check').prop('checked', checked);
            $('#jemc-orphan-body tr').toggleClass('jemc-row-selected', checked);

            state.selectedOrphanPaths = [];
            if (checked) {
                $.each(state.orphanResults, function (i, o) { state.selectedOrphanPaths.push(o.path); });
            }
        });

        $('#jemc-delete-orphans').on('click', function () {
            if (state.selectedOrphanPaths.length === 0) { showNotice(jemcData.i18n.selectItems, 'info'); return; }
            var size = 0;
            $.each(state.orphanResults, function (i, o) {
                if (state.selectedOrphanPaths.indexOf(o.path) > -1) size += o.size;
            });
            showModal('Delete Orphan Thumbnails',
                jemcData.i18n.confirmOrphanDel.replace('%d', state.selectedOrphanPaths.length).replace('%s', formatBytes(size)),
                function () { deleteOrphans(); }
            );
        });
    }

    function scanOrphans() {
        $('#jemc-scan-orphans').prop('disabled', true);
        $('#jemc-orphan-progress').show();
        $('#jemc-orphan-summary, #jemc-orphan-toolbar, #jemc-orphan-table-wrap').hide();

        $.post(jemcData.ajaxUrl, { action: 'jemc_scan_orphan_thumbnails', nonce: jemcData.nonce })
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

            $('#jemc-orphan-summary-text').text('Found ' + data.total + ' orphan thumbnail files (' + data.totalSize + ' total)');
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
            $body.append(
                '<tr data-path="' + escAttr(item.path) + '">' +
                '<td class="jemc-col-check"><input type="checkbox" class="jemc-orphan-check" value="' + escAttr(item.path) + '" /></td>' +
                '<td class="jemc-col-name">' + escHtml(item.filename) + '</td>' +
                '<td class="jemc-col-dims">' + item.width + ' x ' + item.height + '</td>' +
                '<td class="jemc-col-size">' + formatBytes(item.size) + '</td>' +
                '<td class="jemc-col-path jemc-path-cell">' + escHtml(item.relative_path) + '</td>' +
                '<td class="jemc-col-reason">' + escHtml(item.reason) + '</td>' +
                '</tr>'
            );
        });

        $body.find('.jemc-orphan-check').on('change', function () {
            var path = $(this).val();
            var checked = $(this).is(':checked');
            $(this).closest('tr').toggleClass('jemc-row-selected', checked);

            if (checked && state.selectedOrphanPaths.indexOf(path) === -1) {
                state.selectedOrphanPaths.push(path);
            } else if (!checked) {
                var idx = state.selectedOrphanPaths.indexOf(path);
                if (idx > -1) state.selectedOrphanPaths.splice(idx, 1);
            }

            var all = $body.find('.jemc-orphan-check').length;
            var chk = $body.find('.jemc-orphan-check:checked').length;
            $('#jemc-orphan-select-all, #jemc-orphan-select-all-head').prop('checked', all === chk);
        });
    }

    function deleteOrphans() {
        if (state.selectedOrphanPaths.length === 0) return;

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

                showNotice(deletedCount + ' file(s) deleted.' + (failedCount > 0 ? ' ' + failedCount + ' failed.' : ''), failedCount > 0 ? 'error' : 'success');

                setTimeout(function () { $('#jemc-orphan-delete-progress').slideUp(); scanOrphans(); }, 1000);
                return;
            }

            var batch = paths.splice(0, batchSize);

            $.post(jemcData.ajaxUrl, { action: 'jemc_delete_orphan_thumbnails', nonce: jemcData.nonce, paths: batch })
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
                $('#jemc-orphan-delete-status').text(jemcData.i18n.deleting + ' ' + (total - paths.length) + '/' + total);
                nextBatch();
            })
            .fail(function () { failedCount += batch.length; nextBatch(); });
        }

        nextBatch();
    }

    // =========================================================================
    // Duplicates Tab
    // =========================================================================

    function bindDuplicatesTab() {
        $('#jemc-scan-duplicates').on('click', function () { scanDuplicates(); });
    }

    function scanDuplicates() {
        $('#jemc-scan-duplicates').prop('disabled', true);
        $('#jemc-dup-progress').show();
        $('#jemc-dup-summary, #jemc-dup-results').hide();

        $.post(jemcData.ajaxUrl, { action: 'jemc_scan_duplicates', nonce: jemcData.nonce })
        .done(function (response) {
            $('#jemc-dup-progress').hide();
            $('#jemc-scan-duplicates').prop('disabled', false);

            if (!response.success) {
                showNotice(response.data ? response.data.message : jemcData.i18n.error, 'error');
                return;
            }

            var data = response.data;
            state.dupGroups = data.groups;

            if (!data.groups || data.groups.length === 0) {
                $('#jemc-dup-summary').show();
                $('#jemc-dup-summary-text').text(jemcData.i18n.noDuplicates);
                return;
            }

            var s = data.summary;
            $('#jemc-dup-summary-text').text(
                'Found ' + s.total_groups + ' duplicate group(s) with ' + s.total_duplicate_files +
                ' extra file(s) wasting ' + s.total_wasted_size_hr
            );
            $('#jemc-dup-summary').show();
            renderDuplicateGroups(data.groups);
            $('#jemc-dup-results').show();
        })
        .fail(function () {
            $('#jemc-dup-progress').hide();
            $('#jemc-scan-duplicates').prop('disabled', false);
            showNotice(jemcData.i18n.error, 'error');
        });
    }

    function renderDuplicateGroups(groups) {
        var $container = $('#jemc-dup-results').empty();

        $.each(groups, function (i, group) {
            var $card = $('<div class="jemc-dup-group"></div>');
            $card.append(
                '<div class="jemc-dup-group-header">' +
                '<strong>Group ' + (i + 1) + '</strong> &mdash; ' +
                group.count + ' files, ' + formatBytes(group.wasted_size) + ' wasted' +
                '</div>'
            );

            var $table = $(
                '<table class="wp-list-table widefat striped jemc-results-table">' +
                '<thead><tr>' +
                '<th class="jemc-col-check"><input type="checkbox" class="jemc-dup-group-check" data-group="' + i + '" /></th>' +
                '<th class="jemc-col-thumb">Preview</th>' +
                '<th class="jemc-col-name">File Name</th>' +
                '<th class="jemc-col-type">Type</th>' +
                '<th class="jemc-col-size">Size</th>' +
                '<th class="jemc-col-date">Date</th>' +
                '<th class="jemc-col-path">Path</th>' +
                '</tr></thead><tbody></tbody></table>'
            );

            var $tbody = $table.find('tbody');
            $.each(group.files, function (j, file) {
                var thumbHtml = buildThumbHtml(file);
                var editLink = file.edit_url
                    ? '<a href="' + escAttr(file.edit_url) + '" target="_blank">' + escHtml(file.filename) + '</a>'
                    : escHtml(file.filename);

                $tbody.append(
                    '<tr data-id="' + file.id + '">' +
                    '<td class="jemc-col-check">' + (j > 0 ? '<input type="checkbox" class="jemc-dup-check" value="' + file.id + '" />' : '<span class="jemc-keep-badge">Keep</span>') + '</td>' +
                    '<td class="jemc-col-thumb">' + thumbHtml + '</td>' +
                    '<td class="jemc-col-name">' + editLink + '</td>' +
                    '<td class="jemc-col-type">' + escHtml(file.mime_type) + '</td>' +
                    '<td class="jemc-col-size">' + escHtml(file.file_size_hr) + '</td>' +
                    '<td class="jemc-col-date">' + escHtml(formatDate(file.upload_date)) + '</td>' +
                    '<td class="jemc-col-path jemc-path-cell">' + escHtml(file.relative_path) + '</td>' +
                    '</tr>'
                );
            });

            $card.append($table);

            var $actions = $(
                '<div class="jemc-dup-group-actions">' +
                '<button type="button" class="button jemc-button-danger jemc-dup-delete-group" data-group="' + i + '">Delete Selected Duplicates</button>' +
                '</div>'
            );
            $card.append($actions);
            $container.append($card);
        });

        // Group select-all checkboxes
        $container.find('.jemc-dup-group-check').on('change', function () {
            var checked = $(this).is(':checked');
            $(this).closest('table').find('.jemc-dup-check').prop('checked', checked)
                .closest('tr').toggleClass('jemc-row-selected', checked);
        });

        $container.find('.jemc-dup-check').on('change', function () {
            $(this).closest('tr').toggleClass('jemc-row-selected', $(this).is(':checked'));
        });

        // Delete duplicates
        $container.find('.jemc-dup-delete-group').on('click', function () {
            var groupIdx = parseInt($(this).data('group'), 10);
            var $group = $container.find('.jemc-dup-group').eq(groupIdx);
            var ids = [];
            $group.find('.jemc-dup-check:checked').each(function () {
                ids.push(parseInt($(this).val(), 10));
            });

            if (ids.length === 0) { showNotice(jemcData.i18n.selectItems, 'info'); return; }

            showModal('Delete Duplicates',
                'Permanently delete ' + ids.length + ' duplicate file(s)?',
                function () {
                    $.post(jemcData.ajaxUrl, {
                        action: 'jemc_delete_media',
                        nonce: jemcData.nonce,
                        ids: ids,
                        force_delete: 1
                    })
                    .done(function (response) {
                        if (response.success) {
                            showNotice(response.data.deleted + ' duplicate(s) deleted.', 'success');
                            scanDuplicates();
                        } else {
                            showNotice(jemcData.i18n.error, 'error');
                        }
                    })
                    .fail(function () { showNotice(jemcData.i18n.error, 'error'); });
                }
            );
        });
    }

    // =========================================================================
    // Broken Links Tab
    // =========================================================================

    function bindBrokenLinksTab() {
        $('#jemc-scan-broken').on('click', function () { scanBrokenLinks(); });
    }

    function scanBrokenLinks() {
        $('#jemc-scan-broken').prop('disabled', true);
        $('#jemc-broken-progress').show();
        $('#jemc-broken-summary, #jemc-broken-table-wrap').hide();

        $.post(jemcData.ajaxUrl, { action: 'jemc_scan_broken_links', nonce: jemcData.nonce })
        .done(function (response) {
            $('#jemc-broken-progress').hide();
            $('#jemc-scan-broken').prop('disabled', false);

            if (!response.success) {
                showNotice(response.data ? response.data.message : jemcData.i18n.error, 'error');
                return;
            }

            state.brokenLinks = response.data.links;

            if (response.data.total === 0) {
                $('#jemc-broken-summary').show();
                $('#jemc-broken-summary-text').text(jemcData.i18n.noBrokenLinks);
                return;
            }

            $('#jemc-broken-summary-text').text('Found ' + response.data.total + ' broken media reference(s)');
            $('#jemc-broken-summary').show();

            var $body = $('#jemc-broken-body').empty();
            $.each(response.data.links, function (i, link) {
                var sourceLink = link.source_edit_url
                    ? '<a href="' + escAttr(link.source_edit_url) + '" target="_blank">' + escHtml(link.source_title) + '</a>'
                    : escHtml(link.source_title);

                $body.append(
                    '<tr>' +
                    '<td class="jemc-col-name jemc-path-cell">' + escHtml(link.url) + '</td>' +
                    '<td class="jemc-col-type">' + escHtml(link.source_type) + '</td>' +
                    '<td class="jemc-col-name">' + sourceLink + '</td>' +
                    '</tr>'
                );
            });
            $('#jemc-broken-table-wrap').show();
        })
        .fail(function () {
            $('#jemc-broken-progress').hide();
            $('#jemc-scan-broken').prop('disabled', false);
            showNotice(jemcData.i18n.error, 'error');
        });
    }

    // =========================================================================
    // Quarantine Tab
    // =========================================================================

    function bindQuarantineTab() {
        $('#jemc-load-quarantine').on('click', function () { loadQuarantine(); });

        $('#jemc-quarantine-select-all, #jemc-quarantine-select-all-head').on('change', function () {
            var checked = $(this).is(':checked');
            $('#jemc-quarantine-select-all, #jemc-quarantine-select-all-head').prop('checked', checked);
            $('#jemc-quarantine-body .jemc-quarantine-check').prop('checked', checked)
                .closest('tr').toggleClass('jemc-row-selected', checked);

            state.selectedQuarantineKeys = [];
            if (checked) {
                $.each(state.quarantineItems, function (i, item) {
                    state.selectedQuarantineKeys.push(item.key);
                });
            }
        });

        $('#jemc-restore-quarantine').on('click', function () {
            if (state.selectedQuarantineKeys.length === 0) { showNotice(jemcData.i18n.selectItems, 'info'); return; }
            showModal('Restore Files',
                'Restore ' + state.selectedQuarantineKeys.length + ' file(s) back to the media library?',
                function () { restoreQuarantine(); }
            );
        });

        $('#jemc-delete-quarantine').on('click', function () {
            if (state.selectedQuarantineKeys.length === 0) { showNotice(jemcData.i18n.selectItems, 'info'); return; }
            showModal('Delete Permanently',
                'Permanently delete ' + state.selectedQuarantineKeys.length + ' quarantined file(s)? This cannot be undone!',
                function () { deleteQuarantine(); }
            );
        });
    }

    function loadQuarantine() {
        $.post(jemcData.ajaxUrl, { action: 'jemc_get_quarantine', nonce: jemcData.nonce })
        .done(function (response) {
            if (!response.success) {
                showNotice(response.data ? response.data.message : jemcData.i18n.error, 'error');
                return;
            }

            state.quarantineItems = response.data.items;
            state.selectedQuarantineKeys = [];
            var summary = response.data.summary;

            if (!response.data.items || response.data.items.length === 0) {
                $('#jemc-quarantine-summary').show();
                $('#jemc-quarantine-summary-text').text(jemcData.i18n.noQuarantineItems);
                $('#jemc-quarantine-toolbar, #jemc-quarantine-table-wrap').hide();
                return;
            }

            $('#jemc-quarantine-summary-text').text(
                summary.total_items + ' quarantined file(s) (' + summary.total_size_hr + ' total)'
            );
            $('#jemc-quarantine-summary').show();
            $('#jemc-quarantine-toolbar').show();

            var $body = $('#jemc-quarantine-body').empty();
            $.each(response.data.items, function (i, item) {
                $body.append(
                    '<tr data-key="' + escAttr(item.key) + '">' +
                    '<td class="jemc-col-check"><input type="checkbox" class="jemc-quarantine-check" value="' + escAttr(item.key) + '" /></td>' +
                    '<td class="jemc-col-name">' + escHtml(item.filename) + '</td>' +
                    '<td class="jemc-col-type">' + escHtml(item.mime_type) + '</td>' +
                    '<td class="jemc-col-size">' + formatBytes(item.file_size) + '</td>' +
                    '<td class="jemc-col-date">' + escHtml(formatDate(item.date)) + '</td>' +
                    '<td class="jemc-col-path jemc-path-cell">' + escHtml(item.original_path) + '</td>' +
                    '</tr>'
                );
            });
            $('#jemc-quarantine-table-wrap').show();

            $body.find('.jemc-quarantine-check').on('change', function () {
                var key = $(this).val();
                var checked = $(this).is(':checked');
                $(this).closest('tr').toggleClass('jemc-row-selected', checked);

                if (checked && state.selectedQuarantineKeys.indexOf(key) === -1) {
                    state.selectedQuarantineKeys.push(key);
                } else if (!checked) {
                    var idx = state.selectedQuarantineKeys.indexOf(key);
                    if (idx > -1) state.selectedQuarantineKeys.splice(idx, 1);
                }
            });
        })
        .fail(function () { showNotice(jemcData.i18n.error, 'error'); });
    }

    function restoreQuarantine() {
        $.post(jemcData.ajaxUrl, {
            action: 'jemc_restore_quarantine',
            nonce: jemcData.nonce,
            keys: state.selectedQuarantineKeys
        })
        .done(function (response) {
            if (response.success) {
                showNotice(response.data.restored + ' file(s) restored.' + (response.data.failed > 0 ? ' ' + response.data.failed + ' failed.' : ''), 'success');
                loadQuarantine();
            } else {
                showNotice(jemcData.i18n.error, 'error');
            }
        })
        .fail(function () { showNotice(jemcData.i18n.error, 'error'); });
    }

    function deleteQuarantine() {
        $.post(jemcData.ajaxUrl, {
            action: 'jemc_delete_quarantine',
            nonce: jemcData.nonce,
            keys: state.selectedQuarantineKeys
        })
        .done(function (response) {
            if (response.success) {
                showNotice(response.data.deleted + ' file(s) permanently deleted.', 'success');
                loadQuarantine();
            } else {
                showNotice(jemcData.i18n.error, 'error');
            }
        })
        .fail(function () { showNotice(jemcData.i18n.error, 'error'); });
    }

    // =========================================================================
    // Optimizer Tab
    // =========================================================================

    function bindOptimizerTab() {
        $('#jemc-scan-optimizable').on('click', function () { scanOptimizable(); });

        $('#jemc-opt-select-all, #jemc-opt-select-all-head').on('change', function () {
            var checked = $(this).is(':checked');
            $('#jemc-opt-select-all, #jemc-opt-select-all-head').prop('checked', checked);
            $('#jemc-opt-body .jemc-opt-check').prop('checked', checked)
                .closest('tr').toggleClass('jemc-row-selected', checked);

            state.selectedOptIds = [];
            if (checked) {
                $.each(state.optItems, function (i, item) { state.selectedOptIds.push(item.id); });
            }
        });

        $('#jemc-optimize-selected').on('click', function () {
            if (state.selectedOptIds.length === 0) { showNotice(jemcData.i18n.selectItems, 'info'); return; }
            showModal('Optimize Images',
                jemcData.i18n.confirmOptimize.replace('%d', state.selectedOptIds.length),
                function () { optimizeImages(); }
            );
        });
    }

    function scanOptimizable() {
        $('#jemc-scan-optimizable').prop('disabled', true);
        $('#jemc-opt-progress').show();
        $('#jemc-opt-summary, #jemc-opt-toolbar, #jemc-opt-table-wrap').hide();

        $.post(jemcData.ajaxUrl, { action: 'jemc_scan_optimizable', nonce: jemcData.nonce })
        .done(function (response) {
            $('#jemc-opt-progress').hide();
            $('#jemc-scan-optimizable').prop('disabled', false);

            if (!response.success) {
                showNotice(response.data ? response.data.message : jemcData.i18n.error, 'error');
                return;
            }

            state.optItems = response.data.items;
            state.selectedOptIds = [];

            if (response.data.total === 0) {
                $('#jemc-opt-summary').show();
                $('#jemc-opt-summary-text').text(jemcData.i18n.noOptimizable);
                return;
            }

            $('#jemc-opt-summary-text').text(
                'Found ' + response.data.total + ' optimizable image(s) — estimated savings: ' + response.data.totalPotential
            );
            $('#jemc-opt-summary').show();
            $('#jemc-opt-toolbar').show();

            var $body = $('#jemc-opt-body').empty();
            $.each(response.data.items, function (i, item) {
                var thumbHtml = item.thumbnail
                    ? '<img src="' + escAttr(item.thumbnail) + '" class="jemc-thumb-img" alt="" />'
                    : '<span class="dashicons dashicons-format-image" style="font-size:36px;width:36px;height:36px;color:#c3c4c7;"></span>';

                var reasons = [];
                if (item.is_oversized) reasons.push('Oversized');
                if (item.has_exif) reasons.push('Has EXIF');

                $body.append(
                    '<tr data-id="' + item.id + '">' +
                    '<td class="jemc-col-check"><input type="checkbox" class="jemc-opt-check" value="' + item.id + '" /></td>' +
                    '<td class="jemc-col-thumb">' + thumbHtml + '</td>' +
                    '<td class="jemc-col-name">' + escHtml(item.filename) + '</td>' +
                    '<td class="jemc-col-size">' + formatBytes(item.current_size) + '</td>' +
                    '<td class="jemc-col-dims">' + escHtml(item.current_dimensions) + '</td>' +
                    '<td class="jemc-col-size">' + formatBytes(item.potential_savings) + '</td>' +
                    '<td class="jemc-col-type">' + escHtml(reasons.join(', ')) + '</td>' +
                    '</tr>'
                );
            });
            $('#jemc-opt-table-wrap').show();

            $body.find('.jemc-opt-check').on('change', function () {
                var id = parseInt($(this).val(), 10);
                var checked = $(this).is(':checked');
                $(this).closest('tr').toggleClass('jemc-row-selected', checked);

                if (checked && state.selectedOptIds.indexOf(id) === -1) {
                    state.selectedOptIds.push(id);
                } else if (!checked) {
                    var idx = state.selectedOptIds.indexOf(id);
                    if (idx > -1) state.selectedOptIds.splice(idx, 1);
                }
            });
        })
        .fail(function () {
            $('#jemc-opt-progress').hide();
            $('#jemc-scan-optimizable').prop('disabled', false);
            showNotice(jemcData.i18n.error, 'error');
        });
    }

    function optimizeImages() {
        if (state.selectedOptIds.length === 0) return;

        var ids = state.selectedOptIds.slice();
        var batchSize = 5;
        var total = ids.length;
        var optimized = 0;
        var failed = 0;
        var totalSaved = 0;

        var maxDim = parseInt($('#jemc-max-dimension').val(), 10) || 2560;
        var stripExif = $('#jemc-strip-exif').is(':checked') ? 1 : 0;

        $('#jemc-opt-action-progress').show();
        $('#jemc-opt-toolbar button').prop('disabled', true);

        function nextBatch() {
            if (ids.length === 0) {
                state.selectedOptIds = [];
                $('#jemc-opt-action-fill').css('width', '100%');
                $('#jemc-opt-action-text').text('100%');
                $('#jemc-opt-action-status').text(jemcData.i18n.optimizeComplete);
                $('#jemc-opt-toolbar button').prop('disabled', false);

                showNotice(
                    optimized + ' image(s) optimized, ' + formatBytes(totalSaved) + ' saved.' +
                    (failed > 0 ? ' ' + failed + ' failed.' : ''),
                    failed > 0 ? 'error' : 'success'
                );

                setTimeout(function () { $('#jemc-opt-action-progress').slideUp(); }, 2000);
                return;
            }

            var batch = ids.splice(0, batchSize);

            $.post(jemcData.ajaxUrl, {
                action: 'jemc_optimize_images',
                nonce: jemcData.nonce,
                ids: batch,
                max_dimension: maxDim,
                strip_exif: stripExif
            })
            .done(function (response) {
                if (response.success) {
                    optimized += response.data.optimized;
                    failed += response.data.failed;
                    totalSaved += response.data.total_saved || 0;
                } else {
                    failed += batch.length;
                }

                var pct = Math.round(((total - ids.length) / total) * 100);
                $('#jemc-opt-action-fill').css('width', pct + '%');
                $('#jemc-opt-action-text').text(pct + '%');
                $('#jemc-opt-action-status').text(jemcData.i18n.optimizing + ' ' + (total - ids.length) + '/' + total);
                nextBatch();
            })
            .fail(function () { failed += batch.length; nextBatch(); });
        }

        nextBatch();
    }

    // =========================================================================
    // Settings Tab
    // =========================================================================

    function bindSettingsTab() {
        $('#jemc-save-schedule').on('click', function () { saveSchedule(); });

        // Load current settings when Settings tab is first clicked
        $('.jemc-tabs .nav-tab[data-tab="settings"]').one('click', function () {
            loadScheduleSettings();
        });
    }

    function loadScheduleSettings() {
        $.post(jemcData.ajaxUrl, { action: 'jemc_get_schedule', nonce: jemcData.nonce })
        .done(function (response) {
            if (response.success) {
                var s = response.data;
                $('#jemc-schedule-enabled').prop('checked', s.enabled);
                $('#jemc-schedule-frequency').val(s.frequency);
                if (s.email) $('#jemc-schedule-email').val(s.email);
                if (s.last_run) {
                    $('#jemc-schedule-last-run').text(s.last_run);
                }
            }
        });
    }

    function saveSchedule() {
        $.post(jemcData.ajaxUrl, {
            action: 'jemc_save_schedule',
            nonce: jemcData.nonce,
            enabled: $('#jemc-schedule-enabled').is(':checked') ? 1 : 0,
            frequency: $('#jemc-schedule-frequency').val(),
            email: $('#jemc-schedule-email').val()
        })
        .done(function (response) {
            if (response.success) {
                showNotice(jemcData.i18n.scheduleSaved, 'success');
            } else {
                showNotice(jemcData.i18n.error, 'error');
            }
        })
        .fail(function () { showNotice(jemcData.i18n.error, 'error'); });
    }

    // =========================================================================
    // Modal
    // =========================================================================

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
            if (typeof onConfirm === 'function') onConfirm();
        });
    }

    function hideModal() {
        $('#jemc-modal').hide();
    }

    // =========================================================================
    // Notices
    // =========================================================================

    function showNotice(message, type) {
        type = type || 'info';
        var $notice = $('<div class="jemc-notice jemc-notice-' + type + '">' + escHtml(message) + '</div>');
        $('#jemc-notices').prepend($notice);
        setTimeout(function () {
            $notice.fadeOut(300, function () { $notice.remove(); });
        }, 6000);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    function escHtml(str) {
        if (str === null || str === undefined) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(String(str)));
        return div.innerHTML;
    }

    function escAttr(str) {
        return escHtml(str).replace(/"/g, '&quot;');
    }

    function formatBytes(bytes) {
        if (!bytes || bytes === 0) return '0 B';
        var units = ['B', 'KB', 'MB', 'GB'];
        var i = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
        return (bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
    }

    function formatDate(dateStr) {
        if (!dateStr) return '-';
        var d = new Date(dateStr);
        if (isNaN(d.getTime())) return dateStr;
        return d.toLocaleDateString();
    }

    function getMimeGroup(mime) {
        if (!mime) return 'Other';
        var map = {
            'image/jpeg': 'JPEG', 'image/jpg': 'JPEG', 'image/png': 'PNG',
            'image/gif': 'GIF', 'image/webp': 'WebP', 'image/svg+xml': 'SVG',
            'image/avif': 'AVIF', 'application/pdf': 'PDF'
        };
        if (map[mime]) return map[mime];
        if (mime.indexOf('video/') === 0) return 'Video';
        if (mime.indexOf('audio/') === 0) return 'Audio';
        if (mime.indexOf('image/') === 0) return 'Image';
        return 'Other';
    }

    function buildThumbHtml(item) {
        if (item.thumbnail && item.mime_type && item.mime_type.indexOf('image/') === 0) {
            return '<img src="' + escAttr(item.thumbnail) + '" class="jemc-thumb-img" alt="" />';
        } else if (item.thumbnail) {
            return '<img src="' + escAttr(item.thumbnail) + '" class="jemc-thumb-icon" alt="" />';
        }
        return '<span class="dashicons dashicons-media-default" style="font-size:36px;width:36px;height:36px;color:#c3c4c7;"></span>';
    }

    // =========================================================================
    // DOM Ready
    // =========================================================================

    $(document).ready(init);

})(jQuery);
