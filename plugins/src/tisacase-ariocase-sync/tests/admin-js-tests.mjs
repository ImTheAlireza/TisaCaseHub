#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const script = fs.readFileSync(path.join(root, 'assets/admin.js'), 'utf8');
let pass = 0; let fail = 0;
function ok(label, condition, detail = '') { if (condition) { pass++; console.log('OK   ' + label); } else { fail++; console.error('FAIL ' + label + (detail ? ' >> ' + detail : '')); } }
function reply(data, success = true, status = 200) {
	const text = JSON.stringify(success ? { success: true, data } : { success: false, data });
	return { status, text: async () => text, json: async () => JSON.parse(text) };
}
function htmlReply(body, status) { return { status, text: async () => body, json: async () => { throw new Error('not json'); } }; }
function entryMarkup() {
	return '<article class="tcas-pair-entry" data-state="idle" data-confidence="manual"><button type="button" class="tcas-remove-pair">×</button>'
		+ '<div class="tcas-pair-top"><span class="tcas-pair-flag tcas-pair-flag-idle">در انتظار بررسی</span><span class="tcas-spinner" hidden></span><label class="tcas-pair-select"><input type="checkbox" class="tcas-row-select" disabled><span>انتخاب</span></label></div>'
		+ '<small class="tcas-pair-flag-note" hidden></small>'
		+ '<div class="tcas-pair-fields"><label class="tcas-shop-field tcas-shop-local"><input class="tcas-local-code" type="text"><span class="tcas-variation-title"></span></label><span class="tcas-pair-link">+</span><label class="tcas-shop-field tcas-shop-remote"><input class="tcas-remote-code" type="text"><span class="tcas-variation-title"></span></label></div>'
		+ '<div class="tcas-pair-stock" hidden></div><small class="tcas-pair-message"></small></article>';
}
function makeDom(entryCount = 1) {
	let entries = '';
	for (let i = 0; i < entryCount; i++) entries += entryMarkup();
	const dom = new JSDOM(`<!doctype html><html><body>
		<div class="tcas-converter-flow"><label class="tcas-converter-source"><span class="tcas-converter-input-row"><input id="tcas-link-input"><button id="tcas-link-resolve">تبدیل</button></span></label><div id="tcas-link-result" class="tcas-converter-result" hidden><div class="tcas-converter-result-meta"><span id="tcas-link-shop"></span><small id="tcas-link-product"></small></div><label id="tcas-link-variation-wrap" class="tcas-link-variation" hidden><select id="tcas-link-variation"></select></label><span class="tcas-converter-reference"><input id="tcas-link-sku" readonly><button id="tcas-link-copy" disabled>کپی</button></span></div><span id="tcas-link-status"></span></div>
		<input id="tcas-smart-local"><input id="tcas-smart-remote"><button id="tcas-smart-match">تطبیق</button><button id="tcas-smart-next" hidden>دسته بعد</button><span id="tcas-smart-status"></span>
		<section id="tcas-smart-issues" hidden><h3>موارد نیازمند بررسی</h3><span id="tcas-smart-issues-count"></span><p class="tcas-smart-issues-help"></p><div id="tcas-smart-issue-filters" role="group"></div><div id="tcas-smart-issue-list" role="list"></div></section>
		<section class="tcas-card tcas-pairs-card"><div class="tcas-card-head"><div class="tcas-pairs-actions"><button id="tcas-select-valid" class="button">انتخاب همه</button><button id="tcas-preview-start" class="button">بررسی همه دوباره</button><button id="tcas-pair-add" class="button">افزودن جفت</button></div></div><p class="tcas-pairs-help">help</p>
		<div id="tcas-pair-entries" class="tcas-pair-entries">${entries}</div><template id="tcas-pair-entry-template">${entryMarkup()}</template>
		<div class="tcas-pair-actions"><button id="tcas-connect-start" class="button" disabled>اتصال انتخاب‌شده‌ها</button><span id="tcas-preview-status" class="tcas-status"></span><span id="tcas-connect-status" class="tcas-status"></span></div>
		<div id="tcas-preview-progress" class="tcas-progress" hidden><progress max="100" value="0"></progress><span>۰٪</span></div>
		<div id="tcas-connect-progress" class="tcas-progress" hidden><progress max="100" value="0"></progress><span>۰٪</span></div></section>
		<input id="tcas-test-code"><button id="tcas-test-connection">تست</button><div id="tcas-test-result"></div>
	</body></html>`, { url: 'https://tisa.test/wp-admin/admin.php?page=tcas', runScripts: 'outside-only' });
	dom.window.TCAS_ADMIN = { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'nonce', maxPairs: 50, partnerLabel: 'ArioCase', mappingsUrl: '/wp-admin/admin.php?page=tcas-mappings', strings: { working: '...', selectRows: 'select', disconnect: 'confirm' } };
	return dom;
}
async function waitFor(fn, timeout = 2000) { const start = Date.now(); while (!fn()) { if (Date.now() - start > timeout) throw new Error('waitFor timeout'); await new Promise((resolve) => setTimeout(resolve, 10)); } }
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
function codes(local, remote) { return { local_code: local, remote_code: remote }; }
function pairRow(overrides) {
	return Object.assign({ status: 'connect', selectable: true, local_name: 'Local case', remote_name: 'Remote case', stock: { quantity: 4, status: 'instock' }, local_stock: { quantity: 2, status: 'instock' }, message: 'دو محصول جفت می‌شوند.' }, overrides || {});
}
function type(entry, field, value) {
	const input = entry.querySelector(field);
	input.value = value;
	input.dispatchEvent(new entry.ownerDocument.defaultView.Event('input'));
}

