/**
 * admin.js where it is not on the Bulk Generate screen, or is given an
 * address it did not make (review after 1.4.31).
 *
 *   node tests/test-admin-tabs.js
 *
 * A loose stand-in for jQuery: every call answers a collection whose methods
 * hand it back, with a length per selector. Like jQuery's selector engine, it
 * throws on an attribute selector broken by a quote in its value.
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

const source = fs.readFileSync(path.join(__dirname, '..', 'admin', 'js', 'admin.js'), 'utf8');

function run(hash, present) {
    const log = { ajax: 0, docEvents: [], threw: null, active: [] };
    let ready = null;
    const documentStub = { getElementById: (id) => (present.includes('#' + id) ? { id } : null) };

    function collection(selector, length) {
        const c = {
            length,
            selector,
            on() { return c; }, not() { return c; }, attr() { return c; }, removeAttr() { return c; },
            removeClass() { return c; }, text() { return c; }, data() { return undefined; }, prop() { return c; },
            hide() { return c; }, show() { return c; }, val() { return ''; }, is() { return false; },
            hasClass() { return false; }, next() { return c; }, closest() { return c; }, find() { return c; },
            each() { return c; }, trigger() { return c; },
            filter(fn) { return 'function' === typeof fn ? collection(selector + ':filtered', 0) : c; },
            addClass(cls) { if ('active' === cls || 'nav-tab-active' === cls) { log.active.push(selector); } return c; },
        };
        return c;
    }

    function $(arg) {
        if (arg === documentStub) {
            return {
                ready(cb) { ready = cb; },
                on(event) { log.docEvents.push(event); return this; },
                trigger() { return this; },
            };
        }
        if ('function' === typeof arg) { ready = arg; return null; }
        if (null === arg) { return collection('null', 0); }
        if (arg && 'object' === typeof arg) { return collection('#' + arg.id, 1); }
        if (/\[[\w-]+="[^"\]]*"[^\]]/.test(arg)) {
            throw new Error('Syntax error, unrecognized expression: ' + arg);
        }
        return collection(arg, present.includes(arg) ? 1 : 0);
    }
    $.ajax = () => { log.ajax++; };

    vm.runInNewContext(source, {
        jQuery: $,
        document: documentStub,
        window: { location: { hash } },
        location: { reload() {} },
        raplsPicAdmin: { ajaxUrl: '/admin-ajax.php', nonce: 'n', i18n: {} },
        confirm: () => true,
        alert: () => {},
        setTimeout: () => {},
    });
    try {
        ready();
    } catch (e) {
        log.threw = e.message;
    }
    return log;
}

console.log('\n--- An address with a quote in it ---');
const quoted = run('#tab-a"b', ['.rapls-pic-tabs .nav-tab', '#rapls-pic-refresh-stats']);
check('a quote in the tab name throws nothing, and chooses no tab', [quoted.threw, quoted.active], [null, []]);

console.log('\n--- The Media Library, where there is no Statistics box ---');
const library = run('', []);
check('nothing is asked, and no tab listener is left behind', [library.threw, library.ajax, library.docEvents.includes('rapls-pic-tab')], [null, 0, false]);
const settings = run('', ['#rapls-pic-refresh-stats']);
check('  ...on the settings screen, the Status tab is waited for', [settings.ajax, settings.docEvents.includes('rapls-pic-tab')], [0, true]);

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
