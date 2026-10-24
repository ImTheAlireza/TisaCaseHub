(function () {
	'use strict';

	const cfg = window.TCAS_ADMIN || {};
	const $ = (id) => document.getElementById(id);
	const state = { token: '', previewDone: false, running: false };

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

	function productCell(code, name, id) {
		const cell = node('td', 'tcas-code-cell');
		cell.appendChild(node('code', '', code || '—'));
		if (name) cell.appendChild(node('small', '', name));
		if (id) cell.appendChild(node('small', '', '#' + id));
		return cell;
	}

	function addPreviewRow(row) {
		const tbody = $('tcas-preview-table') && $('tcas-preview-table').querySelector('tbody');
		if (!tbody) return;
		const tr = node('tr', 'tcas-result-' + row.status);
		const checkCell = node('td');
		const checkbox = document.createElement('input');
		checkbox.type = 'checkbox';
		checkbox.className = 'tcas-row-select';
		checkbox.value = String(row.index);
		checkbox.disabled = !row.selectable;
		checkbox.checked = Boolean(row.selectable);
		checkCell.appendChild(checkbox);
		tr.appendChild(checkCell);
		tr.appendChild(productCell(row.local_code, row.local_name, row.local_id));
		tr.appendChild(productCell(row.remote_code, row.remote_name, 0));
		tr.appendChild(node('td', '', stockLabel(row.stock)));
		const message = node('td', '', row.message || '');
		if (row.status === 'error') message.classList.add('tcas-error-text');
		tr.appendChild(message);
		tbody.appendChild(tr);
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
		const pairs = $('tcas-pairs') ? $('tcas-pairs').value : '';
		if (!pairs.trim()) { text('tcas-preview-status', 'حداقل یک جفت وارد کنید.', true); return; }
		const button = $('tcas-preview-start');
		state.running = true;
		state.previewDone = false;
		state.token = '';
		busy(button, true);
		text('tcas-preview-status', 'در حال ساخت عملیات…');
		const panel = $('tcas-preview-panel');
		if (panel) panel.hidden = true;
		const tbody = $('tcas-preview-table') && $('tcas-preview-table').querySelector('tbody');
		if (tbody) tbody.textContent = '';
		const results = $('tcas-connect-results');
		if (results) results.textContent = '';
		try {
			const data = await request('preview_start', { pairs });
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

	async function testConnection() {
		const button = $('tcas-test-connection');
		const code = $('tcas-test-code') ? $('tcas-test-code').value.trim() : '';
		const out = $('tcas-test-result');
		if (!code) { if (out) out.textContent = 'لینک یا SKU را وارد کنید.'; return; }
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
