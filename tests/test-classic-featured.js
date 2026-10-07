/**
 * The classic editor's "Set featured image" offers thumbnailed PDFs; no other
 * picker on the screen is changed (Codex review of 1.4.29, 1).
 *
 *   node tests/test-classic-featured.js
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
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${label.padEnd(60)} got=${JSON.stringify(got)} want=${JSON.stringify(want)}`);
}

const queries = [];
const wp = {
    media: {
        query: (props) => { queries.push(props); return { props }; },
        // As media-editor.js builds it: the FeaturedImage state asks for images.
        featuredImage: { frame() { return wp.media.query({ type: 'image' }); } },
    },
};
const source = fs.readFileSync(path.join(__dirname, '..', 'admin', 'js', 'classic-featured.js'), 'utf8');
vm.runInNewContext(source, { window: { wp }, wp });

wp.media.featuredImage.frame();
wp.media.query({ type: 'image' });
wp.media.query({ type: 'image', uploadedTo: 3 });
check('"Set featured image" asks for thumbnailed PDFs too', queries[0].type, ['image', 'application/pdf', 'rapls-pic/with-thumbnail']);
check('  ...any other image query on the screen asks for images only', [queries[1].type, queries[2].type], ['image', 'image']);
check('  ...and keeps its other props', queries[2].uploadedTo, 3);

// "Add Media": its frame has a featured image tab and the gallery's, core's too.
const withEditor = { media: { query: (props) => { queries.push(props); return {}; }, featuredImage: { frame() {} }, editor: { add() { withEditor.media.query({ type: 'image' }); return 'frame'; } } } };
vm.runInNewContext(source, { window: { wp: withEditor }, wp: withEditor });
check('"Add Media" frame: its image queries ask for thumbnailed PDFs, and it is returned', [withEditor.media.editor.add(), queries[queries.length - 1].type], ['frame', ['image', 'application/pdf', 'rapls-pic/with-thumbnail']]);
withEditor.media.query({ type: 'image' });
check('  ...and only while it is built', queries[queries.length - 1].type, 'image');

// A frame that throws while being built does not leave every later query marked.
wp.media.featuredImage.frame = (function (built) { return built; })(wp.media.featuredImage.frame);
const throwing = { media: { query: (props) => { queries.push(props); return {}; }, featuredImage: { frame() { throw new Error('boom'); } } } };
vm.runInNewContext(source, { window: { wp: throwing }, wp: throwing });
try { throwing.media.featuredImage.frame(); } catch (e) { /* expected */ }
throwing.media.query({ type: 'image' });
check('  ...a frame that throws leaves later queries alone', queries[queries.length - 1].type, 'image');

// Without media-editor.js (no featuredImage), nothing is wrapped.
const bare = { media: { query: () => 'original' } };
const query = bare.media.query;
vm.runInNewContext(source, { window: { wp: bare }, wp: bare });
check('Without the featured image frame, the query is left alone', bare.media.query === query, true);

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
