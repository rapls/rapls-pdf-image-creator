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

function setup(data, userLink = 'none', userSize = 'medium', sendWith = null) {
    // Backbone's set(): every attribute first, then a change:key for each that changed.
    function Model(attrs) { this.attrs = Object.assign({}, attrs); this.handlers = {}; }
    Model.prototype.get = function (k) { return this.attrs[k]; };
    Model.prototype.set = function (k, v) {
        const attrs = 'object' === typeof k ? k : { [k]: v };
        const changed = Object.keys(attrs).filter((key) => this.attrs[key] !== attrs[key]);
        Object.assign(this.attrs, attrs);
        changed.forEach((key) => (this.handlers['change:' + key] || []).splice(0).forEach((cb) => cb()));
    };
    Model.prototype.once = function (event, cb) { (this.handlers[event] = this.handlers[event] || []).push(cb); };
    function Library() { this._displays = {}; this._defaultDisplaySettings = { align: 'none', size: userSize, link: userLink }; }
    Library.prototype.defaultDisplaySettings = function (attachment) {
        const settings = Object.assign({}, this._defaultDisplaySettings);
        // Tolerant here, so that the script's own guard is what is tested.
        if (attachment && 'function' === typeof attachment.get && attachment.get('type') !== 'image' && settings.link === 'none') { settings.link = 'file'; }
        return settings;
    };
    Library.prototype.display = function (attachment) {
        if (!this._displays[attachment.cid]) { this._displays[attachment.cid] = new Model(this.defaultDisplaySettings(attachment)); }
        return this._displays[attachment.cid];
    };
    const original = Library.prototype.defaultDisplaySettings;
    // media-editor.js: send.attachment sends the size for images only.
    const posted = [];
    const send = sendWith ? { attachment: sendWith } : {
        attachment(props, attachment) {
            const options = { id: attachment.id };
            if ('image' === attachment.type && props.size) { options['image-size'] = props.size; }
            return wp.media.post('send-attachment-to-editor', { attachment: options });
        },
    };
    const wp = { media: { controller: { Library }, editor: { send }, post(action, request) { posted.push([action, request]); return 'sent'; } } };
    const window = { wp };
    if (undefined !== data) { window.raplsPicInsertLink = data; }
    vm.runInNewContext(source, { window, wp });
    return { Library, Model, wp, posted, replaced: Library.prototype.defaultDisplaySettings !== original };
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

// A PDF uploaded in the window is in the selection, and shown, before it has
// a mime type or a thumbnail (review of 1.4.34).
const uploading = (Model) => attachment(Model, { uploading: true, filename: 'Report.PDF', type: undefined });
const finish = (file, attrs) => file.set(Object.assign({ uploading: false, type: 'application', mime: 'application/pdf' }, attrs));
for (const [setting, want] of [['none', 'none'], ['attachment', 'post']]) {
    const up = setup({ link: setting, size: 'large' });
    const lib = new up.Library();
    const file = uploading(up.Model);
    check(`uploading, setting "${setting}": core's default while it uploads`, lib.display(file).get('link'), 'file');
    finish(file, { picThumbnailId: 71 });
    check(`  ...and "${want}" once it has a thumbnail`, [lib.display(file).get('link'), lib.display(file).get('size')], [want, 'large']);
}
{
    const up = setup({ link: 'none', size: 'large' });
    const lib = new up.Library();
    const file = uploading(up.Model);
    lib.display(file).set('link', 'post');
    lib.display(file).set('size', 'thumbnail');
    finish(file, { picThumbnailId: 71 });
    check('uploading, changed in the window meanwhile: the window\'s choice kept', [lib.display(file).get('link'), lib.display(file).get('size')], ['post', 'thumbnail']);
    const failed = uploading(up.Model);
    lib.display(failed);
    finish(failed, {});
    check('uploaded, but no thumbnail made: core\'s default', lib.display(failed).get('link'), 'file');
    const notPdf = uploading(up.Model);
    lib.display(notPdf);
    finish(notPdf, { mime: 'application/octet-stream', picThumbnailId: 73 });
    check('named .pdf but not one: core\'s default', lib.display(notPdf).get('link'), 'file');
    const doc = attachment(up.Model, { uploading: true, filename: 'notes.docx' });
    lib.display(doc);
    doc.set({ uploading: false, mime: 'application/pdf', picThumbnailId: 72 });
    check('a file that is not named .pdf is not waited for', lib.display(doc).get('link'), 'file');
}

// The window's Size, which core sends only for an image (review of 1.4.34).
{
    const up = setup({ link: 'none', size: 'medium' });
    const pdfJson = { id: 7, type: 'application', mime: 'application/pdf', picThumbnailId: 70 };
    check('a thumbnailed PDF starts at the "Insert size" setting', new up.Library().display(attachment(up.Model, pdf)).get('size'), 'medium');
    check('Insert: the size picked is sent as image-size', [up.wp.media.editor.send.attachment({ size: 'large' }, pdfJson), up.posted[0]], ['sent', ['send-attachment-to-editor', { attachment: { id: 7, 'image-size': 'large' } }]]);
    const post = up.wp.media.post;
    up.wp.media.editor.send.attachment({ size: 'large' }, { id: 8, type: 'application', mime: 'application/pdf' });
    up.wp.media.editor.send.attachment({ size: 'large' }, { id: 9, type: 'application', mime: 'application/zip', picThumbnailId: 70 });
    check('  ...not for a PDF without a thumbnail, nor for another file', [up.posted[1][1], up.posted[2][1]], [{ attachment: { id: 8 } }, { attachment: { id: 9 } }]);
    check('  ...and wp.media.post is put back', up.wp.media.post === post, true);
    let raised = null;
    up.wp.media.post = () => { throw new Error('offline'); };
    const failing = up.wp.media.post;
    try { up.wp.media.editor.send.attachment({ size: 'large' }, pdfJson); } catch (e) { raised = e.message; }
    check('  ...even when sending throws', [raised, up.wp.media.post === failing], ['offline', true]);
}

// A size the window does not list (review of 1.4.35): core turned an
// "Insert size" of medium_large into "medium", where 1.4.20 inserted it.
{
    const names = ['thumbnail', 'medium', 'large', 'full'];
    const up = setup({ link: 'none', size: 'medium_large', sizes: names });
    const lib = new up.Library();
    const shown = lib.display(attachment(up.Model, Object.assign({ sizes: { full: {}, medium: {}, medium_large: {} } }, pdf)));
    check('a size core does not list: the window stays at core\'s start, noted', [shown.get('size'), shown.get('picSizeStart')], ['medium', 'medium']);
    const pdfJson = { id: 7, type: 'application', mime: 'application/pdf', picThumbnailId: 70 };
    up.wp.media.editor.send.attachment(Object.assign({}, shown.attrs), pdfJson);
    check('  ...left alone there: no size sent, so the setting decides', up.posted[0][1], { attachment: { id: 7 } });
    up.wp.media.editor.send.attachment(Object.assign({}, shown.attrs, { size: 'large' }), pdfJson);
    check('  ...changed there: the window\'s size is sent', up.posted[1][1], { attachment: { id: 7, 'image-size': 'large' } });

    const listed = setup({ link: 'none', size: 'large', sizes: names });
    const noFile = new listed.Library().display(attachment(listed.Model, Object.assign({ sizes: { full: {}, medium: {} } }, pdf)));
    check('a listed size the thumbnail has no file for: not shown either', [noFile.get('size'), noFile.get('picSizeStart')], ['medium', 'medium']);
    const withFile = new listed.Library().display(attachment(listed.Model, Object.assign({ sizes: { full: {}, large: {} } }, pdf)));
    check('  ...one it has: the window starts there', [withFile.get('size'), withFile.get('picSizeStart')], ['large', undefined]);

    const emptyList = setup({ link: 'none', size: 'large', sizes: [] });
    check('  ...an empty list from a broken filter does not stop it', new emptyList.Library().display(attachment(emptyList.Model, Object.assign({ sizes: { full: {}, large: {} } }, pdf))).get('size'), 'large');

    const up2 = setup({ link: 'none', size: 'medium_large', sizes: names });
    const lib2 = new up2.Library();
    const file = attachment(up2.Model, { uploading: true, filename: 'Report.pdf', type: undefined });
    lib2.display(file);
    file.set({ uploading: false, type: 'application', mime: 'application/pdf', picThumbnailId: 71, sizes: { full: {} } });
    check('  ...and for a PDF uploaded in the window', [lib2.display(file).get('size'), lib2.display(file).get('picSizeStart')], ['medium', 'medium']);
    const up3 = setup({ link: 'none', size: 'large', sizes: names });
    const lib3 = new up3.Library();
    const noLarge = attachment(up3.Model, { uploading: true, filename: 'Small.pdf', type: undefined });
    lib3.display(noLarge);
    noLarge.set({ uploading: false, type: 'application', mime: 'application/pdf', picThumbnailId: 72, sizes: { full: {}, medium: {} } });
    check('  ...uploaded, its thumbnail too small for the listed size: not shown', [lib3.display(noLarge).get('size'), lib3.display(noLarge).get('picSizeStart')], ['medium', 'medium']);
}

// Guards (mutation testing of 1.4.35): core or another plugin calling with
// no attachment, or one that is not a model; a send that posts without a
// request body.
{
    const up = setup({ link: 'none', size: 'large' });
    const lib = new up.Library();
    const asked = [];
    for (const odd of [undefined, null, {}, { get: 'not a function' }]) {
        try { asked.push(typeof up.Library.prototype.defaultDisplaySettings.call(lib, odd)); } catch (e) { asked.push(e.constructor.name); }
    }
    check('defaultDisplaySettings() with no attachment, or not a model: core\'s answer', asked, ['object', 'object', 'object', 'object']);

    const bare = setup({ link: 'none', size: 'large' }, 'none', 'medium', function () { return wpRef.media.post('send-attachment-to-editor'); });
    var wpRef = bare.wp;
    let raised = null;
    try { bare.wp.media.editor.send.attachment({ size: 'large' }, { id: 7, mime: 'application/pdf', picThumbnailId: 70 }); } catch (e) { raised = e.constructor.name; }
    check('  ...and a send that posts no request: nothing thrown', [raised, bare.posted[0]], [null, ['send-attachment-to-editor', undefined]]);
}

let threw = null;
try { vm.runInNewContext(source, { window: {}, wp: undefined }); } catch (e) { threw = e.message; }
check('no media window on the page: nothing thrown', threw, null);

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
