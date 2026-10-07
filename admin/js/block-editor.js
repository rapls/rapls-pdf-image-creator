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
     * The pickers of WordPress's own that are opened to PDFs
     *
     * editor.MediaUpload wraps every MediaUpload on the screen and is not
     * told which block, or which plugin, it is for. Any picker that asked for
     * images was given PDFs, another plugin's too -- one that can take only
     * images was handed a PDF, and shown this plugin's upload message and
     * delete button (Codex review of 1.4.29, 1). So the image and gallery
     * blocks say so to the pickers inside them, through a context set where
     * the block's name is known (editor.BlockEdit). Their toolbar and sidebar
     * render through portals, which keep it. The featured image picker is
     * known by its own prop. Every other picker is left as it is.
     */
    const InBlock = wp.element.createContext ? wp.element.createContext(null) : null;

    /**
     * Set by the block's own media controls -- MediaPlaceholder and
     * MediaReplaceFlow, inside the image or gallery block -- for the
     * MediaUpload they render. A MediaUpload another plugin puts in the same
     * block, in its sidebar or toolbar, is not inside them, and is left as it
     * is (review of the uncommitted change).
     */
    const PdfPicker = InBlock ? wp.element.createContext(null) : null;

    /** The type added to a picker's library query, so the server knows it (MediaLibrary::PICKER_MARK). */
    const PICKER_MARK = 'rapls-pic/with-thumbnail';

    /** Which of those pickers is opening now: 'core/image', 'core/gallery', 'featured', or ''. */
    let openingPicker = '';

    function pickerKind(props, fromBlock) {
        const name = fromBlock && fromBlock.name;

        if ('core/gallery' === name) {
            return name;
        }

        // The site editor's Post Featured Image block sets the same featured
        // image as the editor's own picker.
        if ('core/post-featured-image' === name) {
            return 'featured';
        }

        // The image block's own pickers choose its image: an id they are
        // given is the block's. A picker another plugin added inside the
        // block -- its sidebar, its toolbar -- chooses something else, and
        // is left as it is.
        if ('core/image' === name) {
            return fromBlock.id && props.value && String(props.value) !== String(fromBlock.id) ? '' : name;
        }

        return props.unstableFeaturedImageFlow || 'editor-post-featured-image__media-modal' === props.modalClass
            ? 'featured'
            : '';
    }

    addFilter(
        'editor.BlockEdit',
        'pic/pdf-picker-context',
        createHigherOrderComponent(function(BlockEdit) {
            return function(props) {
                if (InBlock && ('core/image' === props.name || 'core/gallery' === props.name || 'core/post-featured-image' === props.name)) {
                    const value = { name: props.name, id: props.attributes ? props.attributes.id : undefined };

                    return wp.element.createElement(InBlock.Provider, { value: value }, wp.element.createElement(BlockEdit, props));
                }

                return wp.element.createElement(BlockEdit, props);
            };
        }, 'withPdfPickerContext')
    );

    // The block's own media controls pass the block on to their MediaUpload.
    ['editor.MediaPlaceholder', 'editor.MediaReplaceFlow'].forEach(function(hook) {
        addFilter(
            hook,
            'pic/pdf-picker-controls',
            createHigherOrderComponent(function(Control) {
                return function(props) {
                    const block = InBlock && wp.element.useContext ? wp.element.useContext(InBlock) : null;

                    if (!block || !PdfPicker) {
                        return wp.element.createElement(Control, props);
                    }

                    return wp.element.createElement(PdfPicker.Provider, { value: block }, wp.element.createElement(Control, props));
                };
            }, 'withPdfPickerControl')
        );
    });

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

    /**
     * Pass on what was chosen, without the PDFs that have no thumbnail
     *
     * Those are left out, and the editor says why. Nothing is passed on when
     * nothing is left. Only the image block's picker offers to delete one
     * just uploaded ($offerDelete).
     */
    function onlyWithThumbnails(onSelect, current, offerDelete) {
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
            const actions = offerDelete && !settings.autoGenerate && wp.apiFetch && dropped.length
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
     * Open the pickers of WordPress's own to PDFs that have a thumbnail
     */
    addFilter(
        'editor.MediaUpload',
        'pic/media-upload-pdf-support',
        createHigherOrderComponent(function(MediaUpload) {
            return function(props) {
                const fromBlock = PdfPicker && wp.element.useContext ? wp.element.useContext(PdfPicker) : null;
                const kind = pickerKind(props, fromBlock);

                if (!kind || !props.allowedTypes || !props.allowedTypes.includes('image')) {
                    return wp.element.createElement(MediaUpload, props);
                }

                const newAllowedTypes = [...props.allowedTypes];
                if (!newAllowedTypes.includes('application/pdf')) {
                    newAllowedTypes.push('application/pdf');
                }
                newAllowedTypes.push(PICKER_MARK);

                // Which picker this is, said as it opens: the upload message,
                // the delete button and the list of uploads belong to the
                // image block's alone.
                const render = 'function' === typeof props.render
                    ? function(args) {
                        const open = args && args.open;

                        return props.render(Object.assign({}, args, {
                            open: function() {
                                // For the frame built as it opens, and no
                                // longer: a frame built later -- another
                                // plugin's -- must not take it for this one.
                                openingPicker = kind;

                                try {
                                    return 'function' === typeof open ? open.apply(this, arguments) : undefined;
                                } finally {
                                    openingPicker = '';
                                }
                            }
                        }));
                    }
                    : props.render;

                // Not the gallery: its frame hands over slimmed copies, and
                // it already keeps only what has a URL to show.
                return wp.element.createElement(MediaUpload, Object.assign({}, props, {
                    allowedTypes: newAllowedTypes,
                    render: render,
                    onSelect: props.gallery || 'core/gallery' === kind
                        ? props.onSelect
                        : onlyWithThumbnails(props.onSelect, props.value, 'core/image' === kind)
                }));
            };
        }, 'withPdfSupport')
    );

    /**
     * The media frame: the image block's upload message and its list of uploads
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

                if (settings.autoGenerate || 'core/image' !== this.raplsPicPicker || !settings.uploadMessage || !wp.media.view.UploaderInline) {
                    return originalMediaFrame.prototype.uploadContent.apply(this, arguments);
                }

                this.$el.removeClass('hide-toolbar');
                this.content.set(new wp.media.view.UploaderInline({
                    controller: this,
                    message: settings.uploadMessage
                }));
            },

            initialize: function() {
                // Which picker opened this frame, as it was built; '' for any
                // other, another plugin's included.
                this.raplsPicPicker = openingPicker;

                originalMediaFrame.prototype.initialize.apply(this, arguments);

                // What is uploaded after the image block's picker opens is
                // what it may offer to delete; each opening starts afresh.
                // Not on close: core closes the window before it hands over
                // the selection.
                if ('core/image' === this.raplsPicPicker) {
                    this.on('open', function() {
                        uploadedHere.length = 0;
                    });
                }

            }
        });
    }

    /**
     * The libraries those pickers ask for, as they are built
     *
     * The gallery's frame and the featured image's ask for images themselves
     * -- wp.media.query({ type: 'image' }) -- whatever MediaUpload was told,
     * so a mark on allowedTypes never reached them, and the server no longer
     * adds PDFs to a plain image query (review of the uncommitted change).
     * While one of WordPress's own pickers is being opened, and only then
     * (openingPicker is set around the open), an image query it makes asks
     * for thumbnailed PDFs too.
     */
    if (typeof wp !== 'undefined' && wp.media && 'function' === typeof wp.media.query) {
        const originalQuery = wp.media.query;

        wp.media.query = function(props) {
            const args = Array.prototype.slice.call(arguments);

            if (openingPicker && props && 'image' === props.type) {
                args[0] = Object.assign({}, props, { type: ['image', 'application/pdf', PICKER_MARK] });
            }

            return originalQuery.apply(this, args);
        };
    }

})();
