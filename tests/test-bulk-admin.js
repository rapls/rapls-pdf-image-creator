/**
 * The Bulk Generate screen: a cursor, not a list (Codex review of 1.4.25, 5),
 * and Stop that says it waits for the PDF being drawn (6).
 *
 *   node tests/test-bulk-admin.js
 *
 * admin.js runs against a stand-in jQuery that keeps each element's state and
 * holds every $.ajax call until the test answers it, so the order of requests
 * and what the screen says between them can be checked.
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

function makeElement() {
    return {
        _text: '', _disabled: undefined, _shown: false, _checked: false, _data: {}, children: [], _handlers: {}, _classes: [], length: 1,
        0: { scrollHeight: 0 },
        prop(name, value) {
            if ('disabled' === name) { if (undefined === value) { return this._disabled; } this._disabled = value; }
            if ('checked' === name) { if (undefined === value) { return this._checked; } this._checked = value; }
            return this;
        },
        text(value) { if (undefined === value) { return this._text; } this._text = String(value); return this; },
        show() { this._shown = true; return this; },
        hide() { this._shown = false; return this; },
        data(key, value) { if (undefined === value) { return this._data[key]; } this._data[key] = value; return this; },
        _attr: {},
        css() { return this; },
        attr(name, value) { if (undefined === value) { return this._attr[name]; } this._attr = Object.assign({}, this._attr, { [name]: String(value) }); return this; },
        removeAttr(name) { const copy = Object.assign({}, this._attr); delete copy[name]; this._attr = copy; return this; },
        trigger(event) { if ('focus' === event) { focused = this; } return this; },
        addClass(c) { if (!this._classes.includes(c)) { this._classes = this._classes.concat([c]); } return this; },
        removeClass(c) { this._classes = this._classes.filter((x) => x !== c); return this; },
        hasClass(c) { return this._classes.includes(c); },
        on(event, a, b) { this._handlers[event] = 'function' === typeof a ? a : b; return this; },
        empty() { this.children = []; return this; },
        append(child) { this.children.push(child); return this; },
        appendTo(selector) { $(selector).append(this); return this; },
        scrollTop() { return this; },
        is() { return this._checked; },
        val() { return ''; },
        closest() { return makeElement(); },
        click() { this._handlers.click && this._handlers.click({ preventDefault() {} }); return this; },
    };
}

const elements = {};
let ready = null;
let focused = null;

// The three tabs, as one collection and one by one.
const tabs = ['settings', 'bulk', 'status'].map((name, i) => {
    const tab = makeElement();
    tab._data.tab = name;
    if (0 === i) { tab._classes = ['nav-tab-active']; }
    elements[`.rapls-pic-tabs .nav-tab[data-tab="${name}"]`] = tab;
    return tab;
});
elements['.rapls-pic-tabs .nav-tab'] = {
    length: tabs.length,
    on(event, cb) { tabs.forEach((tab) => { tab._handlers[event] = cb; }); return this; },
    removeClass(c) { tabs.forEach((tab) => tab.removeClass(c)); return this; },
    not(selector) { const left = tabs.filter((tab) => !tab.hasClass(selector.replace('.', ''))); return { attr(n, v) { left.forEach((tab) => tab.attr(n, v)); return this; } }; },
    attr(name, value) { tabs.forEach((tab) => tab.attr(name, value)); return this; },
    index(tab) { return tabs.indexOf(tab); },
    eq(i) { return tabs[i]; },
};
const requests = [];
function $(arg) {
    if (arg === documentStub) {
        return { ready(cb) { ready = cb; }, on() { return this; } };
    }
    if ('string' === typeof arg && '<' === arg.charAt(0)) {
        const created = makeElement();
        const cls = /class="([^"]*)"/.exec(arg);
        created.cls = cls ? cls[1] : '';
        return created;
    }
    // A tab no one has: nothing.
    if (!elements[arg] && 'string' === typeof arg && -1 !== arg.indexOf('[data-tab=')) { return { length: 0 }; }
    if (!elements[arg]) { elements[arg] = makeElement(); }
    return elements[arg];
}
$.ajax = (options) => { requests.push(options); };
const documentStub = {};

let confirms = 0;
let alerts = [];
const i18n = {
    processing: 'Processing...', complete: 'Complete!', error: 'An error occurred.', confirmBulk: 'Start?',
    generating: 'Generating thumbnail %1$d of %2$d...', scan: 'Scan for PDFs', stopped: 'Stopped',
    stopping: 'Stopping after the current PDF...', continueRun: 'Continue', scanning: 'Scanning... %d PDFs so far',
    processingFile: 'Processing: %s', failed: 'Failed', requestFailed: 'Request failed', errorShort: 'Error',
    httpStatus: 'Status: %s', httpError: 'Error: %s',
};

$('#rapls-pic-bulk-start').text('Start Generation');
$('#rapls-pic-bulk-scan').text('Scan for PDFs');

const source = fs.readFileSync(path.join(__dirname, '..', 'admin', 'js', 'admin.js'), 'utf8');
vm.runInNewContext(source, {
    jQuery: $,
    document: documentStub,
    window: { location: { hash: '' } },
    location: { reload() {} },
    raplsPicAdmin: { ajaxUrl: '/admin-ajax.php', nonce: 'n', i18n },
    confirm: () => { confirms++; return true; },
    alert: (m) => { alerts.push(m); },
    setTimeout: () => {},
});
ready();

// The Statistics box asks on load, page by page.
const answer = (data, success = true) => { const r = requests.shift(); r.success({ success, data }); return r; };
const fault = () => { const r = requests.shift(); r.error({}, 'error', 'Gateway Timeout'); return r; };
const next = () => requests[0];

let r = answer({ total: 200, with_thumbnail: 50, without_thumbnail: 150, after: 1200, done: false });
check('Statistics: the first page is asked from the start', [r.data.action, r.data.after], ['rapls_pic_bulk_status', 0]);
check('  ...the next one from where the first stopped, with its counts', [next().data.after, next().data.total, next().data.with_thumbnail], [1200, 200, 50]);
answer({ total: 260, with_thumbnail: 60, without_thumbnail: 200, after: 1260, done: true });
check('  ...the totals shown are the last page\'s, and it stops there', [$('#rapls-pic-stats-total').text(), requests.length, $('#rapls-pic-refresh-stats').prop('disabled')], ['260', 0, false]);

console.log('\n--- Tabs, as a screen reader is told of them (Codex review of 1.4.26, 4) ---');
check('Only the chosen tab is in the Tab order, set by the script', tabs.map((t) => t.attr('tabindex')), [undefined, '-1', '-1']);
const key = (tab, k) => { let prevented = false; tab._handlers.keydown.call(tab, { key: k, preventDefault() { prevented = true; } }); return prevented; };
key(tabs[0], 'ArrowRight');
check('ArrowRight chooses the next tab, and focuses it', [tabs.map((t) => t.attr('aria-selected')), focused === tabs[1]], [['false', 'true', 'false'], true]);
check('  ...the chosen tab is the one Tab lands on', tabs.map((t) => t.attr('tabindex')), ['-1', undefined, '-1']);
key(tabs[0], 'ArrowLeft');
check('ArrowLeft from the first goes round to the last', tabs.map((t) => t.attr('aria-selected')), ['false', 'false', 'true']);
key(tabs[2], 'Home');
check('Home goes to the first, End to the last', [tabs[0].attr('aria-selected'), (key(tabs[0], 'End'), tabs[2].attr('aria-selected'))], ['true', 'true']);
check('  ...other keys are left alone', key(tabs[2], 'a'), false);

console.log('\n--- Scan ---');
$('#rapls-pic-bulk-scan').click();
r = answer({ done: false, after: 1200, total: 150, total_pdfs: 200, with_thumbnail: 50 });
check('Scan: page one from the start', [r.data.action, r.data.after], ['rapls_pic_bulk_scan', 0]);
check('  ...page two from the cursor, counts carried, progress shown', [next().data.after, next().data.total_pdfs, next().data.with_thumbnail, $('#rapls-pic-bulk-scan').text()], [1200, 200, 50, 'Scanning... 200 PDFs so far']);
answer({ done: true, after: 1260, total: 200, total_pdfs: 260, with_thumbnail: 60, rows: 200, statuses: '', note: '' });
check('  ...the result: 200 to do, Start enabled, no list kept', [$('#rapls-pic-bulk-total').text(), $('#rapls-pic-bulk-start').prop('disabled'), requests.length], ['200', false, 0]);

console.log('\n--- Generate ---');
$('#rapls-pic-bulk-start').click();
check('Start asks first, then asks for the next PDF after 0', [confirms, next().data.action, next().data.after], [1, 'rapls_pic_bulk_next', 0]);
answer({ pdf_id: 1002, filename: 'a.pdf', after: 1002, done: false });
check('  ...then draws that PDF by its ID', [next().data.action, next().data.pdf_id, $('#rapls-pic-bulk-status').text()], ['rapls_pic_bulk_generate', 1002, 'Generating thumbnail 1 of 200...']);
answer({ pdf_id: 1002 });
check('  ...and asks for the next one after it', [next().data.action, next().data.after, $('#rapls-pic-stat-generated').text()], ['rapls_pic_bulk_next', 1002, '1']);
check('  ...the progress bar says how far, to a screen reader too (1 of 200)', $('#rapls-pic-progress').attr('aria-valuenow'), '1');
answer({ pdf_id: 1005, filename: 'b.pdf', after: 1005, done: false });
fault();
check('A draw that never answers counts as failed, and the run goes on past it', [$('#rapls-pic-stat-failed').text(), next().data.action, next().data.after], ['1', 'rapls_pic_bulk_next', 1005]);

console.log('\n--- Stop, then Continue (Codex review of 1.4.25, 6) ---');
answer({ pdf_id: 1007, filename: 'c.pdf', after: 1007, done: false });
$('#rapls-pic-bulk-stop').click();
check('Stop says it waits for the PDF being drawn', [$('#rapls-pic-bulk-status').text(), $('#rapls-pic-bulk-stop').prop('disabled')], ['Stopping after the current PDF...', true]);
answer({ pdf_id: 1007 });
check('  ...that PDF is still counted, and nothing more is asked', [$('#rapls-pic-stat-generated').text(), requests.length, $('#rapls-pic-bulk-status').text()], ['2', 0, 'Stopped']);
check('  ...Start becomes Continue', [$('#rapls-pic-bulk-start').text(), $('#rapls-pic-bulk-start').prop('disabled')], ['Continue', false]);
$('#rapls-pic-bulk-start').click();
check('Continue does not ask again, and goes on after the last PDF', [confirms, next().data.after, $('#rapls-pic-stat-generated').text()], [1, 1007, '2']);
r = answer({ pdf_id: null, filename: '', after: 2000, done: false });
check('Nothing in the pages looked at: asks again from where the server got to', [next().data.action, next().data.after], ['rapls_pic_bulk_next', 2000]);
answer({ pdf_id: null, filename: '', after: 2100, done: true });
check('The end: Complete!, Start back to its name and disabled', [$('#rapls-pic-bulk-status').text(), $('#rapls-pic-bulk-start').text(), $('#rapls-pic-bulk-start').prop('disabled'), requests.length], ['Complete!', 'Start Generation', true, 0]);

console.log('\n--- Stop while the next PDF is being looked for (Codex review of 1.4.26, 2) ---');
$('#rapls-pic-bulk-scan').click();
answer({ done: true, after: 1260, total: 2, total_pdfs: 2, with_thumbnail: 0, rows: 2, statuses: '', note: '' });
$('#rapls-pic-bulk-start').click();
check('A run is looking for its first PDF', [next().data.action, next().data.after], ['rapls_pic_bulk_next', 0]);
$('#rapls-pic-bulk-stop').click();
answer({ pdf_id: 10, filename: 'ten.pdf', after: 10, done: false });
check('  ...Stop then: the PDF found is not drawn, and the run stops', [requests.length, $('#rapls-pic-bulk-status').text(), $('#rapls-pic-bulk-start').text()], [0, 'Stopped', 'Continue']);
$('#rapls-pic-bulk-start').click();
check('  ...Continue looks for the same PDF again', [next().data.action, next().data.after], ['rapls_pic_bulk_next', 0]);
answer({ pdf_id: null, filename: '', after: 1260, done: true });

$('#rapls-pic-bulk-scan').click();
answer({ done: true, after: 1260, total: 1, total_pdfs: 1, with_thumbnail: 0, rows: 1, statuses: '', note: '' });
$('#rapls-pic-bulk-start').click();
$('#rapls-pic-bulk-stop').click();
answer({ pdf_id: null, filename: '', after: 1260, done: true });
check('Stop while the look reaches the end: Complete!, not Stopped', [$('#rapls-pic-bulk-status').text(), $('#rapls-pic-bulk-start').text(), requests.length], ['Complete!', 'Start Generation', 0]);

console.log('\n--- A failed look for the next PDF ---');
$('#rapls-pic-bulk-scan').click();
answer({ done: true, after: 1260, total: 3, total_pdfs: 3, with_thumbnail: 0, rows: 3, statuses: '', note: '' });
$('#rapls-pic-bulk-start').click();
check('A new scan starts the run from the beginning, asking first', [confirms, next().data.after, $('#rapls-pic-stat-generated').text()], [4, 0, '0']);
fault();
check('  ...a failed look stops, so Continue asks again from the same place', [$('#rapls-pic-bulk-status').text(), $('#rapls-pic-bulk-start').text(), requests.length], ['Stopped', 'Continue', 0]);
$('#rapls-pic-bulk-start').click();
check('  ...and Continue does', next().data.after, 0);

console.log('\n--- An unknown tab in the address, and Stop at the end ---');
// A second load of the page with "#tab-foo": the tabs stay as they are.
const before = tabs.map((t) => [t.attr('aria-selected'), t.attr('tabindex')]);
vm.runInNewContext(source, {
    jQuery: $, document: documentStub, window: { location: { hash: '#tab-foo' } }, location: { reload() {} },
    raplsPicAdmin: { ajaxUrl: '/admin-ajax.php', nonce: 'n', i18n }, confirm: () => true, alert: () => {}, setTimeout: () => {},
});
requests.length = 0;
ready();
requests.length = 0;
check('"#tab-foo" leaves the tabs as they were (one still reachable)', tabs.map((t) => [t.attr('aria-selected'), t.attr('tabindex')]), before);

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
