#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const script = fs.readFileSync(path.join(root, 'assets/admin.js'), 'utf8');
let pass = 0; let fail = 0;
function ok(label, condition, detail = '') { if (condition) { pass++; console.log('OK   ' + label); } else { fail++; console.error('FAIL ' + label + (detail ? ' >> ' + detail : '')); } }
function reply(data, success = true, status = 200) { return { status, json: async () => (success ? { success: true, data } : { success: false, data }) }; }
function entryMarkup() {
	return '<article class="tcas-pair-entry"><button type="button" class="tcas-remove-pair">×</button><input class="tcas-local-code"><input class="tcas-remote-code"></article>';
}
function makeDom() {
	const dom = new JSDOM(`<!doctype html><html><body>
		<div class="tcas-converter-flow"><label class="tcas-converter-source"><span class="tcas-converter-input-row"><input id="tcas-link-input"><button id="tcas-link-resolve">تبدیل</button></span></label><div id="tcas-link-result" class="tcas-converter-result" hidden><div class="tcas-converter-result-meta"><span id="tcas-link-shop"></span><small id="tcas-link-product"></small></div><label id="tcas-link-variation-wrap" class="tcas-link-variation" hidden><select id="tcas-link-variation"></select></label><span class="tcas-converter-reference"><input id="tcas-link-sku" readonly><button id="tcas-link-copy" disabled>کپی</button></span></div><span id="tcas-link-status"></span></div>
		<div id="tcas-pair-entries">${entryMarkup()}</div><template id="tcas-pair-entry-template">${entryMarkup()}</template><button id="tcas-pair-add">افزودن</button>
		<button id="tcas-preview-start">بررسی</button><span id="tcas-preview-status"></span>
		<div id="tcas-preview-progress" hidden><progress max="100" value="0"><span></span></div>
		<section id="tcas-preview-panel" hidden><div id="tcas-preview-list"></div>
		<button id="tcas-connect-start">اتصال</button><span id="tcas-connect-status"></span>
		<div id="tcas-connect-progress" hidden><progress max="100" value="0"><span></span></div><div id="tcas-connect-results"></div></section>
		<input id="tcas-test-code"><button id="tcas-test-connection">تست</button><div id="tcas-test-result"></div>
	</body></html>`, { url: 'https://tisa.test/wp-admin/admin.php?page=tcas', runScripts: 'outside-only' });
	dom.window.TCAS_ADMIN = { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'nonce', maxPairs: 50, strings: { working: '...', selectRows: 'select', disconnect: 'confirm' } };
	return dom;
}
async function waitFor(fn, timeout = 2000) { const start = Date.now(); while (!fn()) { if (Date.now() - start > timeout) throw new Error('waitFor timeout'); await new Promise((resolve) => setTimeout(resolve, 10)); } }

