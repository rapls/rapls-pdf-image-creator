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

// The block editor as the pickers see it: a context set by editor.BlockEdit for
// the image and gallery blocks, and a frame class that MediaUpload builds as it
// opens. Each load of block-editor.js gets its own.
function load(options = {}) {
    const seen = { notices: [], removed: [], success: [], fetched: [], uploadOptions: null, coreUpload: 0, queries: [] };
    const contexts = [];
    const values = new Map();
    const controlFilters = {};
    let blockEditFilter = null;
    let uploadFilter = null;
    let uploadAdded = null;
    class Base {
        constructor(opts) { this.options = opts; this.$el = { removeClass() {} }; this.content = { set(view) { seen.uploadOptions = view.options; } }; this.handlers = {}; this.stateValue = opts.state || null; }
        on(event, cb, ctx) { (this.handlers[event] = this.handlers[event] || []).push(cb.bind(ctx || this)); }
        trigger(event) { (this.handlers[event] || []).forEach((cb) => cb()); }
        state() { return this.stateValue; }
    }
    Base.prototype.uploadContent = function () { seen.coreUpload++; };
    Base.prototype.initialize = function () {};
    Base.extend = function (proto) { const Child = class extends Base {}; Object.assign(Child.prototype, proto); return Child; };
    const localWp = {
        hooks: { addFilter(hook, ns, cb) { if ('editor.MediaUpload' === hook) { uploadFilter = cb; } if ('editor.BlockEdit' === hook) { blockEditFilter = cb; } if ('editor.MediaPlaceholder' === hook || 'editor.MediaReplaceFlow' === hook) { controlFilters[hook] = cb; } } },
        compose: { createHigherOrderComponent: (fn) => fn },
        element: {
            createElement: (type, props, child) => ({ type, props, child }),
            createContext: (value) => { const c = { Provider: 'Provider' + contexts.length, value }; contexts.push(c); return c; },
            useContext: (c) => (values.has(c) ? values.get(c) : null),
        },
        data: { dispatch: () => ({
            createErrorNotice: (message, opts) => seen.notices.push({ message, options: opts }),
            removeNotice: (id) => seen.removed.push(id),
            createSuccessNotice: (message) => seen.success.push(message),
        }) },
        apiFetch: (request) => { seen.fetched.push(request); return false === options.apiOk ? Promise.reject(new Error('no')) : Promise.resolve({}); },
        Uploader: { queue: { on(event, cb) { if ('add' === event) { uploadAdded = cb; } } } },
        media: { view: { MediaFrame: { Select: Base }, UploaderInline: class { constructor(opts) { this.options = opts; } } }, query: (props) => { seen.queries.push(props); return {}; } },
    };
    const window = { raplsPicBlockEditor: Object.assign({ autoGenerate: true, noThumbnail: 'no thumbnail', uploadMessage: 'uploaded PDFs get no thumbnail', deleteUploaded: 'Delete', deleted: 'Deleted', deleteFailed: 'Not deleted' }, options.settings || {}) };
    vm.runInNewContext(source, { wp: localWp, window, Promise });
    const Frame = localWp.media.view.MediaFrame.Select;
    return {
        seen,
        Frame,
        upload: (id) => uploadAdded({ id }),
        query: (props) => localWp.media.query(props),
        blockEdit: (name, attributes) => blockEditFilter('BlockEdit')({ name, attributes }),
        // A MediaUpload rendered inside a block (its name, or '' for none; and the block's image id),
        // by the block's own media controls unless direct is set (another plugin's, in the same block).
        control: (hook, block) => { values.set(contexts[0], block); const out = controlFilters[hook]('Control')({}); values.clear(); return out; },
        picker(inside, props, blockId, direct) {
            const block = inside ? { name: inside, id: blockId } : null;
            values.set(contexts[0], block);
            values.set(contexts[1], direct ? null : block);
            const chosen = [];
            const element = uploadFilter('MediaUpload')(Object.assign({ allowedTypes: ['image'], onSelect: (media) => chosen.push(media), render: (args) => args }, props || {}));
            values.clear();
            return { element, chosen };
        },
        // Open it as MediaUpload does: the frame is built as it opens.
        open(element) {
            let frame = null;
            const args = element.props.render({ open() { frame = new Frame({ library: { type: element.props.allowedTypes } }); frame.initialize(); frame.trigger('open'); } });
            args.open();
            return frame;
        },
    };
}

const MARK = 'rapls-pic/with-thumbnail';

