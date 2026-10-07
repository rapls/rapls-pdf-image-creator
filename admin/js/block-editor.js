/**
 * PDF Image Creator - Block Editor Integration
 *
 * Enables PDF files with generated thumbnails to be displayed in image blocks.
 *
 * @package PDFImageCreator
 */

(function() {
    'use strict';

    const { addFilter } = wp.hooks;
    const { createHigherOrderComponent } = wp.compose;

    /**
     * Filter the image block to accept PDFs with thumbnails
     */
    addFilter(
        'blocks.registerBlockType',
        'pic/image-block-pdf-support',
        function(settings, name) {
            if (name !== 'core/image') {
                return settings;
            }

            // Extend allowed mime types to include PDF
            const originalTransforms = settings.transforms;
            if (originalTransforms && originalTransforms.from) {
                originalTransforms.from.forEach(function(transform) {
                    if (transform.type === 'files' && transform.isMatch) {
                        const originalIsMatch = transform.isMatch;
                        transform.isMatch = function(files) {
                            // Check if any file is a PDF -- taken as an image
                            // only when it will get a thumbnail on upload.
                            // With Auto Generate off it never does (R67-02).
                            const autoGenerate = !window.raplsPicBlockEditor || !!window.raplsPicBlockEditor.autoGenerate;
                            const list = Array.from(files);
                            const hasPdf = list.some(function(file) {
                                return file.type === 'application/pdf';
                            });
                            // And every file is an image or a PDF, as core asks
                            // every file to be an image: a PDF dropped with a
                            // DOCX made both into image blocks (R68-01).
                            const allImagesOrPdfs = list.every(function(file) {
                                return file.type.indexOf('image/') === 0 || file.type === 'application/pdf';
                            });
                            if (hasPdf && autoGenerate && allImagesOrPdfs) {
                                return true;
                            }
                            return originalIsMatch(files);
                        };
                    }
                });
            }

            return settings;
        }
    );

    /**
     * A PDF the image block can show: one with a thumbnail
     *
     * The server marks those (picThumbnailId, MediaLibrary::filterAttachmentForJs).
     * The library offers only those, but a PDF uploaded in the same window
     * is offered too, and with Auto Generate off -- or a generation that
     * failed -- it has no thumbnail: the image block took a PDF with no image
     * to show (Codex review of 1.4.26, 3).
     */
    function isPdfWithoutThumbnail(media) {
        // Or a picture to show: some frames pass a slimmed copy that drops
        // the mark but keeps the sizes the server built from the thumbnail.
        const hasPicture = !!(media && media.sizes && media.sizes.full && media.sizes.full.url);

        return !!media
            && ('application/pdf' === media.mime || 'application/pdf' === media.mime_type)
            && !media.picThumbnailId
            && !hasPicture;
    }

    /**
     * Pass on what was chosen, without the PDFs that have no thumbnail
     *
     * Those are left out, and the editor says why. Nothing is passed on when
     * nothing is left.
     */
    /**
     * The image block's picker, as this plugin makes it: images and PDFs only
     *
     * Not Cover or Media & Text (video too), nor another plugin's image
     * picker.
     */
    function isImageBlockFrame(options) {
        const library = options && options.library ? options.library.type : null;

        return Array.isArray(library) && 2 === library.length
            && library.indexOf('image') !== -1 && library.indexOf('application/pdf') !== -1;
    }

    /**
     * Attachments uploaded in the image block's picker, while it is open
     *
     * Only these may be offered for deletion. A PDF without a thumbnail can
     * reach the image block another way too -- the block's own PDF,
     * preselected when the window opens, after its thumbnail was deleted --
     * and offering that one could remove a PDF other posts link to. Nor one
     * uploaded on the same page for something else -- a File block, Media &
     * Text, another plugin's picker -- and chosen here later (Codex review of
     * 1.4.28, 3): the list is emptied each time the image block's picker
     * opens. Nothing can be chosen there without opening it, and while it is
     * open it covers the editor, so what is uploaded meanwhile is uploaded
     * in it.
     */
    const uploadedHere = [];

    if (wp.Uploader && wp.Uploader.queue && 'function' === typeof wp.Uploader.queue.on) {
        wp.Uploader.queue.on('add', function(attachment) {
            uploadedHere.push(attachment);
        });
    }

    function wasUploadedHere(id) {
        return uploadedHere.some(function(attachment) {
            const own = attachment && (attachment.id || ('function' === typeof attachment.get ? attachment.get('id') : 0));
            return !!own && String(own) === String(id);
        });
    }

    function onlyWithThumbnails(onSelect, current) {
        if ('function' !== typeof onSelect) {
            return onSelect;
        }

        return function(media) {
            const list = Array.isArray(media) ? media : [media];
            const kept = list.filter(function(item) {
                return !isPdfWithoutThumbnail(item);
            });

            if (kept.length === list.length) {
                return onSelect(media);
            }

            const settings = window.raplsPicBlockEditor || {};
            const message = settings.noThumbnail || 'This PDF has no thumbnail, so it cannot be shown as an image.';
            const notices = wp.data && wp.data.dispatch ? wp.data.dispatch('core/notices') : null;

            // With Auto Generate off, a PDF without a thumbnail chosen here was
            // uploaded in this window (the library lists only PDFs that have
            // one), and it stayed in the Media Library for nothing. Offered
            // to be deleted again, by the person who just uploaded it (Codex
            // review of 1.4.27, 2).
            const dropped = list.filter(isPdfWithoutThumbnail).map(function(item) {
                return item.id;
            }).filter(function(id) {
                return !!id && String(id) !== String(current) && wasUploadedHere(id);
            });
            const actions = !settings.autoGenerate && wp.apiFetch && dropped.length
                ? [{
                    label: settings.deleteUploaded || 'Delete the uploaded PDF',
                    onClick: function() {
                        deleteUploaded(dropped, notices, settings);
                    }
                }]
                : [];

            if (notices) {
                notices.createErrorNotice(message, { type: actions.length ? 'default' : 'snackbar', id: 'rapls-pic-no-thumbnail', isDismissible: true, actions: actions });
            }

            if (Array.isArray(media) && kept.length > 0) {
                return onSelect(kept);
            }

            return undefined;
        };
    }

    /**
     * Delete PDFs just uploaded that the image block could not take
     */
    function deleteUploaded(ids, notices, settings) {
        // Once: the notice stays until the request answers, and a second
        // click deleted nothing and said it had failed.
        if (deleteUploaded.busy) {
            return;
        }

        deleteUploaded.busy = true;

        if (notices) {
            notices.removeNotice('rapls-pic-no-thumbnail');
        }

        Promise.all(ids.map(function(id) {
            return wp.apiFetch({ path: '/wp/v2/media/' + encodeURIComponent(id) + '?force=true', method: 'DELETE' });
        })).then(function() {
            deleteUploaded.busy = false;

            if (notices) {
                notices.createSuccessNotice(settings.deleted || 'The PDF was deleted from the Media Library.', { type: 'snackbar' });
            }
        }, function() {
            deleteUploaded.busy = false;

            if (notices) {
                notices.createErrorNotice(settings.deleteFailed || 'The PDF could not be deleted. Delete it in the Media Library.', { type: 'snackbar' });
            }
        });
    }

    /**
     * Modify the MediaUpload component to accept PDFs
     */
    addFilter(
        'editor.MediaUpload',
        'pic/media-upload-pdf-support',
        createHigherOrderComponent(function(MediaUpload) {
            return function(props) {
                // If this is for image blocks, allow PDFs too
                if (props.allowedTypes && props.allowedTypes.includes('image')) {
                    const newAllowedTypes = [...props.allowedTypes];
                    if (!newAllowedTypes.includes('application/pdf')) {
                        newAllowedTypes.push('application/pdf');
                    }
                    // Not the gallery: its frame hands over slimmed copies, and
                    // it already keeps only what has a URL to show.
                    return wp.element.createElement(MediaUpload, Object.assign({}, props, {
                        allowedTypes: newAllowedTypes,
                        onSelect: props.gallery ? props.onSelect : onlyWithThumbnails(props.onSelect, props.value)
                    }));
                }
                return wp.element.createElement(MediaUpload, props);
            };
        }, 'withPdfSupport')
    );

    /**
     * Filter media library frame to include PDFs when selecting images
     */
    if (typeof wp !== 'undefined' && wp.media) {
        const originalMediaFrame = wp.media.view.MediaFrame.Select;

        wp.media.view.MediaFrame.Select = originalMediaFrame.extend({
            // Said before anything is uploaded: with Auto Generate off, a PDF
            // uploaded while choosing an image gets no thumbnail and cannot
            // be used (Codex review of 1.4.27, 2). Core's own upload tab,
            // with that message; every other frame as it was.
            uploadContent: function() {
                const settings = window.raplsPicBlockEditor || {};
                // Only in the image block's picker, where the message applies.
                const forImages = isImageBlockFrame(this.options);

                if (settings.autoGenerate || !forImages || !settings.uploadMessage || !wp.media.view.UploaderInline) {
                    return originalMediaFrame.prototype.uploadContent.apply(this, arguments);
                }

                this.$el.removeClass('hide-toolbar');
                this.content.set(new wp.media.view.UploaderInline({
                    controller: this,
                    message: settings.uploadMessage
                }));
            },

            initialize: function() {
                originalMediaFrame.prototype.initialize.apply(this, arguments);

                // What is uploaded after the image block's picker opens is
                // what it may offer to delete; each opening starts afresh.
                // Not on close: core closes the window before it hands over
                // the selection.
                if (isImageBlockFrame(this.options)) {
                    this.on('open', function() {
                        uploadedHere.length = 0;
                    });
                }

                // Listen for library ready
                this.on('ready', function() {
                    const library = this.state().get('library');
                    if (library && library.props) {
                        const type = library.props.get('type');
                        if (type === 'image') {
                            // Also include PDFs
                            library.props.set('type', ['image', 'application/pdf']);
                        }
                    }
                }, this);
            }
        });
    }

})();