{
	const dom = makeDom(); const { window } = dom; const calls = [];
	const queue = [
		reply({ token: 'a'.repeat(32), total: 2 }),
		reply({ done: false, processed: 1, total: 2, row: { index: 0, local_code: 'AR120', remote_code: 'AR22', status: 'connect', selectable: true, local_name: 'قاب تیساکیس', remote_name: '<img src=x>', local_id: 10, stock: { quantity: 4, status: 'instock' }, message: 'ready' } }),
		reply({ done: true, processed: 2, total: 2, row: { index: 1, local_code: 'BAD', remote_code: 'NONE', status: 'error', selectable: false, local_name: '', remote_name: '', local_id: 0, stock: null, message: 'not found' } }),
		reply({ total: 1 }),
		reply({ done: true, processed: 1, total: 1, row: { local_code: 'AR120', remote_code: 'AR22', status: 'done', message: 'connected', product_id: 10, edit_url: '/wp-admin/post.php?post=10&action=edit' } }),
	];
	window.fetch = async (url, options) => { calls.push(options.body); return queue.shift(); };
	window.eval(script);
	window.document.querySelector('.tcas-local-code').value = 'AR120'; window.document.querySelector('.tcas-remote-code').value = 'AR22';
	window.document.getElementById('tcas-pair-add').click();
	ok('کارت جفت تازه با دو Input ساخته می‌شود', window.document.querySelectorAll('#tcas-pair-entries .tcas-pair-entry').length === 2 && window.document.querySelectorAll('#tcas-pair-entries .tcas-pair-entry:last-child input').length === 2);
	const second = window.document.querySelector('#tcas-pair-entries .tcas-pair-entry:last-child');
	second.querySelector('.tcas-local-code').value = 'BAD'; second.querySelector('.tcas-remote-code').value = 'NONE';
	window.document.getElementById('tcas-preview-start').click();
	await waitFor(() => window.document.querySelectorAll('#tcas-preview-list .tcas-preview-card').length === 2 && window.document.getElementById('tcas-preview-status').textContent.includes('کامل'));
	ok('Preview هر دو جفت را به کارت تبدیل می‌کند', window.document.querySelectorAll('#tcas-preview-list .tcas-preview-card').length === 2);
	ok('فقط جفت قابل‌اتصال Checkbox فعال دارد', window.document.querySelectorAll('.tcas-row-select:not(:disabled)').length === 1);
	ok('Index جفت برای انتخاب استفاده می‌شود', window.document.querySelector('.tcas-row-select').value === '0');
	const previewInputs = window.document.querySelectorAll('.tcas-preview-card:first-child .tcas-shop-field input');
	ok('کد تیساکیس و ArioCase در دو Input خوانا نمایش داده می‌شوند', previewInputs[0].value === 'AR120' && previewInputs[1].value === 'AR22' && previewInputs[0].readOnly && previewInputs[1].readOnly);
	ok('نام ریموت با textContent امن رندر می‌شود', !window.document.querySelector('#tcas-preview-list img'));
	const previewStart = calls.find((body) => body.get('action') === 'tcas_preview_start');
	ok('کارت‌ها به قالب جفت استاندارد تبدیل می‌شوند', previewStart.get('pairs') === 'AR120 - AR22\nBAD - NONE');
	ok('Nonce و Token ارسال می‌شوند', previewStart.get('nonce') === 'nonce' && calls[1].get('token') === 'a'.repeat(32));
	window.document.getElementById('tcas-connect-start').click();
	await waitFor(() => window.document.querySelectorAll('#tcas-connect-results .tcas-result-card').length === 1);
	const connectStart = calls.find((body) => body.get('action') === 'tcas_connect_start');
	ok('فقط Index جفت انتخاب‌شده ارسال می‌شود', connectStart.getAll('pairs[]').join(',') === '0');
	ok('هیچ گزینهٔ قیمت یا تصویری ارسال نمی‌شود', connectStart.get('prices') === null && connectStart.get('images') === null);
	ok('اقدام بعدی connect_step است', calls.some((body) => body.get('action') === 'tcas_connect_step'));
	ok('نتیجه و لینک محصول تیساکیس نمایش داده می‌شود', window.document.querySelector('#tcas-connect-results a').getAttribute('href').includes('post=10'));
	ok('پیشرفت اتصال ۱۰۰٪ می‌شود', window.document.querySelector('#tcas-connect-progress progress').value === 100);
	dom.window.close();
}

{
	const dom = makeDom(); const { window } = dom; let requests = 0;
	window.fetch = async () => { requests++; return reply({}); }; window.eval(script);
	window.document.querySelector('.tcas-local-code').value = 'https://tisacase.com/product/case/'; window.document.querySelector('.tcas-remote-code').value = 'AR22';
	window.document.getElementById('tcas-preview-start').click();
	await waitFor(() => window.document.getElementById('tcas-preview-status').textContent.includes('تبدیل لینک'));
	ok('Input جفت لینک را به‌جای SKU ارسال نمی‌کند', requests === 0);
	ok('راهنمای استفاده از تبدیل‌کننده نمایش داده می‌شود', window.document.getElementById('tcas-preview-status').classList.contains('is-error'));
	dom.window.close();
}

{
	const dom = makeDom(); const { window } = dom; const queue = [reply({ token: 'b'.repeat(32), total: 2 }), reply({ message: 'API قطع است' }, false, 500)];
	window.fetch = async () => queue.shift(); window.eval(script);
	window.document.querySelector('.tcas-local-code').value = 'AR120'; window.document.querySelector('.tcas-remote-code').value = 'AR22'; window.document.getElementById('tcas-preview-start').click();
	await waitFor(() => window.document.getElementById('tcas-preview-status').textContent === 'API قطع است');
	ok('خطای AJAX نمایش داده می‌شود', window.document.getElementById('tcas-preview-status').textContent === 'API قطع است');
	ok('بعد از خطا دکمه آزاد می‌شود', !window.document.getElementById('tcas-preview-start').disabled);
	ok('کلاس خطا اعمال می‌شود', window.document.getElementById('tcas-preview-status').classList.contains('is-error'));
	dom.window.close();
}