// Codex review of 1.4.29, 1: only the pickers of WordPress's own are opened to PDFs.
{
    const ed = load();
    const image = ed.picker('core/image');
    check('Image block: PDFs are offered alongside images, and the picker is marked for the server', JSON.stringify(image.element.props.allowedTypes), JSON.stringify(['image', 'application/pdf', MARK]));
    const featured = ed.picker('', { unstableFeaturedImageFlow: true });
    check('Featured image: the same', JSON.stringify(featured.element.props.allowedTypes), JSON.stringify(['image', 'application/pdf', MARK]));
    const onSelect = () => {};
    const other = ed.picker('', { onSelect });
    check('Another plugin\'s image picker is left as it is (Codex review of 1.4.29, 1)', JSON.stringify([other.element.props.allowedTypes, other.element.props.onSelect === onSelect]), JSON.stringify([['image'], true]));
    const otherBlock = ed.picker('acme/hero', { onSelect });
    check('  ...inside another block too', JSON.stringify([otherBlock.element.props.allowedTypes, otherBlock.element.props.onSelect === onSelect]), JSON.stringify([['image'], true]));
    const gallery = ed.picker('core/gallery', { gallery: true, onSelect });
    check('Gallery: PDFs offered, and its onSelect is the block\'s own', JSON.stringify([gallery.element.props.allowedTypes, gallery.element.props.onSelect === onSelect]), JSON.stringify([['image', 'application/pdf', MARK], true]));
    const audio = ed.picker('core/image', { allowedTypes: ['audio'], onSelect });
    check('A picker that does not ask for images is left alone, even in the image block', JSON.stringify(audio.element.props.allowedTypes), JSON.stringify(['audio']));
    const wrapped = ed.blockEdit('core/image', { id: 5 });
    const plain = ed.blockEdit('core/paragraph', {});
    check('editor.BlockEdit: the image block says so to what is inside it, with its image; others do not', JSON.stringify([wrapped.type, wrapped.props.value, plain.type]), JSON.stringify(['Provider0', { name: 'core/image', id: 5 }, 'BlockEdit']));
    const viaPlaceholder = ed.control('editor.MediaPlaceholder', { name: 'core/image', id: 5 });
    const viaReplace = ed.control('editor.MediaReplaceFlow', { name: 'core/gallery' });
    const outside = ed.control('editor.MediaPlaceholder', null);
    check('  ...its MediaPlaceholder and MediaReplaceFlow pass it on to their picker; outside such a block they do not', JSON.stringify([viaPlaceholder.type, viaPlaceholder.props.value, viaReplace.type, outside.type]), JSON.stringify(['Provider1', { name: 'core/image', id: 5 }, 'Provider1', 'Control']));
    const postFeatured = ed.picker('core/post-featured-image', {});
    const postFeaturedBlock = ed.blockEdit('core/post-featured-image', {});
    check('  ...the Post Featured Image block is a featured image picker: PDFs, no delete', JSON.stringify([postFeatured.element.props.allowedTypes, postFeaturedBlock.type]), JSON.stringify([['image', 'application/pdf', MARK], 'Provider0']));
    const direct = ed.picker('core/image', { onSelect }, 5, true);
    const directEmpty = ed.picker('core/image', { onSelect }, undefined, true);
    const directGallery = ed.picker('core/gallery', { value: 7, onSelect }, undefined, true);
    check('  ...another plugin\'s picker put straight into the image or gallery block is left as it is', JSON.stringify([direct.element.props.allowedTypes, directEmpty.element.props.allowedTypes, directGallery.element.props.allowedTypes, direct.element.props.onSelect === onSelect]), JSON.stringify([['image'], ['image'], ['image'], true]));
    const own = ed.picker('core/image', { value: 5 }, 5);
    const added = ed.picker('core/image', { value: 9, onSelect }, 5);
    check('  ...the block\'s own picker (its image) is opened to PDFs; another plugin\'s inside the block is not', JSON.stringify([own.element.props.allowedTypes, added.element.props.allowedTypes, added.element.props.onSelect === onSelect]), JSON.stringify([['image', 'application/pdf', MARK], ['image'], true]));

}

// Codex review of 1.4.26, 3: a PDF chosen for an image block is passed on only
// when it has a thumbnail.
for (const autoGenerate of [true, false]) {
    const ed = load({ settings: { autoGenerate } });
    const { element, chosen } = ed.picker('core/image');
    const label = autoGenerate ? 'on' : 'off';
    element.props.onSelect({ id: 1, mime: 'application/pdf' });
    check(`Image block (auto ${label}): a PDF without a thumbnail is not passed on, and said so`, JSON.stringify([chosen.length, ed.seen.notices.map((n) => n.message)]), JSON.stringify([0, ['no thumbnail']]));
    element.props.onSelect({ id: 2, mime: 'application/pdf', picThumbnailId: 20 });
    element.props.onSelect({ id: 3, mime: 'image/jpeg' });
    check(`  ...a PDF with a thumbnail, and an image, are`, JSON.stringify(chosen.map((m) => m.id)), JSON.stringify([2, 3]));
    element.props.onSelect([{ id: 4, mime: 'image/png' }, { id: 5, mime_type: 'application/pdf' }]);
    check(`  ...from several, only those without a thumbnail are left out`, JSON.stringify(chosen[2].map((m) => m.id)), JSON.stringify([4]));
    element.props.onSelect({ id: 6, mime: 'application/pdf', sizes: { full: { url: 'https://e.test/6.jpg' } } });
    check(`  ...a slimmed copy that kept the thumbnail's sizes is passed on`, chosen[3] && chosen[3].id, 6);
}

