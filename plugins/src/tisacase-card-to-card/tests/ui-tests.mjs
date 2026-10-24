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
customer.window.close(); admin.window.close();
console.log('\nResult: ' + count + ' UI checks passed (jsdom, not a real browser/Woo site)');