{
	const dom = makeDom(); const { window } = dom; const calls = []; let copied = '';
	window.fetch = async (url, options) => { calls.push(options.body); return reply({ shop: 'tisacase', shop_label: 'TisaCase', sku: 'TS0100', name: 'Local case', product_id: 41 }); };
	Object.defineProperty(window.navigator, 'clipboard', { configurable: true, value: { writeText: async (value) => { copied = value; } } });
	window.eval(script);
	ok('کارت نتیجه در حالت اولیه کاملاً مخفی است', window.document.getElementById('tcas-link-result').hidden);
	window.document.getElementById('tcas-link-input').value = 'https://tisacase.com/product/local-case/'; window.document.getElementById('tcas-link-resolve').click();
	await waitFor(() => window.document.getElementById('tcas-link-sku').value === 'TS0100');
	ok('کارت نتیجه فقط بعد از تبدیل نمایش داده می‌شود', !window.document.getElementById('tcas-link-result').hidden);
	ok('تبدیل لینک، URL را برای Resolver ارسال می‌کند', calls[0].get('action') === 'tcas_resolve_link' && calls[0].get('url') === 'https://tisacase.com/product/local-case/');
	ok('فروشگاه و SKU در خروجی نمایش داده می‌شوند', window.document.getElementById('tcas-link-shop').textContent.includes('TisaCase') && window.document.getElementById('tcas-link-result').dataset.shop === 'tisacase');
	ok('دکمهٔ کپی بعد از دریافت SKU فعال می‌شود', !window.document.getElementById('tcas-link-copy').disabled);
	window.document.getElementById('tcas-link-copy').click();
	await waitFor(() => copied === 'TS0100');
	ok('دکمهٔ کپی همان SKU را در Clipboard می‌نویسد', copied === 'TS0100');
	dom.window.close();
}

{
	const dom = makeDom(); const { window } = dom; let copied = '';
	window.fetch = async () => reply({ shop: 'ariocase', shop_label: 'ArioCase', sku: '', reference: 'ID:658', name: 'ID only case', product_id: 658 });
	Object.defineProperty(window.navigator, 'clipboard', { configurable: true, value: { writeText: async (value) => { copied = value; } } });
	window.eval(script); window.document.getElementById('tcas-link-input').value = 'https://ariocase.com/product/id-only-case/'; window.document.getElementById('tcas-link-resolve').click();
	await waitFor(() => window.document.getElementById('tcas-link-sku').value === 'ID:658');
	ok('محصول بدون SKU مرجع ID را در خروجی تبدیل‌کننده می‌گذارد', window.document.getElementById('tcas-link-sku').value === 'ID:658' && window.document.getElementById('tcas-link-shop').textContent.includes('ID'));
	ok('دکمهٔ کپی برای مرجع ID فعال است', !window.document.getElementById('tcas-link-copy').disabled);
	window.document.getElementById('tcas-link-copy').click();
	await waitFor(() => copied === 'ID:658');
	ok('کپی، همان ID canonical را بدون SKU جعلی می‌نویسد', copied === 'ID:658');
	dom.window.close();
}

{
	const dom = makeDom(); const { window } = dom;
	window.fetch = async () => reply({ message: 'اتصال موفق', product: { code: 'AR22', name: 'Case', stock: { quantity: 3, status: 'instock' } } });
	window.eval(script); window.document.getElementById('tcas-test-code').value = 'AR22'; window.document.getElementById('tcas-test-connection').click();
	await waitFor(() => window.document.querySelector('#tcas-test-result pre'));
	ok('تست اتصال خلاصهٔ JSON نمایش می‌دهد', window.document.querySelector('#tcas-test-result pre').textContent.includes('AR22'));
	ok('دکمهٔ تست آزاد می‌شود', !window.document.getElementById('tcas-test-connection').disabled);
	dom.window.close();
}
{
	const dom = makeDom(); const { window } = dom; let copied = '';
	window.fetch = async () => reply({ shop: 'ariocase', shop_label: 'ArioCase', sku: '', reference: 'ID:700', name: 'Remote variable', product_id: 700, type: 'variable', variations: [
		{ product_id: 701, parent_id: 700, sku: '', reference: 'ID:700/701', label: 'مشکی' },
		{ product_id: 702, parent_id: 700, sku: 'AR-BLUE', reference: 'AR-BLUE', label: 'آبی' },
	] });
	Object.defineProperty(window.navigator, 'clipboard', { configurable: true, value: { writeText: async (value) => { copied = value; } } });
	window.eval(script); window.document.getElementById('tcas-link-input').value = 'https://ariocase.com/product/remote-variable/'; window.document.getElementById('tcas-link-resolve').click();
	await waitFor(() => window.document.getElementById('tcas-link-variation').options.length === 3);
	ok('لینک والد، منوی Variation را می‌سازد و کپی والد را قفل می‌کند', !window.document.getElementById('tcas-link-variation-wrap').hidden && window.document.getElementById('tcas-link-sku').value === '' && window.document.getElementById('tcas-link-copy').disabled);
	ok('گزینه، ID عددی و مرجع کامل Variation را نشان می‌دهد', window.document.getElementById('tcas-link-variation').options[1].textContent.includes('#701') && window.document.getElementById('tcas-link-variation').options[1].textContent.includes('ID:700/701'));
	const select = window.document.getElementById('tcas-link-variation'); select.value = '0'; select.dispatchEvent(new window.Event('change'));
	await waitFor(() => window.document.getElementById('tcas-link-sku').value === 'ID:700/701');
	ok('انتخاب Variation مرجع والد/تنوع را قابل‌کپی می‌کند', !window.document.getElementById('tcas-link-copy').disabled && window.document.getElementById('tcas-link-shop').textContent.includes('ID'));
	window.document.getElementById('tcas-link-copy').click(); await waitFor(() => copied === 'ID:700/701');
	ok('Clipboard مرجع Variation را می‌گیرد نه ID والد را', copied === 'ID:700/701');
	select.value = '1'; select.dispatchEvent(new window.Event('change'));
	await waitFor(() => window.document.getElementById('tcas-link-sku').value === 'AR-BLUE');
	ok('Variation دارای SKU همچنان همان SKU را می‌دهد', window.document.getElementById('tcas-link-sku').value === 'AR-BLUE');
	dom.window.close();
}

