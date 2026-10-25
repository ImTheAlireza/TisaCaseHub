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
panel.querySelector('[data-tcc-copy="4850000"]').click(); await pause();
test('Copy converts IRT to exact ASCII rials, not formatted/rounded text', () => assert.equal(copied, '4850000'));
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
test('Persian picker displays selected filename as text', () => assert.equal(form.querySelector('[data-tcc-file-name]').textContent, 'receipt.png'));
test('Persian picker has a localized accessible name', () => assert.equal(input.getAttribute('aria-label'), 'انتخاب تصویر رسید'));
test('File chooser is localized independently of browser language', () => assert.match(form.querySelector('.tcc-file-action').textContent, /انتخاب تصویر/));
Object.defineProperty(input, 'files', {configurable: true, value: [new w.File(['PDF'], 'receipt.pdf', {type: 'application/pdf'})]});
input.dispatchEvent(new w.Event('change'));
test('Unsupported file gets clear message', () => assert.match(form.querySelector('.tcc-feedback').textContent, /JPG/));
Object.defineProperty(input, 'files', {configurable: true, value: []}); input.dispatchEvent(new w.Event('change'));
test('Clearing a file resets the Persian filename and removes stale preview', () => { assert.equal(form.querySelector('[data-tcc-file-name]').textContent, 'تصویری انتخاب نشده'); assert.equal(form.querySelector('.tcc-preview').hasAttribute('src'), false); });
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

