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
		<input id="tcas-link-input"><button id="tcas-link-resolve">تبدیل</button><div id="tcas-link-result"><span id="tcas-link-shop"></span><input id="tcas-link-sku" readonly><button id="tcas-link-copy" disabled>کپی</button><small id="tcas-link-product"></small></div><span id="tcas-link-status"></span>
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
	window.eval(script); window.document.getElementById('tcas-link-input').value = 'https://tisacase.com/product/local-case/'; window.document.getElementById('tcas-link-resolve').click();
	await waitFor(() => window.document.getElementById('tcas-link-sku').value === 'TS0100');
	ok('تبدیل لینک، URL را برای Resolver ارسال می‌کند', calls[0].get('action') === 'tcas_resolve_link' && calls[0].get('url') === 'https://tisacase.com/product/local-case/');
	ok('فروشگاه و SKU در خروجی نمایش داده می‌شوند', window.document.getElementById('tcas-link-shop').textContent.includes('TisaCase') && window.document.getElementById('tcas-link-result').dataset.shop === 'tisacase');
	ok('دکمهٔ کپی بعد از دریافت SKU فعال می‌شود', !window.document.getElementById('tcas-link-copy').disabled);
	window.document.getElementById('tcas-link-copy').click();
	await waitFor(() => copied === 'TS0100');
	ok('دکمهٔ کپی همان SKU را در Clipboard می‌نویسد', copied === 'TS0100');
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
console.log(`\n${pass} passed, ${fail} failed`); if (fail) process.exit(1);
