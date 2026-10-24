import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import {JSDOM, VirtualConsole} from 'jsdom';
const root = path.resolve(import.meta.dirname, '..');
const fixtures = process.env.TCC_TEST_OUTPUT || '.cache/tcc-tests';
let count = 0;
function test(name, callback) { callback(); count++; console.log('OK ' + name); }
const pause = () => new Promise(resolve => setTimeout(resolve, 0));
function dom(file) {
    const console = new VirtualConsole();
    console.on('jsdomError', error => { if (!error.message.includes('navigation')) throw error; });
    return new JSDOM(fs.readFileSync(path.join(fixtures, file), 'utf8'), {url: 'https://shop.example.test/checkout/order-received/101/', runScripts: 'outside-only', virtualConsole: console});
}
const customer = dom('customer.html'); const w = customer.window; let copied; let calls = [];
Object.defineProperty(w, 'isSecureContext', {value: true});
Object.defineProperty(w.navigator, 'clipboard', {configurable: true, value: {writeText: async text => { copied = text; }}});
w.URL.createObjectURL = () => 'blob:test-image'; w.URL.revokeObjectURL = () => {};
class XHR {
    constructor() { this.upload = {}; calls.push(this); }
    open(method, url) { this.method = method; this.url = url; }
    send(data) { this.sent = data; }
}
w.XMLHttpRequest = XHR;
w.eval(fs.readFileSync(path.join(root, 'assets/frontend.js'), 'utf8'));
const panel = w.document.querySelector('.tcc-payment'), form = panel.querySelector('form');
const submit = () => form.dispatchEvent(new w.SubmitEvent('submit', {bubbles: true, cancelable: true, submitter: form.querySelector('button[type=submit]')}));
panel.querySelector('[data-tcc-copy="485000"]').click(); await pause();
test('Copy uses exact ASCII amount, not formatted/rounded text', () => assert.equal(copied, '485000'));
test('Copy feedback is accessible', () => assert.match(panel.querySelector('.tcc-copy-feedback').textContent, /کپی شد/));
panel.querySelector('.tcc-bank [data-tcc-copy]').click(); await pause();
test('Copy card removes visual spaces', () => assert.match(copied, /^\d{16}$/));
w.navigator.clipboard.writeText = async () => { throw new Error('denied'); };
panel.querySelector('[data-tcc-copy]').click(); await pause();
test('Clipboard failure offers manual fallback', () => assert.match(panel.querySelector('.tcc-copy-feedback').textContent, /دستی/));
const input = form.querySelector('[type=file]');
Object.defineProperty(input, 'files', {configurable: true, value: [new w.File(['PNG'], 'receipt.png', {type: 'image/png'})]});
input.dispatchEvent(new w.Event('change'));
test('Selected image previews without uploading', () => assert.equal(form.querySelector('.tcc-preview').hidden, false));
Object.defineProperty(input, 'files', {configurable: true, value: [new w.File(['PDF'], 'receipt.pdf', {type: 'application/pdf'})]});
input.dispatchEvent(new w.Event('change'));
test('Unsupported file gets clear message', () => assert.match(form.querySelector('.tcc-feedback').textContent, /JPG/));
Object.defineProperty(input, 'files', {configurable: true, value: []});
const requestKey = form.elements.request_key.value;
submit();
test('Upload targets same-origin admin endpoint', () => { assert.equal(calls[0].method, 'POST'); assert.equal(new URL(calls[0].url).origin, w.location.origin); });
test('Upload includes nonce, token and idempotency key', () => { for (const field of ['_wpnonce', 'tcc_token', 'request_key']) assert.ok(calls[0].sent.get(field)); });
test('Button disabled while uploading', () => assert.equal(form.querySelector('[type=submit]').disabled, true));
calls[0].upload.onprogress({lengthComputable: true, loaded: 5, total: 10});
test('Byte progress visible', () => assert.equal(form.querySelector('progress').value, 50));
calls[0].onerror();
test('Network failure keeps same request identity and unlocks retry', () => { assert.equal(form.elements.request_key.value, requestKey); assert.equal(form.querySelector('[type=submit]').disabled, false); });
submit(); calls[1].status = 400; calls[1].response = {success: false, data: {message: 'خطای امن تست'}}; calls[1].onload();
test('Server error shown as text without dropping the form', () => assert.equal(form.querySelector('.tcc-feedback').textContent, 'خطای امن تست'));
submit(); calls[2].status = 502; calls[2].response = null; calls[2].onload();
test('Non-JSON proxy response gives usable fallback', () => assert.match(form.querySelector('.tcc-feedback').textContent, /پاسخ سرور/));
submit(); calls[3].ontimeout();
test('Timeout allows retry with no new order/key', () => { assert.equal(form.elements.request_key.value, requestKey); assert.equal(form.querySelector('[type=submit]').disabled, false); });