{
	const dom = makeDom(); const { window } = dom;
	window.fetch = async () => reply({ shop: 'tisacase', shop_label: 'TisaCase', sku: 'TS-PARENT', reference: 'TS-PARENT', name: 'Local variable', product_id: 43, type: 'variable', variations: [ { product_id: 44, parent_id: 43, sku: 'TS-RED', reference: 'ID:44', label: 'قرمز' } ] });
	window.eval(script); window.document.getElementById('tcas-link-input').value = 'https://tisacase.com/product/local-variable/'; window.document.getElementById('tcas-link-resolve').click();
	await waitFor(() => window.document.getElementById('tcas-link-sku').value === 'ID:44');
	ok('تک‌Variation تیساکیس خودکار با ID یکتا آماده می‌شود', window.document.getElementById('tcas-link-sku').value === 'ID:44' && !window.document.getElementById('tcas-link-copy').disabled);
	dom.window.close();
}

{
	const dom = makeDom(); const { window } = dom;
	window.fetch = async () => reply({ shop: 'tisacase', shop_label: 'TisaCase', sku: 'SB193', reference: 'SB193', name: 'Shared SKU parent', product_id: 46, type: 'variable', variations: [
		{ product_id: 47, parent_id: 46, sku: 'SB193', reference: 'ID:47', label: 'iPhone 13' },
		{ product_id: 48, parent_id: 46, sku: 'SB193', reference: 'ID:48', label: 'iPhone 13 Pro' },
	] });
	window.eval(script); window.document.getElementById('tcas-link-input').value = 'https://tisacase.com/product/shared-skus/'; window.document.getElementById('tcas-link-resolve').click();
	await waitFor(() => window.document.getElementById('tcas-link-variation').options.length === 3);
	ok('SKU مشترک، گزینه‌های جدا با ID و کپی قفل‌شده دارد', window.document.getElementById('tcas-link-copy').disabled && window.document.getElementById('tcas-link-variation').options[2].textContent.includes('#48') && window.document.getElementById('tcas-link-variation').options[2].textContent.includes('SB193'));
	const select = window.document.getElementById('tcas-link-variation'); select.value = '1'; select.dispatchEvent(new window.Event('change'));
	await waitFor(() => window.document.getElementById('tcas-link-sku').value === 'ID:48');
	ok('انتخاب Variation با SKU مشترک، ID همان متغیر را کپی‌پذیر می‌کند', !window.document.getElementById('tcas-link-copy').disabled && window.document.getElementById('tcas-link-shop').textContent.includes('ID'));
	dom.window.close();
}

const uiCss = fs.readFileSync(path.join(root, 'assets/admin.css'), 'utf8');
const pairsView = fs.readFileSync(path.join(root, 'views/pairs.php'), 'utf8');
ok('Checkbox تنظیمات اندازهٔ ثابت دارد و تمام‌عرض نمی‌شود', uiCss.includes('.tcas-check-line input[type=checkbox]') && uiCss.includes('width:16px') && uiCss.includes('justify-self:start'));
ok('Selector و استایل جمع‌وجور Variation در View واقعی وجود دارد', pairsView.includes('id="tcas-link-variation"') && pairsView.includes('class="tcas-link-variation" hidden') && uiCss.includes('.tcas-link-variation select'));
ok('چیدمان قدیمی و فلش حذف و نتیجهٔ واقعی پیش‌فرض مخفی است', pairsView.includes('tcas-converter-flow') && pairsView.includes('id="tcas-link-result" class="tcas-converter-result" hidden') && !pairsView.includes('tcas-converter-layout') && !pairsView.includes('tcas-converter-arrow') && !uiCss.includes('.tcas-converter-arrow') && !uiCss.includes('.tcas-input-action'));
console.log(`\n${pass} passed, ${fail} failed`); if (fail) process.exit(1);