// ---------- ۱) بررسی زندهٔ همان کارت بعد از هر ویرایش ----------
{
	const dom = makeDom(); const { window } = dom; const calls = [];
	window.fetch = async (url, options) => {
		const body = options.body; calls.push(body);
		if (body.get('action') === 'tcas_preview_pair') return reply({ row: pairRow(codes(body.get('local_code'), body.get('remote_code'))) });
		return reply({});
	};
	window.eval(script);
	const entry = window.document.querySelector('.tcas-pair-entry');
	const spinner = entry.querySelector('.tcas-spinner');
	ok('کارت خالی در حالت انتظار است و قابل انتخاب نیست', entry.dataset.state === 'idle' && entry.querySelector('.tcas-row-select').disabled && window.document.getElementById('tcas-connect-start').disabled && spinner.hidden);
	type(entry, '.tcas-local-code', 'TS1');
	type(entry, '.tcas-remote-code', 'AR1');
	await sleep(80);
	ok('درخواست بررسی فقط بعد از مکث کوتاه ارسال می‌شود', calls.filter((body) => body.get('action') === 'tcas_preview_pair').length === 0);
	await waitFor(() => entry.dataset.state === 'review');
	ok('ویرایش فیلد، همان کارت را در سرور بررسی می‌کند', calls.some((body) => body.get('action') === 'tcas_preview_pair' && body.get('local_code') === 'TS1' && body.get('remote_code') === 'AR1'));
	ok('جفت دستی که خودکار قطعی نشده، زرد و نیاز به بررسی است', entry.classList.contains('is-review') && entry.querySelector('.tcas-pair-flag').classList.contains('is-warn') && entry.querySelector('.tcas-pair-flag').textContent.includes('دستی'));
	ok('کارت زرد قابل انتخاب است و دکمهٔ اتصال فعال می‌شود', !entry.querySelector('.tcas-row-select').disabled && entry.querySelector('.tcas-row-select').checked && !window.document.getElementById('tcas-connect-start').disabled);
	ok('موجودی هر دو فروشگاه روی همان کارت نوشته می‌شود', !entry.querySelector('.tcas-pair-stock').hidden && entry.querySelector('.tcas-pair-stock').textContent.includes('TisaCase') && entry.querySelector('.tcas-pair-stock').textContent.includes('ArioCase') && entry.querySelector('.tcas-pair-stock').textContent.includes('4'));
	ok('اسپینر بعد از پایان بررسی مخفی می‌شود', spinner.hidden && !entry.classList.contains('is-loading'));
	dom.window.close();
}

