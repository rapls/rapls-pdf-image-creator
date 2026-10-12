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
    // to one -- in the block editor only, for the gallery block. The classic
    // editor's "Create Gallery" makes a [gallery] shortcode, which WordPress
    // fills with images alone: a PDF chosen there vanished from the post
    // without a word (review of 1.4.32). So does the Classic block's "Add
    // Media" inside the block editor: its frame is the classic one, known
    // by its "insert" state, which the gallery block's frame does not have
    // (review after 1.4.32).
    const blockEditor = !!(window.document && window.document.body && window.document.body.classList
        && window.document.body.classList.contains('block-editor-page'));
    const CORE_STATES = blockEditor ? ['featured-image', 'gallery', 'gallery-library'] : ['featured-image'];

    const Library = wp.media.controller.Library;
    const originalInitialize = Library.prototype.initialize;

    // On the prototype: the featured image and gallery-library states call
    // Library.prototype.initialize from their own, and so come through here.
    Library.prototype.initialize = function() {
        const result = originalInitialize.apply(this, arguments);
        const id = this.id || ('function' === typeof this.get ? this.get('id') : '');
        const library = 'function' === typeof this.get ? this.get('library') : null;

        if (CORE_STATES.indexOf(id) === -1 || !library || !library.props || 'image' !== library.props.get('type')) {
            return result;
        }

        const mark = function() {
            library.props.set('type', ['image', 'application/pdf', PICKER_MARK]);
        };

        // A gallery state waits for its frame, which core gives it as the
        // state is added ('ready'), before anything is shown.
        if ('featured-image' === id || 'function' !== typeof this.once) {
            mark();
        } else {
            this.once('ready', function() {
                const states = this.frame && this.frame.states;

                if (!states || 'function' !== typeof states.get || !states.get('insert')) {
                    mark();
                }
            }, this);
        }

        return result;
    };
})();
