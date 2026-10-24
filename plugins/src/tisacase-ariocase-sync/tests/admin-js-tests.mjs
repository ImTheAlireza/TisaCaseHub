#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const script = fs.readFileSync(path.join(root, 'assets/admin.js'), 'utf8');
let pass = 0;
let fail = 0;
function ok(label, condition, detail = '') {
	if (condition) { pass++; console.log('OK   ' + label); }
	else { fail++; console.error('FAIL ' + label + (detail ? ' >> ' + detail : '')); }
}
function reply(data, success = true, status = 200) {
	return { status, json: async () => (success ? { success: true, data } : { success: false, data }) };
}
function makeDom() {
	const dom = new JSDOM(`<!doctype html><html><body>
		<textarea id="tcas-codes">CH1\nCH2</textarea><button id="tcas-preview-start">پیش‌نمایش</button><span id="tcas-preview-status"></span>
		<div id="tcas-preview-progress" hidden><progress max="100" value="0"><span></span></div>
		<section id="tcas-preview-panel" hidden><table id="tcas-preview-table"><tbody></tbody></table>
		<input id="tcas-import-prices" type="checkbox" checked><input id="tcas-import-images" type="checkbox" checked>
		<button id="tcas-import-start">ایمپورت</button><span id="tcas-import-status"></span>
		<div id="tcas-import-progress" hidden><progress max="100" value="0"><span></span></div><div id="tcas-import-results"></div></section>
		<input id="tcas-test-code"><button id="tcas-test-connection">تست</button><div id="tcas-test-result"></div>
	</body></html>`, { url: 'https://tisa.test/wp-admin/admin.php?page=tcas', runScripts: 'outside-only' });
	dom.window.TCAS_ADMIN = { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'nonce', strings: { working: '...', selectRows: 'select', disconnect: 'confirm' } };
	return dom;
}
async function waitFor(fn, timeout = 2000) {
	const start = Date.now();
	while (!fn()) {
		if (Date.now() - start > timeout) throw new Error('waitFor timeout');
		await new Promise((resolve) => setTimeout(resolve, 10));
	}
}

// جریان کامل Preview → انتخاب → Import.
{
	const dom = makeDom();
	const { window } = dom;
	const calls = [];
	const queue = [
		reply({ token: 'a'.repeat(32), total: 2 }),
		reply({ done: false, processed: 1, total: 2, row: { code: 'CH1', status: 'import', selectable: true, name: 'Case 1', local_id: 0, stock: { quantity: 4, status: 'instock' }, message: 'new' } }),
		reply({ done: true, processed: 2, total: 2, row: { code: 'CH2', status: 'error', selectable: false, name: '', local_id: 0, stock: null, message: 'not found' } }),
		reply({ total: 1 }),
		reply({ done: true, processed: 1, total: 1, row: { code: 'CH1', status: 'done', message: 'created', product_id: 91, edit_url: '/wp-admin/post.php?post=91&action=edit', warnings: [] } }),
	];
	window.fetch = async (url, options) => { calls.push(options.body); return queue.shift(); };
	window.eval(script);
	window.document.getElementById('tcas-preview-start').click();
	await waitFor(() => window.document.querySelectorAll('#tcas-preview-table tbody tr').length === 2 && window.document.getElementById('tcas-preview-status').textContent.includes('کامل'));
	ok('Preview هر دو ردیف را می‌سازد', window.document.querySelectorAll('#tcas-preview-table tbody tr').length === 2);
	ok('فقط ردیف قابل‌اجرا Checkbox فعال دارد', window.document.querySelectorAll('.tcas-row-select:not(:disabled)').length === 1);
	ok('پیش‌نمایش کامل، دکمهٔ ایمپورت را آزاد می‌کند', !window.document.getElementById('tcas-preview-start').disabled);
	ok('کدها با textContent رندر می‌شوند نه HTML', window.document.querySelector('#tcas-preview-table tbody tr td:nth-child(2)').textContent === 'CH1');
	ok('توکن در Requestهای مرحله ارسال می‌شود', calls[1].get('token') === 'a'.repeat(32));
	ok('Nonce ارسال می‌شود', calls[0].get('nonce') === 'nonce');
	window.document.getElementById('tcas-import-start').click();
	await waitFor(() => window.document.querySelectorAll('#tcas-import-results .tcas-result-card').length === 1);
	const importStart = calls.find((body) => body.get('action') === 'tcas_import_start');
	ok('فقط کد انتخاب‌شده وارد Import می‌شود', importStart.getAll('codes[]').join(',') === 'CH1');
	ok('گزینه‌های قیمت و تصویر ارسال می‌شوند', importStart.get('prices') === '1' && importStart.get('images') === '1');
	ok('نتیجه و لینک ویرایش نمایش داده می‌شود', window.document.querySelector('#tcas-import-results a').getAttribute('href').includes('post=91'));
	ok('پیشرفت به ۱۰۰٪ می‌رسد', window.document.querySelector('#tcas-import-progress progress').value === 100);
	dom.window.close();
}

// خطای API در میانهٔ Preview: دکمه آزاد و پیام واقعی نمایش داده شود.
{
	const dom = makeDom();
	const { window } = dom;
	const queue = [reply({ token: 'b'.repeat(32), total: 2 }), reply({ message: 'API قطع است' }, false, 500)];
	window.fetch = async () => queue.shift();
	window.eval(script);
	window.document.getElementById('tcas-preview-start').click();
	await waitFor(() => window.document.getElementById('tcas-preview-status').textContent === 'API قطع است');
	ok('خطای AJAX به کاربر نشان داده می‌شود', window.document.getElementById('tcas-preview-status').textContent === 'API قطع است');
	ok('بعد از خطا دکمه دوباره قابل استفاده است', !window.document.getElementById('tcas-preview-start').disabled);
	ok('کلاس خطا اعمال می‌شود', window.document.getElementById('tcas-preview-status').classList.contains('is-error'));
	dom.window.close();
}

// تست اتصال مستقل.
{
	const dom = makeDom();
	const { window } = dom;
	window.fetch = async () => reply({ message: 'اتصال موفق', product: { code: 'CH1', name: 'Case', stock: { quantity: 3, status: 'instock' } } });
	window.eval(script);
	window.document.getElementById('tcas-test-code').value = 'CH1';
	window.document.getElementById('tcas-test-connection').click();
	await waitFor(() => window.document.querySelector('#tcas-test-result pre'));
	ok('تست اتصال خلاصهٔ JSON امن نمایش می‌دهد', window.document.querySelector('#tcas-test-result pre').textContent.includes('CH1'));
	ok('دکمهٔ تست بعد از پاسخ آزاد می‌شود', !window.document.getElementById('tcas-test-connection').disabled);
	dom.window.close();
}

console.log(`\n${pass} passed, ${fail} failed`);
if (fail) process.exit(1);