// ---------- ۲) اسپینر در طول درخواست ----------
{
	const dom = makeDom(); const { window } = dom;
	let release = null;
	window.fetch = async (url, options) => {
		if (options.body.get('action') !== 'tcas_preview_pair') return reply({});
		await new Promise((resolve) => { release = resolve; });
		return reply({ row: pairRow({}) });
	};
	window.eval(script);
	const entry = window.document.querySelector('.tcas-pair-entry');
	type(entry, '.tcas-local-code', 'TS2');
	type(entry, '.tcas-remote-code', 'AR2');
	await waitFor(() => entry.querySelector('.tcas-spinner').hidden === false);
	ok('در طول بررسی، اسپینر همان کارت دیده می‌شود', !entry.querySelector('.tcas-spinner').hidden && entry.classList.contains('is-loading'));
	release();
	await waitFor(() => entry.querySelector('.tcas-spinner').hidden);
	ok('بعد از پاسخ، اسپینر خاموش می‌شود', entry.querySelector('.tcas-spinner').hidden && !entry.classList.contains('is-loading'));
	dom.window.close();
}

// ---------- ۳) کارت ناقص: کادر قرمز و فیلد خالی قابل ویرایش ----------
{
	const dom = makeDom(); const { window } = dom; const calls = [];
	window.fetch = async (url, options) => { calls.push(options.body); return reply({}); };
	window.eval(script);
	const entry = window.document.querySelector('.tcas-pair-entry');
	type(entry, '.tcas-local-code', 'TS3');
	await waitFor(() => entry.dataset.state === 'incomplete');
	ok('کارت با یک سمت خالی قرمز می‌شود و نشان «بدون همتا» می‌گیرد', entry.classList.contains('is-orphan') && entry.querySelector('.tcas-pair-flag').textContent.includes('خالی'));
	ok('برای کارت ناقص هیچ درخواستی به سرور نمی‌رود', calls.filter((body) => body.get('action') === 'tcas_preview_pair').length === 0);
	ok('فیلد سمت ناقص خالی و قابل ویرایش می‌ماند', entry.querySelector('.tcas-remote-code').value === '' && !entry.querySelector('.tcas-local-code').readOnly && !entry.querySelector('.tcas-remote-code').readOnly);
	ok('کارت ناقص قابل انتخاب و اتصال نیست', entry.querySelector('.tcas-row-select').disabled && window.document.getElementById('tcas-connect-start').disabled && entry.querySelector('.tcas-pair-message').textContent.includes('دستی'));
	dom.window.close();
}

// ---------- ۴) پاسخ قطعی از تطبیق هوشمند: کارت سبز ----------
{
	const dom = makeDom(); const { window } = dom;
	window.fetch = async (url, options) => {
		const action = options.body.get('action');
		if (action === 'tcas_match_variations') return reply({ matches: [{ local_code: 'ID:1', remote_code: 'ID:9/1', local_label: 'A', remote_label: 'A', confidence: 'exact', matched_by: 'sku', score: 100 }], ambiguous: [], unmatched: [], local: { name: 'L', reference: 'ID:0', count: 1 }, remote: { name: 'R', reference: 'ID:9', count: 1 } });
		if (action === 'tcas_preview_start') return reply({ token: 'a'.repeat(32), total: 1 });
		if (action === 'tcas_preview_step') return reply({ done: true, processed: 1, total: 1, row: pairRow(Object.assign(codes('ID:1', 'ID:9/1'), { index: 0 })) });
		return reply({});
	};
	window.eval(script);
	window.document.getElementById('tcas-smart-local').value = 'ID:0';
	window.document.getElementById('tcas-smart-remote').value = 'ID:9';
	window.document.getElementById('tcas-smart-match').click();
	await waitFor(() => window.document.querySelector('.tcas-pair-entry') && window.document.querySelector('.tcas-pair-entry').dataset.state === 'ok');
	const entry = window.document.querySelector('.tcas-pair-entry');
	ok('تطبیق قطعی کارت را سبز و انتخاب‌شده می‌کند', entry.classList.contains('is-settled') && entry.querySelector('.tcas-pair-flag').classList.contains('is-ok') && entry.querySelector('.tcas-pair-flag').textContent === 'مشترک قطعی' && entry.querySelector('.tcas-row-select').checked);
	ok('بعد از تطبیق، موجودی تازه روی همان کارت خوانده می‌شود', !entry.querySelector('.tcas-pair-stock').hidden && entry.querySelector('.tcas-pair-stock').textContent.includes('TisaCase'));
	dom.window.close();
}

