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
                            // Check if any file is a PDF
                            const hasPdf = Array.from(files).some(function(file) {
                                return file.type === 'application/pdf';
                            });
                            if (hasPdf) {
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
                    return wp.element.createElement(MediaUpload, Object.assign({}, props, {
                        allowedTypes: newAllowedTypes
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
