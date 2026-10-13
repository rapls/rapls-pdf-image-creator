/**
 * insert-link.js: the media window's Link To starts at the plugin's "Link To"
 * setting for a PDF with a thumbnail, and a choice made there is kept
 * (review of 1.4.33).
 *
 *   node tests/test-insert-link.js
 *
 * Library is a stand-in built as media-views.js builds it: one display model
 * per attachment, made from defaultDisplaySettings() the first time, which
 * turns a document's "none" into "file".
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

const source = fs.readFileSync(path.join(__dirname, '..', 'admin', 'js', 'insert-link.js'), 'utf8');

function setup(data, userLink = 'none') {
    function Model(attrs) { this.attrs = Object.assign({}, attrs); }
    Model.prototype.get = function (k) { return this.attrs[k]; };
    Model.prototype.set = function (k, v) { this.attrs[k] = v; };
    function Library() { this._displays = {}; this._defaultDisplaySettings = { align: 'none', size: 'medium', link: userLink }; }
    Library.prototype.defaultDisplaySettings = function (attachment) {
        const settings = Object.assign({}, this._defaultDisplaySettings);
        if (attachment.get('type') !== 'image' && settings.link === 'none') { settings.link = 'file'; }
        return settings;
    };
    Library.prototype.display = function (attachment) {
        if (!this._displays[attachment.cid]) { this._displays[attachment.cid] = new Model(this.defaultDisplaySettings(attachment)); }
        return this._displays[attachment.cid];
    };
    const original = Library.prototype.defaultDisplaySettings;
    const wp = { media: { controller: { Library } } };
    const window = { wp };
    if (undefined !== data) { window.raplsPicInsertLink = data; }
    vm.runInNewContext(source, { window, wp });
    return { Library, Model, replaced: Library.prototype.defaultDisplaySettings !== original };
}

let n = 0;
const attachment = (Model, attrs) => Object.assign(new Model(attrs), { cid: 'c' + (++n) });
const pdf = { type: 'application', mime: 'application/pdf', picThumbnailId: 70 };

for (const [setting, want] of [['file', 'file'], ['attachment', 'post'], ['none', 'none']]) {
    const { Library, Model } = setup({ link: setting });
    check(`setting "${setting}": a thumbnailed PDF starts at "${want}"`, new Library().display(attachment(Model, pdf)).get('link'), want);
}

const { Library, Model } = setup({ link: 'attachment' });
const library = new Library();
check('a PDF without a thumbnail keeps core\'s default', library.display(attachment(Model, { type: 'application', mime: 'application/pdf' })).get('link'), 'file');
check('an image keeps core\'s default', library.display(attachment(Model, { type: 'image', mime: 'image/jpeg', picThumbnailId: 70 })).get('link'), 'none');
const chosen = attachment(Model, pdf);
library.display(chosen).set('link', 'none');
check('a choice made in the window is kept when it is shown again', library.display(chosen).get('link'), 'none');
check('  ...and the other settings are core\'s', [library.display(chosen).get('align'), library.display(chosen).get('size')], ['none', 'medium']);

check('no setting given, or one not known: core\'s method left alone', [setup(undefined).replaced, setup({ link: 'custom' }).replaced, setup({ link: 'toString' }).replaced], [false, false, false]);

let threw = null;
try { vm.runInNewContext(source, { window: {}, wp: undefined }); } catch (e) { threw = e.message; }
check('no media window on the page: nothing thrown', threw, null);

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