// ---------- ۵) Variation بدون همتا: کارت قرمز با سمت خالی ----------
{
	const dom = makeDom(0); const { window } = dom;
	window.fetch = async (url, options) => {
		const action = options.body.get('action');
		if (action === 'tcas_match_variations') return reply({ matches: [], ambiguous: [], unmatched: [{ shop: 'tisacase', reference: 'ID:77', label: 'Only local', index: 0, reason: 'no_candidate' }], local: { name: 'L', reference: 'ID:0', count: 1 }, remote: { name: 'R', reference: 'ID:9', count: 1 } });
		return reply({});
	};
	window.eval(script);
	window.document.getElementById('tcas-smart-local').value = 'ID:0';
	window.document.getElementById('tcas-smart-remote').value = 'ID:9';
	window.document.getElementById('tcas-smart-match').click();
	await waitFor(() => window.document.querySelector('.tcas-pair-entry') && window.document.querySelector('.tcas-pair-entry').dataset.state === 'incomplete');
	const entry = window.document.querySelector('.tcas-pair-entry');
	ok('Variation بدون همتا کارت قرمز با شناسهٔ سمت موجود می‌گیرد', entry.classList.contains('is-orphan') && entry.querySelector('.tcas-local-code').value === 'ID:77');
	ok('سمت پیدا نشده خالی می‌ماند تا کاربر دستی پر کند', entry.querySelector('.tcas-remote-code').value === '' && !entry.querySelector('.tcas-remote-code').readOnly && entry.querySelector('.tcas-pair-flag').textContent.includes('ArioCase'));
	ok('گزارش تطبیق، ساخت کارت قرمز را توضیح می‌دهد', window.document.getElementById('tcas-smart-status').textContent.includes('کارت قرمز'));
	dom.window.close();
}

// ---------- ۶) اتصال از همان کارت‌های انتخاب‌شده ----------
{
	const dom = makeDom(); const { window } = dom; const bodies = [];
	window.fetch = async (url, options) => {
		const body = options.body; bodies.push(body);
		const action = body.get('action');
		if (action === 'tcas_preview_pair') return reply({ row: pairRow(codes('TS20', 'AR20')) });
		if (action === 'tcas_preview_start') return reply({ token: 'd'.repeat(32), total: 1 });
		if (action === 'tcas_preview_step') return reply({ done: true, processed: 1, total: 1, row: pairRow(Object.assign(codes('TS20', 'AR20'), { index: 0 })) });
		if (action === 'tcas_connect_start') return reply({ token: 'c'.repeat(32), total: 1 });
		if (action === 'tcas_connect_step') return reply({ done: true, processed: 1, total: 1, row: { index: 0, status: 'done', message: 'متصل شد', edit_url: '/wp-admin/post.php?post=12' } });
		return reply({});
	};
	window.eval(script);
	const entry = window.document.querySelector('.tcas-pair-entry');
	type(entry, '.tcas-local-code', 'TS20');
	type(entry, '.tcas-remote-code', 'AR20');
	await waitFor(() => entry.dataset.state === 'review');
	window.document.getElementById('tcas-connect-start').click();
	await waitFor(() => entry.dataset.state === 'done');
	const connectStart = bodies.find((body) => body.get('action') === 'tcas_connect_start');
	ok('اتصال فقط برای کارت‌های انتخاب‌شده و با همان ایندکس ارسال می‌شود', Boolean(connectStart) && connectStart.get('pairs[]') === '0');
	ok('کارت بعد از اتصال وضعیت «متصل شد» می‌گیرد', entry.querySelector('.tcas-pair-flag').textContent.includes('متصل شد') && entry.classList.contains('is-settled') && entry.querySelector('.tcas-row-select').disabled);
	ok('لینک محصول متصل‌شده روی همان کارت نشان داده می‌شود', Boolean(entry.querySelector('.tcas-pair-edit-link')));
	dom.window.close();
}

