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
    function onlyWithThumbnails(onSelect) {
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

            if (wp.data && wp.data.dispatch && wp.data.dispatch('core/notices')) {
                wp.data.dispatch('core/notices').createErrorNotice(message, { type: 'snackbar', id: 'rapls-pic-no-thumbnail' });
            }

            if (Array.isArray(media) && kept.length > 0) {
                return onSelect(kept);
            }

            return undefined;
        };
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
                        onSelect: props.gallery ? props.onSelect : onlyWithThumbnails(props.onSelect)
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
            initialize: function() {
                originalMediaFrame.prototype.initialize.apply(this, arguments);

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
