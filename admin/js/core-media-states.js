/**
 * PDF Image Creator - WordPress's own featured image and gallery pickers
 *
 * Those pickers offer PDFs that have a thumbnail, as the image block does.
 * Their libraries ask for images themselves -- wp.media.query({ type:
 * 'image' }) -- so the library of each such state, known by the id core
 * gives it, is marked for the server (MediaLibrary::PICKER_MARK) once the
 * state is made. Nothing else is: a state another plugin adds, to the same
 * frame or its own, keeps what it asked for. Up to 1.4.30 the queries made
 * while those frames were being built were all marked, and a state another
 * plugin added meanwhile got PDFs too (Codex review of 1.4.30).
 *
 * Loaded in the block editor and on classic post screens.
 *
 * @package PDFImageCreator
 */

(function() {
    'use strict';

    if (!window.wp || !wp.media || !wp.media.controller || !wp.media.controller.Library) {
        return;
    }

    const PICKER_MARK = 'rapls-pic/with-thumbnail';

    // featured-image: "Set featured image", in either editor, and the tab in
    // "Add Media". gallery and gallery-library: making a gallery and adding
    // to one, in "Add Media" and the gallery block.
    const CORE_STATES = ['featured-image', 'gallery', 'gallery-library'];

    const Library = wp.media.controller.Library;
    const originalInitialize = Library.prototype.initialize;

    // On the prototype: the featured image and gallery-library states call
    // Library.prototype.initialize from their own, and so come through here.
    Library.prototype.initialize = function() {
        const result = originalInitialize.apply(this, arguments);
        const id = this.id || ('function' === typeof this.get ? this.get('id') : '');
        const library = 'function' === typeof this.get ? this.get('library') : null;

        if (CORE_STATES.indexOf(id) !== -1 && library && library.props && 'image' === library.props.get('type')) {
            library.props.set('type', ['image', 'application/pdf', PICKER_MARK]);
        }

        return result;
    };
})();