const admin = dom('admin.html'); const a = admin.window; const doc = a.document;
let financialRequests = 0; a.fetch = async () => { financialRequests++; throw new Error('Financial AJAX must not run'); };
a.eval(fs.readFileSync(path.join(root, 'assets/admin.js'), 'utf8'));
const main = doc.querySelector('#post'), box = doc.querySelector('.tcc-order-box');
const reason = box.querySelector('[data-tcc-reason]'), confirmed = box.querySelector('[data-tcc-confirmed]');
const stage = value => box.querySelector('[data-tcc-decision][value="' + value + '"]').click();
const updateOrder = () => { const event = new a.SubmitEvent('submit', {bubbles: true, cancelable: true, submitter: doc.querySelector('#publish')}); main.dispatchEvent(event); return !event.defaultPrevented; };
test('One native Woo form, without nested or external review forms', () => { assert.equal(doc.querySelectorAll('form').length, 1); assert.equal(doc.querySelectorAll('form form').length, 0); });
test('Native form owns every prefixed decision field', () => { for (const control of box.querySelectorAll('[name^="tcc_review["]')) assert.equal(control.form, main); });
test('No financial action is preselected', () => assert.equal(box.querySelector('[data-tcc-decision]:checked'), null));
test('Method name remains visible inside the same order', () => assert.match(box.textContent, /کارت به کارت تیساکیس/));
for (const decision of ['approve', 'reject', 'correct', 'reconcile']) {
    stage(decision);
    test('Selecting ' + decision + ' stages only, without an HTTP request', () => { assert.equal(new a.FormData(main).get('tcc_review[decision]'), decision); assert.equal(financialRequests, 0); assert.match(box.querySelector('.tcc-feedback').textContent, /به‌روزرسانی سفارش/); });
}
reason.value = ''; stage('reject');
test('Update blocks rejection without a customer-facing reason', () => { assert.equal(updateOrder(), false); assert.match(box.querySelector('.tcc-feedback').textContent, /پیام/); });
const preset = box.querySelector('.tcc-reason-preset'); preset.selectedIndex = 1; preset.dispatchEvent(new a.Event('change', {bubbles: true}));
test('Preset fills customer reason but not private note', () => { assert.ok(reason.value); assert.equal(box.querySelector('[name="tcc_review[private_note]"]').value, ''); });
box.querySelector('[name="tcc_review[private_note]"]').value = 'INTERNAL';
test('Native Update carries decision, reason, revision, fingerprint and separate CSRF', () => {
    assert.equal(updateOrder(), true); const data = new a.FormData(main);
    for (const key of ['order_id', 'revision', 'fingerprint', 'nonce', 'reason']) assert.ok(data.get('tcc_review[' + key + ']'), key);
    assert.equal(data.get('tcc_review[decision]'), 'reject'); assert.equal(data.get('tcc_review[revision]'), '2'); assert.equal(data.get('tcc_review[private_note]'), 'INTERNAL');
});
stage('approve');
test('Approval still requires explicit bank confirmation at Update', () => { assert.equal(updateOrder(), false); assert.match(box.querySelector('.tcc-feedback').textContent, /ورود وجه/); });
confirmed.checked = true; confirmed.dispatchEvent(new a.Event('change', {bubbles: true}));
test('Confirmed approval travels in native Update, not AJAX', () => { assert.equal(updateOrder(), true); assert.equal(new a.FormData(main).get('tcc_review[confirmed]'), 'yes'); assert.equal(financialRequests, 0); });
main.querySelector('[name=order_total]').value = '900'; main.querySelector('[name=order_total]').dispatchEvent(new a.Event('change', {bubbles: true}));
test('Real Woo edits are saved with the intent for server-side revalidation', () => { assert.equal(updateOrder(), true); assert.equal(new a.FormData(main).get('order_total'), '900'); assert.equal(new a.FormData(main).get('tcc_review[decision]'), 'approve'); });
box.querySelector('[data-tcc-clear]').click();
test('Cancel selection restores ordinary order save without a decision', () => { assert.equal(new a.FormData(main).get('tcc_review[decision]'), null); assert.equal(confirmed.required, false); assert.equal(reason.required, false); assert.equal(updateOrder(), true); });
for (const id of ['post', 'order', 'woocommerce-order']) {
    const fixture = dom(id === 'order' ? 'admin-hpos.html' : 'admin.html'), w = fixture.window, d = w.document; d.querySelector('form').id = id;
    let requests = 0; w.fetch = async () => { requests++; };
    w.eval(fs.readFileSync(path.join(root, 'assets/admin.js'), 'utf8'));
    const orderForm = d.getElementById(id); const ui = d.createElement('input'); ui.name = 'select2_search'; ui.value = 'widget initialization'; orderForm.append(ui); ui.dispatchEvent(new w.Event('change', {bubbles: true}));
    d.querySelector('[data-tcc-decision][value=reject]').click();
    test('Automatic Woo widget edits never prevent selection in #' + id, () => { assert.equal(new w.FormData(orderForm).get('tcc_review[decision]'), 'reject'); assert.equal(requests, 0); assert.doesNotMatch(d.querySelector('.tcc-feedback').textContent, /تغییرات ذخیره‌نشده/); });
    d.querySelector('[data-tcc-reason]').value = 'تصویر خوانا نیست'; d.querySelector('[data-tcc-reason]').dispatchEvent(new w.Event('input', {bubbles: true}));
    const event = new w.SubmitEvent('submit', {bubbles: true, cancelable: true}); orderForm.dispatchEvent(event);
    test('Native Update submits the intent from #' + id, () => { assert.equal(event.defaultPrevented, false); assert.equal(new w.FormData(orderForm).get('tcc_review[decision]'), 'reject'); assert.equal(new w.FormData(orderForm).get('action'), id === 'order' ? 'edit_order' : 'editpost'); assert.equal(requests, 0); });
    fixture.window.close();
}
const image = box.querySelector('.tcc-admin-image img'); box.querySelector('.tcc-rotate').click();
test('Receipt rotation remains presentation-only', () => { assert.equal(image.style.transform, 'rotate(90deg)'); assert.ok(image.src.includes('action=tcc_receipt')); });
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
test('Bank name is a native dropdown with 37 catalog choices, not free text', () => { assert.equal(bank.tagName, 'SELECT'); assert.equal(bank.querySelectorAll('option[data-bank-logo]').length, 37); });
test('Existing unknown bank remains an explicit selected legacy option', () => { assert.equal(bank.value, 'بانک آزمایشی'); assert.match(bank.selectedOptions[0].textContent, /نام فعلی/); });
bank.value = 'گردشگری'; bank.dispatchEvent(new sw.Event('change', {bubbles: true}));
test('Changing bank previews only its local logo and marks settings unsaved', () => { assert.match(row.querySelector('[data-tcc-bank-logo]').src, /assets\/banks\/gardeshgari\.svg$/); assert.equal(row.querySelector('[data-tcc-bank-logo]').hidden, false); assert.match(sf.querySelector('[role=status]').textContent, /ذخیره‌نشده/); });
row.querySelector('[data-tcc-bank-logo]').dispatchEvent(new sw.Event('error'));
test('Unavailable bank preview falls back to the generic icon', () => { assert.equal(row.querySelector('[data-tcc-bank-logo]').hidden, true); assert.equal(row.querySelector('[data-tcc-bank-fallback]').hidden, false); });
bank.value = ''; bank.dispatchEvent(new sw.Event('change', {bubbles: true}));
test('Clearing the optional bank removes stale logo without a blank-src request', () => { assert.equal(row.querySelector('[data-tcc-bank-logo]').hasAttribute('src'), false); assert.equal(row.querySelector('[data-tcc-bank-fallback]').hidden, false); });
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
    assert.equal(rows[2].querySelector('[data-card-field=bank]').tagName, 'SELECT'); assert.equal(rows[2].querySelectorAll('option[data-bank-logo]').length, 37);
});
for (let i = 0; i < 25; i++) add.click();
test('Add-card UI enforces twenty-row limit with no index collisions', () => {
    const names = Array.from(sf.querySelectorAll('#tcc-cards [data-card-field=number]')).map(input => input.name);
    assert.equal(names.length, 20); assert.equal(new Set(names).size, 20); assert.equal(add.disabled, true); assert.equal(sf.querySelector('#tcc-card-count').textContent, '20');
});
tabs[3].click();
const adminRole = sf.querySelector('[name="reviewer_roles[]"][value=administrator]');
const managerRole = sf.querySelector('[name="reviewer_roles[]"][value=shop_manager]');
test('Access tab shows real WordPress role names, including custom roles', () => { assert.ok(sf.querySelector('[value=finance]')); assert.match(sd.querySelector('.tcc-role-options').textContent, /مدیر فروشگاه/); });
test('Administrator role is checked and protected from accidental removal', () => { assert.equal(adminRole.checked, true); assert.equal(adminRole.disabled, true); });
managerRole.checked = true; managerRole.dispatchEvent(new sw.Event('change', {bubbles: true})); tabs[0].click();
test('Role choices submit from hidden access tab with presence marker', () => { const data = new sw.FormData(sf); assert.deepEqual(data.getAll('reviewer_roles[]'), ['shop_manager']); assert.equal(data.get('reviewer_roles_present'), '1'); });
tabs[3].click();
test('Switching tabs keeps pending role selection', () => assert.equal(managerRole.checked, true));
managerRole.checked = false; managerRole.dispatchEvent(new sw.Event('change', {bubbles: true}));
test('Unchecking all optional roles still submits an explicit role-update marker', () => { const data = new sw.FormData(sf); assert.deepEqual(data.getAll('reviewer_roles[]'), []); assert.equal(data.get('reviewer_roles_present'), '1'); });
test('Collapsed individual exceptions remain submitted separately from roles', () => { assert.equal(sf.querySelector('.tcc-individual-reviewers').open, false); assert.equal(new sw.FormData(sf).get('reviewers'), '2,7'); });
const emptyDom = dom('settings-empty.html'); const ew = emptyDom.window; ew.eval(fs.readFileSync(path.join(root, 'assets/admin.js'), 'utf8'));
test('First-run card editor works without preconfigured destinations', () => { assert.equal(ew.document.querySelector('.tcc-card-row').open, true); ew.document.querySelector('#tcc-add-card').click(); assert.equal(ew.document.querySelectorAll('#tcc-cards > details').length, 2); });
customer.window.close(); admin.window.close(); settingsDom.window.close(); emptyDom.window.close();
console.log('\nResult: ' + count + ' UI checks passed (jsdom, not a real browser/Woo site)');
