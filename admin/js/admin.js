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

        activateTab: function(tabName) {
            // Update nav tabs
            $('.rapls-pic-tabs .nav-tab').removeClass('nav-tab-active');
            $('.rapls-pic-tabs .nav-tab[data-tab="' + tabName + '"]').addClass('nav-tab-active');

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
     */
    const BulkProcessor = {
        pdfs: [],
        currentIndex: 0,
        generated: 0,
        failed: 0,
        isRunning: false,

        init: function() {
            $('#rapls-pic-bulk-scan').on('click', this.scan.bind(this));
            $('#rapls-pic-bulk-start').on('click', this.start.bind(this));
            $('#rapls-pic-bulk-stop').on('click', this.stop.bind(this));
        },

        scan: function() {
            const self = this;
            const includeExisting = $('#rapls-pic-include-existing').is(':checked');

            $('#rapls-pic-bulk-scan').prop('disabled', true).text(raplsPicAdmin.i18n.processing);
            $('#rapls-pic-bulk-results').hide();
            $('#rapls-pic-bulk-progress').hide();

            $.ajax({
                url: raplsPicAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rapls_pic_bulk_scan',
                    nonce: raplsPicAdmin.nonce,
                    include_existing: includeExisting ? 1 : 0
                },
                success: function(response) {
                    $('#rapls-pic-bulk-scan').prop('disabled', false).text($('#rapls-pic-bulk-scan').data('original-text') || 'Scan for PDFs');

                    if (response.success) {
                        self.pdfs = response.data.pdfs;
                        $('#rapls-pic-bulk-total').text(response.data.total);
                        $('#rapls-pic-bulk-results').show();

                        if (response.data.total > 0) {
                            $('#rapls-pic-bulk-start').prop('disabled', false);
                        } else {
                            $('#rapls-pic-bulk-start').prop('disabled', true);
                        }
                    } else {
                        var errorMsg = response.data.message || raplsPicAdmin.i18n.error;
                        if (response.data.file) {
                            errorMsg += '\n\nFile: ' + response.data.file + '\nLine: ' + response.data.line;
                        }
                        alert(errorMsg);
                    }
                },
                error: function(xhr, status, error) {
                    $('#rapls-pic-bulk-scan').prop('disabled', false).text($('#rapls-pic-bulk-scan').data('original-text') || 'Scan for PDFs');
                    alert(raplsPicAdmin.i18n.error + '\n\nStatus: ' + status + '\nError: ' + error);
                }
            });
        },

        start: function() {
            if (this.pdfs.length === 0) {
                return;
            }

            if (!confirm(raplsPicAdmin.i18n.confirmBulk)) {
                return;
            }

            this.currentIndex = 0;
            this.generated = 0;
            this.failed = 0;
            this.isRunning = true;

            // Update UI
            $('#rapls-pic-bulk-scan').prop('disabled', true);
            $('#rapls-pic-bulk-start').prop('disabled', true);
            $('#rapls-pic-bulk-stop').prop('disabled', false);
            $('#rapls-pic-bulk-progress').show();
            $('#rapls-pic-bulk-log').show();
            $('.rapls-pic-log-content').empty();

            this.processNext();
        },

        stop: function() {
            this.isRunning = false;
            $('#rapls-pic-bulk-stop').prop('disabled', true);
            this.updateStatus('Stopped');
            this.finish();
        },

        processNext: function() {
            if (!this.isRunning || this.currentIndex >= this.pdfs.length) {
                this.finish();
                return;
            }

            const self = this;
            const pdf = this.pdfs[this.currentIndex];
            const force = $('#rapls-pic-include-existing').is(':checked');

            // Update status
            const statusText = raplsPicAdmin.i18n.generating
                .replace('%1$d', this.currentIndex + 1)
                .replace('%2$d', this.pdfs.length);
            this.updateStatus(statusText);

            // Log current file
            this.log('Processing: ' + pdf.filename, 'info');

            $.ajax({
                url: raplsPicAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rapls_pic_bulk_generate',
                    nonce: raplsPicAdmin.nonce,
                    pdf_id: pdf.id,
                    force: force ? 1 : 0
                },
                success: function(response) {
                    if (response.success) {
                        self.generated++;
                        self.log('✓ ' + pdf.filename, 'success');
                    } else {
                        self.failed++;
                        self.log('✗ ' + pdf.filename + ': ' + (response.data.message || 'Failed'), 'error');
                    }
                    self.updateStats();
                    self.currentIndex++;
                    self.processNext();
                },
                error: function() {
                    self.failed++;
                    self.log('✗ ' + pdf.filename + ': Request failed', 'error');
                    self.updateStats();
                    self.currentIndex++;
                    self.processNext();
                }
            });
        },

        updateStatus: function(text) {
            $('#rapls-pic-bulk-status').text(text);
        },

        updateStats: function() {
            const percent = Math.round((this.currentIndex / this.pdfs.length) * 100);
            $('#rapls-pic-progress-bar').css('width', percent + '%');
            $('#rapls-pic-stat-generated').text(this.generated);
            $('#rapls-pic-stat-failed').text(this.failed);
        },

        log: function(message, type) {
            const $log = $('.rapls-pic-log-content');
            const $entry = $('<div class="log-' + type + '">').text(message);
            $log.append($entry);
            $log.scrollTop($log[0].scrollHeight);
        },

        finish: function() {
            this.isRunning = false;
            $('#rapls-pic-bulk-scan').prop('disabled', false);
            $('#rapls-pic-bulk-start').prop('disabled', true);
            $('#rapls-pic-bulk-stop').prop('disabled', true);

            if (this.currentIndex >= this.pdfs.length) {
                this.updateStatus(raplsPicAdmin.i18n.complete);
                this.updateStats();
            }
        }
    };

    /**
     * Statistics refresh
     */
    const Statistics = {
        init: function() {
            $('#rapls-pic-refresh-stats').on('click', this.refresh.bind(this));
            this.refresh();
        },

        refresh: function() {
            const $button = $('#rapls-pic-refresh-stats');
            $button.prop('disabled', true);

            $.ajax({
                url: raplsPicAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'rapls_pic_bulk_status',
                    nonce: raplsPicAdmin.nonce
                },
                success: function(response) {
                    $button.prop('disabled', false);

                    if (response.success) {
                        $('#rapls-pic-stats-total').text(response.data.total);
                        $('#rapls-pic-stats-with').text(response.data.with_thumbnail);
                        $('#rapls-pic-stats-without').text(response.data.without_thumbnail);
                    }
                },
                error: function() {
                    $button.prop('disabled', false);
                }
            });
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
                    $link.removeClass('processing').text('✗ Error');
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
