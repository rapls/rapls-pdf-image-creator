/**
 * PDF Image Creator - the classic editor's featured image
 *
 * "Set featured image", and the featured image and gallery tabs of "Add
 * Media", offer PDFs that have a thumbnail, as the block editor's pickers
 * do. Their frames ask for images themselves --
 * wp.media.query({ type: 'image' }) -- so while that frame is being built,
 * and only then, an image query asks for thumbnailed PDFs too
 * (MediaLibrary::PICKER_MARK). Any other picker on the screen, another
 * plugin's included, asks for what it asked for.
 *
 * @package PDFImageCreator
 */

(function() {
    'use strict';

    if (!window.wp || !wp.media || 'function' !== typeof wp.media.query || !wp.media.featuredImage || 'function' !== typeof wp.media.featuredImage.frame) {
        return;
    }

    const PICKER_MARK = 'rapls-pic/with-thumbnail';
    const originalQuery = wp.media.query;
    const originalFrame = wp.media.featuredImage.frame;
    let building = false;

    wp.media.query = function(props) {
        const args = Array.prototype.slice.call(arguments);

        if (building && props && 'image' === props.type) {
            args[0] = Object.assign({}, props, { type: ['image', 'application/pdf', PICKER_MARK] });
        }

        return originalQuery.apply(this, args);
    };

    function whileBuilding(build) {
        return function() {
            building = true;

            try {
                return build.apply(this, arguments);
            } finally {
                building = false;
            }
        };
    }

    wp.media.featuredImage.frame = whileBuilding(originalFrame);

    // "Add Media", whose frame has a featured image tab of its own and the
    // gallery's: core's too, as they were before (review of the uncommitted
    // change).
    if (wp.media.editor && 'function' === typeof wp.media.editor.add) {
        wp.media.editor.add = whileBuilding(wp.media.editor.add);
    }
})();