// ---------- ۷) پاسخ غیر JSON و خطای فایروال ----------
{
	const dom = makeDom(); const { window } = dom; let attempts = 0;
	window.fetch = async () => { attempts++; return htmlReply('<html>blocked</html>', 418); };
	window.eval(script);
	const entry = window.document.querySelector('.tcas-pair-entry');
	type(entry, '.tcas-local-code', 'TS30');
	type(entry, '.tcas-remote-code', 'AR30');
	await waitFor(() => entry.dataset.state === 'error');
	ok('پاسخ غیر JSON یک بار دیگر تلاش می‌شود', attempts === 2, String(attempts));
	ok('خطای ۴۱۸ با پیام فایروال/محدودیت نرخ گزارش می‌شود', entry.querySelector('.tcas-pair-message').textContent.includes('فایروال') && entry.querySelector('.tcas-pair-message').textContent.includes('418'));
	ok('کارت خطادار قرمز و غیرقابل‌انتخاب می‌شود', entry.classList.contains('is-failed') && entry.querySelector('.tcas-row-select').disabled);
	dom.window.close();
}

// ---------- ۸) پاسخ دیررس کارت را خراب نمی‌کند ----------
{
	const dom = makeDom(); const { window } = dom; let call = 0;
	window.fetch = async (url, options) => {
		if (options.body.get('action') !== 'tcas_preview_pair') return reply({});
		call++;
		if (call === 1) { await sleep(400); return reply({ row: pairRow(Object.assign(codes('OLD', 'OLD'), { local_name: 'STALE' })) }); }
		return reply({ row: pairRow(Object.assign(codes('NEW', 'NEW'), { local_name: 'FRESH' })) });
	};
	window.eval(script);
	const entry = window.document.querySelector('.tcas-pair-entry');
	type(entry, '.tcas-local-code', 'OLD');
	type(entry, '.tcas-remote-code', 'OLD');
	await waitFor(() => call === 1);
	type(entry, '.tcas-local-code', 'NEW');
	type(entry, '.tcas-remote-code', 'NEW');
	await waitFor(() => call === 2);
	await sleep(700);
	const titles = Array.from(entry.querySelectorAll('.tcas-variation-title')).map((node) => node.textContent);
	ok('پاسخ قدیمی نادیده گرفته و نام تازه حفظ می‌شود', titles.includes('FRESH') && !titles.includes('STALE'));
	dom.window.close();
}

