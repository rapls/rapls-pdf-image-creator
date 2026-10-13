/**
 * core-media-states.js: WordPress's own featured image and gallery states
 * offer thumbnailed PDFs; a state another plugin adds keeps what it asked
 * for (Codex review of 1.4.30).
 *
 *   node tests/test-core-media-states.js
 *
 * The states are stand-ins built the way media-views.js builds them: each
 * subclass calls Library.prototype.initialize from its own, and a library
 * is a query whose props carry the type.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let pass = 0;
let fail = 0;
function check(label, got, want) {
    const ok = JSON.stringify(got) === JSON.stringify(want);
    ok ? pass++ : fail++;
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${label.padEnd(62)} got=${JSON.stringify(got)} want=${JSON.stringify(want)}`);
}

function setup(blockEditor = true) {
    const query = (props) => { let p = Object.assign({}, props); return { props: { get: (k) => p[k], set: (k, v) => { p = Object.assign({}, p, { [k]: v }); } } }; };
    function Library(attributes) { this.attributes = Object.assign({}, this.defaults || {}, attributes || {}); this.id = this.attributes.id; this.initialize(); }
    Library.prototype.get = function (k) { return this.attributes[k]; };
    Library.prototype.set = function (k, v) { this.attributes[k] = v; };
    // As Backbone's Events and the frame's states collection: a state is told
    // 'ready' when it is added to a frame, with this.frame set.
    Library.prototype.once = function (event, fn, context) { (this.waiting = this.waiting || []).push([event, fn, context]); };
    Library.prototype.addTo = function (frame) { this.frame = frame; (this.waiting || []).forEach(([event, fn, context]) => { if ('ready' === event) { fn.call(context || this); } }); this.waiting = []; return this; };
    Library.prototype.initialize = function () { if (!this.get('library')) { this.set('library', query({})); } };
    function sub(defaults, own) {
        function State(attributes) { Library.call(this, attributes); }
        State.prototype = Object.create(Library.prototype);
        State.prototype.defaults = defaults;
        State.prototype.initialize = function () { own.call(this); Library.prototype.initialize.apply(this, arguments); };
        return State;
    }
    const FeaturedImage = sub({ id: 'featured-image' }, function () { if (!this.get('library')) { this.set('library', query({ type: 'image' })); } });
    const GalleryAdd = sub({ id: 'gallery-library' }, function () { if (!this.get('library')) { this.set('library', query({ type: 'image' })); } });
    const wp = { media: { query, controller: { Library, FeaturedImage, GalleryAdd } } };
    const source = fs.readFileSync(path.join(__dirname, '..', 'admin', 'js', 'core-media-states.js'), 'utf8');
    const document = { body: { classList: { contains: (c) => blockEditor && 'block-editor-page' === c } } };
    vm.runInNewContext(source, { window: { wp, document }, wp, document });
    return wp;
}

const MARKED = ['image', 'application/pdf', 'rapls-pic/with-thumbnail'];
const wp = setup();
const type = (state) => state.get('library').props.get('type');
// The gallery block's frame (media-utils' getGalleryDetailsMediaFrame) has
// no "insert" state; the classic one (MediaFrame.Post) does.
const frameOf = (...ids) => ({ states: { get: (id) => (ids.includes(id) ? { id } : undefined) } });
const galleryBlock = frameOf('gallery', 'gallery-edit', 'gallery-library');

check('Featured image state: thumbnailed PDFs too', type(new wp.media.controller.FeaturedImage()), MARKED);
check('Gallery state (gallery block)', type(new wp.media.controller.Library({ id: 'gallery', library: wp.media.query({ type: 'image' }) }).addTo(galleryBlock)), MARKED);
check('Adding to a gallery (gallery-library)', type(new wp.media.controller.GalleryAdd().addTo(galleryBlock)), MARKED);
// Review after 1.4.32: the Classic block's "Add Media" in the block editor
// opens the classic frame, whose "Create Gallery" makes a [gallery] shortcode.
const classicBlock = frameOf('insert', 'gallery', 'gallery-library', 'featured-image');
check('The Classic block\'s gallery states keep images only, in the block editor too', [
    type(new wp.media.controller.Library({ id: 'gallery', library: wp.media.query({ type: 'image' }) }).addTo(classicBlock)),
    type(new wp.media.controller.GalleryAdd().addTo(classicBlock)),
    type(new wp.media.controller.FeaturedImage().addTo(classicBlock)),
], ['image', 'image', MARKED]);

// Review after 1.4.33: a frame that has no states collection, or one that
// cannot be asked, is the block editor's: the state still offers PDFs, and
// nothing throws.
const safely = (frame) => { try { return type(new wp.media.controller.GalleryAdd().addTo(frame)); } catch (e) { return 'threw: ' + e.message; } };
check('A frame with no states, or states that cannot be asked: offered, no error', [safely({}), safely({ states: {} })], [MARKED, MARKED]);

// Codex's repro: a state another plugin adds while the same frame is built, asking for images.
const theirs = new wp.media.controller.Library({ id: 'acme-logo', library: wp.media.query({ type: 'image', pluginState: 'acme-logo' }) });
check('Another plugin\'s image state keeps what it asked for (Codex review of 1.4.30)', [type(theirs), theirs.get('library').props.get('pluginState')], ['image', 'acme-logo']);
check('  ...and so does a core state that does not ask for images (Insert Media)', type(new wp.media.controller.Library({ id: 'insert' })), undefined);
check('  ...or a core state of these ids asking for something else', type(new wp.media.controller.Library({ id: 'gallery', library: wp.media.query({ type: 'audio' }) })), 'audio');

// Review of 1.4.32: the classic editor's "Create Gallery" makes a [gallery]
// shortcode, which shows images only, so its gallery states keep images only.
const classic = setup(false);
const classicType = (state) => state.get('library').props.get('type');
check('Classic editor: featured image still offers thumbnailed PDFs', classicType(new classic.media.controller.FeaturedImage()), MARKED);
check('  ...but its gallery states keep images only', [
    classicType(new classic.media.controller.Library({ id: 'gallery', library: classic.media.query({ type: 'image' }) })),
    classicType(new classic.media.controller.GalleryAdd()),
], ['image', 'image']);

// Without the media library loaded, nothing happens.
const bare = { media: {} };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'admin', 'js', 'core-media-states.js'), 'utf8'), { window: { wp: bare }, wp: bare });
check('Without wp.media.controller, nothing is touched', Object.keys(bare.media), []);

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
