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
	return '<article class="tcas-pair-entry"><button type="button" class="tcas-remove-pair">×</button><label class="tcas-shop-field tcas-shop-local"><input class="tcas-local-code"></label><label class="tcas-shop-field tcas-shop-remote"><input class="tcas-remote-code"></label></article>';
}
function makeDom() {
	const dom = new JSDOM(`<!doctype html><html><body>
		<div class="tcas-converter-flow"><label class="tcas-converter-source"><span class="tcas-converter-input-row"><input id="tcas-link-input"><button id="tcas-link-resolve">تبدیل</button></span></label><div id="tcas-link-result" class="tcas-converter-result" hidden><div class="tcas-converter-result-meta"><span id="tcas-link-shop"></span><small id="tcas-link-product"></small></div><label id="tcas-link-variation-wrap" class="tcas-link-variation" hidden><select id="tcas-link-variation"></select></label><span class="tcas-converter-reference"><input id="tcas-link-sku" readonly><button id="tcas-link-copy" disabled>کپی</button></span></div><span id="tcas-link-status"></span></div>
		<input id="tcas-smart-local"><input id="tcas-smart-remote"><button id="tcas-smart-match">تطبیق</button><button id="tcas-smart-next" hidden>دسته بعد</button><span id="tcas-smart-status"></span><div id="tcas-smart-results" hidden></div>
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
	const editorTitles = window.document.querySelectorAll('#tcas-pair-entries .tcas-pair-entry:first-child .tcas-variation-title');
	ok('بعد از Preview عنوان Variation زیر فیلدهای کارت ورودی قرار می‌گیرد', editorTitles.length === 2 && editorTitles[0].textContent === 'قاب تیساکیس' && editorTitles[1].textContent === '<img src=x>');
	const previewTitles = window.document.querySelectorAll('.tcas-preview-card:first-child .tcas-variation-title');
	ok('عنوان هر فروشگاه زیر فیلد همان فروشگاه در Preview است', previewTitles.length === 2 && previewTitles[0].textContent === 'قاب تیساکیس' && previewTitles[1].textContent === '<img src=x>');
	ok('نام ریموت با textContent امن رندر می‌شود', !window.document.querySelector('#tcas-preview-list img') && !window.document.querySelector('#tcas-pair-entries img'));
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
	const editedCode = window.document.querySelector('#tcas-pair-entries .tcas-pair-entry:first-child .tcas-local-code');
	editedCode.value = 'CHANGED'; editedCode.dispatchEvent(new window.Event('input'));
	ok('تغییر دستی کد، عنوان قدیمی فقط همان فیلد را پاک می‌کند', !window.document.querySelector('#tcas-pair-entries .tcas-pair-entry:first-child .tcas-shop-local .tcas-variation-title') && window.document.querySelector('#tcas-pair-entries .tcas-pair-entry:first-child .tcas-shop-remote .tcas-variation-title'));
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

{
	const dom = makeDom(); const { window } = dom; const calls = [];
	window.fetch = async (url, options) => {
		const body = options.body; calls.push(body);
		const action = body.get('action');
		if (action === 'tcas_match_variations') return reply({
			matches: [
				{ local_code: 'ID:51', remote_code: 'ID:70/71', local_label: 'S25 Ultra', remote_label: 's25ultra', score: 95, local_index: 0, remote_index: 0 },
				{ local_code: 'ID:52', remote_code: 'AR-BLUE', local_label: 'Blue', remote_label: 'Blue', score: 100, local_index: 1, remote_index: 1 },
			],
			ambiguous: [{ shop: 'tisacase', reference: 'ID:53', label: 'Duplicate', index: 2 }], unmatched: [{ shop: 'ariocase', reference: 'ID:70/73', label: 'S24', index: 2 }],
			local: { name: 'Local product', reference: 'ID:50', count: 3 }, remote: { name: 'Remote product', reference: 'ID:70', count: 3 },
		});
		if (action === 'tcas_preview_start') return reply({ token: 'c'.repeat(32), total: 2 });
		if (action === 'tcas_preview_step') return reply({ done: true, processed: 2, total: 2 });
		return reply({ message: 'unexpected' }, false, 500);
	};
	window.eval(script);
	window.document.getElementById('tcas-smart-local').value = 'ID:50'; window.document.getElementById('tcas-smart-remote').value = 'ID:70';
	window.document.getElementById('tcas-smart-match').click();
	await waitFor(() => window.document.querySelectorAll('#tcas-pair-entries .tcas-pair-entry').length === 2);
	const entries = window.document.querySelectorAll('#tcas-pair-entries .tcas-pair-entry');
	ok('تطبیق JS مرجع دقیق Local و Remote را در کارت‌ها می‌گذارد', entries[0].querySelector('.tcas-local-code').value === 'ID:51' && entries[0].querySelector('.tcas-remote-code').value === 'ID:70/71' && entries[1].querySelector('.tcas-remote-code').value === 'AR-BLUE');
	ok('بلافاصله بعد از تطبیق، عنوان هر Variation زیر فیلد خودش دیده می‌شود', entries[0].querySelector('.tcas-shop-local .tcas-variation-title').textContent === 'S25 Ultra' && entries[0].querySelector('.tcas-shop-remote .tcas-variation-title').textContent === 's25ultra' && entries[1].querySelector('.tcas-shop-local .tcas-variation-title').textContent === 'Blue');
	ok('تطبیق فقط یک AJAX می‌گیرد و خودکار Preview/Connect نمی‌کند', calls.length === 1 && calls[0].get('action') === 'tcas_match_variations');
	ok('تعداد موارد مبهم و بدون تطبیق در Status دیده می‌شود', window.document.getElementById('tcas-smart-status').textContent.includes('2 مورد'));
	ok('تعداد Variationهای Load شدهٔ هر دو فروشگاه دیده می‌شود', window.document.getElementById('tcas-smart-status').textContent.includes('TisaCase: 3') && window.document.getElementById('tcas-smart-status').textContent.includes('ArioCase: 3'));
	const groups = window.document.querySelectorAll('#tcas-smart-results details.tcas-smart-group');
	ok('Variationها زیر کارت آکارئونی محصول والد همان فروشگاه گروه‌بندی می‌شوند', groups.length === 2 && groups[0].querySelector('summary').textContent.includes('Local product') && groups[1].querySelector('summary').textContent.includes('Remote product'));
	ok('با بازکردن کارت محصول، کارت‌های Variation و جفت متناظر دیده می‌شوند', groups[0].querySelectorAll('.tcas-smart-variant').length === 3 && groups[0].querySelector('.tcas-smart-variant-other').textContent.includes('s25ultra') && groups[1].querySelectorAll('.tcas-smart-variant').length === 3);
	window.document.getElementById('tcas-preview-start').click();
	await waitFor(() => window.document.getElementById('tcas-preview-status').textContent.includes('کامل'));
	const previewStart = calls.find((body) => body.get('action') === 'tcas_preview_start');
	ok('خروجی تطبیق وارد همان Preview تازهٔ قبلی می‌شود', previewStart && previewStart.get('pairs') === 'ID:51 - ID:70/71\nID:52 - AR-BLUE');
	ok('Preview همچنان قبل از اتصال انتخابی است', !calls.some((body) => body.get('action') === 'tcas_connect_start'));
	dom.window.close();
}

{
	const dom = makeDom(); const { window } = dom; window.TCAS_ADMIN.maxPairs = 2;
	window.fetch = async () => reply({ matches: [
		{ local_code: 'ID:1', remote_code: 'ID:9/1' }, { local_code: 'ID:2', remote_code: 'ID:9/2' }, { local_code: 'ID:3', remote_code: 'ID:9/3' },
	], ambiguous: [], unmatched: [] });
	window.eval(script); window.document.getElementById('tcas-smart-local').value = 'ID:50'; window.document.getElementById('tcas-smart-remote').value = 'ID:70'; window.document.getElementById('tcas-smart-match').click();
	await waitFor(() => window.document.querySelectorAll('#tcas-pair-entries .tcas-pair-entry').length === 2);
	ok('تطبیق بیش از حد دسته را به اندازهٔ MaxPairs محدود می‌کند', !window.document.getElementById('tcas-smart-next').hidden && window.document.querySelector('#tcas-pair-entries .tcas-pair-entry:last-child .tcas-local-code').value === 'ID:2');
	window.document.getElementById('tcas-smart-next').click();
	await waitFor(() => window.document.querySelectorAll('#tcas-pair-entries .tcas-pair-entry').length === 1);
	ok('دسته بعد فقط جفت‌های باقی‌مانده را جایگزین می‌کند', window.document.querySelector('.tcas-local-code').value === 'ID:3' && window.document.querySelector('.tcas-remote-code').value === 'ID:9/3');
	ok('بعد از آخرین دسته دکمه مخفی می‌شود', window.document.getElementById('tcas-smart-next').hidden && window.document.getElementById('tcas-smart-status').textContent.includes('3/3'));
	dom.window.close();
}

{
	const dom = makeDom(); const { window } = dom; let requests = 0;
	window.fetch = async () => { requests++; return reply({ matches: [], ambiguous: [{ label: 'A' }, { label: 'B' }], unmatched: [{ label: 'C' }] }); };
	window.eval(script); window.document.getElementById('tcas-smart-local').value = 'ID:50'; window.document.getElementById('tcas-smart-remote').value = 'ID:70'; window.document.getElementById('tcas-smart-match').click();
	await waitFor(() => window.document.getElementById('tcas-smart-status').textContent.includes('جفت یکتایی پیدا نشد'));
	ok('نتیجهٔ مبهم هیچ کارتی برای اتصال نمی‌سازد', window.document.querySelectorAll('#tcas-pair-entries .tcas-pair-entry').length === 1 && window.document.querySelector('.tcas-local-code').value === '');
	ok('نبود تطبیق یکتا خطای قابل‌مشاهده دارد', window.document.getElementById('tcas-smart-status').classList.contains('is-error') && requests === 1);
	dom.window.close();
}

const uiCss = fs.readFileSync(path.join(root, 'assets/admin.css'), 'utf8');
const pairsView = fs.readFileSync(path.join(root, 'views/pairs.php'), 'utf8');
ok('Checkbox تنظیمات اندازهٔ ثابت دارد و تمام‌عرض نمی‌شود', uiCss.includes('.tcas-check-line input[type=checkbox]') && uiCss.includes('width:16px') && uiCss.includes('justify-self:start'));
ok('Selector و استایل جمع‌وجور Variation در View واقعی وجود دارد', pairsView.includes('id="tcas-link-variation"') && pairsView.includes('class="tcas-link-variation" hidden') && uiCss.includes('.tcas-link-variation select'));
ok('چیدمان قدیمی و فلش حذف و نتیجهٔ واقعی پیش‌فرض مخفی است', pairsView.includes('tcas-converter-flow') && pairsView.includes('id="tcas-link-result" class="tcas-converter-result" hidden') && !pairsView.includes('tcas-converter-layout') && !pairsView.includes('tcas-converter-arrow') && !uiCss.includes('.tcas-converter-arrow') && !uiCss.includes('.tcas-input-action'));
ok('کارت تطبیق و کنترل دستهٔ بعد در View و CSS واقعی وجود دارد', pairsView.includes('id="tcas-smart-local"') && pairsView.includes('id="tcas-smart-remote"') && pairsView.includes('id="tcas-smart-next" class="button" hidden') && uiCss.includes('.tcas-smart-grid'));
console.log(`\n${pass} passed, ${fail} failed`); if (fail) process.exit(1);