// Codex review of 1.4.27, 2 and 1.4.28, 3: with Auto Generate off, a PDF
// uploaded in the image block's picker can be deleted again from the notice,
// and the upload tab says beforehand that it gets no thumbnail.
async function chooseUploaded(how, options = {}) {
    const ed = load(Object.assign({ settings: { autoGenerate: false } }, options));
    const { element } = ed.picker('inside' in how ? how.inside : 'core/image', how.props || {});
    if ('here' === how.uploaded) {
        ed.open(element);
        ed.upload(41);
    } else if ('elsewhere' === how.uploaded) {
        ed.open(ed.picker('', { allowedTypes: ['application/pdf'] }).element);
        ed.upload(41);
        ed.open(element);
    } else if ('before-reopen' === how.uploaded) {
        ed.open(element);
        ed.upload(41);
        ed.open(element);
    } else if ('closed' === how.uploaded) {
        ed.upload(41);
        ed.open(element);
    }
    element.props.onSelect({ id: 41, mime: 'application/pdf' });
    const actions = (ed.seen.notices[0] && ed.seen.notices[0].options.actions) || [];
    if (actions[0]) { actions[0].onClick(); if (how.twice) { actions[0].onClick(); } await new Promise((r) => setTimeout(r, 0)); }
    return { ed, actions: actions.map((x) => x.label) };
}

(async () => {
    let r = await chooseUploaded({ uploaded: 'here' });
    check('Auto off: a PDF uploaded in the image block\'s picker can be deleted from the notice', JSON.stringify(r.actions), JSON.stringify(['Delete']));
    check('  ...for good, through the REST API', JSON.stringify(r.ed.seen.fetched), JSON.stringify([{ path: '/wp/v2/media/41?force=true', method: 'DELETE' }]));
    check('  ...and then says so in place of the notice', JSON.stringify([r.ed.seen.removed, r.ed.seen.success]), JSON.stringify([['rapls-pic-no-thumbnail'], ['Deleted']]));
    r = await chooseUploaded({ uploaded: 'here' }, { apiOk: false });
    check('  ...refused: says it was not deleted', JSON.stringify(r.ed.seen.notices.slice(1).map((n) => n.message)), JSON.stringify(['Not deleted']));
    r = await chooseUploaded({ uploaded: 'here', twice: true });
    check('  ...clicked twice: deleted once, and said once', JSON.stringify([r.ed.seen.fetched.length, r.ed.seen.success]), JSON.stringify([1, ['Deleted']]));
    for (const [how, label] of [['elsewhere', 'uploaded in another picker on the page'], ['closed', 'uploaded while no image picker was open'], ['before-reopen', 'uploaded the last time the picker was open'], ['none', 'not uploaded at all']]) {
        r = await chooseUploaded({ uploaded: how });
        check(`  ...never one ${label}`, JSON.stringify([r.actions, r.ed.seen.fetched]), JSON.stringify([[], []]));
    }
    r = await chooseUploaded({ uploaded: 'here', props: { value: 41 } });
    check('  ...nor the block\'s own PDF, preselected when the window opens', JSON.stringify(r.actions), JSON.stringify([]));
    r = await chooseUploaded({ uploaded: 'here', inside: '', props: { unstableFeaturedImageFlow: true } });
    check('  ...nor in the featured image picker: there, the notice only', JSON.stringify([r.actions, r.ed.seen.notices.length]), JSON.stringify([[], 1]));
    r = await chooseUploaded({ uploaded: 'here' }, { settings: { autoGenerate: true } });
    check('Auto on: nothing offered to delete', JSON.stringify([r.actions, r.ed.seen.fetched]), JSON.stringify([[], []]));

    // The upload tab: the image block's picker only, and only with Auto Generate off.
    let ed = load({ settings: { autoGenerate: false } });
    ed.open(ed.picker('core/image').element).uploadContent();
    check('Upload tab, auto off: the image block\'s picker says it first', ed.seen.uploadOptions && ed.seen.uploadOptions.message, 'uploaded PDFs get no thumbnail');
    ed.seen.uploadOptions = null;
    // A frame another plugin builds itself, right after the image block's opened, with the same types.
    const theirs = new ed.Frame({ library: { type: ['image', 'application/pdf', MARK] } });
    theirs.initialize();
    theirs.uploadContent();
    ed.open(ed.picker('', { unstableFeaturedImageFlow: true }).element).uploadContent();
    check('  ...the featured image picker and another plugin\'s frame keep core\'s', JSON.stringify([ed.seen.uploadOptions, ed.seen.coreUpload]), JSON.stringify([null, 2]));
    ed = load({ settings: { autoGenerate: true } });
    ed.open(ed.picker('core/image').element).uploadContent();
    check('Upload tab, auto on: core\'s', JSON.stringify([ed.seen.uploadOptions, ed.seen.coreUpload]), JSON.stringify([null, 1]));

    console.log(`\n${pass} passed, ${fail} failed`);
    process.exit(fail ? 1 : 0);
})();