// ---------- ۹) حذف کارت بررسی در جریان را متوقف می‌کند ----------
{
	const dom = makeDom(2); const { window } = dom; let release = null; let calls = 0;
	window.fetch = async (url, options) => {
		if (options.body.get('action') !== 'tcas_preview_pair') return reply({});
		calls++;
		await new Promise((resolve) => { release = resolve; });
		return reply({ row: pairRow({}) });
	};
	window.eval(script);
	const cards = window.document.querySelectorAll('.tcas-pair-entry');
	type(cards[1], '.tcas-local-code', 'TS-X');
	type(cards[1], '.tcas-remote-code', 'AR-X');
	await waitFor(() => cards[1].querySelector('.tcas-spinner').hidden === false);
	cards[1].querySelector('.tcas-remove-pair').click();
	release();
	await sleep(250);
	ok('حذف کارت در حین بررسی خطا نمی‌دهد و کارت حذف می‌شود', window.document.querySelectorAll('.tcas-pair-entry').length === 1 && calls === 1);
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
	ok('تعداد Variationهای واقعی قابل‌اتصال در Status نمایش داده می‌شود', window.document.getElementById('tcas-link-status').textContent.includes('2 Variation'));
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
const linkedGroupsView = fs.readFileSync(path.join(root, 'views/linked-groups.php'), 'utf8');
ok('Checkbox تنظیمات اندازهٔ ثابت دارد و تمام‌عرض نمی‌شود', uiCss.includes('.tcas-check-line input[type=checkbox]') && uiCss.includes('width:16px') && uiCss.includes('justify-self:start'));
ok('Selector و استایل جمع‌وجور Variation در View واقعی وجود دارد', pairsView.includes('id="tcas-link-variation"') && pairsView.includes('class="tcas-link-variation" hidden') && uiCss.includes('.tcas-link-variation select'));
ok('چیدمان قدیمی و فلش حذف و نتیجهٔ واقعی پیش‌فرض مخفی است', pairsView.includes('tcas-converter-flow') && pairsView.includes('id="tcas-link-result" class="tcas-converter-result" hidden') && !pairsView.includes('tcas-converter-layout') && !pairsView.includes('tcas-converter-arrow') && !uiCss.includes('.tcas-converter-arrow') && !uiCss.includes('.tcas-input-action'));
ok('کارت تطبیق و کنترل دستهٔ بعد در View و CSS واقعی وجود دارد', pairsView.includes('id="tcas-smart-local"') && pairsView.includes('id="tcas-smart-remote"') && pairsView.includes('id="tcas-smart-next" class="button" hidden') && uiCss.includes('.tcas-smart-grid'));
ok('فهرست موارد مشکل‌دار، فیلتر و دلیل دقیق در View و CSS واقعی وجود دارد', pairsView.includes('id="tcas-smart-issue-filters"') && pairsView.includes('id="tcas-smart-issue-list"') && pairsView.includes('بررسی دستی') && uiCss.includes('.tcas-smart-issue-filter') && uiCss.includes('.tcas-smart-issue[hidden]') && uiCss.includes('.tcas-smart-issue-candidate') && uiCss.includes('.tcas-pair-flag'));
const pairsCard = pairsView.slice(pairsView.indexOf('tcas-pairs-card'), pairsView.indexOf('tcas-linked-groups'));
ok('بخش جداگانهٔ پیش‌نمایش حذف شده است', !pairsView.includes('tcas-preview-panel') && !pairsView.includes('tcas-preview-list') && !pairsView.includes('tcas-connect-results'));
ok('هر کارت جفت اسپینر، نشان وضعیت، موجودی و پیام خودش را دارد', pairsView.includes('id="tcas-pair-entry-template"') && pairsView.includes('class="tcas-spinner"') && pairsView.includes('class="tcas-pair-stock"') && pairsView.includes('class="tcas-pair-flag') && pairsView.includes('class="tcas-pair-message"'));
ok('فیلدهای کد هر کارت قابل ویرایش‌اند (readonly نیستند)', pairsCard.includes('class="tcas-local-code" type="text"') && pairsCard.includes('class="tcas-remote-code" type="text"') && !pairsCard.includes('readonly'));
ok('رنگ‌های سبز/زرد/قرمز کارت و نشان در CSS واقعی تعریف شده‌اند', uiCss.includes('.tcas-pair-entry.is-settled') && uiCss.includes('.tcas-pair-entry.is-review') && uiCss.includes('.tcas-pair-entry.is-orphan') && uiCss.includes('.tcas-pair-flag.is-ok') && uiCss.includes('.tcas-pair-flag.is-warn') && uiCss.includes('.tcas-pair-flag.is-danger'));
ok('اسپینر و موجودی کارت استایل اختصاصی دارند', uiCss.includes('.tcas-spinner') && uiCss.includes('@keyframes tcas-spin') && uiCss.includes('.tcas-pair-stock') && uiCss.includes('.tcas-pair-top'));
ok('نشان‌های خانوادهٔ مدل و پیشنهاد در CSS واقعی وجود دارد', uiCss.includes('.tcas-pair-flag.is-warn') && uiCss.includes('.tcas-pair-flag-note') && uiCss.includes('.tcas-smart-issue-multiple_families'));
ok('جفت‌های تازه با کارت والد و شبکهٔ Variation سه‌ستونی ساخته می‌شوند', script.includes('tcas-fresh-product-group') && script.includes('tcas-pair-entries-grouped') && uiCss.includes('.tcas-pair-entries-grouped { grid-template-columns:repeat(3,minmax(0,1fr))'));
ok('محصولات متصل آکارئونی و کارت‌های Variation سه‌ستونی با موجودی دارند', linkedGroupsView.includes('<details class="tcas-linked-group">') && linkedGroupsView.includes('tcas-linked-variation-card') && linkedGroupsView.includes("$row['quantity']") && uiCss.includes('.tcas-linked-variation-grid { display:grid; grid-template-columns:repeat(3,minmax(0,1fr))'));
console.log(`\n${pass} passed, ${fail} failed`); if (fail) process.exit(1);
