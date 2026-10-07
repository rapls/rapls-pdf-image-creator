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

function setup() {
    const query = (props) => { let p = Object.assign({}, props); return { props: { get: (k) => p[k], set: (k, v) => { p = Object.assign({}, p, { [k]: v }); } } }; };
    function Library(attributes) { this.attributes = Object.assign({}, this.defaults || {}, attributes || {}); this.id = this.attributes.id; this.initialize(); }
    Library.prototype.get = function (k) { return this.attributes[k]; };
    Library.prototype.set = function (k, v) { this.attributes[k] = v; };
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
    vm.runInNewContext(source, { window: { wp }, wp });
    return wp;
}

const MARKED = ['image', 'application/pdf', 'rapls-pic/with-thumbnail'];
const wp = setup();
const type = (state) => state.get('library').props.get('type');

check('Featured image state: thumbnailed PDFs too', type(new wp.media.controller.FeaturedImage()), MARKED);
check('Gallery state (Add Media, gallery block)', type(new wp.media.controller.Library({ id: 'gallery', library: wp.media.query({ type: 'image' }) })), MARKED);
check('Adding to a gallery (gallery-library)', type(new wp.media.controller.GalleryAdd()), MARKED);

// Codex's repro: a state another plugin adds while the same frame is built, asking for images.
const theirs = new wp.media.controller.Library({ id: 'acme-logo', library: wp.media.query({ type: 'image', pluginState: 'acme-logo' }) });
check('Another plugin\'s image state keeps what it asked for (Codex review of 1.4.30)', [type(theirs), theirs.get('library').props.get('pluginState')], ['image', 'acme-logo']);
check('  ...and so does a core state that does not ask for images (Insert Media)', type(new wp.media.controller.Library({ id: 'insert' })), undefined);
check('  ...or a core state of these ids asking for something else', type(new wp.media.controller.Library({ id: 'gallery', library: wp.media.query({ type: 'audio' }) })), 'audio');

// Without the media library loaded, nothing happens.
const bare = { media: {} };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'admin', 'js', 'core-media-states.js'), 'utf8'), { window: { wp: bare }, wp: bare });
check('Without wp.media.controller, nothing is touched', Object.keys(bare.media), []);

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
