(function () {
	'use strict';

	const cfg = window.TCAS_ADMIN || {};
	const $ = (id) => document.getElementById(id);
	const state = {
		token: '', previewDone: false, running: false, linkProduct: null, linkVariations: [],
		smartMatches: [], smartOffset: 0, smartSignature: '', smartAmbiguous: 0, smartUnmatched: 0,
		smartLocalCount: 0, smartRemoteCount: 0, smartLocalName: '', smartRemoteName: '',
		smartLocalReference: '', smartRemoteReference: '',
		smartIssues: [], smartIssueFilter: 'all'
	};

	// دلیل دقیق هر مورد «نیازمند بررسی»؛ همان کدهایی که Matcher برمی‌گرداند.
	const issueReasons = {
		duplicate_reference: {
			flag: 'مرجع تکراری',
			reason: (item) => 'مرجع این Variation در همین فروشگاه ' + String(Number(item.duplicate_count) || 2) + ' بار تکرار شده است؛ تا یکتاشدن مرجع، جفت خودکار ساخته نمی‌شود.'
		},
		multiple_candidates: {
			flag: 'چند گزینه',
			reason: (item) => String(Number(item.candidate_count) || 0) + ' Variation محتمل در سمت مقابل پیدا شد؛ انتخاب خودکار دلخواه انجام نمی‌شود.'
		},
		shared_key: {
			flag: 'کلید مشترک',
			reason: () => 'کلید تطبیق (SKU یا برچسب) با چند Variation دیگر مشترک است؛ اتصال خودکار امن نیست.'
		},
		counterpart_unusable: {
			flag: 'همتای نامعتبر',
			reason: () => 'همتای محتمل پیدا شد، اما مرجع Variation مقابل تکراری یا خالی است و قابل اتصال نیست.'
		},
		missing_reference: {
			flag: 'بدون مرجع',
			reason: () => 'این Variation مرجع SKU/ID ندارد؛ پیش از اتصال باید مرجع بگیرد.'
		},
		no_candidate: {
			flag: 'بدون همتا',
			reason: () => 'هیچ Variation هم‌ارزی در سمت مقابل پیدا نشد (نه با SKU و نه با برچسب)؛ اگر این مدل در فروشگاه مقابل وجود ندارد، این مورد طبیعی است.'
		}
	};
	const issueKeyLabels = { sku: 'SKU', label: 'برچسب', tokens: 'الگو' };

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
		body.append('partner_id', cfg.partnerId || 'ariocase');
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

	function setVariationTitle(field, value) {
		if (!field) return;
		const title = typeof value === 'string' ? value.trim() : '';
		let small = field.querySelector('.tcas-variation-title');
		if (!title) {
			if (small) small.remove();
			return;
		}
		if (!small) {
			small = node('small', 'tcas-variation-title');
			small.dir = 'auto';
			field.appendChild(small);
		}
		small.textContent = title;
		small.title = title;
	}

	function setPairEntryTitles(entry, localTitle, remoteTitle) {
		if (!entry) return;
		setVariationTitle(entry.querySelector('.tcas-shop-local'), localTitle);
		setVariationTitle(entry.querySelector('.tcas-shop-remote'), remoteTitle);
	}

	function updatePairEntryTitles(row) {
		const entries = pairEntries();
		const sameCodes = (entry) => {
			if (!entry) return false;
			const local = entry.querySelector('.tcas-local-code');
			const remote = entry.querySelector('.tcas-remote-code');
			return local && remote && local.value.trim() === String(row.local_code || '') && remote.value.trim() === String(row.remote_code || '');
		};
		let entry = entries[Number(row.index)];
		if (!sameCodes(entry)) entry = entries.find(sameCodes);
		if (entry) setPairEntryTitles(entry, row.local_name, row.remote_name);
	}

	function readonlyShopField(labelText, code, shopClass, variationTitle) {
		const label = node('label', 'tcas-shop-field ' + shopClass);
		label.appendChild(node('span', 'tcas-shop-label', labelText));
		const input = document.createElement('input');
		input.type = 'text';
		input.dir = 'ltr';
		input.readOnly = true;
		input.value = code || '—';
		input.setAttribute('aria-label', labelText);
		label.appendChild(input);
		setVariationTitle(label, variationTitle);
		return label;
	}

	// جفت‌هایی که با SKU یکتا تطبیق نشده‌اند با نشان «بررسی» علامت می‌خورند.
	function flagMatchEntry(entry, match) {
		if (!entry || !match) return;
		const confidence = match.confidence ? String(match.confidence) : '';
		if (!confidence || 'exact' === confidence) return;
		const kind = 'tokens' === match.matched_by ? 'تطبیق الگویی' : 'تطبیق از برچسب';
		const badge = node('span', 'tcas-pair-flag', 'بررسی · ' + kind);
		badge.title = 'این جفت با SKU یکتا تطبیق نشد؛ مرجع دو طرف را پیش از اتصال کنترل کنید.';
		const fields = entry.querySelector('.tcas-pair-fields');
		if (fields) entry.insertBefore(badge, fields);
		else entry.appendChild(badge);
	}

	function pairFields(localCode, remoteCode, localTitle, remoteTitle) {
		const fields = node('div', 'tcas-pair-fields');
		fields.appendChild(readonlyShopField('تیساکیس · TisaCase', localCode, 'tcas-shop-local', localTitle));
		fields.appendChild(node('span', 'tcas-pair-link', '+'));
		fields.appendChild(readonlyShopField(cfg.partnerLabel || 'ArioCase', remoteCode, 'tcas-shop-remote', remoteTitle));
		return fields;
	}

	function addPreviewRow(row) {
		updatePairEntryTitles(row);
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
		card.appendChild(pairFields(row.local_code, row.remote_code, row.local_name, row.remote_name));
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
			resetSmart();
		});
		entry.querySelectorAll('input').forEach((input) => input.addEventListener('input', () => {
			setVariationTitle(input.closest('.tcas-shop-field'), '');
			const flag = entry.querySelector('.tcas-pair-flag');
			if (flag) flag.remove();
			invalidatePreview();
			resetSmart();
		}));
	}

	function addPairEntry(localCode, remoteCode, focusInput, targetList) {
		const template = $('tcas-pair-entry-template');
		const list = targetList || $('tcas-pair-entries');
		const max = Number(cfg.maxPairs) || 50;
		if (!template || !list || pairEntries().length >= max) return;
		const entry = template.content.firstElementChild.cloneNode(true);
		list.appendChild(entry);
		const local = entry.querySelector('.tcas-local-code');
		const remote = entry.querySelector('.tcas-remote-code');
		if (local) local.value = typeof localCode === 'string' ? localCode : '';
		if (remote) remote.value = typeof remoteCode === 'string' ? remoteCode : '';
		wirePairEntry(entry);
		refreshPairEntries();
		invalidatePreview();
		if (local && focusInput !== false) local.focus();
		return entry;
	}

	function issueFlag(value, className) {
		return node('span', 'tcas-smart-issue-flag' + (className ? ' ' + className : ''), value);
	}

	function issueCandidates(item) {
		const candidates = Array.isArray(item.candidates) ? item.candidates : [];
		if (!candidates.length) return null;
		const box = node('div', 'tcas-smart-issue-candidates');
		const total = Number(item.candidate_count) || candidates.length;
		box.appendChild(node('span', 'tcas-smart-issue-candidates-title', 'گزینه‌های محتمل (' + String(total) + '):'));
		candidates.forEach((candidate) => {
			if (!candidate || typeof candidate !== 'object') return;
			const shop = candidate.shop === 'tisacase' ? 'TisaCase' : (cfg.partnerLabel || 'ArioCase');
			const parts = [
				shop,
				candidate.label ? String(candidate.label) : 'بدون عنوان',
				candidate.reference ? String(candidate.reference) : 'بدون مرجع',
				issueKeyLabels[candidate.key] || ''
			].filter(Boolean);
			if (candidate.usable === false) parts.push('غیرقابل‌اتصال');
			box.appendChild(node('span', 'tcas-smart-issue-candidate' + (candidate.usable === false ? ' is-unusable' : ''), parts.join(' · ')));
		});
		return box;
	}

	function issueTwins(item) {
		const twins = Array.isArray(item.twins) ? item.twins : [];
		if (Number(item.duplicate_count) < 2 || !twins.length) return null;
		const box = node('div', 'tcas-smart-issue-twins');
		box.appendChild(node('span', 'tcas-smart-issue-twins-title', 'همین مرجع در همین فروشگاه:'));
		twins.forEach((twin) => {
			if (!twin || typeof twin !== 'object') return;
			box.appendChild(node('span', 'tcas-smart-issue-twin', (twin.label ? String(twin.label) : 'Variation') + (twin.reference ? ' · ' + String(twin.reference) : '')));
		});
		return box;
	}

	function reviewIssue(item) {
		const isLocal = item && item.shop === 'tisacase';
		const reference = item && item.reference ? String(item.reference) : '';
		const entry = addPairEntry(isLocal ? reference : '', isLocal ? '' : reference, false);
		if (!entry) {
			text('tcas-smart-status', 'برای ساخت کارت بررسی، یک کارت خالی را از فهرست جفت‌ها حذف کنید.', true);
			return;
		}
		const target = entry.querySelector(isLocal ? '.tcas-remote-code' : '.tcas-local-code');
		if (target) {
			target.focus();
			if (target.scrollIntoView) target.scrollIntoView({ block: 'nearest' });
		}
		text('tcas-smart-status', 'کارت بررسی برای «' + (item.label ? String(item.label) : (reference || 'این Variation')) + '» ساخته شد؛ مرجع سمت مقابل را وارد کنید.');
	}

	function issueRow(item, kind) {
		const info = issueReasons[item.reason] || null;
		const row = node('article', 'tcas-smart-issue tcas-smart-issue-' + kind + (info ? ' tcas-smart-issue-' + item.reason : ''));
		row.dataset.kind = kind;
		row.dataset.reason = info ? String(item.reason) : '';
		row.setAttribute('role', 'listitem');
		const header = node('div', 'tcas-smart-issue-header');
		const flags = node('span', 'tcas-smart-issue-flags');
		flags.appendChild(issueFlag(kind === 'ambiguous' ? 'مبهم' : 'بدون تطبیق', 'tcas-smart-issue-flag-kind'));
		if (info) flags.appendChild(issueFlag(info.flag, 'tcas-smart-issue-flag-reason'));
		header.appendChild(flags);
		header.appendChild(node('span', 'tcas-smart-issue-shop', item.shop === 'tisacase' ? 'TisaCase' : (cfg.partnerLabel || 'ArioCase')));
		row.appendChild(header);
		row.appendChild(node('strong', 'tcas-smart-issue-label', item.label ? String(item.label) : 'Variation #' + String((Number(item.index) || 0) + 1)));
		row.appendChild(node('code', 'tcas-smart-issue-reference', item.reference ? String(item.reference) : 'مرجع ثبت نشده'));
		const reason = info ? info.reason(item) : (kind === 'ambiguous' ? 'مرجع تکراری یا چند گزینهٔ محتمل؛ تطبیق خودکار امن نیست.' : 'همتای یکتا در سمت دیگر پیدا نشد.');
		row.appendChild(node('small', 'tcas-smart-issue-reason', reason));
		const twins = issueTwins(item);
		if (twins) row.appendChild(twins);
		const candidates = issueCandidates(item);
		if (candidates) row.appendChild(candidates);
		const review = node('button', 'button tcas-smart-issue-review', 'بررسی دستی');
		review.type = 'button';
		review.title = 'یک کارت جفت با مرجع همین Variation می‌سازد تا سمت مقابل را دستی کامل کنید.';
		review.addEventListener('click', () => reviewIssue(item));
		row.appendChild(review);
		return row;
	}

	function applyIssueFilter() {
		const list = $('tcas-smart-issue-list');
		if (list) {
			Array.from(list.children).forEach((row) => {
				row.hidden = state.smartIssueFilter !== 'all' && row.dataset.kind !== state.smartIssueFilter;
			});
		}
		const wrap = $('tcas-smart-issue-filters');
		if (wrap) {
			Array.from(wrap.children).forEach((button) => {
				const active = button.dataset.filter === state.smartIssueFilter;
				button.classList.toggle('is-active', active);
				button.setAttribute('aria-pressed', active ? 'true' : 'false');
			});
		}
	}

	function renderIssueFilters() {
		const wrap = $('tcas-smart-issue-filters');
		if (!wrap) return;
		wrap.textContent = '';
		const count = (kind) => state.smartIssues.filter((entry) => kind === 'all' || entry.kind === kind).length;
		[['all', 'همه'], ['ambiguous', 'مبهم'], ['unmatched', 'بدون تطبیق']].forEach(([key, label]) => {
			const button = node('button', 'button tcas-smart-issue-filter', label + ' ' + String(count(key)));
			button.type = 'button';
			button.dataset.filter = key;
			button.setAttribute('aria-pressed', state.smartIssueFilter === key ? 'true' : 'false');
			if (state.smartIssueFilter === key) button.classList.add('is-active');
			button.addEventListener('click', () => {
				state.smartIssueFilter = key;
				applyIssueFilter();
			});
			wrap.appendChild(button);
		});
		wrap.hidden = state.smartIssues.length === 0;
	}

	function renderSmartIssues(ambiguous, unmatched) {
		const panel = $('tcas-smart-issues');
		const list = $('tcas-smart-issue-list');
		const count = $('tcas-smart-issues-count');
		if (!panel || !list) return;
		list.textContent = '';
		state.smartIssues = [];
		(Array.isArray(ambiguous) ? ambiguous : []).forEach((item) => { if (item && typeof item === 'object') state.smartIssues.push({ item, kind: 'ambiguous' }); });
		(Array.isArray(unmatched) ? unmatched : []).forEach((item) => { if (item && typeof item === 'object') state.smartIssues.push({ item, kind: 'unmatched' }); });
		state.smartIssues.forEach(({ item, kind }) => list.appendChild(issueRow(item, kind)));
		renderIssueFilters();
		applyIssueFilter();
		const visibleCount = list.children.length;
		panel.hidden = visibleCount === 0;
		if (count) count.textContent = String(visibleCount) + ' مورد';
	}

	function resetSmart() {
		state.smartMatches = [];
		state.smartOffset = 0;
		state.smartSignature = '';
		state.smartAmbiguous = 0;
		state.smartUnmatched = 0;
		state.smartLocalCount = 0;
		state.smartRemoteCount = 0;
		state.smartLocalName = '';
		state.smartRemoteName = '';
		state.smartLocalReference = '';
		state.smartRemoteReference = '';
		state.smartIssues = [];
		state.smartIssueFilter = 'all';
		renderSmartIssues([], []);
		const next = $('tcas-smart-next');
		if (next) next.hidden = true;
		text('tcas-smart-status', 'موارد مبهم و بدون تطبیق وصل نمی‌شوند.');
	}

	function smartSignature() {
		const local = $('tcas-smart-local');
		const remote = $('tcas-smart-remote');
		return (local ? local.value.trim() : '') + '\n' + (remote ? remote.value.trim() : '');
	}

	function smartCoverage() {
		const local = Number(state.smartLocalCount) || 0;
		const remote = Number(state.smartRemoteCount) || 0;
		return local || remote ? 'TisaCase: ' + String(local) + ' · ' + (cfg.partnerLabel || 'ArioCase') + ': ' + String(remote) + ' — ' : '';
	}

	function fillSmartBatch() {
		if (state.running) return;
		if (!state.smartSignature || state.smartSignature !== smartSignature()) {
			resetSmart();
			text('tcas-smart-status', 'محصول والد تغییر کرده است؛ دوباره تطبیق بدهید.', true);
			return;
		}
		const max = Number(cfg.maxPairs) || 50;
		const start = state.smartOffset;
		const batch = state.smartMatches.slice(start, start + max);
		if (!batch.length) {
			text('tcas-smart-status', 'جفت دیگری برای این تطبیق نمانده است.');
			return;
		}
		const hasCurrentPairs = pairEntries().some((entry) => {
			const local = entry.querySelector('.tcas-local-code');
			const remote = entry.querySelector('.tcas-remote-code');
			return (local && local.value.trim()) || (remote && remote.value.trim());
		});
		if (start === 0 && hasCurrentPairs && !window.confirm('جفت‌های فعلی با نتیجهٔ تطبیق جایگزین شوند؟')) {
			const next = $('tcas-smart-next');
			if (next) next.hidden = true;
			text('tcas-smart-status', 'جایگزینی جفت‌های فعلی انجام نشد.', true);
			return;
		}
		const list = $('tcas-pair-entries');
		if (!list) return;
		list.textContent = '';
		const group = node('details', 'tcas-fresh-product-group');
		const summary = node('summary', 'tcas-fresh-product-summary');
		const productNames = node('span', 'tcas-fresh-product-names');
		productNames.appendChild(node('strong', 'tcas-shop-local-text', 'TisaCase · ' + (state.smartLocalName || state.smartLocalReference || 'محصول')));
		productNames.appendChild(node('span', 'tcas-pair-link', '↔'));
		productNames.appendChild(node('strong', 'tcas-shop-remote-text', (cfg.partnerLabel || 'ArioCase') + ' · ' + (state.smartRemoteName || state.smartRemoteReference || 'محصول')));
		summary.appendChild(productNames);
		summary.appendChild(node('span', 'tcas-fresh-product-count', String(batch.length) + ' Variation'));
		group.appendChild(summary);
		const groupEntries = node('div', 'tcas-pair-entries tcas-pair-entries-grouped');
		group.appendChild(groupEntries);
		list.appendChild(group);
		batch.forEach((match) => {
			const entry = addPairEntry(match.local_code, match.remote_code, false, groupEntries);
			setPairEntryTitles(entry, match.local_label, match.remote_label);
			flagMatchEntry(entry, match);
		});
		if (pairEntries().length !== batch.length) {
			resetSmart();
			text('tcas-smart-status', 'ساخت کارت‌های جفت کامل نشد؛ دوباره تلاش کنید.', true);
			return;
		}
		state.smartOffset = start + batch.length;
		invalidatePreview();
		const excluded = state.smartAmbiguous + state.smartUnmatched;
		const flagged = batch.filter((match) => match && match.confidence && 'exact' !== match.confidence).length;
		let message = smartCoverage() + String(batch.length) + ' جفت آماده شد (' + String(state.smartOffset) + '/' + String(state.smartMatches.length) + ').';
		if (flagged) message += ' ' + String(flagged) + ' جفت بدون SKU یکتا و با نشان «بررسی» علامت خورد.';
		if (excluded) message += ' ' + String(excluded) + ' مورد مبهم/بدون تطبیق با دلیل مشخص در فهرست زیر علامت‌گذاری شد.';
		message += state.smartOffset < state.smartMatches.length ? ' پس از اتصال، «دسته بعد» را بزنید.' : ' پیش‌نمایش را بزنید.';
		text('tcas-smart-status', message);
		const next = $('tcas-smart-next');
		if (next) next.hidden = state.smartOffset >= state.smartMatches.length;
	}

	async function matchVariations() {
		if (state.running) return;
		const local = $('tcas-smart-local');
		const remote = $('tcas-smart-remote');
		if (!local || !remote || !local.value.trim() || !remote.value.trim()) {
			text('tcas-smart-status', 'مرجع هر دو محصول متغیر را وارد کنید.', true);
			return;
		}
		resetSmart();
		state.smartSignature = smartSignature();
		state.running = true;
		const button = $('tcas-smart-match');
		busy(button, true, 'در حال تطبیق…');
		text('tcas-smart-status', 'در حال دریافت Variationها و بررسی تطبیق یک‌به‌یک…');
		try {
			const data = await request('match_variations', { local_code: local.value.trim(), remote_code: remote.value.trim() });
			state.smartMatches = Array.isArray(data.matches) ? data.matches : [];
			const ambiguous = Array.isArray(data.ambiguous) ? data.ambiguous : [];
			const unmatched = Array.isArray(data.unmatched) ? data.unmatched : [];
			state.smartAmbiguous = ambiguous.length;
			state.smartUnmatched = unmatched.length;
			renderSmartIssues(ambiguous, unmatched);
			state.smartLocalCount = data.local && Number.isFinite(Number(data.local.count)) ? Number(data.local.count) : 0;
			state.smartRemoteCount = data.remote && Number.isFinite(Number(data.remote.count)) ? Number(data.remote.count) : 0;
			state.smartLocalName = data.local && data.local.name ? String(data.local.name) : '';
			state.smartRemoteName = data.remote && data.remote.name ? String(data.remote.name) : '';
			state.smartLocalReference = data.local && data.local.reference ? String(data.local.reference) : '';
			state.smartRemoteReference = data.remote && data.remote.reference ? String(data.remote.reference) : '';
			state.smartOffset = 0;
			if (!state.smartMatches.length) {
				const excluded = state.smartAmbiguous + state.smartUnmatched;
				text('tcas-smart-status', smartCoverage() + 'جفت یکتایی پیدا نشد' + (excluded ? '؛ ' + String(excluded) + ' مورد مبهم/بدون تطبیق با دلیل مشخص در فهرست زیر علامت‌گذاری شد.' : '.'), true);
			} else {
				state.running = false;
				fillSmartBatch();
			}
		} catch (error) {
			resetSmart();
			text('tcas-smart-status', error.message, true);
		}
		state.running = false;
		busy(button, false);
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
		text('tcas-connect-status', 'عملیات تمام شد؛ جفت‌های تأییدشده در محصولات متصل گروه‌بندی شدند. ');
		const status = $('tcas-connect-status');
		if (status && cfg.mappingsUrl) {
			const link = node('a', 'tcas-connected-groups-link', 'مشاهدهٔ کارت‌های محصولات متصل');
			link.href = cfg.mappingsUrl;
			status.appendChild(link);
		}
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

	function productDiagnostic(data) {
		const parts = [data && data.name ? data.name : 'محصول'];
		if (data && data.product_id) parts.push('ID ' + String(data.product_id));
		if (data && data.type === 'variable') {
			const count = Array.isArray(data.variations) ? data.variations.length : 0;
			parts.push(String(count) + ' Variation');
		}
		return parts.join(' · ');
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
		if (result) {
			result.hidden = true;
			delete result.dataset.shop;
		}
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
		if (!variation) {
			setLinkOutput('', (data.shop_label || 'محصول') + ' · Variation', productDiagnostic(data));
			text('tcas-link-status', 'برای ادامه، یکی از Variationهای محصول را انتخاب کنید.');
			return;
		}
		if (!variation.reference) {
			setLinkOutput('', (data.shop_label || 'محصول') + ' · Variation', productDiagnostic(data));
			text('tcas-link-status', 'این Variation شناسهٔ قابل‌اتصال ندارد؛ یک مورد دیگر را انتخاب کنید.', true);
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
			if (result) {
				result.hidden = false;
				if (data.shop) result.dataset.shop = data.shop;
			}
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
				setLinkOutput('', (data.shop_label || 'محصول') + ' · Variation', productDiagnostic(data));
				const usable = state.linkVariations.filter((variation) => Boolean(variation.reference));
				if (usable.length === 1 && select) {
					select.value = String(state.linkVariations.indexOf(usable[0]));
					chooseLinkVariation();
				} else {
					text('tcas-link-status', usable.length ? String(usable.length) + ' Variation قابل‌اتصال بارگذاری شد؛ یک مورد را انتخاب کنید.' : 'Variation واقعی با شناسهٔ معتبر پیدا نشد؛ گزینهٔ Attribute کافی نیست.', !usable.length);
				}
			} else {
				const reference = data.reference || data.sku || (data.product_id ? 'ID:' + String(data.product_id) : '');
				setLinkOutput(reference, (data.shop_label || 'محصول') + (/^ID:/i.test(reference) ? ' · ID' : ' · SKU'), productDiagnostic(data));
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
	if (pairAdd) pairAdd.addEventListener('click', () => { resetSmart(); addPairEntry(); });
	const smartMatch = $('tcas-smart-match');
	if (smartMatch) smartMatch.addEventListener('click', matchVariations);
	const smartNext = $('tcas-smart-next');
	if (smartNext) smartNext.addEventListener('click', fillSmartBatch);
	['tcas-smart-local', 'tcas-smart-remote'].forEach((id) => {
		const input = $(id);
		if (!input) return;
		input.addEventListener('input', resetSmart);
		input.addEventListener('keydown', (event) => { if (event.key === 'Enter') { event.preventDefault(); matchVariations(); } });
	});
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
