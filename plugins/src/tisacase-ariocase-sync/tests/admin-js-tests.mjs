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
function makeDom() {
	const dom = new JSDOM(`<!doctype html><html><body>
		<textarea id="tcas-pairs">AR120 - AR22\nBAD - NONE</textarea><button id="tcas-preview-start">بررسی</button><span id="tcas-preview-status"></span>
		<div id="tcas-preview-progress" hidden><progress max="100" value="0"><span></span></div>
		<section id="tcas-preview-panel" hidden><table id="tcas-preview-table"><tbody></tbody></table>
		<button id="tcas-connect-start">اتصال</button><span id="tcas-connect-status"></span>
		<div id="tcas-connect-progress" hidden><progress max="100" value="0"><span></span></div><div id="tcas-connect-results"></div></section>
		<input id="tcas-test-code"><button id="tcas-test-connection">تست</button><div id="tcas-test-result"></div>
	</body></html>`, { url: 'https://tisa.test/wp-admin/admin.php?page=tcas', runScripts: 'outside-only' });
	dom.window.TCAS_ADMIN = { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'nonce', strings: { working: '...', selectRows: 'select', disconnect: 'confirm' } };
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
	window.eval(script); window.document.getElementById('tcas-preview-start').click();
	await waitFor(() => window.document.querySelectorAll('#tcas-preview-table tbody tr').length === 2 && window.document.getElementById('tcas-preview-status').textContent.includes('کامل'));
	ok('Preview هر دو جفت را می‌سازد', window.document.querySelectorAll('#tcas-preview-table tbody tr').length === 2);
	ok('فقط جفت قابل‌اتصال Checkbox فعال دارد', window.document.querySelectorAll('.tcas-row-select:not(:disabled)').length === 1);
	ok('Index جفت برای انتخاب استفاده می‌شود', window.document.querySelector('.tcas-row-select').value === '0');
	ok('کد تیساکیس و ArioCase جدا نمایش داده می‌شوند', window.document.querySelector('tbody tr td:nth-child(2) code').textContent === 'AR120' && window.document.querySelector('tbody tr td:nth-child(3) code').textContent === 'AR22');
	ok('نام ریموت با textContent امن رندر می‌شود', !window.document.querySelector('tbody img'));
	ok('Nonce و Token ارسال می‌شوند', calls[0].get('nonce') === 'nonce' && calls[1].get('token') === 'a'.repeat(32));
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
	const dom = makeDom(); const { window } = dom; const queue = [reply({ token: 'b'.repeat(32), total: 2 }), reply({ message: 'API قطع است' }, false, 500)];
	window.fetch = async () => queue.shift(); window.eval(script); window.document.getElementById('tcas-preview-start').click();
	await waitFor(() => window.document.getElementById('tcas-preview-status').textContent === 'API قطع است');
	ok('خطای AJAX نمایش داده می‌شود', window.document.getElementById('tcas-preview-status').textContent === 'API قطع است');
	ok('بعد از خطا دکمه آزاد می‌شود', !window.document.getElementById('tcas-preview-start').disabled);
	ok('کلاس خطا اعمال می‌شود', window.document.getElementById('tcas-preview-status').classList.contains('is-error'));
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