const admin = dom('admin.html'); const a = admin.window; const doc = a.document; let fetched = [];
a.fetch = async (url, options) => { fetched.push({url, options}); return {ok: false, json: async () => ({success: false, data: {message: 'وضعیت تغییر کرده است'}})}; };
a.eval(fs.readFileSync(path.join(root, 'assets/admin.js'), 'utf8'));
const af = doc.querySelector('.tcc-review-form'), box = doc.querySelector('.tcc-order-box');
function decide(value) { af.dispatchEvent(new a.SubmitEvent('submit', {bubbles: true, cancelable: true, submitter: box.querySelector('button[value="' + value + '"]')})); }
test('No nested forms in actual PHP render', () => { assert.equal(doc.querySelectorAll('form form').length, 0); assert.equal(doc.querySelectorAll('form').length, 2); });
test('External review form owns all decision controls', () => { for (const control of box.querySelectorAll('[form]')) assert.equal(control.form, af); });
test('Method name visible in order box', () => assert.match(box.textContent, /کارت به کارت تیساکیس/));
decide('approve'); await pause();
test('Approve blocked before explicit bank confirmation', () => { assert.equal(fetched.length, 0); assert.match(box.querySelector('.tcc-feedback').textContent, /ورود وجه/); });
decide('reject'); await pause();
test('Reject requires a public reason', () => { assert.equal(fetched.length, 0); assert.match(box.querySelector('.tcc-feedback').textContent, /علت/); });
const preset = box.querySelector('.tcc-reason-preset'); preset.selectedIndex = 1; preset.dispatchEvent(new a.Event('change', {bubbles: true}));
test('Reason preset fills public reason only', () => { assert.ok(box.querySelector('[name=reason]').value); assert.equal(box.querySelector('[name=private_note]').value, ''); });
box.querySelector('[name=private_note]').value = 'INTERNAL'; decide('reject'); await pause();
test('Reject posts same order with revision and correct decision', () => { const data = fetched[0].options.body; assert.equal(data.get('decision'), 'reject'); assert.equal(data.get('order_id'), '101'); assert.equal(data.get('revision'), '2'); assert.equal(data.get('private_note'), 'INTERNAL'); assert.ok(data.get('_wpnonce')); });
test('Server conflict visible; buttons re-enabled', () => { assert.match(box.querySelector('.tcc-feedback').textContent, /تغییر کرده/); assert.equal(box.querySelector('[value=approve]').disabled, false); });
box.querySelector('[name=confirmed]').checked = true; decide('approve'); await pause();
test('Approval includes explicit confirmation', () => assert.equal(fetched[1].options.body.get('confirmed'), 'yes'));
const silent = doc.querySelector('[name=order_total]'); silent.value = '999'; const silentBefore = fetched.length; decide('approve'); await pause();
test('Silent/select-widget edits detected even without native change event', () => assert.equal(fetched.length, silentBefore)); silent.value = '485000';
const dirty = doc.querySelector('[name=order_total]'); dirty.value = '100'; dirty.dispatchEvent(new a.Event('input', {bubbles: true})); const before = fetched.length;
decide('approve'); await pause();
test('Unsaved Woo order edits block financial decisions', () => { assert.equal(fetched.length, before); assert.match(box.querySelector('.tcc-feedback').textContent, /ذخیره/); });
const image = box.querySelector('.tcc-admin-image img'); box.querySelector('.tcc-rotate').click();
test('Rotation changes presentation, not receipt source', () => { assert.equal(image.style.transform, 'rotate(90deg)'); assert.ok(image.src.includes('action=tcc_receipt')); });
const settingsDom = dom('settings.html'); const sw = settingsDom.window, sd = sw.document;
const sf = sd.querySelector('#tcc-settings-form');
test('No-JS settings keep all panels visible and submittable', () => {
    assert.equal(sf.querySelectorAll('[data-tcc-panel][hidden]').length, 0);
    assert.equal(new sw.FormData(sf).get('settings[hours]'), '12');
});
sw.eval(fs.readFileSync(path.join(root, 'assets/admin.js'), 'utf8'));
const tabs = Array.from(sf.querySelectorAll('[role=tab]'));
test('Settings progressively enhance to one accessible active tab', () => {
    assert.equal(sf.querySelectorAll('[role=tabpanel]:not([hidden])').length, 1);
    assert.equal(tabs[0].getAttribute('aria-selected'), 'true');
    assert.equal(tabs.filter(tab => tab.tabIndex === 0).length, 1);
});
tabs[1].click();
test('Tab click updates active panel and submitted UI tab without resetting fields', () => {
    assert.equal(sf.elements.ui_tab.value, 'payment'); assert.equal(sd.querySelector('#tcc-tab-payment').hidden, false);
    assert.equal(sf.querySelector('[name="settings[hours]"]').value, '12');
});
test('Hidden panels still submit every setting with the same nonce', () => {
    const data = new sw.FormData(sf);
    for (const key of ['enabled', 'instructions', 'review_text', 'support', 'receipt_required', 'max_mb', 'hours', 'guest_days', 'notify_admin', 'notify_customer', 'recipients', 'reject_reasons', 'default_card']) assert.notEqual(data.get('settings[' + key + ']'), null, key);
    assert.equal(data.get('reviewers'), '2,7'); assert.ok(data.get('_wpnonce'));
});
tabs[1].dispatchEvent(new sw.KeyboardEvent('keydown', {key: 'ArrowLeft', bubbles: true, cancelable: true}));
test('RTL left arrow moves to the next tab and transfers focus', () => { assert.equal(sf.elements.ui_tab.value, 'notifications'); assert.equal(sd.activeElement, tabs[2]); });
tabs[2].dispatchEvent(new sw.KeyboardEvent('keydown', {key: 'ArrowRight', bubbles: true, cancelable: true}));
test('RTL right arrow moves to the previous tab', () => assert.equal(sf.elements.ui_tab.value, 'payment'));
tabs[1].dispatchEvent(new sw.KeyboardEvent('keydown', {key: 'End', bubbles: true, cancelable: true}));
test('End selects final tab', () => assert.equal(sf.elements.ui_tab.value, 'access'));
tabs[3].dispatchEvent(new sw.KeyboardEvent('keydown', {key: 'Home', bubbles: true, cancelable: true}));
test('Home returns to first tab', () => assert.equal(sf.elements.ui_tab.value, 'cards'));
const hours = sf.querySelector('[name="settings[hours]"]'); hours.value = '0'; hours.dispatchEvent(new sw.Event('invalid'));
test('Invalid input automatically reveals its hidden tab', () => { assert.equal(sd.querySelector('#tcc-tab-payment').hidden, false); assert.equal(sf.elements.ui_tab.value, 'payment'); }); hours.value = '12';
hours.dispatchEvent(new sw.Event('input', {bubbles: true}));
test('Settings show a concise accessible unsaved-changes indicator', () => assert.match(sf.querySelector('[role=status]').textContent, /ذخیره‌نشده/));
tabs[0].click();
const row = sf.querySelector('.tcc-card-row'); const bank = row.querySelector('[data-card-field=bank]'), label = row.querySelector('[data-card-field=label]');
label.value = '<img src=x onerror=alert(1)>'; label.dispatchEvent(new sw.Event('input', {bubbles: true}));
test('Live card summary uses text, never HTML from user input', () => { assert.equal(row.querySelector('.tcc-card-title').textContent, label.value); assert.equal(row.querySelector('.tcc-card-title img'), null); });
row.querySelector('[data-card-field=number]').value = '۶۰۳۷ ۹۹۰۰ ۰۰۰۰ ۸۷۶۵'; row.querySelector('[data-card-field=number]').dispatchEvent(new sw.Event('input', {bubbles: true}));
test('Card summary retains last four Persian digits only', () => assert.equal(row.querySelector('.tcc-card-last4').textContent, '•••• ۸۷۶۵'));
const removed = row.querySelector('[data-card-field=remove]'); removed.checked = true; removed.dispatchEvent(new sw.Event('change', {bubbles: true}));
test('Card deletion is visibly staged, not silently removed', () => { assert.equal(row.classList.contains('is-removing'), true); assert.match(row.querySelector('.tcc-card-state').textContent, /پس از ذخیره/); assert.equal(new sw.FormData(sf).get('settings[cards][0][remove]'), '1'); });
removed.checked = false; removed.dispatchEvent(new sw.Event('change', {bubbles: true}));
const add = sf.querySelector('#tcc-add-card'); add.click();
test('New card opens with unique field indexes and focuses its number', () => {
    const rows = sf.querySelectorAll('#tcc-cards > .tcc-card-row'); assert.equal(rows.length, 3); assert.equal(rows[2].open, true);
    assert.equal(sd.activeElement.name, 'settings[cards][2][number]');
});
for (let i = 0; i < 25; i++) add.click();
test('Add-card UI enforces twenty-row limit with no index collisions', () => {
    const names = Array.from(sf.querySelectorAll('#tcc-cards [data-card-field=number]')).map(input => input.name);
    assert.equal(names.length, 20); assert.equal(new Set(names).size, 20); assert.equal(add.disabled, true); assert.equal(sf.querySelector('#tcc-card-count').textContent, '20');
});
const emptyDom = dom('settings-empty.html'); const ew = emptyDom.window; ew.eval(fs.readFileSync(path.join(root, 'assets/admin.js'), 'utf8'));
test('First-run card editor works without preconfigured destinations', () => { assert.equal(ew.document.querySelector('.tcc-card-row').open, true); ew.document.querySelector('#tcc-add-card').click(); assert.equal(ew.document.querySelectorAll('#tcc-cards > details').length, 2); });
customer.window.close(); admin.window.close(); settingsDom.window.close(); emptyDom.window.close();
console.log('\nResult: ' + count + ' UI checks passed (jsdom, not a real browser/Woo site)');
