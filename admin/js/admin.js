/**
 * PDF Image Creator - Admin JavaScript
 *
 * @package PDFImageCreator
 */

(function($) {
    'use strict';

    /**
     * Tab functionality
     */
    const Tabs = {
        init: function() {
            $('.rapls-pic-tabs .nav-tab').on('click', this.switchTab);
            $('.rapls-pic-tabs .nav-tab').on('keydown', this.moveWithKeys);

            // Only the chosen tab in the Tab order -- set here, not in the
            // markup, so without this script every tab is still a link the
            // keyboard reaches.
            $('.rapls-pic-tabs .nav-tab').not('.nav-tab-active').attr('tabindex', '-1');

            // Handle hash in URL
            const hash = window.location.hash;
            if (hash && hash.startsWith('#tab-')) {
                const tabName = hash.replace('#tab-', '');
                this.activateTab(tabName);
            }
        },

        switchTab: function(e) {
            e.preventDefault();
            const tabName = $(this).data('tab');
            Tabs.activateTab(tabName);
        },

        // The arrow keys, Home and End move between tabs, as the tab pattern
        // has them; Tab itself goes on to the chosen tab's panel.
        moveWithKeys: function(e) {
            const $tabs = $('.rapls-pic-tabs .nav-tab');
            const at = $tabs.index(this);
            let to = -1;

            if ('ArrowRight' === e.key || 'ArrowDown' === e.key) {
                to = (at + 1) % $tabs.length;
            } else if ('ArrowLeft' === e.key || 'ArrowUp' === e.key) {
                to = (at - 1 + $tabs.length) % $tabs.length;
            } else if ('Home' === e.key) {
                to = 0;
            } else if ('End' === e.key) {
                to = $tabs.length - 1;
            }

            if (to < 0) {
                return;
            }

            e.preventDefault();
            const $to = $tabs.eq(to);
            Tabs.activateTab($to.data('tab'));
            $to.trigger('focus');
        },

        activateTab: function(tabName) {
            // A name no tab has -- "#tab-foo" in the address -- changes
            // nothing: unselecting every tab left none the Tab key could reach.
            if (!$('.rapls-pic-tabs .nav-tab[data-tab="' + tabName + '"]').length) {
                return;
            }

            // Update nav tabs, and what a screen reader is told of them
            // (Codex review of 1.4.26, 4): the chosen one is selected and is
            // the one the Tab key lands on.
            $('.rapls-pic-tabs .nav-tab').removeClass('nav-tab-active').attr('aria-selected', 'false').attr('tabindex', '-1');
            $('.rapls-pic-tabs .nav-tab[data-tab="' + tabName + '"]').addClass('nav-tab-active').attr('aria-selected', 'true').removeAttr('tabindex');

            // Update content
            $('.rapls-pic-tab-content').removeClass('active');
            $('#tab-' + tabName).addClass('active');

            // Update URL hash
            window.location.hash = 'tab-' + tabName;
        }
    };

    /**
     * Range slider functionality
     */
    const RangeSlider = {
        init: function() {
            $('input[type="range"].rapls-pic-range').on('input', function() {
                $(this).next('output').text(this.value);
            });
        }
    };

    /**
     * Bulk processor functionality
     *
     * The library is read a page at a time on the server, from a cursor this
     * hands back: the scan counts, and each generate request finds the next
     * PDF after the cursor and draws it. Nothing here holds the list of PDFs.
     */
    const BulkProcessor = {
        total: 0,
        done: 0,
        after: 0,
        force: false,
        generated: 0,
        failed: 0,
        isRunning: false,
        stopRequested: false,
        canContinue: false,

        init: function() {
            $('#rapls-pic-bulk-scan').on('click', this.scan.bind(this));
            $('#rapls-pic-bulk-start').on('click', this.start.bind(this));
            $('#rapls-pic-bulk-stop').on('click', this.stop.bind(this));
            $('#rapls-pic-bulk-start').data('original-text', $('#rapls-pic-bulk-start').text());
        },

        scanFailed: function(message) {
            $('#rapls-pic-bulk-scan').prop('disabled', false).text($('#rapls-pic-bulk-scan').data('original-text') || raplsPicAdmin.i18n.scan);
            alert(message);
        },

        scan: function() {
            const self = this;
            const includeExisting = $('#rapls-pic-include-existing').is(':checked');

            $('#rapls-pic-bulk-scan').prop('disabled', true).text(raplsPicAdmin.i18n.processing);
            $('#rapls-pic-bulk-start').prop('disabled', true).text($('#rapls-pic-bulk-start').data('original-text'));
            $('#rapls-pic-bulk-results').hide();
            $('#rapls-pic-bulk-progress').hide();
            this.canContinue = false;

            const page = function(after, counts) {
                $.ajax({
                    url: raplsPicAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'rapls_pic_bulk_scan',
                        nonce: raplsPicAdmin.nonce,
                        include_existing: includeExisting ? 1 : 0,
                        after: after,
                        total_pdfs: counts.total_pdfs,
                        with_thumbnail: counts.with_thumbnail
                    },
                    success: function(response) {
                        if (!response.success) {
                            self.scanFailed((response.data && response.data.message) || raplsPicAdmin.i18n.error);
                            return;
                        }

                        if (!response.data.done) {
                            $('#rapls-pic-bulk-scan').text(raplsPicAdmin.i18n.scanning.replace('%d', response.data.total_pdfs));
                            page(response.data.after, response.data);
                            return;
                        }

                        self.showScan(response.data, includeExisting);
                    },
                    error: function(xhr, status, error) {
                        self.scanFailed(raplsPicAdmin.i18n.error + '\n\n' + raplsPicAdmin.i18n.httpStatus.replace('%s', status) + '\n' + raplsPicAdmin.i18n.httpError.replace('%s', error));
                    }
                });
            };

            page(0, {total_pdfs: 0, with_thumbnail: 0});
        },

        showScan: function(data, includeExisting) {
            const self = this;

            $('#rapls-pic-bulk-scan').prop('disabled', false).text($('#rapls-pic-bulk-scan').data('original-text') || raplsPicAdmin.i18n.scan);
            this.total = data.total;
            this.force = includeExisting;
            this.after = 0;
            $('#rapls-pic-bulk-total').text(data.total);

            // Only worth showing when it disagrees with the scan;
            // otherwise it is a second number saying the same thing.
            if (typeof data.rows !== 'undefined' && data.rows !== data.total) {
                $('#rapls-pic-bulk-rows').text(data.rows + (data.statuses ? ' (' + data.statuses + ')' : ''));
                $('#rapls-pic-bulk-rows-row').show();
            } else {
                $('#rapls-pic-bulk-rows-row').hide();
            }

            if (data.note) {
                $('#rapls-pic-bulk-note').text(data.note);

                if (data.retry) {
                    $('<button>')
                        .attr('type', 'button')
                        .addClass('button button-secondary')
                        .css('margin-left', '8px')
                        .text(data.retry_label)
                        .on('click', function() {
                            $('#rapls-pic-include-existing').prop('checked', true);
                            self.scan();
                        })
                        .appendTo('#rapls-pic-bulk-note');
                }

                $('#rapls-pic-bulk-note-row').show();
            } else {
                $('#rapls-pic-bulk-note-row').hide();
            }

            $('#rapls-pic-bulk-results').show();
            $('#rapls-pic-bulk-start').prop('disabled', !(data.total > 0));
        },

        start: function() {
            if (this.total < 1) {
                return;
            }

            // Carrying on after Stop starts from where it stopped, with the
            // counts so far; a fresh start asks first.
            if (!this.canContinue) {
                if (!confirm(raplsPicAdmin.i18n.confirmBulk)) {
                    return;
                }

                this.after = 0;
                this.done = 0;
                this.generated = 0;
                this.failed = 0;
                $('.rapls-pic-log-content').empty();
                this.updateStats();
            }

            this.canContinue = false;
            this.isRunning = true;
            this.stopRequested = false;

            // Update UI
            $('#rapls-pic-bulk-scan').prop('disabled', true);
            $('#rapls-pic-bulk-start').prop('disabled', true).text($('#rapls-pic-bulk-start').data('original-text'));
            $('#rapls-pic-bulk-stop').prop('disabled', false);
            $('#rapls-pic-bulk-progress').show();
            $('#rapls-pic-bulk-log').show();

            this.processNext();
        },

        // The PDF being drawn is not interrupted: the server has already
        // started on it, and its result is still counted. What stops is
        // everything after it, and the screen says so until it has.
        stop: function() {
            if (!this.isRunning) {
                return;
            }

            this.stopRequested = true;
            $('#rapls-pic-bulk-stop').prop('disabled', true);
            this.updateStatus(raplsPicAdmin.i18n.stopping);
        },

        processNext: function() {
            if (this.stopRequested) {
                this.finish(false);
                return;
            }

            const self = this;

            $.ajax({
                url: raplsPicAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rapls_pic_bulk_next',
                    nonce: raplsPicAdmin.nonce,
                    after: this.after,
                    force: this.force ? 1 : 0
                },
                success: function(response) {
                    // Stop pressed while the next PDF was being looked for:
                    // nothing is being drawn, so nothing more starts. The
                    // cursor stays, and Continue looks for the same PDF again
                    // (Codex review of 1.4.26, 2).
                    if (self.stopRequested) {
                        // The end reached meanwhile is the end, not a stop;
                        // empty pages looked through are not looked at again.
                        if (response.success && response.data && !response.data.pdf_id) {
                            self.after = response.data.after;
                            self.finish(!!response.data.done);
                            return;
                        }

                        self.finish(false);
                        return;
                    }

                    if (!response.success) {
                        self.log('✗ ' + ((response.data && response.data.message) || raplsPicAdmin.i18n.requestFailed), 'error');
                        self.finish(false);
                        return;
                    }

                    // Nothing in the pages looked at: on from where the
                    // server got to, or the end.
                    if (!response.data.pdf_id) {
                        self.after = response.data.after;

                        if (response.data.done) {
                            self.finish(true);
                        } else {
                            self.processNext();
                        }

                        return;
                    }

                    self.generate(response.data.pdf_id, response.data.filename);
                },
                error: function() {
                    // Nothing was drawn and the cursor did not move: stopped,
                    // so Continue asks again from the same place.
                    self.log('✗ ' + raplsPicAdmin.i18n.requestFailed, 'error');
                    self.finish(false);
                }
            });
        },

        generate: function(pdfId, filename) {
            const self = this;

            // Update status
            this.updateStatus(raplsPicAdmin.i18n.generating
                .replace('%1$d', Math.min(this.done + 1, this.total))
                .replace('%2$d', this.total));

            // Log current file
            this.log(raplsPicAdmin.i18n.processingFile.replace('%s', filename), 'info');

            // On past this PDF whatever happens to it, as before.
            const next = function() {
                self.after = pdfId;
                self.done++;
                self.updateStats();
                self.processNext();
            };

            $.ajax({
                url: raplsPicAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rapls_pic_bulk_generate',
                    nonce: raplsPicAdmin.nonce,
                    pdf_id: pdfId,
                    force: this.force ? 1 : 0
                },
                success: function(response) {
                    if (response.success) {
                        self.generated++;
                        self.log('✓ ' + filename, 'success');
                    } else {
                        self.failed++;
                        self.log('✗ ' + filename + ': ' + ((response.data && response.data.message) || raplsPicAdmin.i18n.failed), 'error');
                    }
                    next();
                },
                error: function() {
                    self.failed++;
                    self.log('✗ ' + filename + ': ' + raplsPicAdmin.i18n.requestFailed, 'error');
                    next();
                }
            });
        },

        updateStatus: function(text) {
            $('#rapls-pic-bulk-status').text(text);
        },

        updateStats: function() {
            const percent = this.total > 0 ? Math.min(100, Math.round((this.done / this.total) * 100)) : 100;
            $('#rapls-pic-progress-bar').css('width', percent + '%');
            $('#rapls-pic-progress').attr('aria-valuenow', percent);
            $('#rapls-pic-stat-generated').text(this.generated);
            $('#rapls-pic-stat-failed').text(this.failed);
        },

        log: function(message, type) {
            const $log = $('.rapls-pic-log-content');
            const $entry = $('<div class="log-' + type + '">').text(message);
            $log.append($entry);
            $log.scrollTop($log[0].scrollHeight);
        },

        finish: function(complete) {
            this.isRunning = false;
            this.stopRequested = false;
            $('#rapls-pic-bulk-scan').prop('disabled', false);
            $('#rapls-pic-bulk-stop').prop('disabled', true);

            if (complete) {
                this.canContinue = false;
                $('#rapls-pic-bulk-start').prop('disabled', true).text($('#rapls-pic-bulk-start').data('original-text'));
                this.done = this.total;
                this.updateStatus(raplsPicAdmin.i18n.complete);
                this.updateStats();
                return;
            }

            // Stopped: Start carries on from the PDF after the last one.
            this.canContinue = true;
            $('#rapls-pic-bulk-start').prop('disabled', false).text(raplsPicAdmin.i18n.continueRun);
            this.updateStatus(raplsPicAdmin.i18n.stopped);
        }
    };

    /**
     * Statistics refresh, a page of the library at a time
     */
    const Statistics = {
        init: function() {
            $('#rapls-pic-refresh-stats').on('click', this.refresh.bind(this));
            this.refresh();
        },

        refresh: function() {
            const $button = $('#rapls-pic-refresh-stats');
            $button.prop('disabled', true);

            const page = function(after, counts) {
                $.ajax({
                    url: raplsPicAdmin.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'rapls_pic_bulk_status',
                        nonce: raplsPicAdmin.nonce,
                        after: after,
                        total: counts.total,
                        with_thumbnail: counts.with_thumbnail
                    },
                    success: function(response) {
                        if (!response.success) {
                            $button.prop('disabled', false);
                            return;
                        }

                        $('#rapls-pic-stats-total').text(response.data.total);
                        $('#rapls-pic-stats-with').text(response.data.with_thumbnail);
                        $('#rapls-pic-stats-without').text(response.data.without_thumbnail);

                        if (response.data.done) {
                            $button.prop('disabled', false);
                        } else {
                            page(response.data.after, response.data);
                        }
                    },
                    error: function() {
                        $button.prop('disabled', false);
                    }
                });
            };

            page(0, {total: 0, with_thumbnail: 0});
        }
    };

    /**
     * Insert Settings functionality
     */
    const InsertSettings = {
        init: function() {
            // Toggle custom HTML row visibility based on insert type
            $('input[name="rapls_pic_settings[insert_type]"]').on('change', this.toggleCustomHtml);
            this.toggleCustomHtml();

            // Toggle insert size and link visibility
            $('input[name="rapls_pic_settings[insert_type]"]').on('change', this.toggleImageOptions);
            this.toggleImageOptions();
        },

        toggleCustomHtml: function() {
            const insertType = $('input[name="rapls_pic_settings[insert_type]"]:checked').val();
            if (insertType === 'custom') {
                $('#rapls-pic-custom-html-row').show();
            } else {
                $('#rapls-pic-custom-html-row').hide();
            }
        },

        toggleImageOptions: function() {
            const insertType = $('input[name="rapls_pic_settings[insert_type]"]:checked').val();
            const $sizeRow = $('#rapls_pic_insert_size').closest('tr');

            if (insertType === 'image') {
                $sizeRow.show();
            } else {
                $sizeRow.hide();
            }
        }
    };

    /**
     * Media Library enhancements
     */
    const MediaLibrary = {
        init: function() {
            // Add click handler for regenerate links via AJAX
            $(document).on('click', '.rapls-pic-regenerate-ajax', this.regenerate.bind(this));
        },

        regenerate: function(e) {
            e.preventDefault();

            const $link = $(e.currentTarget);
            const attachmentId = $link.data('attachment-id');

            if ($link.hasClass('processing')) {
                return;
            }

            $link.addClass('processing').text(raplsPicAdmin.i18n.processing);

            $.ajax({
                url: raplsPicAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rapls_pic_regenerate_thumbnail',
                    nonce: raplsPicAdmin.nonce,
                    attachment_id: attachmentId,
                    force: 1
                },
                success: function(response) {
                    if (response.success) {
                        $link.removeClass('processing').text('✓');
                        // Refresh the page after a short delay
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        $link.removeClass('processing').text('✗ ' + response.data.message);
                    }
                },
                error: function() {
                    $link.removeClass('processing').text('✗ ' + raplsPicAdmin.i18n.errorShort);
                }
            });
        }
    };

    /**
     * Initialize on document ready
     */
    $(document).ready(function() {
        // Store original button text
        $('#rapls-pic-bulk-scan').data('original-text', $('#rapls-pic-bulk-scan').text());

        // Initialize all modules
        Tabs.init();
        RangeSlider.init();
        BulkProcessor.init();
        Statistics.init();
        MediaLibrary.init();
        InsertSettings.init();
    });

})(jQuery);
