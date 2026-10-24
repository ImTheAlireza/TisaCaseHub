(function () {
	'use strict';

	const cfg = window.TCAS_ADMIN || {};
	const $ = (id) => document.getElementById(id);
	const state = { token: '', previewDone: false, running: false, linkProduct: null, linkVariations: [] };

	function text(id, value, isError) {
		const node = $(id);
		if (!node) return;
		node.textContent = value || '';
		node.classList.toggle('is-error', Boolean(isError));
		node.classList.toggle('is-success', !isError && Boolean(value));
	}

	function busy(button, active, label) {
		if (!button) return;
		if (active) {
			button.dataset.oldText = button.textContent;
			button.disabled = true;
			button.textContent = label || (cfg.strings && cfg.strings.working) || '...';
		} else {
			button.disabled = false;
			if (button.dataset.oldText) button.textContent = button.dataset.oldText;
		}
	}

	async function request(action, data) {
		const body = new FormData();
		body.append('action', 'tcas_' + action);
		body.append('nonce', cfg.nonce || '');
		Object.entries(data || {}).forEach(([key, value]) => {
			if (Array.isArray(value)) value.forEach((item) => body.append(key + '[]', item));
			else body.append(key, value === null || typeof value === 'undefined' ? '' : String(value));
		});
		const response = await fetch(cfg.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' });
		let payload;
		try { payload = await response.json(); } catch (error) { throw new Error('پاسخ JSON معتبر از سرور سایت دریافت نشد (HTTP ' + response.status + ').'); }
		if (!payload || !payload.success) {
			throw new Error(payload && payload.data && payload.data.message ? payload.data.message : (cfg.strings && cfg.strings.unknownError) || 'خطا');
		}
		return payload.data || {};
	}

	function node(tag, className, value) {
		const item = document.createElement(tag);
		if (className) item.className = className;
		if (typeof value !== 'undefined') item.textContent = value;
		return item;
	}

	function progress(kind, processed, total) {
		const wrap = $(kind + '-progress');
		if (!wrap) return;
		wrap.hidden = false;
		const percent = total ? Math.round((processed / total) * 100) : 0;
		const bar = wrap.querySelector('progress');
		if (bar) bar.value = percent;
		const label = wrap.querySelector('span');
		if (label) label.textContent = percent.toLocaleString('fa-IR') + '٪';
	}

	function stockLabel(stock) {
		if (!stock || (stock.quantity === null && !stock.status)) return '—';
		const quantity = stock.quantity === null ? '' : String(stock.quantity);
		const statuses = { instock: 'موجود', outofstock: 'ناموجود', onbackorder: 'پیش‌سفارش' };
		return (quantity !== '' ? quantity + ' · ' : '') + (statuses[stock.status] || 'وضعیت نامشخص');
	}

	function readonlyShopField(labelText, code, shopClass) {
		const label = node('label', 'tcas-shop-field ' + shopClass);
		label.appendChild(node('span', 'tcas-shop-label', labelText));
		const input = document.createElement('input');
		input.type = 'text';
		input.dir = 'ltr';
		input.readOnly = true;
		input.value = code || '—';
		input.setAttribute('aria-label', labelText);
		label.appendChild(input);
		return label;
	}

	function pairFields(localCode, remoteCode) {
		const fields = node('div', 'tcas-pair-fields');
		fields.appendChild(readonlyShopField('تیساکیس · TisaCase', localCode, 'tcas-shop-local'));
		fields.appendChild(node('span', 'tcas-pair-link', '+'));
		fields.appendChild(readonlyShopField('آریوکیس · ArioCase', remoteCode, 'tcas-shop-remote'));
		return fields;
	}

	function addPreviewRow(row) {
		const list = $('tcas-preview-list');
		if (!list) return;
		const card = node('article', 'tcas-pair-card tcas-preview-card tcas-result-' + row.status);
		const head = node('div', 'tcas-preview-head');
		const selectLabel = node('label', 'tcas-pair-select');
		const checkbox = document.createElement('input');
		checkbox.type = 'checkbox';
		checkbox.className = 'tcas-row-select';
		checkbox.value = String(row.index);
		checkbox.disabled = !row.selectable;
		checkbox.checked = Boolean(row.selectable);
		selectLabel.appendChild(checkbox);
		selectLabel.appendChild(node('span', '', row.selectable ? 'انتخاب' : 'غیرقابل اتصال'));
		head.appendChild(selectLabel);
		head.appendChild(node('span', 'tcas-stock-pill', stockLabel(row.stock)));
		card.appendChild(head);
		card.appendChild(pairFields(row.local_code, row.remote_code));
		const names = [row.local_name, row.remote_name].filter(Boolean).join(' / ');
		if (names) card.appendChild(node('div', 'tcas-preview-names', names));
		const message = node('p', 'tcas-preview-message', row.message || '');
		if (row.status === 'error') message.classList.add('tcas-error-text');
		card.appendChild(message);
		list.appendChild(card);
	}

	function pairEntries() {
		return Array.from(document.querySelectorAll('#tcas-pair-entries .tcas-pair-entry'));
	}

	function refreshPairEntries() {
		const entries = pairEntries();
		const max = Number(cfg.maxPairs) || 50;
		entries.forEach((entry) => {
			const remove = entry.querySelector('.tcas-remove-pair');
			if (remove) remove.disabled = entries.length === 1;
		});
		const add = $('tcas-pair-add');
		if (add) add.disabled = entries.length >= max;
	}

	function invalidatePreview() {
		if (!state.token || state.running) return;
		state.token = '';
		state.previewDone = false;
		const panel = $('tcas-preview-panel');
		if (panel) panel.hidden = true;
		text('tcas-preview-status', 'تغییرات را دوباره بررسی کنید.');
	}

	function wirePairEntry(entry) {
		const remove = entry.querySelector('.tcas-remove-pair');
		if (remove) remove.addEventListener('click', () => {
			if (pairEntries().length <= 1) return;
			entry.remove();
			refreshPairEntries();
			invalidatePreview();
		});
		entry.querySelectorAll('input').forEach((input) => input.addEventListener('input', invalidatePreview));
	}

	function addPairEntry() {
		const template = $('tcas-pair-entry-template');
		const list = $('tcas-pair-entries');
		const max = Number(cfg.maxPairs) || 50;
		if (!template || !list || pairEntries().length >= max) return;
		const entry = template.content.firstElementChild.cloneNode(true);
		list.appendChild(entry);
		wirePairEntry(entry);
		refreshPairEntries();
		invalidatePreview();
		const input = entry.querySelector('.tcas-local-code');
		if (input) input.focus();
	}

	function collectPairs() {
		const lines = [];
		for (const entry of pairEntries()) {
			const localInput = entry.querySelector('.tcas-local-code');
			const remoteInput = entry.querySelector('.tcas-remote-code');
			const local = localInput ? localInput.value.trim() : '';
			const remote = remoteInput ? remoteInput.value.trim() : '';
			if (!local && !remote) continue;
			if (!local || !remote) {
				const missing = local ? remoteInput : localInput;
				if (missing) missing.focus();
				throw new Error('برای هر جفت، شناسهٔ هر دو فروشگاه را وارد کنید.');
			}
			if (/^(?:[a-z]+:\/\/|www\.)/i.test(local) || /^(?:[a-z]+:\/\/|www\.)/i.test(remote)) {
				throw new Error('لینک را ابتدا در «تبدیل لینک به شناسه» به شناسه تبدیل کنید.');
			}
			lines.push(local + ' - ' + remote);
		}
		if (!lines.length) throw new Error('حداقل یک جفت شناسه وارد کنید.');
		return lines;
	}

	async function previewLoop(button) {
		let done = false;
		while (!done) {
			const data = await request('preview_step', { token: state.token });
			if (data.row) addPreviewRow(data.row);
			progress('tcas-preview', data.processed || 0, data.total || 0);
			text('tcas-preview-status', String(data.processed || 0) + ' از ' + String(data.total || 0));
			done = Boolean(data.done);
		}
		state.previewDone = true;
		text('tcas-preview-status', 'بررسی کامل شد؛ جفت‌ها را تأیید کنید.');
		const connectButton = $('tcas-connect-start');
		if (connectButton) connectButton.disabled = false;
		busy(button, false);
		state.running = false;
	}

	async function startPreview() {
		if (state.running) return;
		let lines;
		try { lines = collectPairs(); } catch (error) { text('tcas-preview-status', error.message, true); return; }
		const button = $('tcas-preview-start');
		state.running = true;
		state.previewDone = false;
		state.token = '';
		busy(button, true);
		text('tcas-preview-status', 'در حال ساخت عملیات…');
		const panel = $('tcas-preview-panel');
		if (panel) panel.hidden = true;
		const list = $('tcas-preview-list');
		if (list) list.textContent = '';
		const results = $('tcas-connect-results');
		if (results) results.textContent = '';
		try {
			const data = await request('preview_start', { pairs: lines.join('\n') });
			state.token = data.token;
			if (panel) panel.hidden = false;
			progress('tcas-preview', 0, data.total || 0);
			await previewLoop(button);
		} catch (error) {
			state.running = false;
			busy(button, false);
			text('tcas-preview-status', error.message, true);
		}
	}

	function connectResult(row) {
		const wrap = $('tcas-connect-results');
		if (!wrap) return;
		const card = node('div', 'tcas-result-card ' + (row.status === 'done' ? 'is-success' : 'is-error'));
		const label = row.local_code + ' - ' + row.remote_code;
		card.appendChild(node('strong', '', label + ' — ' + row.message));
		if (row.edit_url) {
			const link = node('a', '', 'بازکردن محصول تیساکیس');
			link.href = row.edit_url;
			card.appendChild(link);
		}
		wrap.appendChild(card);
	}

	async function connectLoop(button) {
		let done = false;
		while (!done) {
			const data = await request('connect_step', { token: state.token });
			if (data.row) connectResult(data.row);
			progress('tcas-connect', data.processed || 0, data.total || 0);
			done = Boolean(data.done);
		}
		text('tcas-connect-status', 'عملیات جفت‌های انتخابی تمام شد.');
		busy(button, false);
		state.running = false;
	}

	async function startConnect() {
		if (state.running || !state.token || !state.previewDone) return;
		const pairs = Array.from(document.querySelectorAll('.tcas-row-select:checked')).map((input) => input.value);
		if (!pairs.length) { text('tcas-connect-status', (cfg.strings && cfg.strings.selectRows) || 'جفتی انتخاب نشده.', true); return; }
		const button = $('tcas-connect-start');
		state.running = true;
		busy(button, true);
		text('tcas-connect-status', 'در حال آماده‌سازی…');
		const results = $('tcas-connect-results');
		if (results) results.textContent = '';
		try {
			const data = await request('connect_start', { token: state.token, pairs });
			progress('tcas-connect', 0, data.total || 0);
			await connectLoop(button);
		} catch (error) {
			state.running = false;
			busy(button, false);
			text('tcas-connect-status', error.message, true);
		}
	}

	function setLinkOutput(reference, shopLabel, productName) {
		const sku = $('tcas-link-sku');
		if (sku) sku.value = reference || '';
		const copy = $('tcas-link-copy');
		if (copy) copy.disabled = !reference;
		const shop = $('tcas-link-shop');
		if (shop) shop.textContent = shopLabel || 'SKU / ID';
		const product = $('tcas-link-product');
		if (product) product.textContent = productName || '';
	}

	function resetLinkResult() {
		state.linkProduct = null;
		state.linkVariations = [];
		setLinkOutput('', 'SKU / ID', '');
		const result = $('tcas-link-result');
		if (result) delete result.dataset.shop;
		const select = $('tcas-link-variation');
		if (select) select.textContent = '';
		const wrap = $('tcas-link-variation-wrap');
		if (wrap) wrap.hidden = true;
	}

	function chooseLinkVariation() {
		const select = $('tcas-link-variation');
		const data = state.linkProduct;
		if (!select || !data) return;
		const variation = '' !== select.value ? state.linkVariations[Number(select.value)] : null;
		if (!variation || !variation.reference) {
			setLinkOutput('', (data.shop_label || 'محصول') + ' · Variation', data.name || '');
			text('tcas-link-status', 'یک Variation با شناسهٔ معتبر انتخاب کنید.', true);
			return;
		}
		const reference = String(variation.reference);
		const label = variation.label ? data.name + ' — ' + variation.label : data.name;
		setLinkOutput(reference, (data.shop_label || 'محصول') + (/^ID:/i.test(reference) ? ' · ID' : ' · SKU'), label || '');
		text('tcas-link-status', 'شناسهٔ Variation آمادهٔ کپی است.');
	}

	async function resolveLink() {
		const input = $('tcas-link-input');
		const button = $('tcas-link-resolve');
		const url = input ? input.value.trim() : '';
		if (!url || !/^https?:\/\//i.test(url)) { text('tcas-link-status', 'لینک کامل محصول را وارد کنید.', true); return; }
		resetLinkResult();
		busy(button, true, 'در حال تشخیص…');
		text('tcas-link-status', 'در حال تشخیص فروشگاه و دریافت شناسه…');
		try {
			const data = await request('resolve_link', { url });
			state.linkProduct = data;
			state.linkVariations = Array.isArray(data.variations) ? data.variations : [];
			const result = $('tcas-link-result');
			if (result && data.shop) result.dataset.shop = data.shop;
			if (data.type === 'variable') {
				const wrap = $('tcas-link-variation-wrap');
				const select = $('tcas-link-variation');
				if (wrap) wrap.hidden = false;
				if (select) {
					select.textContent = '';
					const placeholder = node('option', '', 'انتخاب Variation…');
					placeholder.value = '';
					select.appendChild(placeholder);
					state.linkVariations.forEach((variation, index) => {
						const identity = variation.sku || variation.reference || 'بدون شناسه';
						const details = [variation.label || 'Variation', variation.product_id ? '#' + String(variation.product_id) : '', identity].filter(Boolean);
						const option = node('option', '', details.join(' · '));
						option.value = String(index);
						option.disabled = !variation.reference;
						select.appendChild(option);
					});
				}
				setLinkOutput('', (data.shop_label || 'محصول') + ' · Variation', data.name || '');
				const usable = state.linkVariations.filter((variation) => Boolean(variation.reference));
				if (usable.length === 1 && select) {
					select.value = String(state.linkVariations.indexOf(usable[0]));
					chooseLinkVariation();
				} else {
					text('tcas-link-status', usable.length ? 'یک Variation را انتخاب کنید.' : 'Variation با شناسهٔ معتبر پیدا نشد.', !usable.length);
				}
			} else {
				const reference = data.reference || data.sku || (data.product_id ? 'ID:' + String(data.product_id) : '');
				setLinkOutput(reference, (data.shop_label || 'محصول') + (/^ID:/i.test(reference) ? ' · ID' : ' · SKU'), data.name || '');
				text('tcas-link-status', reference ? 'شناسه آمادهٔ کپی است.' : 'شناسه پیدا نشد.', !reference);
			}
		} catch (error) { text('tcas-link-status', error.message, true); }
		busy(button, false);
	}

	async function copyLinkSku() {
		const input = $('tcas-link-sku');
		const value = input ? input.value : '';
		if (!value) return;
		let copied = false;
		if (navigator.clipboard && navigator.clipboard.writeText) {
			try { await navigator.clipboard.writeText(value); copied = true; } catch (error) { copied = false; }
		}
		if (!copied) {
			input.focus();
			input.select();
			if (document.execCommand) {
				try { copied = document.execCommand('copy'); } catch (error) { copied = false; }
			}
		}
		text('tcas-link-status', copied ? 'شناسه کپی شد.' : 'شناسه انتخاب شد؛ آن را با کلید کپی بردارید.');
	}

	async function testConnection() {
		const button = $('tcas-test-connection');
		const code = $('tcas-test-code') ? $('tcas-test-code').value.trim() : '';
		const out = $('tcas-test-result');
		if (!code) { if (out) out.textContent = 'لینک یا شناسه را وارد کنید.'; return; }
		busy(button, true);
		if (out) out.textContent = 'در حال اتصال و بررسی ساختار پاسخ…';
		try {
			const data = await request('test_connection', { code });
			if (out) {
				out.textContent = '';
				out.appendChild(node('strong', 'tcas-success-text', data.message || 'اتصال موفق.'));
				if (data.product) out.appendChild(node('pre', 'tcas-json-preview', JSON.stringify(data.product, null, 2)));
			}
		} catch (error) { if (out) out.textContent = error.message; }
		busy(button, false);
	}

	async function simpleAction(button, action, data, statusId) {
		busy(button, true);
		text(statusId, (cfg.strings && cfg.strings.working) || '...');
		try {
			const result = await request(action, data);
			text(statusId, result.message || 'انجام شد.');
			if (result.reload) window.setTimeout(() => window.location.reload(), 450);
		} catch (error) { text(statusId, error.message, true); }
		busy(button, false);
	}

	pairEntries().forEach(wirePairEntry);
	refreshPairEntries();
	const pairAdd = $('tcas-pair-add');
	if (pairAdd) pairAdd.addEventListener('click', addPairEntry);
	const previewButton = $('tcas-preview-start');
	if (previewButton) previewButton.addEventListener('click', startPreview);
	const connectButton = $('tcas-connect-start');
	if (connectButton) connectButton.addEventListener('click', startConnect);
	const selectValid = $('tcas-select-valid');
	if (selectValid) selectValid.addEventListener('click', () => {
		const boxes = Array.from(document.querySelectorAll('.tcas-row-select:not(:disabled)'));
		const allChecked = boxes.length && boxes.every((box) => box.checked);
		boxes.forEach((box) => { box.checked = !allChecked; });
	});
	const resolveButton = $('tcas-link-resolve');
	if (resolveButton) resolveButton.addEventListener('click', resolveLink);
	const linkInput = $('tcas-link-input');
	if (linkInput) {
		linkInput.addEventListener('input', () => { resetLinkResult(); text('tcas-link-status', ''); });
		linkInput.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); resolveLink(); } });
	}
	const copyButton = $('tcas-link-copy');
	if (copyButton) copyButton.addEventListener('click', copyLinkSku);
	const variationSelect = $('tcas-link-variation');
	if (variationSelect) variationSelect.addEventListener('change', chooseLinkVariation);
	const testButton = $('tcas-test-connection');
	if (testButton) testButton.addEventListener('click', testConnection);
	const mapButton = $('tcas-map-add');
	if (mapButton) mapButton.addEventListener('click', () => simpleAction(mapButton, 'add_mapping', {
		sku: $('tcas-map-sku') ? $('tcas-map-sku').value : '',
		remote_code: $('tcas-map-code') ? $('tcas-map-code').value : ''
	}, 'tcas-map-status'));
	document.querySelectorAll('.tcas-sync-one').forEach((button) => button.addEventListener('click', () => simpleAction(button, 'sync_mapping', { product_id: button.dataset.productId }, 'tcas-sync-status')));
	document.querySelectorAll('.tcas-disconnect').forEach((button) => button.addEventListener('click', () => {
		if (!window.confirm((cfg.strings && cfg.strings.disconnect) || 'ادامه؟')) return;
		simpleAction(button, 'disconnect_mapping', { product_id: button.dataset.productId }, 'tcas-sync-status');
	}));
	const clearLogs = $('tcas-clear-logs');
	if (clearLogs) clearLogs.addEventListener('click', () => simpleAction(clearLogs, 'clear_logs', {}, 'tcas-log-status'));
}());
