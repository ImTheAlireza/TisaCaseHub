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
		<label class="tcas-shop-field tcas-shop-local"><input id="tcas-smart-local" type="text"></label><label class="tcas-shop-field tcas-shop-remote"><input id="tcas-smart-remote" type="text"></label><button id="tcas-smart-match">تطبیق</button><button id="tcas-smart-next" hidden>دسته بعد</button><span id="tcas-smart-status"></span>
		<section id="tcas-smart-issues" hidden><h3>موارد نیازمند بررسی</h3><span id="tcas-smart-issues-count"></span><p class="tcas-smart-issues-help"></p><div id="tcas-smart-issue-filters" role="group"></div><div id="tcas-smart-issue-list" role="list"></div></section>
		<section class="tcas-card tcas-pairs-card"><div class="tcas-card-head"><div class="tcas-pairs-actions"><button id="tcas-select-valid" class="button">انتخاب همه</button><button id="tcas-preview-start" class="button">بررسی همه دوباره</button><button id="tcas-pair-add" class="button">افزودن جفت</button></div></div><p class="tcas-pairs-help">help</p>
		<div id="tcas-pair-entries" class="tcas-pair-entries">${entries}</div><template id="tcas-pair-entry-template">${entryMarkup()}</template>
		<div class="tcas-pair-actions"><button id="tcas-connect-start" class="button" disabled>اتصال انتخاب‌شده‌ها</button><span id="tcas-preview-status" class="tcas-status"></span><span id="tcas-connect-status" class="tcas-status"></span></div>
		<div id="tcas-preview-progress" class="tcas-progress" hidden><progress max="100" value="0"></progress><span>۰٪</span></div>
		<div id="tcas-connect-progress" class="tcas-progress" hidden><progress max="100" value="0"></progress><span>۰٪</span></div></section>
		<input id="tcas-test-code"><button id="tcas-test-connection">تست</button><div id="tcas-test-result"></div>
	</body></html>`, { url: 'https://tisa.test/wp-admin/admin.php?page=tcas', runScripts: 'outside-only' });
	dom.window.TCAS_ADMIN = { ajaxUrl: '/wp-admin/admin-ajax.php', restUrl: 'https://tisa.test/wp-json/tcas/v1/do', restNonce: 'rest-nonce', stepSize: 5, nonce: 'nonce', partnerLabel: 'ArioCase', mappingsUrl: '/wp-admin/admin.php?page=tcas-mappings', strings: { working: '...', selectRows: 'select', disconnect: 'confirm' } };
	return dom;
}
async function waitFor(fn, timeout = 2000) { const start = Date.now(); while (!fn()) { if (Date.now() - start > timeout) throw new Error('waitFor timeout'); await new Promise((resolve) => setTimeout(resolve, 10)); } }
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
function codes(local, remote) { return { local_code: local, remote_code: remote }; }
function pairRow(overrides) {
	return Object.assign({ status: 'connect', selectable: true, local_name: 'Local case', remote_name: 'Remote case', stock: { quantity: 4, status: 'instock' }, local_stock: { quantity: 2, status: 'instock' }, message: 'دو محصول جفت می‌شوند.' }, overrides || {});
}
function decodeValue(value) {
	if (typeof value !== 'string' || !value.startsWith('b64:')) return value;
	return Buffer.from(value.slice(4), 'base64').toString('utf8');
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

// ---------- ۴) تطبیق هوشمند: کارت سبز بدون هیچ خواندن موجودی ----------
{
	const dom = makeDom(); const { window } = dom; const actions = [];
	window.fetch = async (url, options) => {
		const action = options.body.get('action'); actions.push(action);
		if (action === 'tcas_match_variations') return reply({ matches: [{ local_code: 'ID:1', remote_code: 'ID:9/1', local_label: 'A', remote_label: 'A', confidence: 'exact', matched_by: 'sku', score: 100 }], ambiguous: [], unmatched: [], local: { name: 'L', reference: 'ID:0', count: 1 }, remote: { name: 'R', reference: 'ID:9', count: 1 } });
		if (action === 'tcas_preview_start') return reply({ token: 'a'.repeat(32), total: 1 });
		if (action === 'tcas_preview_step') return reply({ done: true, processed: 1, total: 1, rows: [pairRow(Object.assign(codes('ID:1', 'ID:9/1'), { index: 0 }))] });
		return reply({});
	};
	window.eval(script);
	window.document.getElementById('tcas-smart-local').value = 'ID:0';
	window.document.getElementById('tcas-smart-remote').value = 'ID:9';
	window.document.getElementById('tcas-smart-match').click();
	await waitFor(() => window.document.querySelector('.tcas-pair-entry') && window.document.querySelector('.tcas-pair-entry').dataset.state === 'ready', 8000);
	const entry = window.document.querySelector('.tcas-pair-entry');
	ok('تطبیق قطعی کارت را سبز و انتخاب‌شده می‌کند', entry.classList.contains('is-ready') && entry.querySelector('.tcas-pair-flag').classList.contains('is-ok') && entry.querySelector('.tcas-pair-flag').textContent === 'قطعی · آمادهٔ اتصال' && entry.querySelector('.tcas-row-select').checked);
	ok('بعد از تطبیق هیچ درخواستی برای موجودی زده نمی‌شود', !actions.includes('tcas_preview_start') && !actions.includes('tcas_preview_pair'), actions.join(','));
	ok('موجودی فقط بعد از اتصال نمایش داده می‌شود', entry.querySelector('.tcas-pair-stock').hidden);
	ok('پیام کارت می‌گوید موجودی هنگام اتصال خوانده می‌شود', entry.querySelector('.tcas-pair-message').textContent.includes('اتصال')); 
	ok('دکمهٔ اتصال با کارت آماده فعال است', !window.document.getElementById('tcas-connect-start').disabled);
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

// ---------- ۵.۵) فهرست مدل: یک Variation همکار روی دو کارت تیساکیس ----------
{
	const dom = makeDom(); const { window } = dom; const bodies = [];
	window.fetch = async (url, options) => {
		const action = options.body.get('action');
		bodies.push({ action, body: options.body });
		if (action === 'tcas_match_variations') return reply({
			matches: [
				{ local_code: 'ID:407473', remote_code: 'ID:46922/46993', local_label: 'Samsung A52', remote_label: 'Samsung A52 / A52s', confidence: 'list', matched_by: 'list', score: 84, shared: ['a52'], extra: ['a52s'], shared_remote: true },
				{ local_code: 'ID:407474', remote_code: 'ID:46922/46993', local_label: 'Samsung A52s', remote_label: 'Samsung A52 / A52s', confidence: 'list', matched_by: 'list', score: 84, shared: ['a52s'], extra: ['a52'], shared_remote: true }
			],
			ambiguous: [], unmatched: [],
			local: { name: 'L', reference: 'ID:0', count: 2 }, remote: { name: 'R', reference: 'ID:9', count: 1 }
		});
		if (action === 'tcas_preview_start') return reply({ token: 'b'.repeat(32), total: 2 });
		if (action === 'tcas_preview_step') return reply({ done: true, processed: 2, total: 2, rows: [] });
		return reply({});
	};
	window.eval(script);
	window.document.getElementById('tcas-smart-local').value = 'ID:0';
	window.document.getElementById('tcas-smart-remote').value = 'ID:9';
	window.document.getElementById('tcas-smart-match').click();
	await waitFor(() => window.document.querySelectorAll('.tcas-pair-entry').length === 2, 8000);
	const entries = Array.from(window.document.querySelectorAll('.tcas-pair-entry'));
	ok('یک Variation چندمدلی همکار دو کارت جدا برای دو Variation تیساکیس می‌سازد', 2 === entries.length && entries[0].querySelector('.tcas-local-code').value === 'ID:407473' && entries[1].querySelector('.tcas-local-code').value === 'ID:407474');
	ok('هر دو کارت به همان مرجع همکار وصل می‌شوند', entries[0].querySelector('.tcas-remote-code').value === 'ID:46922/46993' && entries[1].querySelector('.tcas-remote-code').value === 'ID:46922/46993');
	ok('نشان کارت «پیشنهاد · فهرست مدل» با رنگ بررسی است', entries[0].querySelector('.tcas-pair-flag').textContent.includes('فهرست مدل') && entries[0].querySelector('.tcas-pair-flag').classList.contains('is-warn') && entries[0].classList.contains('is-review'));
	ok('یادداشت کارت، مدل خودش و مدل‌های دیگر همان Variation را نشان می‌دهد', entries[0].querySelector('.tcas-pair-flag-note').textContent.includes('مدل این کارت') && entries[0].querySelector('.tcas-pair-flag-note').textContent.includes('a52s'));
	ok('هر دو کارت برای اتصال انتخاب‌شده‌اند', entries.every((entry) => entry.querySelector('.tcas-row-select').checked && !entry.querySelector('.tcas-row-select').disabled));
	ok('پرچم مرجع مشترک روی کارت ذخیره می‌شود', entries.every((entry) => '1' === entry.dataset.sharedRemote));
	window.document.getElementById('tcas-connect-start').click();
	await waitFor(() => bodies.some((item) => 'tcas_preview_start' === item.action), 8000);
	const start = bodies.filter((item) => 'tcas_preview_start' === item.action).pop();
	const shared = start && start.body.getAll ? start.body.getAll('shared[]') : [];
	ok('درخواست اتصال، هر دو نمایهٔ مرجع مشترک را می‌فرستد', 2 === shared.length && shared.includes('0') && shared.includes('1'));
	ok('گزارش تطبیق، کارت‌های فهرست مدل را می‌شمارد', window.document.getElementById('tcas-smart-status').textContent.includes('Variation چندمدلی'));
	dom.window.close();
}

// ---------- ۵.۶) مرجع مشترک از سمت سرور: کارت بی‌پرچم با برچسب چندمدلی همکار ----------
{
	const dom = makeDom(); const { window } = dom;
	window.fetch = async (url, options) => {
		const action = options.body.get('action');
		// تطبیق، کارت دوم را از پاس «پیشنهاد» می‌سازد: بدون پرچم مرجع مشترک.
		if (action === 'tcas_match_variations') return reply({
			matches: [
				{ local_code: 'ID:407472', remote_code: 'ID:46922/46978', local_label: 'A52', remote_label: 'Samsung A52 / A52s', confidence: 'list', matched_by: 'list', score: 84, shared: ['a52'], extra: ['a52s'], shared_remote: true },
				{ local_code: 'ID:407473', remote_code: 'ID:46922/46978', local_label: 'A52s', remote_label: 'Samsung A52 / A52s', confidence: 'suggested', matched_by: 'suggested', score: 80, shared: ['a52', 'a52s'], extra: [] }
			],
			ambiguous: [], unmatched: [],
			local: { name: 'L', reference: 'ID:0', count: 2 }, remote: { name: 'R', reference: 'ID:9', count: 1 }
		});
		if (action === 'tcas_preview_start') return reply({ token: 'e'.repeat(32), total: 2 });
		// سرور برچسب چندمدلی را تشخیص می‌دهد و هر دو کارت را shared برمی‌گرداند.
		if (action === 'tcas_preview_step') return reply({ done: true, processed: 2, total: 2, rows: [
			pairRow(Object.assign(codes('ID:407472', 'ID:46922/46978'), { index: 0, shared_remote: true, status: 'connect', selectable: true })),
			pairRow(Object.assign(codes('ID:407473', 'ID:46922/46978'), { index: 1, shared_remote: true, status: 'connect', selectable: true }))
		] });
		if (action === 'tcas_connect_start') return reply({ token: 'f'.repeat(32), total: 2 });
		if (action === 'tcas_connect_step') return reply({ done: true, processed: 2, total: 2, rows: [] });
		return reply({});
	};
	window.eval(script);
	window.document.getElementById('tcas-smart-local').value = 'ID:0';
	window.document.getElementById('tcas-smart-remote').value = 'ID:9';
	window.document.getElementById('tcas-smart-match').click();
	await waitFor(() => window.document.querySelectorAll('.tcas-pair-entry').length === 2, 8000);
	const entries = Array.from(window.document.querySelectorAll('.tcas-pair-entry'));
	ok('کارت پیشنهادی بدون پرچم، قبل از بررسی مرجع مشترک را اعلام نمی‌کند', '' === entries[1].dataset.sharedRemote && entries[0].dataset.sharedRemote === '1');
	entries[1].querySelector('.tcas-row-select').checked = true;
	window.document.getElementById('tcas-connect-start').click();
	await waitFor(() => entries[1].dataset.sharedRemote === '1', 8000);
	ok('پاسخ سرور پرچم مرجع مشترک را روی کارت بی‌پرچم فعال می‌کند', '1' === entries[1].dataset.sharedRemote);
	ok('یادداشت «چند مدل» روی همان کارت ظاهر می‌شود', !entries[1].querySelector('.tcas-pair-flag-note').hidden && entries[1].querySelector('.tcas-pair-flag-note').textContent.includes('چند مدل'));
	dom.window.close();
}

// ---------- ۵.۶۵) مرجع اشغال‌شده: کارت هست، انتقال فقط با تأیید ----------
{
	const dom = makeDom(); const { window } = dom; const bodies = []; const confirmations = [];
	window.confirm = (message) => { confirmations.push(String(message)); return true; };
	window.fetch = async (url, options) => {
		const body = options.body; bodies.push(body);
		const action = body.get('action');
		if (action === 'tcas_match_variations') return reply({
			matches: [
				{ local_code: 'ID:309494', remote_code: 'ID:45448/45452', local_label: 'iPhone 13', remote_label: 'iPhone 13', confidence: 'family', matched_by: 'family', score: 88 },
				{ local_code: 'ID:309495', remote_code: 'ID:45448/45453', local_label: 'iPhone 16', remote_label: 'iPhone 16', confidence: 'family', matched_by: 'family', score: 88, taken_by: 401726, taken_by_name: 'قاب چرم کرم گلستان سومه ای - iPhone 13', reclaim: true }
			],
			ambiguous: [], unmatched: [], skipped: [{ local_code: 'ID:309495' }],
			summary: { family: 2, skipped: 1 }, connected: 0,
			local: { name: 'L', reference: 'ID:0', count: 3 }, remote: { name: 'R', reference: 'ID:9', count: 3 }
		});
		if (action === 'tcas_preview_start') return reply({ token: 'r'.repeat(32), total: 2 });
		if (action === 'tcas_connect_start') return reply({ token: 'r'.repeat(32), total: 2 });
		return reply({});
	};
	window.eval(script);
	window.document.getElementById('tcas-smart-local').value = 'ID:0';
	window.document.getElementById('tcas-smart-remote').value = 'ID:9';
	window.document.getElementById('tcas-smart-match').click();
	await waitFor(() => window.document.querySelectorAll('.tcas-pair-entry').length === 2, 8000);
	const entries = Array.from(window.document.querySelectorAll('.tcas-pair-entry'));
	const taken = entries.find((entry) => '401726' === entry.dataset.reclaim);
	ok('Variation با مرجع اشغال‌شده هم کارت می‌گیرد و از چشم نمی‌افتد', Boolean(taken));
	ok('روی کارت نوشته می‌شود مرجع دست کدام محصول است', taken && taken.querySelector('.tcas-pair-flag-note').textContent.includes('401726') && taken.querySelector('.tcas-pair-flag-note').textContent.includes('منتقل'));
	ok('فهرست «موارد نیازمند بررسی» با این کارت شلوغ نمی‌شود', 0 === window.document.querySelectorAll('.tcas-smart-issue').length);
	ok('گزارش تطبیق می‌گوید کارت‌ها با تأیید منتقل می‌شوند', window.document.getElementById('tcas-smart-status').textContent.includes('مرجع از آن Variation برداشته و منتقل می‌شود'));
	// اتصال: بدون تأیید کاربر هیچ انتقالی انجام نمی‌شود.
	window.document.getElementById('tcas-connect-start').disabled = false;
	entries.forEach((entry) => { const box = entry.querySelector('.tcas-row-select'); box.disabled = false; box.checked = true; });
	window.document.getElementById('tcas-connect-start').click();
	await waitFor(() => bodies.some((body) => body.get('action') === 'tcas_preview_start'), 8000);
	const preview = bodies.find((body) => body.get('action') === 'tcas_preview_start');
	ok('پیش از انتقال، کادر تأیید با شمارهٔ محصول قبلی نشان داده می‌شود', confirmations.some((text) => text.includes('401726') && text.includes('قطع')));
	const reclaimField = preview.getAll('reclaim[]').join(',');
	ok('نمایهٔ کارت‌های انتقالی به سرور فرستاده می‌شود', reclaimField.length > 0, reclaimField);
	dom.window.close();
}

// ---------- ۵.۷) کارت از قبل متصل: نه بررسی، نه درخواست ----------
{
	const dom = makeDom(); const { window } = dom; const bodies = [];
	window.fetch = async (url, options) => {
		const action = options.body.get('action');
		bodies.push({ action, body: options.body });
		if (action === 'tcas_match_variations') return reply({
			matches: [
				{ local_code: 'ID:407472', remote_code: 'ID:46922/46978', local_label: 'A52', remote_label: 'Samsung A52 / A52s', confidence: 'list', matched_by: 'list', score: 84, shared: ['a52'], extra: ['a52s'], shared_remote: true, connected: true, connected_code: 'ID:46922/46978' },
				{ local_code: 'ID:407473', remote_code: 'ID:46922/46978', local_label: 'A52s', remote_label: 'Samsung A52 / A52s', confidence: 'list', matched_by: 'list', score: 84, shared: ['a52s'], extra: ['a52'], shared_remote: true }
			],
			ambiguous: [], unmatched: [], connected: 1,
			local: { name: 'L', reference: 'ID:0', count: 2 }, remote: { name: 'R', reference: 'ID:9', count: 1 }
		});
		if (action === 'tcas_preview_start') return reply({ token: 'g'.repeat(32), total: 1 });
		if (action === 'tcas_preview_step') return reply({ done: true, processed: 1, total: 1, rows: [] });
		return reply({});
	};
	window.eval(script);
	window.document.getElementById('tcas-smart-local').value = 'ID:0';
	window.document.getElementById('tcas-smart-remote').value = 'ID:9';
	window.document.getElementById('tcas-smart-match').click();
	await waitFor(() => window.document.querySelectorAll('.tcas-pair-entry').length === 2, 8000);
	const entries = Array.from(window.document.querySelectorAll('.tcas-pair-entry'));
	ok('کارت از قبل متصل سبز و «بدون درخواست دوباره» علامت می‌خورد', entries[0].dataset.state === 'done' && entries[0].querySelector('.tcas-pair-flag').textContent.includes('قبلاً متصل') && entries[0].classList.contains('is-settled'));
	ok('کارت از قبل متصل انتخاب‌نشده و غیرقابل‌انتخاب می‌ماند', !entries[0].querySelector('.tcas-row-select').checked && entries[0].querySelector('.tcas-row-select').disabled && '1' === entries[0].dataset.savedDone);
	ok('گزارش تطبیق، تعداد کارت‌های از قبل متصل را می‌گوید', window.document.getElementById('tcas-smart-status').textContent.includes('از قبل متصل بود'));
	ok('بعد از تطبیق هیچ درخواست موجودی برای کارت‌های از قبل متصل زده نمی‌شود', !bodies.some((item) => 'tcas_preview_pair' === item.action) && !bodies.some((item) => 'tcas_preview_start' === item.action));
	window.document.getElementById('tcas-connect-start').click();
	await waitFor(() => bodies.some((item) => 'tcas_preview_start' === item.action), 8000);
	const start = bodies.filter((item) => 'tcas_preview_start' === item.action).pop();
	const lines = String(start.body.get('pairs') || '').split('\n').filter(Boolean);
	ok('اتصال فقط کارت متصل‌نشده را می‌فرستد (یک جفت، نه دو)', 1 === lines.length && lines[0].includes('ID:407473'), lines.join('|'));
	ok('نمایهٔ مرجع مشترک با همان یک جفت هم‌ترتیب می‌ماند', start.body.getAll('shared[]').join(',') === '0');
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
	ok('پاسخ غیر JSON یک بار دیگر تلاش می‌شود', attempts > 1 && attempts % 2 === 0, String(attempts));
	const message = entry.querySelector('.tcas-pair-message').textContent;
	ok('پیام خطا شامل کد ۴۱۸ و دامنهٔ همان سایت است', message.includes('418') && message.includes('tisa.test') && message.includes('سرور سایت'));
	ok('کارت خطادار قرمز و غیرقابل‌انتخاب می‌شود', entry.classList.contains('is-failed') && entry.querySelector('.tcas-row-select').disabled);
	dom.window.close();
}

// ---------- ۷ب) پنل «جزئیات خطای درخواست»: مبدا و فایروال ----------
{
	const dom = makeDom(); const { window } = dom; const headers = [];
	window.fetch = async (url, options) => {
		headers.push(options.headers || {});
		return {
			status: 418,
			headers: { get: (name) => ({ 'server': 'cloudflare', 'cf-ray': '8f2c1d3e4a5b-FRA', 'content-type': 'text/html; charset=UTF-8' }[String(name).toLowerCase()] || '') },
			text: async () => '<!DOCTYPE html><html><head><title>Access denied</title></head><body><h1>Sorry, you have been blocked</h1><p>Cloudflare Ray ID: 8f2c1d3e4a5b-FRA</p></body></html>'
		};
	};
	window.eval(script);
	const entry = window.document.querySelector('.tcas-pair-entry');
	type(entry, '.tcas-local-code', 'TS40');
	type(entry, '.tcas-remote-code', 'AR40');
	await waitFor(() => entry.dataset.state === 'error');
	const panel = window.document.getElementById('tcas-request-diag');
	ok('پنل جزئیات خطا ساخته و باز می‌شود', Boolean(panel) && !panel.hidden);
	const text = panel.textContent;
	ok('پنل، آدرس و دامنهٔ پاسخ‌دهنده را نشان می‌دهد', text.includes('tisa.test') && text.includes('admin-ajax.php') && text.includes('دامنهٔ پاسخ‌دهنده'));
	ok('پنل، فایروال شناسایی‌شده و cf-ray را نشان می‌دهد', text.includes('Cloudflare') && text.includes('8f2c1d3e4a5b-FRA'));
	ok('پنل، نمونهٔ پاسخ سرور را بدون تگ HTML نشان می‌دهد', text.includes('you have been blocked') && !text.includes('<h1>'));
	ok('پنل توضیح می‌دهد پاسخ از همان site خود کاربر است', text.includes('admin-ajax.php خود وردپرس') && text.includes('ArioCase'));
	ok('درخواست AJAX با هدر X-Requested-With ارسال می‌شود', String(headers[0]['X-Requested-With'] || '') === 'XMLHttpRequest');
	dom.window.close();
}

// ---------- ۷ج) شناسه‌های لینکی به‌صورت b64 (دور زدن فایروال «://») ----------
{
	const dom = makeDom(); const { window } = dom; const bodies = [];
	window.fetch = async (url, options) => {
		bodies.push(options.body);
		const action = options.body.get('action');
		if (action === 'tcas_match_variations') return reply({ matches: [], ambiguous: [], unmatched: [], local: { name: 'L', reference: 'ID:0', count: 0 }, remote: { name: 'R', reference: 'ID:9', count: 0 } });
		return reply({});
	};
	window.eval(script);
	window.document.getElementById('tcas-smart-local').value = 'https://tisacase.com/product/%d9%82%d8%a7%d8%a8/';
	window.document.getElementById('tcas-smart-remote').value = 'https://ariocase.com/product/case/';
	window.document.getElementById('tcas-smart-match').click();
	await waitFor(() => bodies.length > 0);
	const body = bodies[0];
	ok('لینک‌ها با پیشوند b64 ارسال می‌شوند و «://» در بدنهٔ POST نمی‌ماند', body.get('local_code').startsWith('b64:') && !String(body.get('local_code')).includes('://'));
	ok('b64 دقیقاً همان لینک اولیه را برمی‌گرداند (شامل %XX فارسی)', decodeValue(body.get('local_code')) === 'https://tisacase.com/product/%d9%82%d8%a7%d8%a8/' && decodeValue(body.get('remote_code')) === 'https://ariocase.com/product/case/');
	const card = window.document.querySelector('.tcas-pair-entry');
	type(card, '.tcas-local-code', 'ID:401470');
	type(card, '.tcas-remote-code', 'ID:45969/46045');
	const before = bodies.length;
	await waitFor(() => bodies.slice(before).some((body) => body.get('action') === 'tcas_preview_pair'));
	const plain = bodies.slice(before).filter((body) => body.get('action') === 'tcas_preview_pair').pop();
	ok('شناسه‌های غیرلینکی دست‌نخورده و بدون b64 ارسال می‌شوند', plain.get('local_code') === 'ID:401470' && plain.get('remote_code') === 'ID:45969/46045');
	dom.window.close();
}

// ---------- ۷د) دسته‌بندی گام‌ها و ادامه پس از قطع ارتباط ----------
{
	const dom = makeDom(2); const { window } = dom; const steps = []; let startCalls = 0;
	window.fetch = async (url, options) => {
		const body = options.body; const action = body.get('action');
		if (action === 'tcas_preview_start') { startCalls++; return reply({ token: 'e'.repeat(32), total: 2 }); }
		if (action === 'tcas_preview_step') {
			steps.push({ from: body.get('from'), size: body.get('size') });
			const from = Number(body.get('from'));
			const rows = [];
			for (let i = from; i < Math.min(2, from + Number(body.get('size') || 1)); i++) rows.push(pairRow(Object.assign(codes('TS' + i, 'AR' + i), { index: i })));
			const processed = Math.min(2, from + rows.length);
			return reply({ done: processed >= 2, processed, total: 2, rows });
		}
		return reply({});
	};
	window.eval(script);
	const cards = window.document.querySelectorAll('.tcas-pair-entry');
	cards[0].querySelector('.tcas-local-code').value = 'TS0';
	cards[0].querySelector('.tcas-remote-code').value = 'AR0';
	cards[1].querySelector('.tcas-local-code').value = 'TS1';
	cards[1].querySelector('.tcas-remote-code').value = 'AR1';
	window.document.getElementById('tcas-preview-start').click();
	await waitFor(() => cards[0].dataset.state !== 'loading' && cards[0].dataset.state !== 'idle' && cards[1].dataset.state !== 'loading' && cards[1].dataset.state !== 'idle', 8000);
	ok('گام بررسی، دسته‌ای و با اندازهٔ مشخص ارسال می‌شود', steps.length >= 1 && steps[0].size === '5');
	ok('همهٔ کارت‌ها با همان تعداد درخواست کمتر پر می‌شوند', startCalls === 1 && cards[0].querySelector('.tcas-pair-stock').hidden === false && cards[1].querySelector('.tcas-pair-stock').hidden === false);
	const before = steps.length;
	cards[0].dataset.state = 'idle';
	window.document.getElementById('tcas-preview-start').click();
	await waitFor(() => steps.length > before, 8000);
	ok('بررسی دوباره از جفت صفر شروع می‌شود (پارامتر from)', steps[steps.length - 1].from === '0');
	dom.window.close();
}

// ---------- ۷ه) قطع ارتباط در میانهٔ بررسی: تلاش دوباره و ادامه بدون جاافتادن جفت ----------
{
	const dom = makeDom(1); const { window } = dom; const steps = []; let dropped = 0;
	window.fetch = async (url, options) => {
		const body = options.body; const action = body.get('action');
		if (action === 'tcas_preview_start') return reply({ token: 'f'.repeat(32), total: 1 });
		if (action === 'tcas_preview_step') {
			steps.push(Number(body.get('from')));
			dropped++;
			if (dropped <= 2) throw new TypeError('Failed to fetch');
			return reply({ done: true, processed: 1, total: 1, rows: [pairRow(Object.assign(codes('TS7', 'AR7'), { index: 0 }))] });
		}
		return reply({});
	};
	window.eval(script);
	const card = window.document.querySelector('.tcas-pair-entry');
	card.querySelector('.tcas-local-code').value = 'TS7';
	card.querySelector('.tcas-remote-code').value = 'AR7';
	window.document.getElementById('tcas-preview-start').click();
	await waitFor(() => card.dataset.state === 'ok' || card.dataset.state === 'review', 8000);
	ok('قطع ارتباط با تلاش دوباره از همان جفت جبران می‌شود', steps.length === 3 && steps.every((from) => from === 0));
	ok('کارت بعد از تلاش دوباره نتیجهٔ واقعی می‌گیرد', !card.querySelector('.tcas-pair-stock').hidden && card.querySelector('.tcas-pair-flag').textContent !== 'خطا · نیاز به بررسی');
	dom.window.close();
}

// ---------- ۷و) خطای شبکه: پیام HTTP 0 و دلیل قطعی ----------
{
	const dom = makeDom(); const { window } = dom;
	window.fetch = async () => { throw new TypeError('Failed to fetch'); };
	window.eval(script);
	const entry = window.document.querySelector('.tcas-pair-entry');
	type(entry, '.tcas-local-code', 'TS50');
	type(entry, '.tcas-remote-code', 'AR50');
	await waitFor(() => entry.dataset.state === 'error', 8000);
	const message = entry.querySelector('.tcas-pair-message').textContent;
	ok('قطع کامل ارتباط با پیام HTTP 0 گزارش می‌شود', message.includes('HTTP 0') && message.includes('قطع شد'));
	ok('پیام، سه دلیل محتمل قطعی را نام می‌برد', message.includes('فایروال') && message.includes('Timeout') && message.includes('شبکه'));
	const panel = window.document.getElementById('tcas-request-diag');
	ok('پنل جزئیات، دلیل خام مرورگر را نگه می‌دارد', Boolean(panel) && panel.textContent.includes('Failed to fetch'));
	dom.window.close();
}

// ---------- ۷ز) جست‌وجوی محصول TisaCase به‌جای چسباندن لینک ----------
{
	const dom = makeDom(); const { window } = dom; const searches = [];
	window.fetch = async (url, options) => {
		const body = options.body; const action = body.get('action');
		if (action === 'tcas_search_products') {
			searches.push({ term: body.get('term'), productId: body.get('product_id') });
			if (body.get('product_id')) return reply({ shop: 'tisacase', shop_label: 'TisaCase', sku: 'TS-PARENT', reference: 'TS-PARENT', name: 'قاب شیائومی', product_id: 41, type: 'variable', variations: [{ product_id: 42, parent_id: 41, sku: 'TS-RED', reference: 'ID:42', label: 'قرمز' }] });
			if ('قاب' === body.get('term')) return reply({ rows: [
				{ reference: 'TS0100', product_id: 11, type: 'simple', sku: 'TS0100', name: 'قاب شیائومی', label: '', stock: { quantity: 3, status: 'instock' } },
				{ reference: 'ID:42', product_id: 42, parent_id: 41, type: 'variation', sku: 'TS-RED', name: 'قاب شیائومی', label: 'قرمز', stock: { quantity: 1, status: 'instock' } },
			] });
			if ('TS-PARENT' === body.get('term')) return reply({ rows: [
				{ reference: 'TS-PARENT', product_id: 41, type: 'variable', sku: 'TS-PARENT', name: 'قاب شیائومی', label: '', stock: { quantity: null, status: '' } },
			] });
			return reply({ rows: [] });
		}
		return reply({});
	};
	window.eval(script);
	const input = window.document.getElementById('tcas-link-input');
	input.value = 'قاب';
	input.dispatchEvent(new window.Event('input'));
	await waitFor(() => window.document.querySelectorAll('.tcas-search-row').length === 2, 8000);
	ok('جست‌وجوی نام محصول، فهرست انتخاب را بدون ارسال لینک باز می‌کند', searches.some((call) => call.term === 'قاب') && !searches.some((call) => String(call.term || '').includes('://')));
	const rows = window.document.querySelectorAll('.tcas-search-row');
	ok('هر ردیف نام، شناسه و موجودی را نشان می‌دهد', rows[0].textContent.includes('قاب شیائومی') && rows[0].textContent.includes('TS0100') && rows[0].textContent.includes('موجودی'));
	ok('Variation با برچسب خودش نمایش داده می‌شود', rows[1].textContent.includes('قرمز') && rows[1].dataset.reference === 'ID:42');
	rows[0].click();
	await waitFor(() => window.document.getElementById('tcas-link-sku').value === 'TS0100');
	ok('انتخاب محصول ساده، شناسه را بدون هیچ درخواست اضافه‌ای پر می‌کند', searches.filter((call) => call.productId).length === 0 && !window.document.getElementById('tcas-link-result').hidden && !window.document.getElementById('tcas-link-copy').disabled);
	ok('پس از انتخاب، فهرست بسته می‌شود', window.document.querySelectorAll('.tcas-search-row').length === 0);
	input.value = 'TS-PARENT';
	input.dispatchEvent(new window.Event('input'));
	await waitFor(() => window.document.querySelectorAll('.tcas-search-row').length === 1, 8000);
	window.document.querySelector('.tcas-search-row').click();
	await waitFor(() => window.document.getElementById('tcas-link-variation').options.length === 2, 8000);
	ok('انتخاب محصول متغیر، فقط Variationهای همان سایت را می‌خواند', searches.some((call) => String(call.productId) === '41') && !window.document.getElementById('tcas-link-variation-wrap').hidden);
	dom.window.close();
}

// ---------- ۷ز۲) سرعت جست‌وجو: کش مرورگر و شفافیت منبع ----------
{
	const dom = makeDom(); const { window } = dom; const terms = [];
	window.fetch = async (url, options) => {
		const body = options.body;
		if (body.get('action') === 'tcas_search_products') {
			terms.push(body.get('term'));
			return reply({ rows: [
				{ reference: 'TS0100', product_id: 11, type: 'simple', sku: 'TS0100', name: 'قاب شیائومی', label: '', stock: { quantity: 3, status: 'instock' } },
			], source: 'fibo', ms: 6.4 });
		}
		return reply({});
	};
	window.eval(script);
	const input = window.document.getElementById('tcas-link-input');
	input.value = 'قاب';
	input.dispatchEvent(new window.Event('input'));
	await waitFor(() => window.document.querySelectorAll('.tcas-search-row').length === 1, 8000);
	const note = window.document.querySelector('.tcas-search-note');
	ok('پاسخ جست‌وجو منبع (FiboSearch) و زمان پاسخ را نشان می‌دهد', Boolean(note) && note.textContent.includes('FiboSearch') && note.textContent.includes('میلی‌ثانیه') && note.textContent.includes('نتیجه'));
	input.value = '';
	input.dispatchEvent(new window.Event('input'));
	input.value = 'قاب';
	input.dispatchEvent(new window.Event('input'));
	await new Promise((resolve) => setTimeout(resolve, 900));
	ok('عبارت تکراری از کش مرورگر جواب می‌گیرد و درخواست تازه نمی‌فرستد', 1 === terms.length, terms.join('|'));
	ok('نتیجهٔ کش‌شده همچنان در فهرست نمایش داده می‌شود', window.document.querySelectorAll('.tcas-search-row').length === 1);
	dom.window.close();
}

// ---------- ۷ز۳) فهرست محلی: تایپ بدون هیچ درخواستی و همان لحظه ----------
{
	const dom = makeDom(); const { window } = dom; const calls = [];
	const indexRows = [
		[300, '', 'A52s ـ قاب چرم', 120, 'variation', 0, 'outofstock'],
		[120, 'TS120', 'قاب سامسونگ', 0, 'product', null, 'instock'],
		[121, 'TS121', 'قاب شیائومی', 0, 'product', 4, 'instock'],
		[NaN, '', '', 0, 'product', null, ''],
	];
	window.fetch = async (url, options) => {
		const body = options.body; const action = body.get('action');
		calls.push({ action, term: body.get('term') });
		if (action === 'tcas_search_index') return reply({ rows: indexRows, more: false, v: '300-3-all' });
		if (action === 'tcas_search_products') return reply({ rows: [] });
		return reply({});
	};
	window.eval(script);
	await waitFor(() => calls.some((call) => 'tcas_search_index' === call.action), 8000);
	const searchesAfterPrefetch = calls.filter((call) => 'tcas_search_products' === call.action).length;
	ok('فهرست جست‌وجو یک‌بار و بدون دخالت کاربر خوانده می‌شود', 1 === calls.filter((call) => 'tcas_search_index' === call.action).length);
	const input = window.document.getElementById('tcas-link-input');
	input.value = 'قاب';
	input.dispatchEvent(new window.Event('input'));
	await waitFor(() => window.document.querySelectorAll('.tcas-search-row').length === 3, 8000);
	const firstTerms = calls.filter((call) => 'tcas_search_products' === call.action).length;
	ok('تایپ روی فهرست محلی، هیچ درخواست جست‌وجویی نمی‌فرستد', firstTerms === searchesAfterPrefetch, JSON.stringify(calls));
	ok('زیر فهرست نوشته می‌شود نتیجه از فهرست محلی و چند میلی‌ثانیه بوده', window.document.querySelector('.tcas-search-note').textContent.includes('فهرست محلی'));
	ok('ردیف Variation نام والد و برچسب خودش را دارد', Array.from(window.document.querySelectorAll('.tcas-search-row')).some((row) => row.textContent.includes('قاب سامسونگ') && row.textContent.includes('A52s')));
	ok('ردیف بدون شناسه حذف می‌شود', !Array.from(window.document.querySelectorAll('.tcas-search-row')).some((row) => '0' === row.dataset.reference));
	// یکسان‌سازی فارسی: «ك» عربی و «ي» عربی هم پیدا می‌شوند
	input.value = 'قاب شياومي';
	input.dispatchEvent(new window.Event('input'));
	await waitFor(() => window.document.querySelector('.tcas-search-row') && window.document.querySelector('.tcas-search-row').dataset.reference === 'TS121', 8000);
	ok('«ي» و «ك» عربی با «ی» و «ک» فارسی یکی گرفته می‌شوند', window.document.querySelector('.tcas-search-row').dataset.reference === 'TS121');
	// برچسب/عنوان Variation با نرمال‌سازی جست‌وجو می‌شود
	input.value = 'a52s';
	input.dispatchEvent(new window.Event('input'));
	await waitFor(() => window.document.querySelector('.tcas-search-row') && window.document.querySelector('.tcas-search-row').dataset.reference === 'ID:300', 8000);
	ok('Variation با برچسب خودش پیدا می‌شود و مرجعش ID است', window.document.querySelector('.tcas-search-row').dataset.reference === 'ID:300');
	ok('هیچ‌کدام از این جست‌وجوها درخواست سروری نفرستاد', calls.filter((call) => 'tcas_search_products' === call.action).length === searchesAfterPrefetch);
	dom.window.close();
}

// ---------- ۷ز۴) فهرست بزرگ‌تر از سقف: بازگشت به جست‌وجوی سروری ----------
{
	const dom = makeDom(); const { window } = dom; const terms = [];
	window.fetch = async (url, options) => {
		const body = options.body; const action = body.get('action');
		if (action === 'tcas_search_index') return reply({ rows: [[120, 'TS120', 'قاب سامسونگ', 0, 'product', 3, 'instock']], more: true, v: 'x-more' });
		if (action === 'tcas_search_products') { terms.push(body.get('term')); return reply({ rows: [{ reference: 'TS999', product_id: 999, type: 'product', sku: 'TS999', name: 'قاب قدیمی', label: '', stock: { quantity: 1, status: 'instock' } }], source: 'title', ms: 12 }); }
		return reply({});
	};
	window.eval(script);
	const input = window.document.getElementById('tcas-link-input');
	input.value = 'قاب';
	input.dispatchEvent(new window.Event('input'));
	await waitFor(() => window.document.querySelectorAll('.tcas-search-row').length === 1, 8000);
	ok('وقتی فهرست کامل نیست، جست‌وجوی سروری انجام می‌شود', 1 === terms.length && window.document.querySelector('.tcas-search-row').dataset.reference === 'TS999');
	ok('پاسخ سروری هم تعداد و زمان را نشان می‌دهد', window.document.querySelector('.tcas-search-note').textContent.includes('میلی‌ثانیه'));
	dom.window.close();
}

// ---------- ۷ح) لینک چسبانده‌شده همچنان کار می‌کند و جست‌وجو را فعال نمی‌کند ----------
{
	const dom = makeDom(); const { window } = dom; const calls = [];
	window.fetch = async (url, options) => {
		const body = options.body; calls.push(body.get('action'));
		if (body.get('action') === 'tcas_resolve_link') return reply({ shop: 'tisacase', shop_label: 'TisaCase', sku: 'TS0100', reference: 'TS0100', name: 'Local case', product_id: 41, type: 'simple', variations: [] });
		return reply({ rows: [] });
	};
	window.eval(script);
	const input = window.document.getElementById('tcas-link-input');
	input.value = 'https://tisacase.com/product/local-case/';
	input.dispatchEvent(new window.Event('input'));
	await new Promise((resolve) => setTimeout(resolve, 600));
	ok('چسباندن لینک، جست‌وجوی محلی را اجرا نمی‌کند', !calls.includes('tcas_search_products'));
	input.dispatchEvent(new window.KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
	await waitFor(() => window.document.getElementById('tcas-link-sku').value === 'TS0100', 8000);
	ok('Enter روی لینک، همان مسیر تبدیل قبلی را اجرا می‌کند', calls.includes('tcas_resolve_link') && !calls.includes('tcas_search_products'));
	dom.window.close();
}

// ---------- ۷ط) جست‌وجو در کارت جفت و کارت تطبیق ----------
{
	const dom = makeDom(); const { window } = dom; const terms = [];
	window.fetch = async (url, options) => {
		const body = options.body;
		if (body.get('action') === 'tcas_search_products') {
			terms.push(body.get('term'));
			return reply({ rows: [
				{ reference: 'TS0100', product_id: 11, type: 'simple', sku: 'TS0100', name: 'قاب شیائومی', label: '', stock: { quantity: 3, status: 'instock' } },
				{ reference: 'TS-P', product_id: 41, type: 'variable', sku: 'TS-P', name: 'محصول متغیر', label: '', stock: { quantity: null, status: '' } },
			] });
		}
		if (body.get('action') === 'tcas_preview_pair') return reply({ row: pairRow(codes(body.get('local_code'), body.get('remote_code'))) });
		return reply({});
	};
	window.eval(script);
	const entry = window.document.querySelector('.tcas-pair-entry');
	const local = entry.querySelector('.tcas-local-code');
	local.value = 'قاب';
	local.dispatchEvent(new window.Event('input'));
	await waitFor(() => entry.querySelectorAll('.tcas-search-row').length >= 1, 8000);
	ok('فیلد شناسهٔ کارت جفت هم فهرست جست‌وجو دارد', terms.includes('قاب'));
	ok('محصول متغیر در فهرست کارت جفت پیشنهاد نمی‌شود', entry.querySelectorAll('.tcas-search-row').length === 1 && entry.querySelector('.tcas-search-row').dataset.reference === 'TS0100');
	entry.querySelector('.tcas-search-row').click();
	ok('انتخاب محصول، شناسه و نام همان Variation را در کارت می‌گذارد', local.value === 'TS0100' && entry.querySelector('.tcas-shop-local .tcas-variation-title') && entry.querySelector('.tcas-shop-local .tcas-variation-title').textContent === 'قاب شیائومی');
	const smart = window.document.getElementById('tcas-smart-local');
	smart.value = 'TS0100';
	smart.dispatchEvent(new window.Event('input'));
	await waitFor(() => Array.from(window.document.querySelectorAll('.tcas-search-row')).some((row) => !row.closest('.tcas-search-results').hidden), 8000);
	const visibleRow = Array.from(window.document.querySelectorAll('.tcas-search-row')).filter((row) => !row.closest('.tcas-search-results').hidden)[0];
	visibleRow.click();
	ok('جست‌وجو در کارت تطبیق، شناسهٔ والد را در همان فیلد می‌گذارد', smart.value === 'TS0100' && window.document.getElementById('tcas-smart-status').textContent.includes('انتخاب شد'));
	dom.window.close();
}

// ---------- ۷ی) مسدودشدن admin-ajax: ادامهٔ کار از مسیر REST ----------
{
	const dom = makeDom(1); const { window } = dom; const seen = [];
	window.fetch = async (url, options) => {
		seen.push({ url: String(url), action: options.body.get('action'), nonce: (options.headers || {})['X-WP-Nonce'] || '' });
		if (String(url).includes('admin-ajax.php')) {
			return htmlReply('<html><body>404 Not Found — LiteSpeed Web Server</body></html>', 404);
		}
		const action = options.body.get('action');
		if (action === 'tcas_preview_start') return reply({ token: 'g'.repeat(32), total: 1 });
		if (action === 'tcas_preview_step') return reply({ done: true, processed: 1, total: 1, rows: [pairRow(Object.assign(codes('TS8', 'AR8'), { index: 0 }))] });
		return reply({});
	};
	window.eval(script);
	const card = window.document.querySelector('.tcas-pair-entry');
	card.querySelector('.tcas-local-code').value = 'TS8';
	card.querySelector('.tcas-remote-code').value = 'AR8';
	window.document.getElementById('tcas-preview-start').click();
	await waitFor(() => card.dataset.state === 'ok' || card.dataset.state === 'review', 8000);
	const restCalls = seen.filter((call) => call.url.includes('/wp-json/tcas/v1/do/'));
	ok('وقتی admin-ajax صفحهٔ ۴۰۴ هاست را برمی‌گرداند، همان درخواست از مسیر REST تکرار می‌شود', restCalls.length >= 2 && restCalls[0].action === 'tcas_preview_start');
	ok('مسیر REST برای هر action آدرس اختصاصی خودش را می‌گیرد', restCalls.some((call) => call.url.endsWith('/preview_step')) && restCalls.some((call) => call.url.endsWith('/preview_start')));
	ok('nonce وردپرس در هدر REST ارسال می‌شود', restCalls.every((call) => call.nonce === 'rest-nonce'));
	ok('نتیجهٔ واقعی روی کارت می‌نشیند، نه خطای ۴۰۴', card.dataset.state === 'review' || card.dataset.state === 'ok');
	ok('به کاربر گفته می‌شود مسیر درخواست به REST تغییر کرده است', window.document.getElementById('tcas-preview-status').textContent.includes('REST'));
	const before = seen.length;
	card.querySelector('.tcas-local-code').value = 'TS9';
	card.querySelector('.tcas-local-code').dispatchEvent(new window.Event('input'));
	await waitFor(() => seen.length > before, 8000);
	ok('پس از موفقیت REST، درخواست‌های بعدی مستقیم از همان مسیر می‌روند', seen[seen.length - 1].url.includes('/wp-json/tcas/v1/do/') && seen.slice(before).every((call) => call.url.includes('/wp-json/tcas/v1/do/')));
	dom.window.close();
}

// ---------- ۷ک) اگر REST هم بسته باشد، خطای روشن نمایش داده می‌شود ----------
{
	const dom = makeDom(); const { window } = dom; let calls = 0;
	window.fetch = async () => { calls++; return htmlReply('<html>404 Not Found — LiteSpeed</html>', 404); };
	window.eval(script);
	const card = window.document.querySelector('.tcas-pair-entry');
	type(card, '.tcas-local-code', 'TS60');
	type(card, '.tcas-remote-code', 'AR60');
	await waitFor(() => card.dataset.state === 'error', 10000);
	const message = card.querySelector('.tcas-pair-message').textContent;
	ok('هر دو مسیر تلاش می‌شوند', calls >= 4);
	ok('خطای نهایی کد ۴۰۴ و نام دامنه را نشان می‌دهد', message.includes('404') && message.includes('tisa.test'));
	ok('پنل جزئیات، LiteSpeed را به‌عنوان فایروال/CDN نشان می‌دهد', window.document.getElementById('tcas-request-diag').textContent.includes('LiteSpeed'));
	dom.window.close();
}

// ---------- ۷ل) جست‌وجوی کارت تطبیق: نمایش فوری، نتیجهٔ خالی و خطا ----------
{
	const dom = makeDom(); const { window } = dom; let release = null; let answer = 'empty'; let block = 1;
	window.fetch = async (url, options) => {
		if (options.body.get('action') !== 'tcas_search_products') return reply({});
		if (block > 0) { block--; await new Promise((resolve) => { release = resolve; }); }
		if ('error' === answer) return reply({ message: 'جست‌وجو مسدود شد' }, false, 500);
		if ('rows' === answer) return reply({ rows: [{ reference: 'TS0100', product_id: 11, type: 'simple', sku: 'TS0100', name: 'قاب رویایی پروانه آبی', label: '', stock: { quantity: 2, status: 'instock' } }] });
		return reply({ rows: [] });
	};
	window.eval(script);
	const smart = window.document.getElementById('tcas-smart-local');
	smart.value = 'قاب رویایی پروانه آبی';
	smart.dispatchEvent(new window.Event('input'));
	const box = smart.parentElement.querySelector('.tcas-search-results');
	await waitFor(() => release !== null, 8000);
	ok('به‌محض تایپ، «در حال جست‌وجو» همان‌جا دیده می‌شود', !box.hidden && box.textContent.includes('در حال جست‌وجو'));
	release(); release = null;
	await waitFor(() => box.textContent.includes('پیدا نشد'), 8000);
	ok('نتیجهٔ خالی هم پیام دارد و فهرست باز می‌ماند', !box.hidden && box.textContent.includes('پیدا نشد'));
	answer = 'rows';
	smart.value = 'قاب';
	smart.dispatchEvent(new window.Event('input'));
	await waitFor(() => box.querySelectorAll('.tcas-search-row').length === 1, 8000);
	ok('با رسیدن نتیجه، ردیف‌های قابل انتخاب نمایش داده می‌شوند', !box.hidden && box.querySelector('.tcas-search-row').dataset.reference === 'TS0100');
	answer = 'error';
	smart.value = 'خطا';
	smart.dispatchEvent(new window.Event('input'));
	await waitFor(() => box.textContent.includes('مسدود شد'), 8000);
	ok('خطای سرور در همان فهرست نوشته می‌شود', !box.hidden && box.textContent.includes('مسدود شد'));
	ok('هنگام باز بودن فهرست، والدها برای دیده‌شدن آزاد می‌شوند', smart.parentElement.classList.contains('is-open'));
	dom.window.close();
}

// ---------- ۷م) جست‌وجوی عبارت چندواژه‌ای همان عبارت را می‌فرستد ----------
{
	const dom = makeDom(); const { window } = dom; const terms = [];
	window.fetch = async (url, options) => {
		if (options.body.get('action') === 'tcas_search_products') { terms.push(options.body.get('term')); return reply({ rows: [] }); }
		return reply({});
	};
	window.eval(script);
	const smart = window.document.getElementById('tcas-smart-local');
	smart.value = 'قاب رویایی پروانه آبی';
	smart.dispatchEvent(new window.Event('input'));
	await waitFor(() => terms.length > 0, 8000);
	ok('عبارت کاربر کامل و بدون تغییر به سرور می‌رود (شکستن واژه‌ها سمت سرور است)', terms[0] === 'قاب رویایی پروانه آبی');
	ok('جست‌وجو روی محصولات همان سایت انجام می‌شود', window.document.querySelectorAll('.tcas-search-row').length === 0);
	dom.window.close();
}

// ---------- ۷ن) ذخیرهٔ وضعیت کارت و ادامهٔ کار بعد از قطعی ----------
{
	// اجرای اول: کارت اول متصل می‌شود، اتصال کارت دوم قطع می‌شود.
	const dom = makeDom(2); const { window } = dom; let phase = 1;
	window.fetch = async (url, options) => {
		const action = options.body.get('action');
		if (action === 'tcas_preview_start') return reply({ token: 'h'.repeat(32), total: 2 });
		if (action === 'tcas_preview_step') {
			const from = Number(options.body.get('from'));
			if (2 === phase && from >= 1) throw new TypeError('Failed to fetch');
			const rows = [];
			for (let i = from; i < 2; i++) rows.push(pairRow(Object.assign(codes('TS' + i, 'AR' + i), { index: i })));
			return reply({ done: true, processed: 2, total: 2, rows });
		}
		if (action === 'tcas_preview_pair') return reply({ row: pairRow(codes(options.body.get('local_code'), options.body.get('remote_code'))) });
		if (action === 'tcas_connect_start') return reply({ token: 'i'.repeat(32), total: 2 });
		if (action === 'tcas_connect_step') {
			const from = Number(options.body.get('from'));
			if (1 === phase) {
				// فقط کارت اول متصل می‌شود و بعد ارتباط قطع می‌شود.
				if (from >= 1) throw new TypeError('Failed to fetch');
				return reply({ done: false, processed: 1, total: 2, rows: [{ index: 0, status: 'done', message: 'متصل شد', edit_url: '/edit/1' }] });
			}
			return reply({ done: true, processed: 2, total: 2, rows: [{ index: 1, status: 'done', message: 'متصل شد', edit_url: '/edit/2' }] });
		}
		return reply({});
	};
	window.eval(script);
	const cards = window.document.querySelectorAll('.tcas-pair-entry');
	type(cards[0], '.tcas-local-code', 'TS0');
	type(cards[0], '.tcas-remote-code', 'AR0');
	type(cards[1], '.tcas-local-code', 'TS1');
	type(cards[1], '.tcas-remote-code', 'AR1');
	await waitFor(() => !cards[0].querySelector('.tcas-row-select').disabled && !cards[1].querySelector('.tcas-row-select').disabled, 8000);
	window.document.getElementById('tcas-connect-start').click();
	await waitFor(() => cards[0].dataset.state === 'done', 12000);
	ok('کارت متصل‌شده وضعیت «قبلاً متصل» و موجودی ذخیره‌شده را نگه می‌دارد', cards[0].classList.contains('is-saved') && cards[0].querySelector('.tcas-row-select').disabled);
	const saved = window.localStorage.getItem('tcas:cards:ariocase');
	ok('وضعیت هر کارت در حافظهٔ مرورگر ذخیره می‌شود', Boolean(saved) && Object.keys(JSON.parse(saved)).length >= 1 && saved.includes('ts0|ar0'));
	dom.window.close();

	// اجرای دوم: همان صفحه با همان حافظه — فقط کارت به‌روزنشده فرستاده می‌شود.
	const dom2 = makeDom(2); const { window: w2 } = dom2;
	const sent = [];
	w2.localStorage.setItem('tcas:cards:ariocase', saved);
	w2.fetch = async (url, options) => {
		const action = options.body.get('action');
		if (action === 'tcas_preview_start') { sent.push(options.body.get('pairs')); return reply({ token: 'j'.repeat(32), total: 1 }); }
		if (action === 'tcas_preview_step') return reply({ done: true, processed: 1, total: 1, rows: [pairRow(Object.assign(codes('TS1', 'AR1'), { index: 0 }))] });
		if (action === 'tcas_preview_pair') return reply({ row: pairRow(codes(options.body.get('local_code'), options.body.get('remote_code'))) });
		if (action === 'tcas_connect_start') return reply({ token: 'k'.repeat(32), total: 1 });
		if (action === 'tcas_connect_step') return reply({ done: true, processed: 1, total: 1, rows: [{ index: 0, status: 'done', message: 'متصل شد', edit_url: '/edit/2' }] });
		return reply({});
	};
	w2.eval(script);
	const c2 = w2.document.querySelectorAll('.tcas-pair-entry');
	type(c2[0], '.tcas-local-code', 'TS0');
	type(c2[0], '.tcas-remote-code', 'AR0');
	type(c2[1], '.tcas-local-code', 'TS1');
	type(c2[1], '.tcas-remote-code', 'AR1');
	await waitFor(() => !c2[1].querySelector('.tcas-row-select').disabled, 8000);
	w2.document.getElementById('tcas-connect-start').click();
	await waitFor(() => sent.length > 0, 12000);
	ok('بعد از بارگذاری تازه، کارت ذخیره‌شده بدون درخواست سبز و قفل است', c2[0].classList.contains('is-saved') && c2[0].querySelector('.tcas-row-select').disabled);
	ok('فقط کارت به‌روزنشده برای اتصال فرستاده می‌شود', sent[0] === 'TS1 - AR1', String(sent[0]));
	await waitFor(() => c2[1].dataset.state === 'done', 12000);
	ok('کارت باقی‌مانده متصل و ذخیره می‌شود', c2[1].classList.contains('is-saved'));
	ok('وضعیت هر دو کارت در حافظه مانده است', Object.keys(JSON.parse(w2.localStorage.getItem('tcas:cards:ariocase'))).length === 2);
	dom2.window.close();
}

// ---------- ۷س) کارت متصل‌شده دوباره فرستاده نمی‌شود ----------
{
	const dom = makeDom(1); const { window } = dom; let previews = 0;
	window.fetch = async (url, options) => {
		const action = options.body.get('action');
		if (action === 'tcas_preview_start') { previews++; return reply({ token: 'l'.repeat(32), total: 1 }); }
		if (action === 'tcas_preview_step') return reply({ done: true, processed: 1, total: 1, rows: [pairRow(Object.assign(codes('TS5', 'AR5'), { index: 0 }))] });
		if (action === 'tcas_preview_pair') return reply({ row: pairRow(codes(options.body.get('local_code'), options.body.get('remote_code'))) });
		if (action === 'tcas_connect_start') return reply({ token: 'm'.repeat(32), total: 1 });
		if (action === 'tcas_connect_step') return reply({ done: true, processed: 1, total: 1, rows: [{ index: 0, status: 'done', message: 'متصل شد' }] });
		return reply({});
	};
	window.eval(script);
	const card = window.document.querySelector('.tcas-pair-entry');
	type(card, '.tcas-local-code', 'TS5');
	type(card, '.tcas-remote-code', 'AR5');
	await waitFor(() => !card.querySelector('.tcas-row-select').disabled, 8000);
	window.document.getElementById('tcas-connect-start').click();
	await waitFor(() => card.dataset.state === 'done', 12000);
	previews = 0;
	window.document.getElementById('tcas-connect-start').click();
	await new Promise((resolve) => setTimeout(resolve, 250));
	ok('زدن دوبارهٔ اتصال روی کارت متصل‌شده درخواستی نمی‌فرستد', previews === 0);
	ok('وقتی همهٔ کارت‌ها متصل‌اند دکمهٔ اتصال غیرفعال است تا دوباره فرستاده نشوند', window.document.getElementById('tcas-connect-start').disabled && card.querySelector('.tcas-row-select').disabled);
	dom.window.close();
}

// ---------- ۷ع) ویرایش دستی، وضعیت ذخیره‌شده را باطل می‌کند ----------
{
	const dom = makeDom(1); const { window } = dom;
	window.fetch = async (url, options) => {
		const action = options.body.get('action');
		if (action === 'tcas_preview_pair') return reply({ row: pairRow(codes(options.body.get('local_code'), options.body.get('remote_code'))) });
		return reply({});
	};
	window.eval(script);
	const card = window.document.querySelector('.tcas-pair-entry');
	card.querySelector('.tcas-local-code').value = 'TSX';
	card.querySelector('.tcas-remote-code').value = 'ARX';
	card.querySelector('.tcas-local-code').dispatchEvent(new window.Event('input'));
	await waitFor(() => card.dataset.state === 'review' || card.dataset.state === 'ok', 8000);
	ok('بررسی ویرایش، نتیجه را ذخیره می‌کند', Object.keys(JSON.parse(window.localStorage.getItem('tcas:cards:ariocase') || '{}')).length === 1);
	card.querySelector('.tcas-local-code').value = 'TSY';
	card.querySelector('.tcas-local-code').dispatchEvent(new window.Event('input'));
	ok('ویرایش دستی، رکورد ذخیره‌شدهٔ قبلی را پاک می‌کند', Object.keys(JSON.parse(window.localStorage.getItem('tcas:cards:ariocase') || '{}')).length === 0);
	dom.window.close();
}

// ---------- ۷ف) بدون سقف: همهٔ Variationها (حتی ۲۵۰ مورد) کارت می‌گیرند ----------
{
	const dom = makeDom(0); const { window } = dom;
	const matches = [];
	for (let i = 0; i < 250; i++) {
		matches.push({ local_code: 'ID:' + (1000 + i), remote_code: 'ID:9/' + (2000 + i), local_label: 'مدل ' + i, remote_label: 'مدل ' + i, confidence: 'exact', matched_by: 'sku', score: 100 });
	}
	window.fetch = async (url, options) => {
		if (options.body.get('action') === 'tcas_match_variations') return reply({ matches, ambiguous: [], unmatched: [], local: { name: 'L', reference: 'ID:0', count: 250 }, remote: { name: 'R', reference: 'ID:9', count: 250 } });
		return reply({});
	};
	window.eval(script);
	window.document.getElementById('tcas-smart-local').value = 'ID:0';
	window.document.getElementById('tcas-smart-remote').value = 'ID:9';
	window.document.getElementById('tcas-smart-match').click();
	await waitFor(() => window.document.querySelectorAll('.tcas-pair-entry').length === 250, 20000);
	ok('همهٔ ۲۵۰ جفت ساخته می‌شوند و هیچ‌کدام به‌خاطر سقف حذف نمی‌شود', window.document.querySelectorAll('.tcas-pair-entry').length === 250);
	ok('دکمهٔ «دسته بعد» لازم نیست و مخفی می‌ماند', window.document.getElementById('tcas-smart-next').hidden);
	ok('پیام وضعیت، تعداد واقعی را گزارش می‌کند', window.document.getElementById('tcas-smart-status').textContent.includes('250'));
	ok('شمارندهٔ کارت‌ها تعداد را نشان می‌دهد', !window.document.getElementById('tcas-pair-count') || window.document.getElementById('tcas-pair-count').textContent.includes('کارت'));
	ok('افزودن کارت دستی هم محدود نمی‌شود', !window.document.getElementById('tcas-pair-add').disabled);
	dom.window.close();
}

// ---------- ۷ص) دسته‌بندی گام‌ها با تعداد زیاد بزرگ‌تر می‌شود ----------
{
	const dom = makeDom(0); const { window } = dom; const sizes = [];
	window.fetch = async (url, options) => {
		const body = options.body; const action = body.get('action');
		if (action === 'tcas_match_variations') {
			const matches = [];
			for (let i = 0; i < 250; i++) matches.push({ local_code: 'ID:' + (3000 + i), remote_code: 'ID:8/' + (4000 + i), confidence: 'exact', matched_by: 'sku', score: 100 });
			return reply({ matches, ambiguous: [], unmatched: [], local: { name: 'L', reference: 'ID:0', count: 250 }, remote: { name: 'R', reference: 'ID:8', count: 250 } });
		}
		if (action === 'tcas_preview_start') return reply({ token: 'n'.repeat(32), total: 250 });
		if (action === 'tcas_preview_step') {
			sizes.push(Number(body.get('size')));
			const from = Number(body.get('from'));
			return reply({ done: from + Number(body.get('size')) >= 250, processed: Math.min(250, from + Number(body.get('size'))), total: 250, rows: [] });
		}
		return reply({});
	};
	window.eval(script);
	window.document.getElementById('tcas-smart-local').value = 'ID:0';
	window.document.getElementById('tcas-smart-remote').value = 'ID:8';
	window.document.getElementById('tcas-smart-match').click();
	await waitFor(() => window.document.querySelectorAll('.tcas-pair-entry').length === 250, 20000);
	window.document.getElementById('tcas-connect-start').click();
	await waitFor(() => sizes.length > 0, 8000);
	ok('برای کار بزرگ، هر درخواست دستهٔ بزرگ‌تری می‌فرستد (کاهش تعداد درخواست)', sizes[0] >= 12);
	ok('تعداد درخواست‌های لازم برای ۲۵۰ جفت کم می‌ماند', sizes.length <= 30, String(sizes.length));
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
	ok('تبدیل لینک، URL را برای Resolver ارسال می‌کند', calls[0].get('action') === 'tcas_resolve_link' && decodeValue(calls[0].get('url')) === 'https://tisacase.com/product/local-case/');
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
const settingsView = fs.readFileSync(path.join(root, 'views/settings.php'), 'utf8');
const sweepView = fs.readFileSync(path.join(root, 'views/partials/sweep-controls.php'), 'utf8');
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
ok('کارت همگام‌سازی خودکار در تنظیمات با چرخه، نوع نوشتن و سیاست قطعی همکار هست', settingsView.includes('sweep-controls.php') && sweepView.includes('name="tcas_settings[sweep_interval]"') && sweepView.includes('name="tcas_settings[stock_mode]"') && sweepView.includes('name="tcas_settings[fail_mode]"') && sweepView.includes('name="tcas_settings[sweep_batch]"') && sweepView.includes('id="tcas-sweep-now"'));
ok('وضعیت جارو، صف و Backoff در کارت تنظیمات نمایش داده می‌شود', sweepView.includes('id="tcas-sweep-status"') && sweepView.includes('TCAS_Sweeper::summary_text()') && sweepView.includes('در صف است') && sweepView.includes('محدودیت اعمال کرده'));
ok('دکمهٔ همگام‌سازی الان به همان عملیات AJAX وصل است', script.includes("simpleAction(sweepNow, 'sweep_now'") && script.includes("$('tcas-sweep-now')") && uiCss.includes('.tcas-sweep-status') && uiCss.includes('.tcas-sweep-actions') && uiCss.includes('.tcas-sweep-result'));
ok('نشان «فهرست مدل» و پرچم مرجع مشترک در اسکریپت و CSS واقعی تعریف شده‌اند', script.includes("list: { text: 'پیشنهاد · فهرست مدل'") && script.includes('entry.dataset.sharedRemote') && script.includes('shared_remote: entry.dataset.sharedRemote') && uiCss.includes('.tcas-pair-flag.is-list'));
console.log(`\n${pass} passed, ${fail} failed`); if (fail) process.exit(1);
