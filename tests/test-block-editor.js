/**
 * Which dropped files the image block takes (R67-02, R68-01).
 *
 *   node tests/test-block-editor.js
 *
 * block-editor.js is loaded against a stand-in `wp` that records the filter
 * it adds, and the wrapped isMatch is asked about sets of files. The
 * original isMatch is core's: every file an image.
 */

'use strict';

const fs = require('fs');
const path = require('path');
const vm = require('vm');

let registerFilter = null;
let mediaUploadFilter = null;
const notices = [];
const wp = {
    hooks: {
        addFilter(hook, ns, cb) {
            if ('blocks.registerBlockType' === hook) {
                registerFilter = cb;
            }
            if ('editor.MediaUpload' === hook) {
                mediaUploadFilter = cb;
            }
        },
    },
    compose: { createHigherOrderComponent: (fn) => fn },
    element: { createElement: (type, props) => ({ type, props }) },
    data: { dispatch: (store) => ('core/notices' === store ? { createErrorNotice: (message) => notices.push(message) } : null) },
};

const source = fs.readFileSync(path.join(__dirname, '..', 'admin', 'js', 'block-editor.js'), 'utf8');

function isMatchWith(autoGenerate) {
    const window = { raplsPicBlockEditor: { autoGenerate } };
    vm.runInNewContext(source, { wp, window });

    const core = { type: 'files', isMatch: (files) => files.every((f) => 0 === f.type.indexOf('image/')) };
    registerFilter({ transforms: { from: [core] } }, 'core/image');

    return core.isMatch;
}

const pdf = { type: 'application/pdf' };
const jpeg = { type: 'image/jpeg' };
const png = { type: 'image/png' };
const docx = { type: 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' };
const zip = { type: 'application/zip' };

let pass = 0;
let fail = 0;
function check(label, got, want) {
    const ok = got === want;
    ok ? pass++ : fail++;
    console.log(`${ok ? 'ok  ' : 'FAIL'} ${label.padEnd(50)} got=${got} want=${want}`);
}

const on = isMatchWith(true);
check('PDF, auto generate on', on([pdf]), true);
check('PDF + JPEG', on([pdf, jpeg]), true);
check('PDF + DOCX: not the image block (R68-01)', on([pdf, docx]), false);
check('PDF + ZIP: not the image block (R68-01)', on([pdf, zip]), false);
check('JPEG + PNG: core decides, as before', on([jpeg, png]), true);

const off = isMatchWith(false);
check('PDF, auto generate off (R67-02)', off([pdf]), false);
check('JPEG, auto generate off: core decides', off([jpeg]), true);

// Codex review of 1.4.26, 3: a PDF chosen for an image block is passed on only
// when it has a thumbnail. One uploaded in the media window with Auto Generate
// off has none, and the block took a PDF with no image to show.
function mediaUploadWith(autoGenerate) {
    const window = { raplsPicBlockEditor: { autoGenerate, noThumbnail: 'no thumbnail' } };
    vm.runInNewContext(source, { wp, window });
    const chosen = [];
    const element = mediaUploadFilter('MediaUpload')({ allowedTypes: ['image'], onSelect: (media) => chosen.push(media) });
    return { element, chosen };
}

for (const autoGenerate of [true, false]) {
    const { element, chosen } = mediaUploadWith(autoGenerate);
    const label = autoGenerate ? 'on' : 'off';
    notices.length = 0;
    check(`MediaUpload (auto ${label}): PDFs are offered alongside images`, JSON.stringify(element.props.allowedTypes), JSON.stringify(['image', 'application/pdf']));
    element.props.onSelect({ id: 1, mime: 'application/pdf' });
    check(`  ...a PDF without a thumbnail is not passed on, and said so`, JSON.stringify([chosen.length, notices]), JSON.stringify([0, ['no thumbnail']]));
    element.props.onSelect({ id: 2, mime: 'application/pdf', picThumbnailId: 20 });
    element.props.onSelect({ id: 3, mime: 'image/jpeg' });
    check(`  ...a PDF with a thumbnail, and an image, are`, JSON.stringify(chosen.map((m) => m.id)), JSON.stringify([2, 3]));
    element.props.onSelect([{ id: 4, mime: 'image/png' }, { id: 5, mime_type: 'application/pdf' }]);
    check(`  ...from several, only those without a thumbnail are left out`, JSON.stringify(chosen[2].map((m) => m.id)), JSON.stringify([4]));
    element.props.onSelect({ id: 6, mime: 'application/pdf', sizes: { full: { url: 'https://e.test/6.jpg' } } });
    check(`  ...a slimmed copy that kept the thumbnail's sizes is passed on`, chosen[3] && chosen[3].id, 6);
}

// The gallery's frame hands over slimmed copies (no picThumbnailId) and keeps
// only items with a URL itself: its onSelect is left as it is.
{
    const window = { raplsPicBlockEditor: { autoGenerate: true, noThumbnail: 'no thumbnail' } };
    vm.runInNewContext(source, { wp, window });
    const onSelect = () => {};
    const element = mediaUploadFilter('MediaUpload')({ allowedTypes: ['image'], gallery: true, onSelect });
    check('Gallery: onSelect is the block\'s own', element.props.onSelect === onSelect, true);
}

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
