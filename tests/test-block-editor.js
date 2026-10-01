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
const wp = {
    hooks: {
        addFilter(hook, ns, cb) {
            if ('blocks.registerBlockType' === hook) {
                registerFilter = cb;
            }
        },
    },
    compose: { createHigherOrderComponent: (fn) => fn },
    element: { createElement: () => null },
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

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
