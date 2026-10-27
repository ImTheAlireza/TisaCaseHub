(function () {
	'use strict';

	const cfg = window.TCAS_ADMIN || {};
	const $ = (id) => document.getElementById(id);
	const state = {
		token: '', previewDone: false, running: false, linkProduct: null, linkVariations: [],
		smartMatches: [], smartOffset: 0, smartSignature: '', smartAmbiguous: 0, smartUnmatched: 0,
		smartLocalCount: 0, smartRemoteCount: 0, smartLocalName: '', smartRemoteName: '',
		smartLocalReference: '', smartRemoteReference: '',
		smartIssues: [], smartIssueFilter: 'all', transport: 'ajax'
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
		multiple_families: {
			flag: 'چند خانواده',
			reason: (item) => String(Number(item.candidate_count) || 0) + ' برچسب چندمدلی از همان خانواده در سمت مقابل هست؛ برای پرهیز از اتصال اشتباه، خودکار انتخاب نمی‌شود.'
		},
		no_candidate: {
			flag: 'بدون همتا',
			reason: () => 'هیچ Variation هم‌ارزی در سمت مقابل پیدا نشد (نه با SKU و نه با برچسب)؛ اگر این مدل در فروشگاه مقابل وجود ندارد، این مورد طبیعی است.'
		}
	};
	const issueKeyLabels = { sku: 'SKU', label: 'برچسب', tokens: 'الگو', family: 'خانوادهٔ مدل', model: 'مدل' };

	// نشان‌های تطبیق: فقط SKU یکتا بی‌نشان است؛ بقیه باید پیش از اتصال دیده شوند.
	const matchFlags = {
		family: { text: 'بررسی · خانوادهٔ مدل', cls: 'is-family', hint: 'برچسب‌های دو طرف برای یک خانوادهٔ مدل یکی هستند؛ نگارش، ترتیب یا نسل شبکه متفاوت نوشته شده است.' },
		suggested: { text: 'پیشنهاد · خانوادهٔ مدل', cls: 'is-suggested', hint: 'یک طرف چند مدل را با هم فهرست کرده و طرف دیگر تنها یکی از همان خانواده است؛ پیش از اتصال تأیید کنید.' },
		label: { text: 'بررسی · تطبیق از برچسب', cls: '', hint: 'این جفت با SKU یکتا تطبیق نشد؛ مرجع دو طرف را پیش از اتصال کنترل کنید.' },
		tokens: { text: 'بررسی · تطبیق الگویی', cls: '', hint: 'واژه‌های دو برچسب با ترتیب متفاوت یکی شده‌اند؛ مرجع دو طرف را کنترل کنید.' },
		manual: { text: 'دستی · نیاز به بررسی', cls: 'is-manual', hint: 'این جفت دستی وارد شده است؛ کد و موجودی هر دو طرف را کنترل کنید.' },
		orphan: { text: 'بدون همتا', cls: 'is-orphan', hint: 'برای این Variation همتایی پیدا نشد؛ سمت مقابل باید دستی پر شود.' }
	};

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

	function delay(ms) {
		return new Promise((resolve) => window.setTimeout(resolve, ms));
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

	const RETRY_STATUSES = [408, 418, 425, 429, 500, 502, 503, 504];

	/** آدرس واقعی AJAX؛ همان دامنه‌ای که این صفحه از آن باز شده است. */
	function ajaxTarget() {
		const fallback = window.location ? window.location.origin : '';
		try {
			const url = new URL(cfg.ajaxUrl || '/wp-admin/admin-ajax.php', window.location.href);
			return { url: url.href, origin: url.origin, host: url.host, path: url.pathname };
		} catch (error) {
			return { url: String(cfg.ajaxUrl || ''), origin: fallback, host: fallback, path: '' };
		}
	}

	function headerValue(response, name) {
		try {
			return response && response.headers && response.headers.get ? String(response.headers.get(name) || '') : '';
		} catch (error) {
			return '';
		}
	}

	/** نام فایروال/CDN از روی هدرها و بدنهٔ صفحهٔ مسدودی حدس زده می‌شود. */
	function detectFirewall(response, raw) {
		const body = String(raw || '').toLowerCase();
		if (headerValue(response, 'cf-ray') || body.indexOf('cloudflare') !== -1 || body.indexOf('cf-error') !== -1) return 'Cloudflare (CDN / WAF)';
		if (body.indexOf('imunify') !== -1) return 'Imunify360';
		if (body.indexOf('sucuri') !== -1) return 'Sucuri';
		if (body.indexOf('wordfence') !== -1) return 'Wordfence';
		if (body.indexOf('mod_security') !== -1 || body.indexOf('modsecurity') !== -1) return 'ModSecurity (فایروال هاست)';
		if (body.indexOf('litespeed') !== -1) return 'LiteSpeed';
		if (body.indexOf('captcha') !== -1 || body.indexOf('challenge') !== -1) return 'چالش امنیتی / CAPTCHA';
		return '';
	}

	function plainExcerpt(raw) {
		const text = String(raw || '')
			.replace(/<script[\s\S]*?<\/script>/gi, ' ')
			.replace(/<style[\s\S]*?<\/style>/gi, ' ')
			.replace(/<[^>]+>/g, ' ')
			.replace(/&nbsp;/gi, ' ')
			.replace(/&amp;/gi, '&')
			.replace(/\s+/g, ' ')
			.trim();
		return text.length > 400 ? text.slice(0, 400) + '…' : text;
	}

	const diag = { action: '', url: '', host: '', status: 0, server: '', firewall: '', excerpt: '', at: '' };

	function diagRow(list, label, value, mono) {
		const row = node('div', 'tcas-request-diag-row');
		row.appendChild(node('span', 'tcas-request-diag-label', label));
		const cell = node(mono ? 'code' : 'span', 'tcas-request-diag-value', value || '—');
		if (mono) cell.dir = 'ltr';
		row.appendChild(cell);
		list.appendChild(row);
		return row;
	}

	function diagPanel() {
		let panel = $('tcas-request-diag');
		if (panel) return panel;
		const host = document.querySelector('.tcas-pair-actions') || document.querySelector('.tcas-pairs-card');
		if (!host) return null;
		panel = document.createElement('details');
		panel.id = 'tcas-request-diag';
		panel.className = 'tcas-request-diag';
		panel.hidden = true;
		const summary = node('summary', 'tcas-request-diag-summary', 'جزئیات آخرین خطای درخواست (مبدا و پاسخ سرور)');
		panel.appendChild(summary);
		const body = node('div', 'tcas-request-diag-body');
		body.appendChild(node('p', 'tcas-request-diag-note', 'این درخواست فقط به همان سایتی رفته است که این صفحه از آن باز شده (admin-ajax.php خود وردپرس). درخواست به ArioCase از سمت سرور انجام می‌شود و خطای آن هرگز به‌صورت HTTP 418 در مرورگر دیده نمی‌شود؛ پس این پاسخ از سایت خودتان یا CDN/فایروال جلوی آن است.'));
		panel.appendChild(body);
		const actions = node('div', 'tcas-request-diag-actions');
		const copy = node('button', 'button tcas-request-diag-copy', 'کپی جزئیات');
		copy.type = 'button';
		actions.appendChild(copy);
		panel.appendChild(actions);
		host.appendChild(panel);
		return panel;
	}

	function copyText(value) {
		if (window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText) {
			return window.navigator.clipboard.writeText(value).catch(() => fallbackCopy(value));
		}
		return Promise.resolve(fallbackCopy(value));
	}

	function fallbackCopy(value) {
		try {
			const area = document.createElement('textarea');
			area.value = value;
			area.style.position = 'fixed';
			area.style.opacity = '0';
			document.body.appendChild(area);
			area.select();
			document.execCommand('copy');
			document.body.removeChild(area);
		} catch (error) {
			/* بدون دسترسی کلیپ‌بورد هم متن جزئیات در صفحه دیده می‌شود. */
		}
	}

	function diagText() {
		return [
			'درخواست: tcas_' + diag.action,
			'آدرس: ' + diag.url,
			'دامنهٔ پاسخ‌دهنده: ' + diag.host,
			'وضعیت: HTTP ' + diag.status,
			'سرور: ' + (diag.server || 'نامشخص'),
			'فایروال/CDN: ' + (diag.firewall || 'در هدر و بدنه شناسایی نشد'),
			'زمان: ' + diag.at,
			'نمونهٔ پاسخ: ' + (diag.excerpt || '—')
		].join('\n');
	}

	/** خطای شبکه/AJAX را با مبدا، هدرها و نمونهٔ پاسخ ثبت می‌کند. */
	function reportFailure(action, response, raw) {
		const target = ajaxTarget();
		diag.action = action;
		diag.url = target.url;
		diag.host = target.host || target.origin;
		diag.status = response && response.status ? response.status : 0;
		diag.server = headerValue(response, 'server');
		diag.firewall = detectFirewall(response, raw);
		diag.excerpt = plainExcerpt(raw);
		diag.at = new Date().toLocaleString('fa-IR');
		try {
			console.warn('[TisaCase] خطای درخواست ' + action, {
				url: diag.url,
				status: diag.status,
				server: diag.server,
				firewall: diag.firewall,
				cfRay: headerValue(response, 'cf-ray'),
				contentType: headerValue(response, 'content-type'),
				excerpt: diag.excerpt
			});
		} catch (error) {
			/* console در دسترس نیست. */
		}
		const panel = diagPanel();
		if (!panel) return;
		let body = panel.querySelector('.tcas-request-diag-body');
		if (!body) return;
		Array.from(body.querySelectorAll('.tcas-request-diag-row')).forEach((row) => row.remove());
		diagRow(body, 'درخواست', 'tcas_' + diag.action);
		diagRow(body, 'آدرس کامل', diag.url, true);
		diagRow(body, 'دامنهٔ پاسخ‌دهنده', diag.host, true);
		diagRow(body, 'وضعیت', 'HTTP ' + diag.status);
		diagRow(body, 'هدر Server', diag.server);
		diagRow(body, 'فایروال / CDN', diag.firewall || 'در هدر و بدنه شناسایی نشد');
		diagRow(body, 'cf-ray', headerValue(response, 'cf-ray'));
		diagRow(body, 'Content-Type', headerValue(response, 'content-type'));
		diagRow(body, 'نمونهٔ پاسخ', diag.excerpt || '—', true);
		const copy = panel.querySelector('.tcas-request-diag-copy');
		if (copy && !copy.dataset.wired) {
			copy.dataset.wired = '1';
			copy.addEventListener('click', () => {
				copyText(diagText()).then(() => {
					copy.textContent = 'کپی شد';
					window.setTimeout(() => { copy.textContent = 'کپی جزئیات'; }, 1500);
				});
			});
		}
		panel.hidden = false;
		panel.open = true;
	}

	function responseMessage(status, action, host, firewall) {
		if (!status) {
			return 'ارتباط با سرور سایت هنگام «' + action + '» قطع شد (HTTP 0). این خطا یعنی پاسخ به مرورگر نرسیده است:'
				+ ' یا هاست درخواست را قطع کرده (محدودیت نرخ/فایروال)، یا PHP در میانهٔ کار متوقف شده (Fatal/Timeout)، یا اتصال شبکه پایدار نبوده است.'
				+ ' درخواست‌ها خودکار دسته‌بندی می‌شوند تا تعدادشان کم شود؛ اگر تکرار شد، گزارش خطاهای PHP و لاگ فایروال هاست را ببینید.';
		}
		const code = 'HTTP ' + (status || 0);
		const where = host ? ' · ' + host : '';
		const who = firewall ? ' · ' + firewall : '';
		if ([403, 418, 429].includes(status)) {
			return 'درخواست «' + action + '» توسط سرور سایت مسدود شد (' + code + where + who + ').'
				+ ' این پاسخ از همان دامنهٔ خودتان آمده است؛ مبدا و بدنهٔ پاسخ را در «جزئیات آخرین خطای درخواست» زیر همین کارت‌ها ببینید.'
				+ ' چند لحظه صبر کنید و دوباره تلاش کنید؛ اگر تکرار شد، بررسی زندهٔ هر کارت را با دکمهٔ «بررسی همه دوباره» انجام دهید.';
		}
		if (500 <= status) {
			return 'سرور سایت هنگام پردازش «' + action + '» خطا داد (' + code + where + ')؛ گزارش خطاهای PHP همین سایت را ببینید.';
		}
		return 'پاسخ JSON معتبر از سرور سایت دریافت نشد (' + code + where + ').';
	}

	/** شناسه‌های لینکی به‌صورت b64 ارسال می‌شوند تا فایروال‌های هاست که وجود «://» در POST را مسدود می‌کنند حساس نشوند. */
	function transportValue(value) {
		const text = String(value);
		if (!/https?:\/\//i.test(text)) return text;
		try {
			const bytes = new window.TextEncoder().encode(text);
			let binary = '';
			bytes.forEach((byte) => { binary += String.fromCharCode(byte); });
			return 'b64:' + window.btoa(binary);
		} catch (error) {
			return text;
		}
	}

	/** مسیرهای ممکن: admin-ajax اول، REST به‌عنوان پشتیبان در صورت مسدودشدن. */
	function restTarget(action) {
		const base = cfg.restUrl ? String(cfg.restUrl).replace(/\/$/, '') : '';
		return base ? base + '/' + action : '';
	}

	function transportUrl(transport, action) {
		if ('rest' === transport) return restTarget(action);
		return ajaxTarget().url;
	}

	function transportHeaders(transport) {
		const headers = { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json, text/plain, */*' };
		if ('rest' === transport && cfg.restNonce) headers['X-WP-Nonce'] = cfg.restNonce;
		return headers;
	}

	function transports() {
		const order = ['rest' === state.transport ? 'rest' : 'ajax'];
		order.push('rest' === order[0] ? 'ajax' : 'rest');
		if (!restTarget('preview_step')) return ['ajax'];
		return order;
	}

	async function request(action, data, options) {
		const opts = options || {};
		const retries = Number.isFinite(Number(opts.retries)) ? Number(opts.retries) : 1;
		const body = new FormData();
		body.append('action', 'tcas_' + action);
		body.append('nonce', cfg.nonce || '');
		body.append('partner_id', cfg.partnerId || 'ariocase');
		Object.entries(data || {}).forEach(([key, value]) => {
			if (Array.isArray(value)) value.forEach((item) => body.append(key + '[]', transportValue(item)));
			else body.append(key, value === null || typeof value === 'undefined' ? '' : transportValue(value));
		});
		const backoff = Number.isFinite(Number(opts.backoff)) ? Number(opts.backoff) : 700;
		let lastError = null;
		let lastResponse = null;
		let lastRaw = '';
		for (let attempt = 0; attempt <= retries; attempt++) {
			if (attempt) await delay(backoff * attempt);
			for (const transport of transports()) {
				const url = transportUrl(transport, action);
				if (!url) continue;
				let response;
				try {
					response = await fetch(url, { method: 'POST', body, credentials: 'same-origin', headers: transportHeaders(transport) });
				} catch (error) {
					if ('ajax' === transport) {
						lastError = String(error && error.message ? error.message : error);
						continue; // شاید REST باز باشد
					}
					lastError = String(error && error.message ? error.message : error);
					continue;
				}
				let raw = '';
				try { raw = await response.text(); } catch (error) { raw = ''; }
				let payload = null;
				try { payload = raw ? JSON.parse(raw) : null; } catch (error) { payload = null; }
				if (!payload || 'object' !== typeof payload) {
					// مسیر بسته یا صفحهٔ خطای هاست؛ مسیر دیگر امتحان می‌شود.
					lastResponse = response;
					lastRaw = raw;
					lastError = responseMessage(response.status, action, ajaxTarget().host, detectFirewall(response, raw));
					continue;
				}
				if (state.transport !== transport) {
					state.transport = transport;
					if ('rest' === transport) text('tcas-preview-status', 'مسیر admin-ajax مسدود بود؛ درخواست‌ها از مسیر REST همین سایت ادامه پیدا کرد.');
				}
				if (!payload.success) {
					throw new Error(payload && payload.data && payload.data.message ? payload.data.message : (cfg.strings && cfg.strings.unknownError) || 'خطا');
				}
				return payload.data || {};
			}
		}
		if (lastResponse) {
			reportFailure(action, lastResponse, lastRaw);
			throw new Error(lastError || responseMessage(lastResponse.status, action, ajaxTarget().host, ''));
		}
		reportFailure(action, null, lastError || '');
		throw new Error(responseMessage(0, action, ajaxTarget().host, ''));
	}

	/** صف تک‌نفره برای بررسی کارت‌ها تا هاست پشت فایروال با موج درخواست مسدود نشود. */
	const previewQueue = { busy: false, items: [] };

	function pumpPreviewQueue() {
		if (previewQueue.busy || !previewQueue.items.length) return;
		const job = previewQueue.items.shift();
		previewQueue.busy = true;
		Promise.resolve()
			.then(job.task)
			.then(job.resolve, job.reject)
			.finally(() => {
				previewQueue.busy = false;
				pumpPreviewQueue();
			});
	}

	function enqueuePreview(task) {
		return new Promise((resolve, reject) => {
			previewQueue.items.push({ task, resolve, reject });
			pumpPreviewQueue();
		});
	}

	// ---- جست‌وجوی محلی محصول TisaCase: بدون درخواست بیرونی و بدون لینک در POST ----
	const SEARCH_DEBOUNCE = 250;

	function looksLikeLink(value) {
		const text = String(value || '').trim();
		return /^(?:https?:\/\/|www\.)/i.test(text) || /\.(?:com|ir|net|org|shop|store)(?:\/|$)/i.test(text);
	}

	function productTypeLabel(type) {
		const labels = { simple: 'ساده', variation: 'Variation', variable: 'متغیر', grouped: 'گروهی', external: 'خارجی' };
		return labels[type] || (type || 'محصول');
	}

	function searchFetch(term) {
		return enqueuePreview(() => request('search_products', { term }));
	}

	/**
	 * یک ورودی را به ترکیب «جست‌وجو + انتخاب» تبدیل می‌کند.
	 * جست‌وجو فقط روی دیتابیس همین سایت انجام می‌شود، پس نه به همکار درخواستی می‌رود
	 * و نه لینکی در بدنهٔ POST قرار می‌گیرد.
	 */
	function attachProductSearch(input, options) {
		if (!input || input.dataset.searchBound) return null;
		const opts = options || {};
		input.dataset.searchBound = '1';
		input.setAttribute('autocomplete', 'off');
		input.setAttribute('role', 'combobox');
		input.setAttribute('aria-autocomplete', 'list');
		const host = opts.host || (input.closest('.tcas-shop-field') || input.parentElement);
		if (!host) return null;
		host.classList.add('tcas-search-host');
		const box = node('div', 'tcas-search-results');
		box.hidden = true;
		box.setAttribute('role', 'listbox');
		host.appendChild(box);
		let timer = 0;
		let seq = 0;
		let rows = [];
		let active = -1;

		const unclip = (open) => {
			// گروه‌های آکاردئونی overflow:hidden دارند و فهرست را می‌برند.
			let parent = host.parentElement;
			while (parent && parent !== document.body) {
				if (parent.classList && (parent.classList.contains('tcas-fresh-product-group') || parent.classList.contains('tcas-card') || parent.classList.contains('tcas-pair-entry'))) {
					parent.classList.toggle('is-search-open', Boolean(open));
				}
				parent = parent.parentElement;
			}
			host.classList.toggle('is-open', Boolean(open));
		};

		const hide = () => {
			box.hidden = true;
			box.textContent = '';
			active = -1;
			rows = [];
			unclip(false);
			input.setAttribute('aria-expanded', 'false');
		};

		const show = () => {
			box.hidden = false;
			unclip(true);
			input.setAttribute('aria-expanded', 'true');
		};

		const render = (list, note) => {
			box.textContent = '';
			rows = Array.isArray(list) ? list : [];
			active = -1;
			if (note) {
				box.appendChild(node('p', 'tcas-search-note', note));
			}
			rows.forEach((row, index) => {
				const item = node('button', 'tcas-search-row');
				item.type = 'button';
				item.setAttribute('role', 'option');
				item.dataset.index = String(index);
				item.dataset.reference = row.reference || '';
				item.appendChild(node('span', 'tcas-search-name', row.name || row.reference || 'محصول'));
				if (row.label) item.appendChild(node('span', 'tcas-search-label', row.label));
				const meta = [row.sku ? 'SKU: ' + row.sku : row.reference, productTypeLabel(row.type)];
				if (row.stock) meta.push('موجودی: ' + stockLabel(row.stock));
				item.appendChild(node('span', 'tcas-search-meta', meta.filter(Boolean).join(' · ')));
				item.addEventListener('mousedown', (event) => event.preventDefault());
				item.addEventListener('click', () => pick(row));
				box.appendChild(item);
			});
			show();
		};

		const pick = (row) => {
			if (!row) return;
			input.value = row.reference || '';
			hide();
			if (typeof opts.onPick === 'function') opts.onPick(row);
		};

		const highlight = () => {
			Array.from(box.querySelectorAll('.tcas-search-row')).forEach((item, index) => {
				item.classList.toggle('is-active', index === active);
				item.setAttribute('aria-selected', index === active ? 'true' : 'false');
			});
			const current = box.querySelector('.tcas-search-row.is-active');
			if (current && current.scrollIntoView) current.scrollIntoView({ block: 'nearest' });
		};

		const run = async () => {
			const term = String(input.value || '').trim();
			if (looksLikeLink(term) || (opts.minLength || 2) > term.length) {
				hide();
				return;
			}
			const current = ++seq;
			render([], 'در حال جست‌وجوی «' + term + '»…');
			try {
				const data = await searchFetch(term);
				if (current !== seq) return;
				const list = (Array.isArray(data.rows) ? data.rows : []).filter((row) => (typeof opts.filter === 'function' ? opts.filter(row) : true));
				if (!list.length) {
					render([], opts.emptyHint || 'محصولی با این نام یا SKU پیدا نشد.');
					return;
				}
				render(list, '');
			} catch (error) {
				if (current !== seq) return;
				render([], error.message);
			}
		};

		input.addEventListener('input', () => {
			const value = String(input.value || '');
			if (looksLikeLink(value) || (opts.minLength || 2) > value.trim().length) {
				window.clearTimeout(timer);
				hide();
				return;
			}
			window.clearTimeout(timer);
			// مثل هر جست‌وجوی دیگری: اولین حرف‌ها فوراً نتیجه می‌دهند و تایپ سریع دوباره‌کاری نمی‌کند.
			const delay = 0 === rows.length && !box.hidden ? 120 : (opts.debounce || SEARCH_DEBOUNCE);
			timer = window.setTimeout(run, delay);
		});
		input.addEventListener('focus', () => { if (rows.length) show(); });
		input.addEventListener('blur', () => { window.setTimeout(hide, 220); });
		input.addEventListener('keydown', (event) => {
			if (box.hidden) return;
			if ('ArrowDown' === event.key) {
				event.preventDefault();
				active = Math.min(active + 1, rows.length - 1);
				highlight();
			} else if ('ArrowUp' === event.key) {
				event.preventDefault();
				active = Math.max(active - 1, 0);
				highlight();
			} else if ('Escape' === event.key) {
				hide();
			} else if ('Enter' === event.key && active >= 0) {
				event.preventDefault();
				event.stopPropagation();
				pick(rows[active]);
			}
		});

		return { hide, run, pick, box };
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

	// ---- وضعیت زندهٔ هر کارت: سبز=قطعی، زرد=نیاز به بررسی، قرمز=ناقص/خطا ----
	const entryTimers = new WeakMap();
	const entrySeq = new WeakMap();

	function entryParts(entry) {
		return {
			flag: entry.querySelector('.tcas-pair-flag'),
			spinner: entry.querySelector('.tcas-spinner'),
			note: entry.querySelector('.tcas-pair-flag-note'),
			stock: entry.querySelector('.tcas-pair-stock'),
			message: entry.querySelector('.tcas-pair-message'),
			checkbox: entry.querySelector('.tcas-row-select'),
			local: entry.querySelector('.tcas-local-code'),
			remote: entry.querySelector('.tcas-remote-code')
		};
	}

	function entryCodes(entry) {
		const parts = entryParts(entry);
		return {
			local: parts.local ? parts.local.value.trim() : '',
			remote: parts.remote ? parts.remote.value.trim() : ''
		};
	}

	function setEntryLoading(entry, loading) {
		const parts = entryParts(entry);
		entry.classList.toggle('is-loading', Boolean(loading));
		if (parts.spinner) parts.spinner.hidden = !loading;
	}

	function setEntryFlag(entry, label, tone, extraClass, hint) {
		const parts = entryParts(entry);
		if (!parts.flag) return;
		parts.flag.textContent = label || '';
		parts.flag.className = 'tcas-pair-flag is-' + (tone || 'idle') + (extraClass ? ' ' + extraClass : '');
		parts.flag.title = hint || '';
	}

	function setEntrySelectable(entry, selectable) {
		const parts = entryParts(entry);
		const box = parts.checkbox;
		if (!box) return;
		const wasDisabled = box.disabled;
		box.disabled = !selectable;
		if (!selectable) box.checked = false;
		else if (wasDisabled) box.checked = true;
		entry.classList.toggle('is-selectable', Boolean(selectable));
	}

	function setEntryState(entry, state, options) {
		const opts = options || {};
		entry.dataset.state = state;
		entry.classList.toggle('is-orphan', 'incomplete' === state || 'orphan' === state);
		entry.classList.toggle('is-failed', 'error' === state);
		entry.classList.toggle('is-settled', 'ok' === state || 'done' === state);
		entry.classList.toggle('is-review', 'review' === state);
		if (typeof opts.flag === 'string') setEntryFlag(entry, opts.flag, opts.tone || 'idle', opts.cls || '', opts.hint || '');
		if (typeof opts.text === 'string') {
			const parts = entryParts(entry);
			if (parts.message) {
				parts.message.textContent = opts.text;
				parts.message.classList.toggle('is-error', 'error' === state || 'incomplete' === state);
			}
		}
	}

	function flagOutcome(entry, row) {
		const confidence = entry.dataset.confidence || 'manual';
		if (row.status === 'connected') {
			return { state: 'ok', flag: 'قبلاً متصل شده', tone: 'ok', hint: 'این جفت از قبل متصل است و دوباره ساخته نمی‌شود.' };
		}
		if ('exact' === confidence) {
			return { state: 'ok', flag: 'مشترک قطعی', tone: 'ok', hint: 'هر دو طرف با SKU یکتا شناسایی شدند؛ اتصال کم‌ریسک است.' };
		}
		const info = matchFlags[confidence] || matchFlags.manual;
		return { state: 'review', flag: info.text, tone: 'warn', cls: info.cls, hint: info.hint };
	}

	function applyRow(entry, row) {
		if (!entry || !row) return;
		const parts = entryParts(entry);
		setPairEntryTitles(entry, row.local_name, row.remote_name);
		if (parts.stock) {
			const cells = [];
			if (row.local_stock) cells.push('TisaCase: ' + stockLabel(row.local_stock));
			cells.push((cfg.partnerLabel || 'ArioCase') + ': ' + stockLabel(row.stock));
			parts.stock.textContent = cells.join(' · ');
			parts.stock.hidden = false;
		}
		if (row.selectable) {
			const outcome = flagOutcome(entry, row);
			setEntryState(entry, outcome.state, { flag: outcome.flag, tone: outcome.tone, cls: outcome.cls, hint: outcome.hint });
			setEntrySelectable(entry, 'connected' !== row.status);
		} else {
			setEntryState(entry, 'error', { flag: 'غیرقابل اتصال · نیاز به بررسی', tone: 'danger', hint: 'این کارت تا رفع مشکل قابل انتخاب و اتصال نیست.' });
			setEntrySelectable(entry, false);
		}
		if (parts.message) {
			parts.message.textContent = row.message || '';
			parts.message.classList.toggle('is-error', !row.selectable);
		}
	}

	async function checkEntry(entry, options) {
		if (!entry) return;
		const opts = options || {};
		const { local, remote } = entryCodes(entry);
		const parts = entryParts(entry);
		if (parts.message) {
			parts.message.textContent = '';
			parts.message.classList.remove('is-error');
		}
		if (!local && !remote) {
			setEntryLoading(entry, false);
			if (parts.stock) parts.stock.hidden = true;
			setEntrySelectable(entry, false);
			setEntryState(entry, 'idle', { flag: 'در انتظار شناسه', tone: 'idle', text: '' });
			updateConnectAvailability();
			return;
		}
		if (!local || !remote) {
			const missing = !local ? 'TisaCase' : (cfg.partnerLabel || 'ArioCase');
			setEntryLoading(entry, false);
			if (parts.stock) parts.stock.hidden = true;
			setEntrySelectable(entry, false);
			setEntryState(entry, 'incomplete', {
				flag: 'ناقص · ' + missing + ' خالی',
				tone: 'danger',
				text: 'این Variation در ' + missing + ' پیدا نشد؛ کد آن را دستی وارد کنید یا کارت را حذف کنید.',
				hint: 'کادر قرمز یعنی یک سمت جفت وجود ندارد یا کد ندارد.'
			});
			updateConnectAvailability();
			return;
		}
		if (/^(?:[a-z]+:\/\/|www\.)/i.test(local) || /^(?:[a-z]+:\/\/|www\.)/i.test(remote)) {
			setEntryLoading(entry, false);
			setEntrySelectable(entry, false);
			setEntryState(entry, 'error', { flag: 'لینک به‌جای شناسه', tone: 'danger', text: 'لینک را ابتدا در «تبدیل لینک به شناسه» به شناسه تبدیل کنید.' });
			updateConnectAvailability();
			return;
		}
		const signature = local + '\u0000' + remote;
		if (!opts.force && entry.dataset.checkedFor === signature && ['ok', 'review', 'done'].includes(entry.dataset.state || '')) {
			updateConnectAvailability();
			return;
		}
		const seq = (entrySeq.get(entry) || 0) + 1;
		entrySeq.set(entry, seq);
		setEntryLoading(entry, true);
		setEntryState(entry, 'loading', { flag: 'در حال بررسی…', tone: 'idle', text: '' });
		try {
			const data = await enqueuePreview(() => request('preview_pair', { local_code: local, remote_code: remote }));
			if (entrySeq.get(entry) !== seq) return;
			applyRow(entry, data.row || {});
			if (entry.dataset.state !== 'error') entry.dataset.checkedFor = signature;
		} catch (error) {
			if (entrySeq.get(entry) !== seq) return;
			entry.dataset.checkedFor = '';
			setEntryState(entry, 'error', { flag: 'خطا · نیاز به بررسی', tone: 'danger', text: error.message });
			setEntrySelectable(entry, false);
		}
		if (entrySeq.get(entry) === seq) setEntryLoading(entry, false);
		updateConnectAvailability();
	}

	function scheduleEntryCheck(entry, delay) {
		clearTimeout(entryTimers.get(entry));
		entryTimers.set(entry, window.setTimeout(() => { checkEntry(entry); }, typeof delay === 'number' ? delay : 500));
	}

	// جفت‌هایی که با SKU یکتا تطبیق نشده‌اند با نشان «بررسی» یا «پیشنهاد» علامت می‌خورند.
	function flagMatchEntry(entry, match) {
		if (!entry || !match) return;
		const confidence = match.confidence ? String(match.confidence) : 'manual';
		entry.dataset.confidence = confidence;
		const info = matchFlags[confidence] || matchFlags[match.matched_by] || matchFlags.label;
		const tone = 'exact' === confidence ? 'ok' : 'warn';
		setEntryFlag(entry, info.text, tone, info.cls, info.hint);
		const parts = entryParts(entry);
		const shared = Array.isArray(match.shared) ? match.shared.map(String) : [];
		const extra = Array.isArray(match.extra) ? match.extra.map(String) : [];
		if (parts.note) {
			if (shared.length || extra.length) {
				const details = [];
				if (shared.length) details.push('مدل مشترک: ' + shared.join('، '));
				if (extra.length) details.push('فقط در برچسب مقابل: ' + extra.join('، '));
				parts.note.textContent = details.join(' · ');
				parts.note.hidden = false;
			} else {
				parts.note.textContent = '';
				parts.note.hidden = true;
			}
		}
	}

	function pairEntries() {
		return Array.from(document.querySelectorAll('#tcas-pair-entries .tcas-pair-entry'));
	}

	function updateConnectAvailability() {
		const selectable = pairEntries().filter((entry) => {
			const box = entry.querySelector('.tcas-row-select');
			return box && !box.disabled;
		}).length;
		if (!state.running) {
			const connect = $('tcas-connect-start');
			if (connect) connect.disabled = selectable === 0;
			const selectAll = $('tcas-select-valid');
			if (selectAll) selectAll.disabled = selectable === 0;
		}
		return selectable;
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
		updateConnectAvailability();
	}

	/** جفت‌های هر کارت؛ ترتیب آرایه با index پاسخ سرور یکی است. */
	function collectPairs(entries) {
		const list = Array.isArray(entries) ? entries : pairEntries();
		const usable = [];
		const lines = [];
		let incomplete = 0;
		let invalid = 0;
		list.forEach((entry) => {
			const { local, remote } = entryCodes(entry);
			if (!local && !remote) return;
			if (!local || !remote) {
				incomplete++;
				return;
			}
			if (/^(?:[a-z]+:\/\/|www\.)/i.test(local) || /^(?:[a-z]+:\/\/|www\.)/i.test(remote)) {
				invalid++;
				return;
			}
			usable.push(entry);
			lines.push(local + ' - ' + remote);
		});
		return { lines, entries: usable, incomplete, invalid };
	}

	function wirePairEntry(entry) {
		const remove = entry.querySelector('.tcas-remove-pair');
		if (remove) remove.addEventListener('click', () => {
			if (pairEntries().length <= 1) return;
			entry.remove();
			refreshPairEntries();
			text('tcas-preview-status', 'کارت حذف شد؛ تغییرات فقط در همین صفحه است.');
		});
		const localField = entry.querySelector('.tcas-local-code');
		if (localField) {
			attachProductSearch(localField, {
				filter: (row) => 'variable' !== row.type && 'grouped' !== row.type,
				onPick: (row) => {
					setVariationTitle(entry.querySelector('.tcas-shop-local'), row.name || '');
					entry.dataset.confidence = 'manual';
					scheduleEntryCheck(entry, 120);
				},
				emptyHint: 'محصول ساده یا Variation با این نام/SKU پیدا نشد؛ شناسه را دستی وارد کنید.'
			});
		}
		entry.querySelectorAll('input[type="text"]').forEach((input) => {
			input.addEventListener('input', () => {
				setVariationTitle(input.closest('.tcas-shop-field'), '');
				entry.dataset.confidence = 'manual';
				const parts = entryParts(entry);
				if (parts.note) {
					parts.note.textContent = '';
					parts.note.hidden = true;
				}
				if (parts.stock) parts.stock.hidden = true;
				setEntrySelectable(entry, false);
				updateConnectAvailability();
				// با هر ویرایش، همان کارت بعد از مکث کوتاه دوباره بررسی می‌شود.
				scheduleEntryCheck(entry, 500);
			});
			input.addEventListener('blur', () => {
				const { local, remote } = entryCodes(entry);
				if (local && remote) {
					clearTimeout(entryTimers.get(entry));
					checkEntry(entry);
				}
			});
		});
		entry.querySelector('.tcas-row-select')?.addEventListener('change', updateConnectAvailability);
	}

	function addPairEntry(localCode, remoteCode, focusInput, targetList, confidence) {
		const template = $('tcas-pair-entry-template');
		const list = targetList || $('tcas-pair-entries');
		const max = Number(cfg.maxPairs) || 50;
		if (!template || !list || pairEntries().length >= max) return null;
		const entry = template.content.firstElementChild.cloneNode(true);
		list.appendChild(entry);
		const local = entry.querySelector('.tcas-local-code');
		const remote = entry.querySelector('.tcas-remote-code');
		if (local) local.value = typeof localCode === 'string' ? localCode : '';
		if (remote) remote.value = typeof remoteCode === 'string' ? remoteCode : '';
		entry.dataset.confidence = confidence || 'manual';
		entry.dataset.state = 'idle';
		wirePairEntry(entry);
		refreshPairEntries();
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

	async function fillSmartBatch() {
		if (state.running) return;
		if (!state.smartSignature || state.smartSignature !== smartSignature()) {
			resetSmart();
			text('tcas-smart-status', 'محصول والد تغییر کرده است؛ دوباره تطبیق بدهید.', true);
			return;
		}
		const max = Number(cfg.maxPairs) || 50;
		const start = state.smartOffset;
		const batch = state.smartMatches.slice(start, start + max);
		const issues = 0 === start ? state.smartIssues : [];
		if (!batch.length && !issues.length) {
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
			const entry = addPairEntry(match.local_code, match.remote_code, false, groupEntries, match.confidence || 'manual');
			if (!entry) return;
			setPairEntryTitles(entry, match.local_label, match.remote_label);
			flagMatchEntry(entry, match);
		});
		// کارت قرمز برای Variationهایی که سمت مقابلشان پیدا نشد؛ همان سمت موجود پیش‌پر و سمت دیگر خالی می‌ماند.
		let orphans = 0;
		let skippedOrphans = 0;
		issues.forEach(({ item }) => {
			const reference = item && item.reference ? String(item.reference) : '';
			if (!reference) {
				skippedOrphans++;
				return;
			}
			const isLocal = item.shop === 'tisacase';
			const entry = addPairEntry(isLocal ? reference : '', isLocal ? '' : reference, false, groupEntries, 'orphan');
			if (!entry) {
				skippedOrphans++;
				return;
			}
			if (isLocal) setVariationTitle(entry.querySelector('.tcas-shop-local'), item.label);
			else setVariationTitle(entry.querySelector('.tcas-shop-remote'), item.label);
			const missing = isLocal ? (cfg.partnerLabel || 'ArioCase') : 'TisaCase';
			setEntryState(entry, 'incomplete', {
				flag: 'بدون همتا · ' + missing + ' خالی',
				tone: 'danger',
				text: 'همتایی برای این Variation پیدا نشد؛ کد ' + missing + ' را دستی وارد کنید یا کارت را حذف کنید.'
			});
			orphans++;
		});
		if (pairEntries().length !== Math.min(batch.length, max) + orphans) {
			resetSmart();
			text('tcas-smart-status', 'ساخت کارت‌های جفت کامل نشد؛ دوباره تلاش کنید.', true);
			return;
		}
		state.smartOffset = start + batch.length;
		const excluded = state.smartAmbiguous + state.smartUnmatched;
		const flagged = batch.filter((match) => match && match.confidence && 'exact' !== match.confidence).length;
		const familyCount = batch.filter((match) => match && 'family' === match.confidence).length;
		const suggestedCount = batch.filter((match) => match && 'suggested' === match.confidence).length;
		let message = smartCoverage() + String(batch.length) + ' جفت آماده شد (' + String(state.smartOffset) + '/' + String(state.smartMatches.length) + ').';
		const smartParts = [];
		if (familyCount) smartParts.push(String(familyCount) + ' جفت از خانوادهٔ مدل');
		if (suggestedCount) smartParts.push(String(suggestedCount) + ' پیشنهاد خانوادگی');
		if (smartParts.length) message += ' ' + smartParts.join(' و ') + ' تشخیص داده شد و با نشان «نیاز به بررسی» علامت خورد.';
		if (flagged && !smartParts.length) message += ' ' + String(flagged) + ' جفت بدون SKU یکتا و با نشان «نیاز به بررسی» علامت خورد.';
		if (excluded) message += ' ' + String(excluded) + ' مورد مبهم/بدون تطبیق با دلیل مشخص در فهرست زیر علامت‌گذاری شد.';
		if (orphans) message += ' ' + String(orphans) + ' کارت قرمز برای Variationهای بدون همتا ساخته شد.';
		if (skippedOrphans) message += ' ' + String(skippedOrphans) + ' مورد به‌خاطر ظرفیت یا نبود مرجع کارت نگرفت.';
		message += state.smartOffset < state.smartMatches.length ? ' پس از اتصال، «دسته بعد» را بزنید.' : ' موجودی تازهٔ هر کارت پایین همان کارت خوانده می‌شود.';
		text('tcas-smart-status', message);
		const next = $('tcas-smart-next');
		if (next) next.hidden = state.smartOffset >= state.smartMatches.length;
		if (pairEntries().some((entry) => {
			const codes = entryCodes(entry);
			return codes.local && codes.remote;
		})) {
			await refreshAllCards($('tcas-preview-start'), { quiet: true });
		}
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
			const reviewable = state.smartIssues.filter(({ item }) => item && item.reference).length;
			if (!state.smartMatches.length) {
				const excluded = state.smartAmbiguous + state.smartUnmatched;
				state.running = false;
				if (reviewable) {
					await fillSmartBatch();
					text('tcas-smart-status', smartCoverage() + 'جفت یکتایی پیدا نشد' + '؛ ' + String(reviewable) + ' کارت قرمز برای بررسی دستی ساخته شد' + (excluded ? ' و ' + String(excluded) + ' مورد با دلیل مشخص در فهرست زیر علامت‌گذاری شد.' : '.'), true);
				} else {
					text('tcas-smart-status', smartCoverage() + 'جفت یکتایی پیدا نشد' + (excluded ? '؛ ' + String(excluded) + ' مورد مبهم/بدون تطبیق با دلیل مشخص در فهرست زیر علامت‌گذاری شد.' : '.'), true);
				}
			} else {
				state.running = false;
				await fillSmartBatch();
			}
		} catch (error) {
			resetSmart();
			text('tcas-smart-status', error.message, true);
		}
		state.running = false;
		busy(button, false);
	}

	/** ردیف‌های یک پاسخ گام (سازگار با پاسخ تک‌ردیفی نسخه‌های قبل). */
	function stepRows(data) {
		if (data && Array.isArray(data.rows)) return data.rows;
		return data && data.row ? [data.row] : [];
	}

	function stepSize() {
		const size = Number(cfg.stepSize);
		return Number.isFinite(size) && size > 0 ? size : 3;
	}

	/**
	 * گام‌های بررسی را دسته‌ای اجرا می‌کند و در صورت قطع ارتباط از همان‌جا ادامه می‌دهد
	 * (سرور با پارامتر «from» همان بازه را دوباره می‌خواند، پس هیچ جفتی جا نمی‌افتد).
	 */
	async function runSteps(job, onRow, onProgress) {
		const total = Number(job.total) || 0;
		let from = 0;
		let done = false;
		let guard = 0;
		let failures = 0;
		while (!done && guard++ < 4000) {
			let data;
			try {
				data = await request('preview_step', { token: job.token, from, size: stepSize() }, { retries: 2, backoff: 900 });
			} catch (error) {
				failures++;
				if (failures > 2 || from >= total) throw error;
				text('tcas-preview-status', 'ارتباط قطع شد؛ تلاش دوباره از جفت ' + String(from + 1) + ' (' + String(failures) + '/۳)…', true);
				await delay(1500 * failures);
				continue;
			}
			failures = 0;
			stepRows(data).forEach((row) => onRow(row));
			const processed = Number(data.processed);
			from = Number.isFinite(processed) ? processed : from + stepRows(data).length;
			if (typeof onProgress === 'function') onProgress(from, Number(data.total) || total);
			done = Boolean(data.done);
			if (!done && from >= total) done = true;
		}
		return from;
	}

	function summarizeRows(entries, rows) {
		const counts = { ok: 0, review: 0, error: 0 };
		rows.forEach((row) => {
			if (!row) return;
			if (!row.selectable) counts.error++;
			else if ('connected' === row.status) counts.ok++;
			else {
				const entry = entries[Number(row.index)];
				const confidence = entry ? entry.dataset.confidence : 'manual';
				if ('exact' === confidence) counts.ok++;
				else counts.review++;
			}
		});
		return counts;
	}

	/** بررسی گروهی کارت‌ها با همان Job سرور؛ نتیجه روی خود کارت‌ها می‌نشیند. */
	async function refreshAllCards(button, options) {
		if (state.running) return;
		const opts = options || {};
		const collected = collectPairs();
		if (!collected.lines.length) {
			if (!opts.quiet) {
				text('tcas-preview-status', collected.incomplete ? 'همهٔ کارت‌ها یک سمت خالی دارند؛ حداقل یک جفت کامل لازم است.' : 'حداقل یک جفت شناسه وارد کنید.', true);
			}
			return;
		}
		const entries = collected.entries;
		state.running = true;
		state.previewDone = false;
		state.token = '';
		if (!opts.quiet) busy(button, true, 'در حال بررسی…');
		entries.forEach((entry) => setEntryLoading(entry, true));
		if (!opts.quiet) text('tcas-preview-status', 'در حال بررسی ' + collected.lines.length + ' جفت و خواندن موجودی تازه…');
		const rows = [];
		try {
			const job = await request('preview_start', { pairs: collected.lines.join('\n') });
			state.token = job.token;
			progress('tcas-preview', 0, job.total || 0);
			await runSteps(job, (row) => {
				rows.push(row);
				const entry = entries[Number(row.index)];
				if (entry) {
					setEntryLoading(entry, false);
					applyRow(entry, row);
				}
			}, (processed, total) => progress('tcas-preview', processed, total));
			state.previewDone = true;
			const counts = summarizeRows(entries, rows);
			const parts = [];
			if (counts.ok) parts.push(counts.ok + ' قطعی');
			if (counts.review) parts.push(counts.review + ' نیاز به بررسی');
			if (counts.error) parts.push(counts.error + ' غیرقابل اتصال');
			if (collected.incomplete) parts.push(collected.incomplete + ' کارت ناقص');
			let summary = 'بررسی تمام شد — ' + (parts.join(' · ') || 'بدون نتیجه') + '.';
			if ('rest' === state.transport) summary += ' درخواست‌ها از مسیر REST همین سایت انجام شد (admin-ajax توسط هاست مسدود شده بود).';
			text('tcas-preview-status', summary);
		} catch (error) {
			text('tcas-preview-status', error.message, true);
		}
		entries.forEach((entry) => setEntryLoading(entry, false));
		state.running = false;
		if (!opts.quiet) busy(button, false);
		updateConnectAvailability();
	}

	function applyConnectRow(entry, row) {
		if (!entry || !row) return false;
		const parts = entryParts(entry);
		setEntryLoading(entry, false);
		if ('done' === row.status) {
			setEntryState(entry, 'done', { flag: 'متصل شد · موجودی به‌روز شد', tone: 'ok', text: row.message || '' });
			setEntrySelectable(entry, false);
			if (row.edit_url && parts.message) {
				const link = node('a', 'tcas-pair-edit-link', 'بازکردن محصول تیساکیس');
				link.href = row.edit_url;
				if (parts.message.textContent) parts.message.appendChild(document.createTextNode(' '));
				parts.message.appendChild(link);
			}
			return true;
		}
		setEntryState(entry, 'error', { flag: 'خطا · نیاز به بررسی', tone: 'danger', text: row.message || '' });
		setEntrySelectable(entry, false);
		return false;
	}

	async function startConnect() {
		if (state.running) return;
		const selected = pairEntries().filter((entry) => {
			const box = entry.querySelector('.tcas-row-select');
			return box && !box.disabled && box.checked;
		});
		if (!selected.length) {
			text('tcas-connect-status', (cfg.strings && cfg.strings.selectRows) || 'حداقل یک کارت قابل اتصال را انتخاب کنید.', true);
			return;
		}
		const pairs = selected.map((entry) => entryCodes(entry)).filter((codes) => codes.local && codes.remote);
		if (!pairs.length) {
			text('tcas-connect-status', 'کارت‌های انتخابی شناسهٔ کامل ندارند.', true);
			return;
		}
		const button = $('tcas-connect-start');
		state.running = true;
		busy(button, true, 'در حال اتصال…');
		text('tcas-connect-status', 'در حال بررسی تازهٔ موجودی و اتصال ' + pairs.length + ' جفت…');
		selected.forEach((entry) => setEntryLoading(entry, true));
		try {
			const job = await request('preview_start', { pairs: pairs.map((codes) => codes.local + ' - ' + codes.remote).join('\n') });
			state.token = job.token;
			const order = [];
			await runSteps(job, (row) => {
				const entry = selected[Number(row.index)];
				order[Number(row.index)] = row;
				if (entry) {
					setEntryLoading(entry, false);
					applyRow(entry, row);
				}
			}, (processed, total) => progress('tcas-preview', processed, total));
			const indexes = order.filter((row) => row && row.selectable).map((row) => Number(row.index));
			if (!indexes.length) throw new Error('هیچ‌کدام از کارت‌های انتخابی الان قابل اتصال نیست؛ پیام همان کارت را ببینید.');
			const connect = await request('connect_start', { token: job.token, pairs: indexes });
			progress('tcas-connect', 0, connect.total || 0);
			let okCount = 0;
			let failCount = 0;
			let from = 0;
			let finished = false;
			let guard2 = 0;
			let failures = 0;
			while (!finished && guard2++ < 4000) {
				let data;
				try {
					data = await request('connect_step', { token: job.token, from, size: stepSize() }, { retries: 2, backoff: 900 });
				} catch (error) {
					failures++;
					if (failures > 2 || from >= (Number(connect.total) || 0)) throw error;
					text('tcas-connect-status', 'ارتباط قطع شد؛ تلاش دوباره برای اتصال از مورد ' + String(from + 1) + '…', true);
					await delay(1500 * failures);
					continue;
				}
				failures = 0;
				stepRows(data).forEach((row) => {
					const index = typeof row.index === 'number' ? Number(row.index) : -1;
					const entry = index >= 0 ? selected[index] : null;
					if (applyConnectRow(entry, row)) okCount++;
					else failCount++;
				});
				const processed = Number(data.processed);
				from = Number.isFinite(processed) ? processed : from + stepRows(data).length;
				progress('tcas-connect', from, Number(data.total) || Number(connect.total) || 0);
				finished = Boolean(data.done);
			}
			text('tcas-connect-status', okCount + ' جفت متصل و فقط موجودی به‌روز شد' + (failCount ? ' · ' + failCount + ' جفت خطا داشت' : '') + '. ');
			const status = $('tcas-connect-status');
			if (status && cfg.mappingsUrl) {
				const link = node('a', 'tcas-connected-groups-link', 'مشاهدهٔ کارت‌های محصولات متصل');
				link.href = cfg.mappingsUrl;
				status.appendChild(link);
			}
		} catch (error) {
			text('tcas-connect-status', error.message, true);
		}
		selected.forEach((entry) => setEntryLoading(entry, false));
		state.running = false;
		busy(button, false);
		updateConnectAvailability();
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

	function showLinkPayload(data) {
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
			return;
		}
		const reference = data.reference || data.sku || (data.product_id ? 'ID:' + String(data.product_id) : '');
		setLinkOutput(reference, (data.shop_label || 'محصول') + (/^ID:/i.test(reference) ? ' · ID' : ' · SKU'), productDiagnostic(data));
		text('tcas-link-status', reference ? 'شناسه آمادهٔ کپی است.' : 'شناسه پیدا نشد.', !reference);
	}

	async function resolveLink() {
		const input = $('tcas-link-input');
		const button = $('tcas-link-resolve');
		const url = input ? input.value.trim() : '';
		if (!url || !/^https?:\/\//i.test(url)) { text('tcas-link-status', 'لینک کامل محصول را وارد کنید یا نام/SKU را جست‌وجو کنید.', true); return; }
		resetLinkResult();
		busy(button, true, 'در حال تشخیص…');
		text('tcas-link-status', 'در حال تشخیص فروشگاه و دریافت شناسه…');
		try {
			showLinkPayload(await request('resolve_link', { url }));
		} catch (error) { text('tcas-link-status', error.message, true); }
		busy(button, false);
	}

	/** انتخاب از فهرست جست‌وجو: محصول ساده بدون هیچ درخواستی پاسخ می‌دهد. */
	async function selectSearchedProduct(row) {
		resetLinkResult();
		const reference = String(row.reference || '');
		if ('variable' === row.type) {
			const button = $('tcas-link-resolve');
			busy(button, true, 'در حال خواندن Variationها…');
			text('tcas-link-status', 'در حال خواندن Variationهای «' + (row.name || reference) + '» از همین سایت…');
			try {
				showLinkPayload(await enqueuePreview(() => request('search_products', { product_id: row.product_id })));
			} catch (error) {
				text('tcas-link-status', error.message, true);
			}
			busy(button, false);
			return;
		}
		showLinkPayload({
			shop: 'tisacase',
			shop_label: 'TisaCase',
			sku: row.sku || '',
			reference: reference,
			name: row.name || reference,
			product_id: row.product_id,
			parent_id: row.parent_id || 0,
			type: row.type || 'simple',
			variations: []
		});
		text('tcas-link-status', 'محصول از فهرست انتخاب شد؛ بدون ارسال لینک و بدون درخواست به همکار.');
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
	if (pairAdd) pairAdd.addEventListener('click', () => {
		const entry = addPairEntry();
		if (entry) setEntryState(entry, 'idle', { flag: 'در انتظار شناسه', tone: 'idle', text: '' });
	});
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
	const smartLocalInput = $('tcas-smart-local');
	if (smartLocalInput) {
		attachProductSearch(smartLocalInput, {
			host: smartLocalInput.closest('.tcas-shop-field') || smartLocalInput.parentElement,
			onPick: (row) => {
				text('tcas-smart-status', 'محصول «' + (row.name || row.reference) + '» انتخاب شد؛ شناسه: ' + row.reference + '. اکنون مرجع همکار را وارد و «یافتن جفت‌ها» را بزنید.');
			},
			emptyHint: 'محصولی پیدا نشد؛ می‌توانید شناسه یا لینک را دستی وارد کنید.'
		});
	}
	const previewButton = $('tcas-preview-start');
	if (previewButton) previewButton.addEventListener('click', () => refreshAllCards(previewButton));
	const connectButton = $('tcas-connect-start');
	if (connectButton) connectButton.addEventListener('click', startConnect);
	const selectValid = $('tcas-select-valid');
	if (selectValid) selectValid.addEventListener('click', () => {
		const boxes = Array.from(document.querySelectorAll('.tcas-row-select:not(:disabled)'));
		const allChecked = boxes.length && boxes.every((box) => box.checked);
		boxes.forEach((box) => { box.checked = !allChecked; });
		updateConnectAvailability();
	});
	const resolveButton = $('tcas-link-resolve');
	if (resolveButton) resolveButton.addEventListener('click', resolveLink);
	const linkInput = $('tcas-link-input');
	if (linkInput) {
		linkInput.addEventListener('input', () => {
			text('tcas-link-status', '');
			if (!looksLikeLink(linkInput.value)) resetLinkResult();
		});
		linkInput.addEventListener('keydown', (event) => {
			if (event.key !== 'Enter') return;
			event.preventDefault();
			if (looksLikeLink(linkInput.value)) resolveLink();
		});
		attachProductSearch(linkInput, {
			host: linkInput.closest('.tcas-converter-source') || linkInput.parentElement,
			onPick: selectSearchedProduct,
			emptyHint: 'محصولی با این نام یا SKU در TisaCase پیدا نشد؛ می‌توانید لینک کامل را بچسبانید.'
		});
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
	if (pairEntries().some((entry) => {
		const codes = entryCodes(entry);
		return codes.local && codes.remote;
	})) {
		refreshAllCards(null, { quiet: true });
	}
	document.querySelectorAll('.tcas-sync-one').forEach((button) => button.addEventListener('click', () => simpleAction(button, 'sync_mapping', { product_id: button.dataset.productId }, 'tcas-sync-status')));
	document.querySelectorAll('.tcas-disconnect').forEach((button) => button.addEventListener('click', () => {
		if (!window.confirm((cfg.strings && cfg.strings.disconnect) || 'ادامه؟')) return;
		simpleAction(button, 'disconnect_mapping', { product_id: button.dataset.productId }, 'tcas-sync-status');
	}));
	const clearLogs = $('tcas-clear-logs');
	if (clearLogs) clearLogs.addEventListener('click', () => simpleAction(clearLogs, 'clear_logs', {}, 'tcas-log-status'));
}());
