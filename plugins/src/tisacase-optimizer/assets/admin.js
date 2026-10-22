/* global jQuery, tcoBoot */
/**
 * TisaCase Optimizer — کنترلر کامل رابط کاربری (همهٔ ۵ ماژول + ۸ زیرتب تخصصی تصاویر)
 */
(function ($) {
	'use strict';

	const boot = window.tcoBoot || {};

	/** Escape زیرمنوهای کشویی وردپرس وقتی منو ثابت است (مطابق قرارداد TisaCase Hub). */
	function menuFlyoutEscape() {
		const wrap = document.getElementById('adminmenuwrap');
		if (!wrap || 'fixed' !== window.getComputedStyle(wrap).position) {
			return;
		}
		$('#adminmenu li.menu-top').on('mouseenter.tco focusin.tco', function () {
			const sub = this.querySelector('.wp-submenu');
			if (!sub || this.classList.contains('wp-has-current-submenu')) {
				return;
			}
			const rect = this.getBoundingClientRect();
			sub.style.position = 'fixed';
			sub.style.top = Math.max(32, Math.min(rect.top, window.innerHeight - sub.offsetHeight - 12)) + 'px';
			if ('rtl' === document.documentElement.dir) {
				sub.style.right = wrap.offsetWidth + 'px';
				sub.style.left = 'auto';
			} else {
				sub.style.left = wrap.offsetWidth + 'px';
				sub.style.right = 'auto';
			}
		});
	}

	$(menuFlyoutEscape);

	const FA_DIGITS = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
	function faNum(val) {
		const num = Number(val || 0);
		const formatted = Number.isFinite(num) ? num.toLocaleString('en-US') : String(val);
		return formatted.replace(/\d/g, (d) => FA_DIGITS[Number(d)]);
	}

	function faTextDigits(str) {
		return String(str || '').replace(/\d/g, (d) => FA_DIGITS[Number(d)]);
	}

	function formatBytes(bytes) {
		const b = Math.max(0, Number(bytes || 0));
		if (b === 0) return '۰ بایت';
		const units = ['بایت', 'کیلوبایت', 'مگابایت', 'گیگابایت'];
		let idx = 0;
		let v = b;
		while (v >= 1024 && idx < units.length - 1) {
			v /= 1024;
			idx += 1;
		}
		const str = idx === 0 ? Math.round(v).toString() : v.toFixed(v >= 10 ? 1 : 2);
		return faTextDigits(str) + ' ' + units[idx];
	}

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	const ICONS = {
		image: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="8.5" cy="9.5" r="1.5"/><path d="M21 15l-5-5L5 20"/></svg>',
		shield: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l8 3v6c0 5-3.5 8-8 9-4.5-1-8-4-8-9V6z"/></svg>',
		database: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>',
		bolt: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M13 3L5 14h5l-1 7 8-11h-5z"/></svg>',
		activity: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>',
	};

	const state = {
		module: boot.initialModule || 'images',
		subtab: boot.initialTab || 'overview',
		settings: Object.assign({}, boot.settings || {}),
		overview: null,
		media: null,
		duplicates: null,
		subsizes: null,
		preflight: null,
		seo: null,
		vault: null,
		vaultTab: 'quarantine',
		vaultSearch: '',
		selectedQuarantineIds: new Set(),
		selectedIds: new Set(),
		selectedPaths: new Set(),
		masterChoices: {},
		deleteMode: (boot.settings && boot.settings.delete_mode) || 'quarantine',
		// «تعداد در هر صفحه» — انتخاب کاربر و ذخیره‌شده در تنظیمات (۰ = همه).
		perPage: Number((boot.settings && boot.settings.per_page) || 25),
		dupPage: 1,
		armedKey: null,
		armedTimer: null,
		batch: {
			running: false,
			stopRequested: false,
			title: '',
			op: 'remove',
			blockReasons: [],
			blockedSample: {},
			done: 0,
			total: 0,
			savedBytes: 0,
		},
		scan: {
			running: false,
			fastFinishRequested: false,
			canSkip: false,
			stageNum: 1,
			stageLabel: 'آماده‌سازی موتور اسکن سریع…',
			detailLabel: '',
			percent: 5,
			elapsedSec: 0,
		},
		loadError: '',
		explorerFilter: {
			search: '',
			sort_by: 'total_bytes',
			order: 'desc',
			min_bytes: 0,
			format: 'ALL',
			usage: 'all',
			opt_state: 'all',
			page: 1,
		},
		unusedFilter: {
			usage: 'strict_unused',
			sort_by: 'total_bytes',
			order: 'desc',
			search: '',
			page: 1,
		},
		seoFilter: 'missing_alt',
		seoPage: 1,
		// خلاصهٔ آخرین عملیات حذف/فشرده‌سازی برای نمایش شفاف «چه شد و چرا».
		lastRun: null,
		// کار نیمه‌تمامِ ذخیره‌شده در سرور (برای کارت «ادامه»).
		activeJob: null,
		conflictRun: null,
	};

	function toast(msg, kind = 'success') {
		const box = document.getElementById('tco-toasts');
		if (!box) return;
		const el = document.createElement('div');
		el.className = 'tco-toast tco-toast--' + kind;
		el.textContent = msg;
		box.appendChild(el);
		setTimeout(() => {
			if (el.parentNode) el.parentNode.removeChild(el);
		}, 3600);
	}

	function armConfirm(key) {
		if (state.armedKey === key) {
			state.armedKey = null;
			clearTimeout(state.armedTimer);
			return true;
		}
		state.armedKey = key;
		clearTimeout(state.armedTimer);
		state.armedTimer = setTimeout(() => {
			state.armedKey = null;
			render();
		}, 4500);
		render();
		return false;
	}

	/* ── کنترلر سراسری «در حال انجام» ───────────────────────────────
	   هدف: هیچ کلیکی بی‌پاسخ نماند. هر درخواست شبکه یک شمارنده بالا می‌برد و
	   نوار باریک بالای صفحه + چیپ شناور با برچسب فارسی و زمان سپری‌شده نشان
	   داده می‌شود. عملیاتِ اندازه‌پذیر (دسته‌ای) درصد هم می‌گیرد. */
	const OP_LABELS = {
		overview: 'خواندن آمار رسانه…',
		query_media: 'آماده‌سازی فهرست تصاویر…',
		get_vault: 'بارگذاری خزانهٔ پشتیبان…',
		get_duplicates: 'تشخیص تصاویر تکراری…',
		get_seo: 'ممیزی متن جایگزین تصاویر…',
		get_subsizes: 'خواندن آمار زیرسایزها…',
		preflight: 'در حال اجرای چکاپ سرور…',
		scan_step: 'اسکن لایه‌های استفاده…',
		remove_items: 'حذف ایمن تصاویر…',
		job_status: 'خواندن وضعیت عملیات نیمه‌تمام…',
		job_cancel: 'رهاکردن عملیات نیمه‌تمام…',
		inspect_sizes: 'تفکیک زیرسایزهای تصویر…',
		optimize_batch: 'فشرده‌سازی تصاویر…',
		clean_unscaled: 'پاک‌سازی فایل‌های خام -scaled…',
		merge_duplicates: 'ادغام تصاویر تکراری…',
		regen_batch: 'بازسازی بندانگشتی‌ها…',
		save_subsizes: 'ذخیرهٔ تنظیمات زیرسایز…',
		clean_disabled_subsizes: 'پاک‌سازی زیرسایزهای غیرفعال…',
		save_settings: 'ذخیرهٔ تنظیمات…',
		toggle_pin: 'به‌روزرسانی لیست سفید…',
		save_single_seo: 'ذخیرهٔ متن جایگزین…',
		bulk_generate_seo: 'تولید گروهی Alt و Title…',
		restore_quarantine: 'بازگردانی از قرنطینه…',
		restore_original: 'بازگردانی فایل اولیه…',
		purge_quarantine: 'تخلیهٔ دائمی قرنطینه…',
		purge_originals: 'پاک‌سازی پشتیبان‌های اولیه…',
		clear_history: 'پاک‌سازی تاریخچه…',
	};

	const busy = {
		count: 0,
		label: '',
		startedAt: 0,
		pct: null,
		timer: null,
		els: null,
		queue: [],
	};

	function busyEnsureEls() {
		if (busy.els && document.body.contains(busy.els.bar)) return busy.els;

		const bar = document.createElement('div');
		bar.className = 'tco-busybar';
		bar.setAttribute('role', 'progressbar');
		bar.setAttribute('aria-hidden', 'true');
		bar.innerHTML = '<span></span>';

		const chip = document.createElement('div');
		chip.className = 'tco-busy-chip';
		chip.setAttribute('role', 'status');
		chip.setAttribute('aria-live', 'polite');
		chip.innerHTML =
			'<span class="tco-spinner tco-spinner--sm" aria-hidden="true"></span>' +
			'<span class="tco-busy-chip__label"></span>' +
			'<span class="tco-busy-chip__pct"></span>' +
			'<span class="tco-busy-chip__time"></span>';

		document.body.appendChild(bar);
		document.body.appendChild(chip);

		busy.els = { bar, chip, fill: bar.firstChild, label: chip.querySelector('.tco-busy-chip__label'), pct: chip.querySelector('.tco-busy-chip__pct'), time: chip.querySelector('.tco-busy-chip__time') };
		return busy.els;
	}

	function busyTick() {
		const els = busy.els;
		if (!els) return;
		const secs = Math.max(0, Math.round((Date.now() - busy.startedAt) / 1000));
		els.time.textContent = secs >= 1 ? faNum(secs) + ' ثانیه' : '';
	}

	/**
	 * شروع یک عملیات طولانی. مقدار برگشتی را به busyEnd بدهید.
	 * @param {string} label برچسب فارسی عملیات.
	 * @param {object} opts  {pct:number|null, silentDelay:boolean}
	 * @returns {object} توکن پایان عملیات.
	 */
	function busyStart(label, opts = {}) {
		const token = { label: label || 'در حال انجام…', done: false, pct: null };
		busy.count += 1;
		busy.queue.push(token);

		if (busy.count === 1) {
			busy.startedAt = Date.now();
			busy.pct = typeof opts.pct === 'number' ? opts.pct : null;
			const els = busyEnsureEls();
			els.label.textContent = token.label;
			els.bar.setAttribute('aria-hidden', 'false');
			els.bar.setAttribute('aria-valuetext', token.label);
			busyApplyPct();

			// ضدپرش: اگر عملیات زیر ۲۲۰ میلی‌ثانیه تمام شود، چیزی نشان نده.
			busy.showTimer = setTimeout(() => {
				if (busy.count === 0) return;
				els.bar.classList.add('is-on');
				els.chip.classList.add('is-on');
				busy.timer = setInterval(busyTick, 500);
				busyTick();
			}, 220);
		} else {
			const els = busyEnsureEls();
			els.label.textContent = token.label;
		}
		return token;
	}

	function busyApplyPct() {
		const els = busy.els;
		if (!els) return;
		if (typeof busy.pct === 'number') {
			const pct = Math.max(0, Math.min(100, Math.round(busy.pct)));
			els.bar.classList.add('is-determinate');
			els.fill.style.width = pct + '%';
			els.bar.setAttribute('aria-valuenow', String(pct));
			els.pct.textContent = faNum(pct) + '٪';
		} else {
			els.bar.classList.remove('is-determinate');
			els.fill.style.width = '';
			els.bar.removeAttribute('aria-valuenow');
			els.pct.textContent = '';
		}
	}

	/**
	 * به‌روزرسانی درصد عملیات جاری (برای دسته‌های اندازه‌پذیر).
	 * @param {number|null} pct درصد ۰ تا ۱۰۰.
	 * @param {string}      label برچسب تازه (اختیاری).
	 */
	function busyProgress(pct, label) {
		if (busy.count === 0) return;
		busy.pct = typeof pct === 'number' ? pct : null;
		const els = busyEnsureEls();
		if (label) els.label.textContent = label;
		busyApplyPct();
	}

	function busyEnd(token) {
		if (!token || token.done) return;
		token.done = true;
		busy.count = Math.max(0, busy.count - 1);
		busy.queue = busy.queue.filter((t) => !t.done);

		const els = busy.els;
		if (busy.count > 0) {
			const next = busy.queue[busy.queue.length - 1];
			if (next && els) els.label.textContent = next.label;
			return;
		}

		clearTimeout(busy.showTimer);
		clearInterval(busy.timer);
		busy.timer = null;
		busy.pct = null;
		if (els) {
			els.bar.classList.remove('is-on', 'is-determinate');
			els.chip.classList.remove('is-on');
			els.bar.setAttribute('aria-hidden', 'true');
			els.time.textContent = '';
			els.pct.textContent = '';
			els.fill.style.width = '';
		}
	}

	/**
	 * اجرای یک عملیات با بازخورد کامل: قفل دکمه + نوار + چیپ + مدیریت خطا.
	 * @param {Element|null} el    دکمه/کنترل کلیک‌شده.
	 * @param {string}       label برچسب فارسی.
	 * @param {Function}     fn    تابع async عملیات.
	 * @returns {Promise<*>}
	 */
	async function withBusy(el, label, fn) {
		const token = busyStart(label);
		let marked = null;
		const markTimer = el
			? setTimeout(() => {
					el.classList.add('tco-is-busy');
					el.setAttribute('aria-busy', 'true');
					marked = el;
			  }, 220)
			: null;

		try {
			return await fn();
		} catch (err) {
			toast((err && err.message) || 'عملیات انجام نشد.', 'error');
			return undefined;
		} finally {
			clearTimeout(markTimer);
			if (marked) {
				marked.classList.remove('tco-is-busy');
				marked.removeAttribute('aria-busy');
			}
			busyEnd(token);
		}
	}

	async function api(op, payload = {}) {
		const body = new URLSearchParams();
		body.set('action', boot.action || 'tisacase_optimizer');
		body.set('nonce', boot.nonce || '');
		body.set('op', op);

		Object.keys(payload).forEach((k) => {
			const val = payload[k];
			if (val === undefined || val === null) return;
			if (Array.isArray(val)) {
				if (val.length === 0) {
					body.set(k, '');
				} else {
					val.forEach((v) => body.append(k + '[]', String(v)));
				}
			} else if (typeof val === 'object') {
				Object.keys(val).forEach((subk) => {
					const sv = val[subk];
					if (Array.isArray(sv)) {
						sv.forEach((item) => body.append(`${k}[${subk}][]`, String(item)));
					} else {
						body.set(`${k}[${subk}]`, String(sv));
					}
				});
			} else {
				body.set(k, String(val));
			}
		});

		const ctrl = typeof AbortController !== 'undefined' ? new AbortController() : null;
		const timer = ctrl ? setTimeout(() => ctrl.abort(), 28000) : null;

		// هر درخواست، حتی آن‌هایی که دکمه‌ای ندارند (بارگذاری خودکار، اسکن مرحله‌ای،
		// به‌روزرسانی خزانه) بازخورد بصری می‌گیرد؛ عملیات دسته‌ای درصد را خودش
		// با busyProgress به‌روز می‌کند.
		const token = busyStart(OP_LABELS[op] || 'در حال ارتباط با سرور…');

		let res;
		try {
			res = await window.fetch(boot.ajaxUrl || 'admin-ajax.php', {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
				body: body.toString(),
				credentials: 'same-origin',
				signal: ctrl ? ctrl.signal : undefined,
			});
		} catch (netErr) {
			if (timer) clearTimeout(timer);
			busyEnd(token);
			const timedOut = netErr && netErr.name === 'AbortError';
			throw new Error(
				timedOut
					? 'پاسخ سرور بیش از ۲۸ ثانیه طول کشید (Timeout شبکه/فایروال).'
					: 'ارتباط با سرور قطع شد؛ اتصال اینترنت یا فایروال هاست را بررسی کنید.'
			);
		}
		if (timer) clearTimeout(timer);

		let rawText = '';
		try {
			rawText = await res.text();
		} finally {
			busyEnd(token);
		}
		let json = null;
		try {
			json = JSON.parse(rawText);
		} catch (_parseErr) {
			const cleanSnippet = String(rawText || '')
				.replace(/<[^>]+>/g, ' ')
				.replace(/\s+/g, ' ')
				.trim()
				.slice(0, 140);
			throw new Error(
				`خطای پاسخ سرور (کد HTTP ${res.status}): پاسخ معتبر JSON دریافت نشد${cleanSnippet ? ' — ' + cleanSnippet : ''}`
			);
		}

		if (!json || !json.success) {
			const payload = (json && json.data) || {};
			const msg = payload.message || `خطا در پردازش درخواست سرور (HTTP ${res.status})`;
			const err = new Error(msg);
			// کد وضعیت و دادهٔ همراه خطا حفظ می‌شود تا رابط بتواند دقیقاً بگوید
			// چه کاری مانع شده و «ادامه/رهاکردن» را همان‌جا پیشنهاد دهد.
			err.status = res.status;
			err.data = payload;
			err.isConflict = Boolean(payload.conflict) || res.status === 409;
			throw err;
		}
		return json.data;
	}

	function renderNav() {
		const nav = document.getElementById('tco-module-nav');
		if (!nav) return;
		const modules = boot.modules || [];
		const qCount = state.overview && state.overview.vault ? state.overview.vault.quarantine_count : 0;

		nav.innerHTML = modules
			.map((m) => {
				const isAct = m.id === state.module;
				let badge = m.badge || '';
				if (m.id === 'vault' && qCount > 0) {
					badge = faNum(qCount) + ' مورد';
				}
				return `<button type="button" class="tco-nav__btn${isAct ? ' is-active' : ''}" data-go-module="${esc(m.id)}">
					${ICONS[m.icon] || ICONS.bolt}
					<span>${esc(m.title)}</span>
					${badge ? `<span class="tco-nav__tag">${esc(badge)}</span>` : ''}
				</button>`;
			})
			.join('');
	}

	function getRecoverableCount() {
		if (state.vault && state.vault.summary) {
			return (state.vault.summary.quarantine_count || 0) + (state.vault.summary.originals_count || 0);
		}
		if (state.overview && state.overview.vault) {
			return (state.overview.vault.quarantine_count || 0) + (state.overview.vault.originals_count || 0);
		}
		if (boot.moduleBoot && boot.moduleBoot.vault && boot.moduleBoot.vault.summary) {
			return (boot.moduleBoot.vault.summary.quarantine_count || 0) + (boot.moduleBoot.vault.summary.originals_count || 0);
		}
		return 0;
	}

	function renderImageSubnav() {
		const imgMod = (boot.modules || []).find((m) => m.id === 'images');
		const subtabs = (imgMod && imgMod.subtabs) || {};
		const ov = state.overview;
		const recCount = getRecoverableCount();
		const counts = {
			explorer: ov ? faNum(ov.total_images) : '',
			unused: ov ? faNum(ov.quick_wins.unused.count + ov.quick_wins.unused.orphan_count) : '',
			restore: recCount > 0 ? faNum(recCount) : '',
			compress: ov ? faNum(ov.quick_wins.compress.count) : '',
			duplicates: ov ? faNum(ov.quick_wins.duplicates.groups) : '',
			seo: ov ? faNum(ov.quick_wins.seo.missing_alt) : '',
		};

		const heroBtn = document.getElementById('tco-hero-vault-btn');
		if (heroBtn) {
			heroBtn.textContent = recCount > 0 ? `پشتیبان و بازگردانی (${faNum(recCount)})` : 'پشتیبان و بازگردانی';
		}

		return `<div class="tco-subnav" role="tablist">
			${Object.keys(subtabs)
				.map((k) => {
					const active = state.subtab === k;
					const badge = counts[k] ? `<span class="tco-subnav__badge">${counts[k]}</span>` : '';
					return `<button type="button" role="tab" aria-selected="${active}" class="tco-subnav__btn${active ? ' is-active' : ''}" data-go-subtab="${esc(k)}">
						<span>${esc(subtabs[k])}</span>${badge}
					</button>`;
				})
				.join('')}
		</div>`;
	}

	/**
	 * کارت «عملیات نیمه‌تمام» — قلبِ رفع مشکل «نمی‌دانم چقدر حذف شد».
	 * همیشه تعداد انجام‌شده/کل، باقی‌مانده و دلیل توقف را نشان می‌دهد و
	 * اجازهٔ ادامه یا رهاکردن می‌دهد.
	 */
	function renderResumeCard() {
		const job = state.activeJob;
		if (!job || state.batch.running) return '';

		const done = Number(job.done || 0);
		const total = Number(job.total || 0);
		const remaining = Number(job.remaining != null ? job.remaining : Math.max(0, total - done));
		const pct = total > 0 ? Math.min(100, Math.round((done / total) * 100)) : 0;
		const blocked = Number(job.blocked || 0);
		const failed = Number(job.failed || 0);
		const op = job.op || 'remove';
		const opLabel = opLabelOf(op);
		const verifying = op === 'remove' && job.phase === 'verify';
		const reason = job.lastError ? esc(job.lastError) : (verifying ? 'بازرسی ایمنی پیش از حذف در جریان است…' : 'عملیات پیش از پایان متوقف شد');

		// کار «رهاشده» (چند دقیقه بی‌خبر): صریح بگوییم و راه یک‌کلیکی بدهیم.
		const idleMin = Math.max(0, Math.round(Number(job.idleSec || 0) / 60));
		const idleNote = job.stale || idleMin >= 3
			? `<p class="tco-muted tco-hint tco-hint--warn">این کار ${faNum(idleMin)} دقیقه هیچ پیشرفتی نداشته (احتمالاً مرورگر بسته شده)؛ می‌توانید «ادامه» را بزنید یا آن را رها کنید.</p>`
			: '';

		// اگر کاربر عملیات تازه‌ای را شروع کرده و با تعارض روبه‌رو شده، این‌جا
		// با یک کلیک کار قبلی رها و همان عملیات شروع می‌شود.
		const pending = state.conflictRun;
		const startOverBtn = pending
			? `<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm" id="tco-discard-and-run">رها کردن و شروع ${esc(opLabelOf(pending.op))}</button>`
			: '';

		const chips = `${
			blocked > 0 ? `<span class="tco-pill tco-pill--warn">${faNum(blocked)} مورد محافظت‌شده</span>` : ''
		}${failed > 0 ? `<span class="tco-pill tco-pill--warn">${faNum(failed)} خطای فایل</span>` : ''}`;

		const reasonList = (job.blockReasons || []).length
			? `<ul class="tco-reason-list">${job.blockReasons
					.map((r) => `<li><strong>${faNum(r.count)} مورد</strong> — ${esc(r.reason)}</li>`)
					.join('')}</ul>`
			: '';

		const hint = job.hint ? `<p class="tco-muted tco-hint">${esc(job.hint)}</p>` : '';
		const rescanBtn = job.needsRescan && op === 'remove'
			? '<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm" id="tco-resume-rescan">اجرای اسکن کامل و تلاش مجدد</button>'
			: '';

		return `<div class="tco-card tco-resume" role="status">
			<div class="tco-card__head">
				<div>
					<h2 class="tco-card__title">عملیات نیمه‌تمام ${opLabel}: ${faNum(done)} از ${faNum(total)} انجام شده</h2>
					<p class="tco-muted">${reason} — پیشرفت در سرور ذخیره شده است؛ با «ادامه» از همان نقطه تکمیل می‌شود. ${faNum(remaining)} مورد در صف باقی مانده.</p>
					${idleNote}
					${hint}
					${reasonList}
					<div class="tco-row-actions" style="margin-top:8px">${chips}</div>
				</div>
				<span class="tco-pill ${done > 0 ? 'tco-pill--ok' : 'tco-pill--warn'}">${faNum(pct)}٪</span>
			</div>
			<div class="tco-progress-bar"><span style="width:${pct}%"></span></div>
			<div class="tco-win__foot">
				<span>تا این لحظه: <strong>${faNum(done)}</strong> مورد پردازش شد${job.verified ? ` · بازرسی‌شده: ${faNum(job.verified)}` : ''}</span>
				<div class="tco-row-actions">
					${rescanBtn}
					${startOverBtn}
					<button type="button" class="tisa-btn ${pending ? 'tisa-btn--secondary' : 'tisa-btn--primary'} tisa-btn--sm" id="tco-resume-job">ادامهٔ ${esc(opLabel)}</button>
					<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tco-discard-job">رها کردن (ثبت همان مقدار انجام‌شده)</button>
				</div>
			</div>
		</div>`;
	}

	/**
	 * نام فارسی نوع عملیات (برای همهٔ پیام‌ها و کارت‌ها).
	 *
	 * @param {string} op نوع عملیات.
	 * @return {string}
	 */
	function opLabelOf(op) {
		return op === 'optimize' ? 'فشرده‌سازی' : op === 'restore' ? 'بازگردانی' : 'حذف';
	}

	function renderBatchProgress() {
		if (!state.batch.running) return '';
		const b = state.batch;
		const pct = b.total > 0 ? Math.min(100, Math.round((b.done / b.total) * 100)) : 15;
		const remaining = b.remaining != null ? b.remaining : Math.max(0, b.total - b.done);
		const eta = b.etaSec > 0 ? ` · حدود ${faNum(b.etaSec)} ثانیه باقی‌مانده` : '';
		const failed = b.failed > 0 ? ` · ${faNum(b.failed)} خطای فایل` : '';
		return `<div class="tco-progress-box">
			<div class="tco-win__foot">
				<strong>${esc(b.title)} — ${faNum(b.done)} از ${faNum(b.total)} (${faNum(pct)}٪)${eta}${failed}</strong>
				${b.phase === 'verify' ? `<span class="tco-pill tco-pill--neutral">در حال بازرسی ایمنی — ${faNum(b.verified)} از ${faNum(b.total)} بررسی شد</span>` : ''}
				<div class="tco-row-actions">
					<span>باقی‌مانده: <strong>${faNum(remaining)}</strong> · آزادشده: <strong>${formatBytes(b.savedBytes)}</strong></span>
					<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tco-stop-batch">توقف و ادامهٔ بعدی</button>
				</div>
			</div>
			<div class="tco-progress-bar"><span style="width:${pct}%"></span></div>
			<p class="tco-muted">پیشرفت هر تصویر در سرور ذخیره می‌شود؛ حتی با بستن مرورگر یا قطع اینترنت، از همین نقطه ادامه می‌دهید.</p>
		</div>`;
	}

	function renderScanOrErrorCard() {
		if (state.loadError) {
			return `<div class="tco-card">
				<div class="tco-card__head">
					<div>
						<h2 class="tco-card__title">وقفه در ارتباط با سرور هنگام بارگذاری داده</h2>
						<p class="tco-card__desc">${esc(state.loadError)}</p>
					</div>
				</div>
				<div class="tco-row-actions">
					<button type="button" class="tisa-btn tisa-btn--primary" id="tco-resume-scan-btn">ادامه از آخرین نقطهٔ ذخیره‌شده</button>
					<button type="button" class="tisa-btn tisa-btn--secondary" id="tco-rescan-btn">شروع اسکن تازه از صفر</button>
				</div>
			</div>`;
		}

		const pct = Math.max(5, Math.min(99, Number(state.scan.percent || 5)));
		return `<div class="tco-card">
			<div class="tco-card__head">
				<div>
					<h2 class="tco-card__title">${esc(state.scan.stageLabel || 'در حال اسکن سریع تک‌گذرهٔ رسانه و دیسک…')}</h2>
					<p class="tco-card__desc">
						${state.scan.detailLabel ? esc(faTextDigits(state.scan.detailLabel)) + ' · ' : ''}
						موتور تک‌گذرهٔ نسل ۳ (بدون فشار IOPS و بدون قفل دیتابیس)
						${state.scan.elapsedSec > 0 ? ' · زمان سپری‌شده: ' + faNum(state.scan.elapsedSec) + ' ثانیه' : ''}
					</p>
				</div>
				<div class="tco-row-actions">
					${
						state.scan.canSkip
							? '<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tco-fast-finish-btn">نمایش فوری نتایج همین الان</button>'
							: ''
					}
					<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" data-go-module="vault">رفتن به پشتیبان و بازگردانی</button>
					<span class="tco-pill tco-pill--ok">${faNum(pct)}٪</span>
				</div>
			</div>
			<div class="tco-progress-bar"><span style="width:${pct}%"></span></div>
		</div>`;
	}

	/**
	 * کارت «قدم بعدی» — از دادهٔ سرور (next_actions) ساخته می‌شود تا کاربر
	 * هرگز مجبور نباشد حدس بزند از کجا شروع کند.
	 */
	function renderNextActions(ov) {
		const actions = (ov && ov.next_actions) || [];
		if (!actions.length) return '';
		const toneClass = (t) => (t === 'warn' ? 'tco-pill--warn' : (t === 'info' ? 'tco-pill--info' : 'tco-pill--ok'));
		return `<div class="tco-card tco-next">
			<div class="tco-card__head">
				<div>
					<h2 class="tco-card__title">قدم بعدی پیشنهادی</h2>
					<p class="tco-card__desc">به ترتیب اولویت، فقط کارهایی که واقعاً کاری برای انجام دادن باقی است.</p>
				</div>
			</div>
			<div class="tco-wins-grid">
				${actions
					.slice(0, 4)
					.map(
						(a) => `<div class="tco-win">
						<div class="tco-row-actions" style="justify-content:space-between">
							<h3 class="tco-win__title">${esc(a.title)}</h3>
							<span class="tco-pill ${toneClass(a.tone)}">${
								a.count > 0 ? `${faNum(a.count)} مورد` : 'آماده'
							}${a.bytes > 0 ? ` · ${formatBytes(a.bytes)}` : ''}</span>
						</div>
						<p class="tco-win__desc">${esc(a.desc)}</p>
						<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" data-next-action="${esc(a.cta)}">${esc(a.label)}</button>
					</div>`
					)
					.join('')}
			</div>
		</div>`;
	}

	function renderOverviewTab() {
		const ov = state.overview;
		if (!ov || state.scan.running || state.loadError) {
			return renderScanOrErrorCard();
		}
		const layers = ov.layers || {};
		const totalLayer = Math.max(1, (layers.main_bytes || 0) + (layers.subsizes_bytes || 0) + (layers.unscaled_bytes || 0) + (layers.quarantine_bytes || 0));
		const pMain = Math.max(2, Math.round(((layers.main_bytes || 0) / totalLayer) * 100));
		const pSub = Math.round(((layers.subsizes_bytes || 0) / totalLayer) * 100);
		const pUns = Math.round(((layers.unscaled_bytes || 0) / totalLayer) * 100);
		const pVlt = Math.max(0, 100 - pMain - pSub - pUns);
		const qw = ov.quick_wins;

		return `<div class="tco-stack">
			<div class="tco-kpi-grid">
				<div class="tco-kpi">
					<span class="tco-kpi__label">حجم واقعی رسانه روی دیسک</span>
					<span class="tco-kpi__val">${formatBytes(ov.uploads_bytes)}</span>
					<span class="tco-kpi__meta">فایل اصلی: ${formatBytes(layers.main_bytes)} · زیرسایزها: ${formatBytes(layers.subsizes_bytes)}</span>
				</div>
				<div class="tco-kpi">
					<span class="tco-kpi__label">تعداد تصاویر و Inode هاست</span>
					<span class="tco-kpi__val">${faNum(ov.total_images)} تصویر</span>
					<span class="tco-kpi__meta">${faNum(ov.total_inodes)} فایل فیزیکی در پوشهٔ uploads</span>
				</div>
				<div class="tco-kpi">
					<span class="tco-kpi__label">آمادهٔ آزادسازی فوری (بدون ریسک)</span>
					<span class="tco-kpi__val">${formatBytes(qw.unused.bytes + qw.unscaled.bytes + qw.duplicates.bytes + qw.unused.orphan_bytes)}</span>
					<span class="tco-kpi__meta">شامل بی‌استفاده‌ها، تکراری‌ها و فایل خام -scaled</span>
				</div>
				<div class="tco-kpi tco-kpi--accent">
					<span class="tco-kpi__label">مجموع فضای آزادشده تا امروز</span>
					<span class="tco-kpi__val">${formatBytes(ov.lifetime_stats.saved_bytes)}</span>
					<span class="tco-kpi__meta">${faNum(ov.lifetime_stats.optimized_count)} بهینه‌سازی · ${faNum(ov.lifetime_stats.quarantined_count + ov.lifetime_stats.deleted_count)} پاک‌سازی</span>
				</div>
			</div>

			${renderNextActions(ov)}

			<div class="tco-card">
				<div class="tco-card__head">
					<div>
						<h2 class="tco-card__title">کالبدشکافی مصرف فضای دیسک و توزیع فرمت‌ها</h2>
						<p class="tco-card__desc">آخرین اسکن عمیق ۱۲لایه: <span class="tco-code">${esc(ov.scan_built_at || 'هم‌اکنون')}</span> (${faNum(ov.scan_ms)} میلی‌ثانیه)</p>
					</div>
					<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tco-rescan-btn">اسکن مجدد ۱۲لایهٔ کل سایت</button>
				</div>
				<div class="tco-meter">
					<span class="tco-meter__seg tco-meter__seg--main" style="width:${pMain}%"></span>
					<span class="tco-meter__seg tco-meter__seg--sub" style="width:${pSub}%"></span>
					<span class="tco-meter__seg tco-meter__seg--unscaled" style="width:${pUns}%"></span>
					<span class="tco-meter__seg tco-meter__seg--vault" style="width:${pVlt}%"></span>
				</div>
				<div class="tco-legend">
					<span class="tco-legend__item"><i class="tco-dot tco-dot--main"></i> فایل‌های اصلی (${formatBytes(layers.main_bytes)})</span>
					<span class="tco-legend__item"><i class="tco-dot tco-dot--sub"></i> زیرسایزهای بندانگشتی (${formatBytes(layers.subsizes_bytes)})</span>
					<span class="tco-legend__item"><i class="tco-dot tco-dot--unscaled"></i> فایل خام پنهان پشت -scaled (${formatBytes(layers.unscaled_bytes)})</span>
					<span class="tco-legend__item"><i class="tco-dot tco-dot--vault"></i> قرنطینه و پشتیبان (${formatBytes(layers.quarantine_bytes)})</span>
				</div>
				<div class="tco-legend">
					${Object.keys(ov.formats || {})
						.map((f) => {
							const item = ov.formats[f];
							return `<span class="tco-pill tco-pill--neutral">${esc(f)}: ${faNum(item.count)} فایل (${formatBytes(item.bytes)})</span>`;
						})
						.join('')}
				</div>
			</div>

			<div class="tco-card">
				<div class="tco-card__head">
					<div>
						<h2 class="tco-card__title">۴ اقدام طلایی برای آزادسازی سریع فضا و افزایش سرعت</h2>
						<p class="tco-card__desc">هر مورد با ایمنی کامل و قابلیت بازگردانی از خزانهٔ ۳۰ روزه اجرا می‌شود.</p>
					</div>
				</div>
				<div class="tco-wins-grid">
					<div class="tco-win">
						<div>
							<div class="tco-win__top">
								<h3 class="tco-win__title">۱. تصاویر مطلقاً بدون استفاده (۰ مرجع)</h3>
								<span class="tco-pill tco-pill--danger">${formatBytes(qw.unused.bytes + qw.unused.orphan_bytes)}</span>
							</div>
							<p class="tco-win__desc">${faNum(qw.unused.count)} تصویر کتابخانه + ${faNum(qw.unused.orphan_count)} فایل رهاشده روی دیسک در هیچ محصول، متغیر، دسته، المنتور یا تنظیمی استفاده نشده‌اند (${faNum(qw.unused.draft_count)} تصویر پیش‌نویس جدا و امن نگه داشته شد).</p>
						</div>
						<div class="tco-win__foot">
							<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm" data-go-subtab="unused">مشاهده و پاک‌سازی تکی/گروهی</button>
							<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" data-go-subtab="explorer">رتبه‌بندی پرحجم‌ترین‌ها</button>
						</div>
					</div>

					<div class="tco-win">
						<div>
							<div class="tco-win__top">
								<h3 class="tco-win__title">۲. فایل‌های خام پنهان پشت تصاویر -scaled</h3>
								<span class="tco-pill tco-pill--warn">${formatBytes(qw.unscaled.bytes)}</span>
							</div>
							<p class="tco-win__desc">${faNum(qw.unscaled.count)} فایل دوربین/تلگرام غول‌پیکر که وردپرس نسخهٔ باکیفیت -scaled برایشان ساخته و فایل خام اولیه را بدون نمایش در سایت روی هاست نگه داشته است.</p>
						</div>
						<div class="tco-win__foot">
							<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm" id="tco-quick-clean-unscaled" ${qw.unscaled.count === 0 ? 'disabled title="همهٔ فایل‌های خام پشت -scaled قبلاً پاک شده‌اند؛ کاری برای انجام نیست"' : 'title="فایل‌های خام اولیه را بدون افت کیفیت نمایش سایت پاک می‌کند"'}>آزادسازی فوری با صفر افت کیفیت</button>
						</div>
					</div>

					<div class="tco-win">
						<div>
							<div class="tco-win__top">
								<h3 class="tco-win__title">۳. فشرده‌سازی و تبدیل PNG/JPG سنگین به WebP</h3>
								<span class="tco-pill tco-pill--ok">~${formatBytes(qw.compress.est_bytes)} صرفه‌جویی</span>
							</div>
							<p class="tco-win__desc">${faNum(qw.compress.count)} تصویر سنگین (${faNum(qw.compress.opaque_png)} فایل PNG بدون شفافیت واقعی) آمادهٔ سبک‌سازی با موتور بومی سرور هستند.</p>
						</div>
						<div class="tco-win__foot">
							<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm" data-go-subtab="compress">تنظیم و شروع فشرده‌سازی</button>
						</div>
					</div>

					<div class="tco-win">
						<div>
							<div class="tco-win__top">
								<h3 class="tco-win__title">۴. ادغام هوشمند تصاویر ۱۰۰٪ تکراری (SHA-1)</h3>
								<span class="tco-pill tco-pill--info">${formatBytes(qw.duplicates.bytes)}</span>
							</div>
							<p class="tco-win__desc">${faNum(qw.duplicates.count)} فایل تکراری در ${faNum(qw.duplicates.groups)} گروه شناسایی شد؛ انتقال خودکار لینک محصولات به تصویر مرجع بدون شکستن هیچ عکسی.</p>
						</div>
						<div class="tco-win__foot">
							<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm" data-go-subtab="duplicates">بررسی و ادغام هوشمند</button>
						</div>
					</div>
				</div>
			</div>
		</div>`;
	}

	function renderUsageCell(row) {
		if (row.usage_status === 'pinned') {
			return '<span class="tco-pill tco-pill--warn">★ پین‌شده در لیست سفید</span>';
		}
		if (row.usage_status === 'missing_file') {
			return '<span class="tco-pill tco-pill--danger">فایل فیزیکی گم‌شده (۴۰۴)</span>';
		}
		if (row.usage_status === 'strict_unused') {
			return '<span class="tco-pill tco-pill--danger">مطلقاً بدون استفاده (۰ مرجع)</span>';
		}
		const headPill =
			row.usage_status === 'draft_only'
				? `<span class="tco-pill tco-pill--warn">فقط پیش‌نویس / زباله‌دان (${faNum(row.refs_count)})</span>`
				: `<span class="tco-pill tco-pill--ok">استفاده‌شده در ${faNum(row.refs_count)} محل</span>`;

		const chips = (row.refs || [])
			.map((r) => {
				if (r.post_id && boot.editPostBase) {
					return `<a class="tco-ref-chip" href="${esc(boot.editPostBase + r.post_id)}" target="_blank" rel="noopener">${esc(r.label)}</a>`;
				}
				return `<span class="tco-ref-chip">${esc(r.label)}</span>`;
			})
			.join('');

		return `${headPill}<div class="tco-refs-list">${chips}</div>`;
	}

	function renderDeleteModeSwitch() {
		const isPerm = state.deleteMode === 'permanent';
		return `<div class="tco-seg" role="group" aria-label="حالت حذف">
			<button type="button" class="tco-seg__btn${!isPerm ? ' is-active' : ''}" data-set-delmode="quarantine">قرنطینهٔ امن ۳۰ روزه</button>
			<button type="button" class="tco-seg__btn is-danger${isPerm ? ' is-active' : ''}" data-set-delmode="permanent">حذف قطعی فوری</button>
		</div>`;
	}

	function renderAttachmentRows(rows, maxBytes) {
		if (!rows || !rows.length) {
			return '<tr><td colspan="6"><div class="tco-empty-state">موردی مطابق این فیلتر یافت نشد.</div></td></tr>';
		}
		return rows
			.map((r) => {
				const checked = state.selectedIds.has(r.id);
				const barPct = maxBytes > 0 ? Math.max(4, Math.round((r.total_bytes / maxBytes) * 100)) : 8;
				const armKey = 'del-' + r.id;
				const armed = state.armedKey === armKey;

				return `<tr class="${checked ? 'is-selected' : ''}" data-row-id="${r.id}">
					<td><input type="checkbox" class="tco-row-check" value="${r.id}" ${checked ? 'checked' : ''}></td>
					<td>
						<div class="tco-media-cell">
							${
								r.thumb_url
									? `<img class="tco-thumb" src="${esc(r.thumb_url)}" alt="${esc(r.alt || r.title)}" data-inspect="${r.id}">`
									: `<div class="tco-thumb tco-thumb--placeholder" data-inspect="${r.id}">${esc(r.format)}</div>`
							}
							<div>
								<strong class="tco-code">${esc(r.filename)}</strong>
								<span class="tco-subtext">#${faNum(r.id)} · ${esc(r.folder || 'uploads')} · ${faTextDigits(r.date)}</span>
								${r.is_opaque_png ? '<span class="tco-pill tco-pill--warn">PNG بدون شفافیت واقعی</span> ' : ''}
								${r.unscaled_bytes > 0 ? `<span class="tco-pill tco-pill--info">خام -scaled: ${formatBytes(r.unscaled_bytes)}</span> ` : ''}
								${
								r.format === 'WEBP'
									? '<span class="tco-pill tco-pill--ok" title="فایل اصلی با پسوند .webp جایگزین شده و در همان مسیر سرو می‌شود">WebP جایگزین‌شده</span>'
									: r.has_webp
										? `<span class="tco-pill tco-pill--info" title="کنار فایل اصلی یک نسخهٔ ${esc(r.format === 'AVIF' ? 'AVIF' : 'WebP')}+پسوند ساخته شده؛ اصل و زیرسایزها دست‌نخورده روی دیسک مانده‌اند">WebP موازی</span>`
										: ''
							}
							</div>
						</div>
					</td>
					<td>
						<span class="tco-pill tco-pill--neutral">${esc(r.format)}</span>
						<span class="tco-subtext">${r.width ? faNum(r.width) + ' × ' + faNum(r.height) : '—'}</span>
					</td>
					<td>
						<strong>${formatBytes(r.total_bytes)}</strong>
						<div class="tco-size-bar"><span style="width:${barPct}%"></span></div>
						<span class="tco-subtext">اصلی: ${formatBytes(r.main_bytes)} · ${faNum(r.subsizes_count)} زیرسایز (${formatBytes(r.subsizes_bytes)})</span>
					</td>
					<td>${renderUsageCell(r)}</td>
					<td>
						<div class="tco-row-actions">
							<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" data-opt-one="${r.id}" title="فشرده‌سازی و تبدیل">بهینه</button>
							<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" data-inspect="${r.id}" title="مقایسه قبل/بعد و جزئیات">جزئیات</button>
							<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm tco-pin-btn${r.is_pinned ? ' is-pinned' : ''}" data-pin-one="${r.id}" data-pinned="${r.is_pinned ? '1' : '0'}" title="محافظت دائمی در لیست سفید">★</button>
							${
								state.deleteMode === 'permanent'
									? `<button type="button" class="tisa-btn tisa-btn--sm tco-btn-danger${armed ? ' is-armed' : ''}" data-remove-one="${r.id}">${armed ? 'تأیید حذف؟' : 'حذف قطعی'}</button>`
									: `<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" data-remove-one="${r.id}">قرنطینه</button>`
							}
						</div>
					</td>
				</tr>`;
			})
			.join('');
	}

	function renderExplorerTab() {
		const f = state.explorerFilter;
		const data = state.media;
		const rows = (data && data.rows) || [];
		const maxBytes = rows.reduce((m, r) => Math.max(m, r.total_bytes || 0), 0);
		const selCount = state.selectedIds.size;
		const bulkArm = state.armedKey === 'bulk-remove';
		const replaceArm = state.armedKey === 'bulk-opt-replace';

		return `<div class="tco-card">
			<div class="tco-card__head">
				<div>
					<h2 class="tco-card__title">کاوشگر و رتبه‌بندی سنگین‌ترین تصاویر کتابخانهٔ رسانه</h2>
					<p class="tco-card__desc">مرتب‌سازی واقعی بر اساس <strong>حجم کل اشغالی روی دیسک (اصلی + خام + تمام زیرسایزها)</strong> یا تک‌فایل اصلی، به همراه نقشهٔ دقیق محل استفاده.</p>
				</div>
				${renderDeleteModeSwitch()}
			</div>

			<div class="tco-toolbar">
				<input type="search" class="tco-input tco-input--grow" id="tco-exp-q" value="${esc(f.search)}" placeholder="جستجو در نام فایل، عنوان محصول، شناسه یا مسیر…">
				<select class="tco-select" id="tco-exp-sort">
					<option value="total_bytes" ${f.sort_by === 'total_bytes' ? 'selected' : ''}>مرتب‌سازی: بیشترین حجم کل روی دیسک</option>
					<option value="main_bytes" ${f.sort_by === 'main_bytes' ? 'selected' : ''}>بیشترین حجم فایل اصلی نمایشی</option>
					<option value="unscaled_bytes" ${f.sort_by === 'unscaled_bytes' ? 'selected' : ''}>بیشترین فایل خام پنهان (-scaled)</option>
					<option value="pixels" ${f.sort_by === 'pixels' ? 'selected' : ''}>بزرگ‌ترین ابعاد پیکسلی</option>
					<option value="subsizes_count" ${f.sort_by === 'subsizes_count' ? 'selected' : ''}>بیشترین تعداد زیرسایز</option>
					<option value="density" ${f.sort_by === 'density' ? 'selected' : ''}>بدترین نسبت حجم به پیکسل (PNG متورم)</option>
					<option value="id" ${f.sort_by === 'id' ? 'selected' : ''}>جدیدترین آپلودها</option>
				</select>
				<select class="tco-select" id="tco-exp-min">
					<option value="0" ${Number(f.min_bytes) === 0 ? 'selected' : ''}>همهٔ حجم‌ها</option>
					<option value="5242880" ${Number(f.min_bytes) === 5242880 ? 'selected' : ''}>بالای ۵ مگابایت</option>
					<option value="2097152" ${Number(f.min_bytes) === 2097152 ? 'selected' : ''}>بالای ۲ مگابایت</option>
					<option value="1048576" ${Number(f.min_bytes) === 1048576 ? 'selected' : ''}>بالای ۱ مگابایت</option>
					<option value="512000" ${Number(f.min_bytes) === 512000 ? 'selected' : ''}>بالای ۵۰۰ کیلوبایت</option>
				</select>
				<select class="tco-select" id="tco-exp-fmt">
					<option value="ALL" ${f.format === 'ALL' ? 'selected' : ''}>همهٔ فرمت‌ها</option>
					<option value="JPEG" ${f.format === 'JPEG' ? 'selected' : ''}>JPEG</option>
					<option value="PNG" ${f.format === 'PNG' ? 'selected' : ''}>PNG</option>
					<option value="OPAQUE_PNG" ${f.format === 'OPAQUE_PNG' ? 'selected' : ''}>فقط PNG بدون شفافیت (کاذب)</option>
					<option value="WEBP" ${f.format === 'WEBP' ? 'selected' : ''}>WebP</option>
					<option value="AVIF" ${f.format === 'AVIF' ? 'selected' : ''}>AVIF</option>
				</select>
				<select class="tco-select" id="tco-exp-usage">
					<option value="all" ${f.usage === 'all' ? 'selected' : ''}>همهٔ وضعیت‌های مصرف</option>
					<option value="used" ${f.usage === 'used' ? 'selected' : ''}>استفاده‌شده در سایت</option>
					<option value="strict_unused" ${f.usage === 'strict_unused' ? 'selected' : ''}>مطلقاً بدون استفاده (۰ مرجع)</option>
					<option value="draft_only" ${f.usage === 'draft_only' ? 'selected' : ''}>فقط در پیش‌نویس / زباله‌دان</option>
					<option value="pinned" ${f.usage === 'pinned' ? 'selected' : ''}>پین‌شده در لیست سفید</option>
				</select>
			</div>

			<div class="tco-bulkbar">
				<div>
					<strong>${data ? faNum(data.total) : '۰'} تصویر منطبق (${formatBytes(data ? data.matched_bytes : 0)})</strong>
					${selCount > 0 ? ` · <span class="tco-pill tco-pill--ok">${faNum(selCount)} مورد انتخاب شده</span>` : ''}
					${data && data.matching_ids && data.matching_ids.length > selCount ? ` <button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" id="tco-select-all-matching">انتخاب همهٔ ${faNum(data.matching_ids.length)} نتیجه</button>` : ''}
					${selCount > 0 ? ' <button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" id="tco-clear-sel">لغو انتخاب</button>' : ''}
				</div>
				<div class="tco-bulkbar__actions">
					<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tco-bulk-opt" ${selCount === 0 ? 'disabled title="ابتدا حداقل یک تصویر را از ستون انتخاب تیک بزنید"' : 'title="فشرده‌سازی و ساخت نسخهٔ WebP کنار فایل (فایل اصل دست‌نخورده می‌ماند)"'}>فشرده‌سازی و WebP (${faNum(selCount)})</button>
					<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm ${replaceArm ? 'is-armed' : ''}" id="tco-bulk-opt-replace" ${selCount === 0 ? 'disabled title="ابتدا حداقل یک تصویر را از ستون انتخاب تیک بزنید"' : 'title="فشرده‌سازی + جایگزینی خودکار فایل اصل با WebP؛ لینک‌ها اصلاح می‌شوند، حجم دیسک واقعاً کم می‌شود و فایل قبلی در خزانه پشتیبان می‌ماند"'}>
						${replaceArm ? `کلیک مجدد برای جایگزینی ${faNum(selCount)} فایل!` : `فشرده‌سازی و جایگزینی (${faNum(selCount)})`}
					</button>
					<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm ${state.deleteMode === 'permanent' ? 'tco-btn-danger' : ''} ${bulkArm ? 'is-armed' : ''}" id="tco-bulk-remove" ${selCount === 0 ? 'disabled title="ابتدا حداقل یک تصویر را انتخاب کنید"' : ''}>
						${state.deleteMode === 'permanent' ? (bulkArm ? 'کلیک مجدد برای حذف قطعی!' : `حذف قطعی انتخابی (${faNum(selCount)})`) : `انتقال به قرنطینه (${faNum(selCount)})`}
					</button>
				</div>
			</div>

			<div class="tco-table-wrap">
				<table class="tco-table">
					<thead>
						<tr>
							<th><input type="checkbox" id="tco-check-page" ${rows.length && rows.every((r) => state.selectedIds.has(r.id)) ? 'checked' : ''}></th>
							<th>تصویر و مشخصات فایل</th>
							<th>فرمت و ابعاد</th>
							<th>حجم واقعی کل دیسک</th>
							<th>نقشهٔ محل استفاده (۱۲لایه)</th>
							<th>عملیات سریع</th>
						</tr>
					</thead>
					<tbody>${renderAttachmentRows(rows, maxBytes)}</tbody>
				</table>
			</div>

			${renderPager({
				page: data ? data.page : 1,
				pages: data ? data.pages : 1,
				total: data ? data.total : rows.length,
				scope: data && data.matching_ids ? data.matching_ids.length : rows.length,
			})}
		</div>`;
	}

	function renderUnusedTab() {
		const u = state.unusedFilter;
		const ov = state.overview;
		const qw = ov ? ov.quick_wins.unused : { count: 0, bytes: 0, draft_count: 0, draft_bytes: 0, orphan_count: 0, orphan_bytes: 0, missing_db: 0 };
		const data = state.media;
		const isDiskOrphans = data && data.mode === 'disk_orphans';
		const rows = (data && data.rows) || [];
		const selCount = isDiskOrphans ? state.selectedPaths.size : state.selectedIds.size;
		const sweepArm = state.armedKey === 'sweep-all-unused';

		// یادآوری مهربان: اگر اسکن خیلی قدیمی باشد، نتیجه ممکن است با امروز سایت
		// فرق کند. بررسی واقعی داخل خودِ اسکن انجام می‌شود («اسکن مجدد» را بزنید).
		const scanAge = Number((state.overview && state.overview.scan_age_hours) || 0);
		const scanHint = scanAge >= 72
			? `<div class="tco-hint">
					آخرین اسکن ${faNum(Math.round(scanAge / 24))} روز پیش انجام شده است. برای نتیجهٔ قطعی همین حالا،
					<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tco-rescan-unused">اسکن مجدد</button>
				</div>`
			: '';

		return `<div class="tco-stack">
			${renderLastRunCard()}
			<div class="tco-card">
				<div class="tco-card__head">
					<div>
						<h2 class="tco-card__title">اسکنر ۱۲لایهٔ تصاویر بدون استفاده و فایل‌های رهاشده روی دیسک</h2>
						<p class="tco-card__desc">تمام محصولات، گالری‌ها، متغیرها، سواچ‌ها و دسته‌ها (` + '`wp_termmeta`' + `)، محتوای گوتنبرگ، المنتور (` + '`\\/`' + `) و تنظیمات قالب بررسی شده‌اند.</p>
					</div>
					<div class="tco-row-actions">
						${renderDeleteModeSwitch()}
						<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" data-go-subtab="restore">پشتیبان و بازگردانی (${faNum(getRecoverableCount())})</button>
						<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" id="tco-rescan-btn">اسکن مجدد</button>
					</div>
				</div>

				<div class="tco-subnav">
					<button type="button" class="tco-subnav__btn${u.usage === 'strict_unused' ? ' is-active' : ''}" data-unused-bucket="strict_unused">
						مطلقاً بدون استفاده — ۰ مرجع (${faNum(qw.count)} · ${formatBytes(qw.bytes)})
					</button>
					<button type="button" class="tco-subnav__btn${u.usage === 'draft_only' ? ' is-active' : ''}" data-unused-bucket="draft_only">
						فقط در پیش‌نویس / زباله‌دان (${faNum(qw.draft_count)} · ${formatBytes(qw.draft_bytes)})
					</button>
					<button type="button" class="tco-subnav__btn${u.usage === 'unregistered_disk' ? ' is-active' : ''}" data-unused-bucket="unregistered_disk">
						فایل‌های رهاشده روی دیسک بدون رکورد دیتابیس (${faNum(qw.orphan_count)} · ${formatBytes(qw.orphan_bytes)})
					</button>
					<button type="button" class="tco-subnav__btn${u.usage === 'missing_file' ? ' is-active' : ''}" data-unused-bucket="missing_file">
						رکوردهای شکستهٔ ۴۰۴ (${faNum(qw.missing_db)})
					</button>
				</div>

				${u.usage === 'strict_unused' ? scanHint : ''}

				<div class="tco-bulkbar">
					<div>
						<strong>${data ? faNum(data.total) : '۰'} مورد در این بخش (${formatBytes(data ? data.matched_bytes : 0)})</strong>
						${u.usage === 'draft_only' ? ' · <span class="tco-pill tco-pill--warn">محافظت ایمپورت ربات تلگرام: این عکس‌ها به پیش‌نویس وصل‌اند</span>' : ''}
					</div>
					<div class="tco-bulkbar__actions">
						${
							selCount > 0
								? `<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tco-bulk-remove">پردازش انتخاب‌شده‌ها (${faNum(selCount)})</button>`
								: ''
						}
						<button type="button" class="tisa-btn ${state.deleteMode === 'permanent' ? 'tco-btn-danger' : 'tisa-btn--primary'} tisa-btn--sm ${sweepArm ? 'is-armed' : ''}" id="tco-sweep-all" ${!data || data.total === 0 ? 'disabled title="موردی در این دسته برای پاک‌سازی نیست"' : ''}>
							${sweepArm ? 'تأیید نهایی روی همهٔ موارد؟' : `${state.deleteMode === 'permanent' ? 'حذف قطعی یک‌جای همهٔ' : 'انتقال یک‌جای همهٔ'} ${data ? faNum(data.total) : '۰'} مورد به قرنطینه`}
						</button>
					</div>
				</div>

				${
					isDiskOrphans
						? `<div class="tco-table-wrap">
							<table class="tco-table">
								<thead>
									<tr>
										<th><input type="checkbox" id="tco-check-orphan-page"></th>
										<th>مسیر نسبی فایل داخل uploads/</th>
										<th>فرمت</th>
										<th>حجم روی دیسک</th>
										<th>آخرین تغییر</th>
										<th>عملیات</th>
									</tr>
								</thead>
								<tbody>
									${
										rows.length
											? rows
													.map((o) => {
														const ck = state.selectedPaths.has(o.rel_path);
														return `<tr class="${ck ? 'is-selected' : ''}">
															<td><input type="checkbox" class="tco-orphan-check" value="${esc(o.rel_path)}" ${ck ? 'checked' : ''}></td>
															<td><strong class="tco-code">${esc(o.rel_path)}</strong></td>
															<td><span class="tco-pill tco-pill--neutral">${esc(o.ext)}</span></td>
															<td><strong>${formatBytes(o.bytes)}</strong></td>
															<td><span class="tco-code">${esc(o.modified)}</span></td>
															<td><button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" data-remove-path="${esc(o.rel_path)}">${state.deleteMode === 'permanent' ? 'حذف فایل' : 'قرنطینه'}</button></td>
														</tr>`;
													})
													.join('')
											: '<tr><td colspan="6"><div class="tco-empty-state">هیچ فایل تصویری ثبت‌نشده‌ای روی دیسک وجود ندارد.</div></td></tr>'
									}
								</tbody>
							</table>
						</div>`
						: `<div class="tco-table-wrap">
							<table class="tco-table">
								<thead>
									<tr>
										<th><input type="checkbox" id="tco-check-page"></th>
										<th>تصویر</th>
										<th>فرمت و ابعاد</th>
										<th>حجم آزادشونده</th>
										<th>وضعیت مرجع</th>
										<th>عملیات تکی</th>
									</tr>
								</thead>
								<tbody>${renderAttachmentRows(rows, rows.reduce((m, r) => Math.max(m, r.total_bytes || 0), 0))}</tbody>
							</table>
						</div>`
				}
				${renderPager({
					page: data ? data.page : 1,
					pages: data ? data.pages : 1,
					total: data ? data.total : rows.length,
					scope: data && data.matching_ids ? data.matching_ids.length : rows.length,
				})}
			</div>
		</div>`;
	}

	function renderCompressTab() {
		const s = state.settings;
		const caps = (boot.moduleBoot && boot.moduleBoot.images && boot.moduleBoot.images.capabilities) || {};
		const ov = state.overview;

		return `<div class="tco-stack">
			<div class="tco-card">
				<div class="tco-card__head">
					<div>
						<h2 class="tco-card__title">موتور فشرده‌سازی بومی، تغییر سایز هوشمند و تبدیل WebP / AVIF</h2>
						<p class="tco-card__desc">اجرای ۱۰۰٪ محلی روی سرور بدون محدودیت تعداد، بدون API بیرونی و با حفظ کامل پروفایل رنگ قاب‌ها.</p>
					</div>
					<div class="tco-row-actions">
						<span class="tco-pill tco-pill--ok">موتور فعال: ${esc(caps.engine || 'Imagick')}</span>
						<span class="tco-pill ${caps.webp ? 'tco-pill--ok' : 'tco-pill--warn'}">WebP: ${caps.webp ? 'پشتیبانی کامل' : 'غیرفعال'}</span>
						<span class="tco-pill ${caps.avif ? 'tco-pill--ok' : 'tco-pill--neutral'}">AVIF: ${caps.avif ? 'پشتیبانی کامل' : 'ندارد'}</span>
					</div>
				</div>

				<div class="tco-form-grid">
					<div class="tco-field">
						<label for="tco-s-preset">پروفایل سطح کیفیت و فشرده‌سازی</label>
						<select class="tco-select" id="tco-s-preset">
							<option value="lossless_near" ${s.quality_preset === 'lossless_near' ? 'selected' : ''}>وفاداری حداکثری رنگ و جزئیات (کیفیت ۸۸–۹۰)</option>
							<option value="balanced" ${s.quality_preset === 'balanced' ? 'selected' : ''}>متعادل فروشگاهی — پیشنهادی (کیفیت ۸۰–۸۲)</option>
							<option value="max_saving" ${s.quality_preset === 'max_saving' ? 'selected' : ''}>حداکثر کاهش حجم دیسک و سرعت (کیفیت ۷۰–۷۲)</option>
							<option value="custom" ${s.quality_preset === 'custom' ? 'selected' : ''}>تنظیم دستی عدد کیفیت</option>
						</select>
					</div>

					<div class="tco-field">
						<label for="tco-s-nextgen">فرمت نسل جدید خروجی</label>
						<select class="tco-select" id="tco-s-nextgen">
							<option value="webp" ${s.nextgen_format === 'webp' ? 'selected' : ''}>تبدیل به WebP (سازگار با ۹۹٪ مرورگرها — پیشنهادی)</option>
							<option value="avif" ${s.nextgen_format === 'avif' ? 'selected' : ''}>تبدیل به AVIF (فوق‌فشرده)</option>
							<option value="none" ${s.nextgen_format === 'none' ? 'selected' : ''}>بدون تغییر فرمت (فقط فشرده‌سازی همان فرمت)</option>
						</select>
					</div>

					<div class="tco-field">
						<label for="tco-s-webpmode">استراتژی ذخیره‌سازی WebP (انتخاب دوگانه)</label>
						<select class="tco-select" id="tco-s-webpmode">
							<option value="sidecar" ${s.webp_mode === 'sidecar' ? 'selected' : ''}>نسخهٔ موازی ایمن (حفظ فایل اصلی + ساخت فایل .webp کنار آن)</option>
							<option value="replace" ${s.webp_mode === 'replace' ? 'selected' : ''}>جایگزینی کامل فایل اصلی با WebP + اصلاح خودکار لینک‌های سایت/المنتور</option>
						</select>
					</div>

					<div class="tco-field">
						<label for="tco-s-delivery">روش سرو خودکار WebP در حالت موازی</label>
						<select class="tco-select" id="tco-s-delivery">
							<option value="picture" ${s.delivery_method === 'picture' ? 'selected' : ''}>بازنویسی هوشمند تگ &lt;picture&gt; (سازگار با همهٔ کش‌ها و CDN)</option>
							<option value="htaccess" ${s.delivery_method === 'htaccess' ? 'selected' : ''}>قوانین مستقیم وب‌سرور در uploads/.htaccess (LiteSpeed/Apache)</option>
							<option value="none" ${s.delivery_method === 'none' ? 'selected' : ''}>غیرفعال (مناسب وقتی جایگزینی مستقیم فعال است)</option>
						</select>
					</div>

					<div class="tco-field">
						<label for="tco-s-maxw">حداکثر عرض مجاز پیکسل (جلوگیری از عکس‌های ۴۰۰۰ پیکسلی)</label>
						<input type="number" class="tco-input" id="tco-s-maxw" value="${Number(s.max_width || 1920)}" min="600" max="5000">
					</div>

					<div class="tco-field">
						<label for="tco-s-maxh">حداکثر ارتفاع مجاز پیکسل</label>
						<input type="number" class="tco-input" id="tco-s-maxh" value="${Number(s.max_height || 1920)}" min="600" max="5000">
					</div>
				</div>

				<div class="tco-form-grid" style="margin-top:16px">
					<label class="tco-check-row">
						<input type="checkbox" id="tco-s-autoupload" ${Number(s.auto_optimize_upload) ? 'checked' : ''}>
						<span><strong>بهینه‌سازی خودکار موقع آپلود</strong> (شامل آپلود دستی و ایمپورت ZIP ربات تلگرام)</span>
					</label>
					<label class="tco-check-row">
						<input type="checkbox" id="tco-s-backuporig" ${Number(s.backup_originals) ? 'checked' : ''}>
						<span><strong>نگهداری نسخهٔ اصلی اولیه در خزانه</strong> (امکان بازگردانی کیفیت اولیه با یک کلیک)</span>
					</label>
					<label class="tco-check-row">
						<input type="checkbox" id="tco-s-opaque" ${Number(s.convert_opaque_png) ? 'checked' : ''}>
						<span><strong>شکار خودکار PNGهای کاذب</strong> (تبدیل PNGهای بدون شفافیت با کاهش حجم تا ۹۰٪)</span>
					</label>
					<label class="tco-check-row">
						<input type="checkbox" id="tco-s-icc" ${Number(s.keep_icc_profile) ? 'checked' : ''}>
						<span><strong>حفظ پروفایل رنگ ICC</strong> (جلوگیری از تغییر رنگ قاب‌ها پس از فشرده‌سازی)</span>
					</label>
				</div>

				<div class="tco-bulkbar" style="margin-top:16px">
					<div>
						<strong>آمادهٔ بهینه‌سازی انبوه: ${ov ? faNum(ov.quick_wins.compress.count) : '۰'} تصویر بهینه‌نشده</strong>
						<span class="tco-subtext">برآورد صرفه‌جویی: ~${formatBytes(ov ? ov.quick_wins.compress.est_bytes : 0)}</span>
					</div>
					<div class="tco-bulkbar__actions">
						<button type="button" class="tisa-btn tisa-btn--secondary" id="tco-save-compress-cfg">ذخیرهٔ تنظیمات فشرده‌سازی</button>
						<button type="button" class="tisa-btn tisa-btn--secondary" id="tco-start-bulk-compress" title="فشرده‌سازی + ساخت نسخهٔ WebP کنار فایل؛ اصل و زیرسایزها دست‌نخورده می‌مانند">شروع بهینه‌سازی انبوه (نسخهٔ موازی)</button>
						<button type="button" class="tisa-btn tisa-btn--primary ${state.armedKey === 'bulk-opt-replace-all' ? 'is-armed' : ''}" id="tco-start-replace-compress" title="فشرده‌سازی + جایگزینی خودکار فایل اصل با WebP؛ لینک‌ها اصلاح می‌شوند و حجم دیسک واقعاً کم می‌شود (فایل قبلی در خزانه پشتیبان می‌ماند)">
							${state.armedKey === 'bulk-opt-replace-all' ? 'کلیک مجدد برای تأیید جایگزینی انبوه!' : 'فشرده‌سازی و جایگزینی انبوه (کاهش حجم دیسک)'}
						</button>
					</div>
				</div>
			</div>
		</div>`;
	}

	/**
	 * انتخاب «تعداد در هر صفحه» — همان چیزی که کاربر خواسته بود: خودش تعیین کند
	 * در هر صفحه چند مورد ببیند (۲۵ تا ۵۰۰ یا همه).
	 *
	 * @return {string}
	 */
	function renderPerPageSelect() {
		const options = [
			[25, '۲۵ مورد'],
			[50, '۵۰ مورد'],
			[100, '۱۰۰ مورد'],
			[200, '۲۰۰ مورد'],
			[500, '۵۰۰ مورد'],
			[0, 'همهٔ موارد'],
		];
		return `<label class="tco-perpage" title="تعداد مواردی که در هر صفحه نمایش داده می‌شود (ذخیره می‌شود)">
			<span>در هر صفحه:</span>
			<select class="tco-select tco-select--sm" id="tco-per-page">
				${options
					.map(
						([v, label]) =>
							`<option value="${v}" ${Number(state.perPage) === v ? 'selected' : ''}>${label}</option>`
					)
					.join('')}
			</select>
		</label>`;
	}

	/**
	 * نوار صفحه‌بندی کامل: اول/قبلی/بعدی/آخر + پرش به شمارهٔ صفحه + تعداد کل.
	 *
	 * قبلاً فقط دو دکمهٔ قبلی/بعدی وجود داشت (و در برخی بخش‌ها هیچ چیز)؛ حالا
	 * هیچ فهرستی بدون راه رفتن بین صفحات و بدون انتخاب تعداد در صفحه نمی‌ماند.
	 *
	 * @param {Object} o تنظیمات نوار.
	 * @return {string}
	 */
	function renderPager(o) {
		const page = Math.max(1, Number(o.page || 1));
		const pages = Math.max(1, Number(o.pages || 1));
		const total = Math.max(0, Number(o.total || 0));
		const scope = o.scope ? Number(o.scope) : 0;
		const isFirst = page <= 1;
		const isLast = page >= pages;
		const jumpValue = o.total > 0 ? page : 1;

		return `<div class="tco-pager">
			<div class="tco-pager__info">
				<span>صفحهٔ <strong>${faNum(page)}</strong> از <strong>${faNum(pages)}</strong></span>
				<span class="tco-muted">${total > 0 ? `${faNum(total)} مورد در مجموع` : 'موردی نیست'}${
					scope > 0 && scope !== total ? ` · ${faNum(scope)} مورد در همین فهرست` : ''
				}</span>
			</div>
			<div class="tco-row-actions tco-pager__actions">
				<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" data-page-edge="first" ${isFirst ? 'disabled title="در صفحهٔ اول هستید"' : 'title="صفحهٔ اول"'}>« اول</button>
				<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" data-page-delta="-1" ${isFirst ? 'disabled title="در صفحهٔ اول هستید"' : 'title="صفحهٔ قبل"'}>قبلی</button>
				<label class="tco-pager__jump">
					<span>برو به صفحه</span>
					<input type="number" min="1" max="${pages}" step="1" class="tco-input tco-input--sm" id="tco-page-jump" value="${faNum(jumpValue)}">
				</label>
				<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" data-page-go="1" ${pages <= 1 ? 'disabled title="تنها یک صفحه وجود دارد"' : ''}>برو</button>
				<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" data-page-delta="1" ${isLast ? 'disabled title="در آخرین صفحه هستید"' : 'title="صفحهٔ بعد"'}>بعدی</button>
				<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" data-page-edge="last" ${isLast ? 'disabled title="در آخرین صفحه هستید"' : 'title="آخرین صفحه"'}>آخر »</button>
				${renderPerPageSelect()}
			</div>
		</div>`;
	}

	/**
	 * بخش فعال، صفحهٔ جاری و شمارهٔ کل صفحاتش را برمی‌گرداند.
	 *
	 * @return {?{get: function():number, set: function(number):void, name: string}}
	 */
	function pageState() {
		if (state.subtab === 'explorer') {
			return {
				name: 'explorer',
				get: () => Number(state.explorerFilter.page || 1),
				set: (p) => {
					state.explorerFilter.page = p;
				},
			};
		}
		if (state.subtab === 'unused') {
			return {
				name: 'unused',
				get: () => Number(state.unusedFilter.page || 1),
				set: (p) => {
					state.unusedFilter.page = p;
				},
			};
		}
		if (state.subtab === 'seo') {
			return {
				name: 'seo',
				get: () => Number(state.seoPage || 1),
				set: (p) => {
					state.seoPage = p;
				},
			};
		}
		if (state.subtab === 'duplicates') {
			const total = Number((state.duplicates && state.duplicates.groups_count) || 0);
			const per = Number(state.perPage);
			const perPageUsed = per > 0 ? per : Math.max(1, total);
			return {
				name: 'duplicates',
				pages: Math.max(1, Math.ceil(total / perPageUsed)),
				get: () => Number(state.dupPage || 1),
				set: (p) => {
					state.dupPage = p;
				},
			};
		}
		return null;
	}

	/**
	 * رفتن به صفحهٔ دلخواه در بخش فعال — با محدودسازی بین ۱ و آخرین صفحه.
	 *
	 * @param {number|string} target شمارهٔ صفحه.
	 */
	async function goToPage(target) {
		const info = pageState();
		if (!info) return;
		const pages = info.pages || Math.max(1, Number((state.media && state.media.pages) || (state.seo && state.seo.pages) || 1));
		const page = Math.min(Math.max(1, Number(target) || 1), Math.max(1, pages));
		if (page === info.get()) return;
		info.set(page);
		if (info.name === 'duplicates') {
			render();
			return;
		}
		await ensureDataForCurrentView(false);
	}

	/**
	 * تعیین «تعداد در هر صفحه» توسط کاربر، ذخیره در تنظیمات و بازگرداندن
	 * همهٔ فهرست‌ها به صفحهٔ اول (چون شمارهٔ صفحات با اندازهٔ جدید عوض می‌شود).
	 *
	 * @param {number} value ۰ یعنی «همهٔ موارد».
	 */
	async function applyPerPage(value) {
		const perPage = Math.max(0, Math.min(500, Number(value) || 0));
		state.perPage = perPage;
		['explorerFilter', 'unusedFilter'].forEach((k) => {
			state[k].page = 1;
		});
		state.dupPage = 1;
		state.seoPage = 1;
		const settings = Object.assign({}, state.settings, { per_page: perPage });
		// تنظیمات در پس‌زمینه ذخیره می‌شود تا کاربر معطل نماند؛ خطا فقط اطلاع داده می‌شود.
		api('save_settings', { settings })
			.then((res) => {
				if (res && res.settings) state.settings = res.settings;
			})
			.catch((err) => toast(`ذخیرهٔ «تعداد در هر صفحه» انجام نشد: ${err.message || 'خطای نامشخص'}`, 'error'));
		await ensureDataForCurrentView(false);
	}

	/**
	 * کارت شفاف «نتیجهٔ آخرین عملیات» — دقیقاً می‌گوید چند مورد انجام شد،
	 * چند مورد چرا انجام نشد و فهرست همان موارد.
	 *
	 * @return {string}
	 */
	function renderLastRunCard() {
		const r = state.lastRun;
		if (!r) return '';
		const reasons = (r.blockReasons || [])
			.map((x) => `<li><strong>${faNum(x.count)} مورد:</strong> ${esc(x.reason)}</li>`)
			.join('');
		const sample = Object.keys(r.blockedItems || {})
			.slice(0, 8)
			.map((id) => {
				const rs = (r.blockedItems[id] || []).join(' · ');
				return `<li><span class="tco-code">#${faNum(id)}</span> — ${esc(rs || 'دلیل نامشخص')}</li>`;
			})
			.join('');

		return `<div class="tco-card tco-result-card">
			<div class="tco-card__head">
				<div>
					<h2 class="tco-card__title">نتیجهٔ آخرین عملیات حذف</h2>
					<p class="tco-card__desc">
						${r.done > 0 ? `<strong>${faNum(r.done)}</strong> مورد با موفقیت انجام شد` : 'هیچ موردی انجام نشد'}
						${r.blocked > 0 ? ` · <strong>${faNum(r.blocked)}</strong> مورد عمداً انجام نشد` : ''}
						${r.failed > 0 ? ` · <strong>${faNum(r.failed)}</strong> مورد خطای فایل داشت` : ''}
					</p>
				</div>
				<div class="tco-row-actions">
					<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" id="tco-dismiss-run">بستن این کارت</button>
				</div>
			</div>
			${
				r.blocked > 0
					? `<div class="tco-hint tco-hint--warn">
							<strong>چرا این موارد حذف نشدند؟</strong> این تصاویر در سایت واقعاً استفاده می‌شوند؛ حذفشان عکس محصول را می‌شکست. حالا از فهرست «مطلقاً بلااستفاده» خارج شده‌اند و دیگر پیش‌نهاد حذف نمی‌شوند.
						</div>
						${reasons ? `<ul class="tco-reason-list">${reasons}</ul>` : ''}
						${sample ? `<details class="tco-details"><summary>دیدن نمونهٔ همان تصاویر (${faNum(Object.keys(r.blockedItems || {}).length)} مورد)</summary><ul class="tco-reason-list">${sample}</ul></details>` : ''}
						${r.needsRescan ? '<p class="tco-hint">برای حذف مطمئن، یک‌بار «اسکن کامل تصاویر» را اجرا کنید تا کاتالوگ تازه شود.</p>' : ''}`
					: ''
			}
		</div>`;
	}

	function renderDuplicatesTab() {
		const dup = state.duplicates;
		if (!dup) {
			return '<div class="tco-card"><div class="tco-loading-state"><span class="tco-spinner"></span><span>در حال محاسبهٔ اثر انگشت محتوایی SHA-1 روی فایل‌ها…</span></div></div>';
		}

		// نمایش گروه‌ها با «تعداد در هر صفحه»ی انتخابی کاربر (قبلاً همه یک‌جا
		// می‌آمد یا بی‌دلیل محدود می‌شد و راه رفتن بین صفحه‌ها نبود).
		const perPage = Number(state.perPage);
		const allGroups = dup.groups || [];
		const groupsPerPage = perPage > 0 ? perPage : Math.max(1, allGroups.length);
		const dupPages = Math.max(1, Math.ceil(allGroups.length / groupsPerPage));
		const dupPage = Math.min(Math.max(1, Number(state.dupPage || 1)), dupPages);
		const startIdx = (dupPage - 1) * groupsPerPage;
		const visibleGroups = allGroups.slice(startIdx, startIdx + groupsPerPage);

		return `<div class="tco-card">
			<div class="tco-card__head">
				<div>
					<h2 class="tco-card__title">تشخیص تصاویر ۱۰۰٪ تکراری (SHA-1) و ادغام هوشمند بدون خرابی محصولات</h2>
					<p class="tco-card__desc">با کلیک روی «ادغام هوشمند»، تمام محصولات، متغیرها، گالری‌ها، دسته‌ها و برگه‌های المنتور به تصویر مرجع منتقل شده و نسخه‌های اضافی پاک/قرنطینه می‌شوند.</p>
				</div>
				<div class="tco-row-actions">
					${renderDeleteModeSwitch()}
					<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm" id="tco-merge-all-dups" ${dup.groups_count === 0 ? 'disabled title="هیچ گروه تکراری‌ای پیدا نشد"' : 'title="ادغام همهٔ گروه‌های تکراری"'}>
						ادغام هوشمند همهٔ ${faNum(dup.groups_count)} گروه (${formatBytes(dup.waste_bytes)})
					</button>
				</div>
			</div>

			${
				dup.groups_count === 0
					? '<div class="tco-empty-state">هیچ تصویر تکراری بایت‌به‌بایتی در کتابخانهٔ رسانه وجود ندارد.</div>'
					: `<div class="tco-stack">
						${visibleGroups
							.map((g) => {
								const chosenMaster = state.masterChoices[g.hash] || g.master_id;
								const dupIds = g.members.map((m) => m.id).filter((id) => id !== chosenMaster);
								return `<div class="tco-dup-group" data-hash="${esc(g.hash)}">
									<div class="tco-win__foot">
										<div>
											<strong>${faNum(g.count)} نسخهٔ یکسان (${esc(g.format)} · ${faTextDigits(g.dimensions)})</strong>
											<span class="tco-code"> · SHA1: ${esc(g.short_hash)}</span>
											<span class="tco-pill tco-pill--info">${formatBytes(g.waste_bytes)} قابل آزادسازی</span>
										</div>
										<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm" data-merge-hash="${esc(g.hash)}" data-master="${chosenMaster}" data-dups="${dupIds.join(',')}">
											ادغام این گروه به #${faNum(chosenMaster)}
										</button>
									</div>
									<div class="tco-dup-members">
										${g.members
											.map((m) => {
												const isMaster = m.id === chosenMaster;
												return `<div class="tco-dup-item${isMaster ? ' is-master' : ''}" data-pick-master="${m.id}" data-group-hash="${esc(g.hash)}">
													${m.thumb_url ? `<img class="tco-thumb" src="${esc(m.thumb_url)}" alt="">` : `<div class="tco-thumb tco-thumb--placeholder">${esc(m.format)}</div>`}
													<div>
														<strong class="tco-code">#${m.id} · ${esc(m.filename)}</strong>
														<span class="tco-subtext">${formatBytes(m.total_bytes)} · ${faNum(m.refs_count)} محل استفاده</span>
														<span class="tco-pill ${isMaster ? 'tco-pill--ok' : 'tco-pill--neutral'}">${isMaster ? 'تصویر مرجع (حفظ می‌شود)' : 'ادغام و حذف می‌شود'}</span>
													</div>
												</div>`;
											})
											.join('')}
									</div>
								</div>`;
							})
							.join('')}
					</div>`
			}
			${renderPager({
				page: dupPage,
				pages: dupPages,
				total: dup.groups_count,
				scope: dup.groups_count,
			})}
		</div>`;
	}

	function renderSubsizesTab() {
		const sizes = state.subsizes;
		if (!sizes) {
			return '<div class="tco-card"><div class="tco-loading-state"><span class="tco-spinner"></span><span>در حال بررسی سایزهای ثبت‌شده و محاسبهٔ حجم هر سایز…</span></div></div>';
		}
		const disabledSet = new Set(state.settings.disabled_sizes || []);

		return `<div class="tco-card">
			<div class="tco-card__head">
				<div>
					<h2 class="tco-card__title">مدیریت زیرسایزهای بندانگشتی و بازسازی (Regenerate Thumbnails)</h2>
					<p class="tco-card__desc">سایزهای بلااستفادهٔ قالب/افزونه‌ها را خاموش کنید تا هم Inode هاست پر نشود و هم فضای فعلی آن‌ها آزاد گردد.</p>
				</div>
				<div class="tco-row-actions">
					<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tco-clean-disabled-sizes" ${disabledSet.size === 0 ? 'disabled title="هیچ زیرسایزی غیرفعال نشده است"' : 'title="فایل‌های زیرسایز غیرفعال را از دیسک پاک می‌کند"'}>حذف فایل‌های سایزهای غیرفعال از دیسک</button>
					<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tco-regen-all-btn">بازسازی دسته‌ای بندانگشتی‌ها</button>
					<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm" id="tco-save-sizes-btn">ذخیرهٔ وضعیت سایزها</button>
				</div>
			</div>

			<div class="tco-table-wrap">
				<table class="tco-table">
					<thead>
						<tr>
							<th>تولید در آپلودهای جدید</th>
							<th>کلید سایز (Slug)</th>
							<th>منشأ</th>
							<th>حداکثر ابعاد و برش</th>
							<th>تعداد فایل روی دیسک</th>
							<th>حجم اشغالی دیسک</th>
							<th>توصیهٔ هوشمند</th>
						</tr>
					</thead>
					<tbody>
						${sizes
							.map((sz) => {
								const enabled = !disabledSet.has(sz.key);
								return `<tr>
									<td>
										<input type="checkbox" class="tco-size-toggle" value="${esc(sz.key)}" ${enabled ? 'checked' : ''} ${sz.essential ? 'disabled' : ''}>
										${sz.essential ? '<span class="tco-pill tco-pill--ok">ضروری فروشگاه</span>' : ''}
									</td>
									<td><strong class="tco-code">${esc(sz.key)}</strong></td>
									<td><span class="tco-pill tco-pill--neutral">${esc(sz.source)}</span></td>
									<td>${faNum(sz.width)} × ${faNum(sz.height)} ${sz.crop ? '(برش دقیق)' : '(تناسبی)'}</td>
									<td>${faNum(sz.files_count)} فایل${sz.avg_bytes ? `<span class="tco-subtext">میانگین ${formatBytes(sz.avg_bytes)}</span>` : ''}</td>
									<td><strong>${formatBytes(sz.bytes)}</strong>${sz.share_pct ? `<span class="tco-subtext">${faNum(sz.share_pct)}٪ از کل زیرسایزها</span>` : ''}</td>
									<td class="tco-advice tco-advice--${esc(sz.advice || 'keep')}">
										${sz.advice === 'disable' ? '<span class="tco-pill tco-pill--warn">پیشنهاد خاموش‌کردن</span>' : ''}
										${sz.advice === 'review' ? '<span class="tco-pill tco-pill--warn">نیازمند بازبینی</span>' : ''}
										${sz.advice === 'oversized' ? '<span class="tco-pill tco-pill--info">ابعاد بزرگ</span>' : ''}
										${sz.advice === 'keep' ? '<span class="tco-pill tco-pill--ok">نگه‌داشتن</span>' : ''}
										<span class="tco-advice__text">${esc(sz.advice_text || '')}</span>
									</td>
								</tr>`;
							})
							.join('')}
					</tbody>
				</table>
			</div>
		</div>`;
	}

	function renderSeoTab() {
		const seo = state.seo;
		if (!seo) {
			return '<div class="tco-card"><div class="tco-loading-state"><span class="tco-spinner"></span><span>در حال ممیزی متن جایگزین (Alt) و عناوین سئو…</span></div></div>';
		}

		return `<div class="tco-card">
			<div class="tco-card__head">
				<div>
					<h2 class="tco-card__title">ممیزی و تکمیل خودکار سئوی تصاویر (Alt و Title از روی محصول متصل)</h2>
					<p class="tco-card__desc">پوشش فعلی Alt در سایت: <strong>${faNum(seo.summary.coverage_pct)}٪</strong> (${faNum(seo.summary.missing_alt)} تصویر بدون Alt · ${faNum(seo.summary.bad_titles)} عنوان خام دوربین/تلگرام)</p>
				</div>
			</div>

			<div class="tco-form-grid" style="margin-bottom:16px">
				<div class="tco-field">
					<label for="tco-seo-pattern">الگوی خودکار متن جایگزین (Alt) — متغیرها: <span class="tco-code">{product_title} {category} {sku} {site_name}</span></label>
					<input type="text" class="tco-input" id="tco-seo-pattern" value="${esc(state.settings.seo_alt_pattern || '{product_title} — {site_name}')}">
				</div>
				<div class="tco-field">
					<label>&nbsp;</label>
					<div class="tco-row-actions">
						<button type="button" class="tisa-btn tisa-btn--primary" id="tco-bulk-seo-btn">تکمیل خودکار همهٔ ${faNum(seo.summary.missing_alt)} تصویر بدون Alt</button>
					</div>
				</div>
			</div>

			<div class="tco-table-wrap">
				<table class="tco-table">
					<thead>
						<tr>
							<th>تصویر</th>
							<th>محصول / محل استفاده</th>
							<th>متن جایگزین (Alt) — قابل ویرایش فوری</th>
							<th>ذخیره</th>
						</tr>
					</thead>
					<tbody>
						${
							seo.rows.length
								? seo.rows
										.map(
											(r) => `<tr>
											<td>
												<div class="tco-media-cell">
													${r.thumb_url ? `<img class="tco-thumb" src="${esc(r.thumb_url)}" alt="">` : `<div class="tco-thumb tco-thumb--placeholder">${esc(r.format)}</div>`}
													<div>
														<strong class="tco-code">${esc(r.filename)}</strong>
														<span class="tco-subtext">#${faNum(r.id)}</span>
													</div>
												</div>
											</td>
											<td>${renderUsageCell(r)}</td>
											<td><input type="text" class="tco-input" style="width:100%" id="tco-alt-in-${r.id}" value="${esc(r.alt || r.suggested_alt)}"></td>
											<td><button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" data-save-seo="${r.id}">ثبت Alt</button></td>
										</tr>`
										)
										.join('')
								: '<tr><td colspan="4"><div class="tco-empty-state">عالی! همهٔ تصاویر دارای متن جایگزین معتبر هستند.</div></td></tr>'
						}
					</tbody>
				</table>
			</div>
			${renderPager({
				page: seo.page || 1,
				pages: seo.pages || 1,
				total: seo.total || 0,
				scope: seo.rows ? seo.rows.length : 0,
			})}
		</div>`;
	}

	function renderSettingsTab() {
		const s = state.settings;
		return `<div class="tco-stack">
			<div class="tco-card">
				<div class="tco-card__head">
					<div>
						<h2 class="tco-card__title">تنظیمات ایمنی، قرنطینه، پوشه‌های مستثنی و خط فرمان WP-CLI</h2>
						<p class="tco-card__desc">پیکربندی پیش‌فرض‌های حذف، مدت نگهداری در قرنطینه و محافظت خودکار از پوشه‌های حساس.</p>
					</div>
				</div>
				<div class="tco-form-grid">
					<div class="tco-field">
						<label for="tco-cfg-delmode">رفتار پیش‌فرض دکمه‌های حذف</label>
						<select class="tco-select" id="tco-cfg-delmode">
							<option value="quarantine" ${s.delete_mode === 'quarantine' ? 'selected' : ''}>انتقال به قرنطینهٔ امن با قابلیت بازیابی (پیشنهادی)</option>
							<option value="permanent" ${s.delete_mode === 'permanent' ? 'selected' : ''}>حذف قطعی و دائمی فوری از دیسک</option>
						</select>
					</div>
					<div class="tco-field">
						<label for="tco-cfg-qdays">مدت نگهداری خودکار در قرنطینه (روز)</label>
						<input type="number" class="tco-input" id="tco-cfg-qdays" value="${Number(s.quarantine_days || 30)}" min="1" max="365">
					</div>
				</div>
				<div class="tco-form-grid" style="margin-top:16px">
					<div class="tco-field">
						<label for="tco-cfg-tickms">سقف زمان هر درخواست حذف (میلی‌ثانیه) — بار روی سرور</label>
						<input type="number" class="tco-input" id="tco-cfg-tickms" value="${Number(s.delete_tick_ms || 4000)}" min="1500" max="15000" step="500">
						<span class="tco-muted">پیش‌فرض ۴۰۰۰. کمتر = درخواست‌های کوتاه‌تر و فشار کمتر روی هاست اشتراکی؛ بیشتر = تمام‌شدن سریع‌تر کار.</span>
					</div>
					<div class="tco-field">
						<label for="tco-cfg-tickmax">حداکثر تصویر در هر درخواست</label>
						<input type="number" class="tco-input" id="tco-cfg-tickmax" value="${Number(s.delete_tick_max || 40)}" min="5" max="200">
						<span class="tco-muted">پیش‌فرض ۴۰. هر تیک پس از این تعداد یا پس از رسیدن به سقف زمان، تمام می‌شود و کار در سرور ذخیره می‌ماند.</span>
					</div>
					<div class="tco-field">
						<label for="tco-cfg-verifychunk">تعداد تصویر در هر گام بازرسی ایمنی</label>
						<input type="number" class="tco-input" id="tco-cfg-verifychunk" value="${Number(s.verify_chunk || 100)}" min="10" max="200">
						<span class="tco-muted">پیش‌فرض ۱۰۰. اگر حذف انبوه در مراحل اول «۰ از N» می‌ماند، این عدد را کمتر کنید تا هر گام سریع‌تر تمام شود.</span>
					</div>
					<div class="tco-field">
						<label for="tco-cfg-throttle">مکث بین تصاویر (میلی‌ثانیه)</label>
						<input type="number" class="tco-input" id="tco-cfg-throttle" value="${Number(s.delete_throttle_ms || 15)}" min="0" max="250" step="5">
						<span class="tco-muted">پیش‌فرض ۱۵. جلوگیری از اشباع دیسک (IOPS) در حذف‌های چندصدتایی.</span>
					</div>
				</div>
				<div class="tco-field" style="margin-top:16px">
					<label for="tco-cfg-excl">زیرپوشه‌های مستثنی در اسکن فیزیکی دیسک (هر خط یک پوشه داخل uploads/)</label>
					<textarea class="tco-textarea tco-code" id="tco-cfg-excl">${esc(s.excluded_dirs || '')}</textarea>
				</div>
				<div class="tco-win__foot" style="margin-top:16px">
					<span class="tco-code">wp tisacase optimize status | scan | clean-unused | clean-unscaled | compress --webp | dedup | seo | vault</span>
					<button type="button" class="tisa-btn tisa-btn--primary" id="tco-save-general-cfg">ذخیرهٔ تنظیمات کلی</button>
				</div>
			</div>
		</div>`;
	}

	function renderVaultModule() {
		const v = state.vault;
		if (!v) {
			return '<div class="tco-card"><div class="tco-loading-state"><span class="tco-spinner"></span><span>در حال باز کردن مرکز پشتیبان و بازگردانی…</span></div></div>';
		}

		const qSearch = (state.vaultSearch || '').trim().toLowerCase();
		const filteredQ = qSearch
			? (v.quarantine || []).filter((q) =>
					`${q.filename || ''} ${q.rel_path || ''} ${q.attachment_id || ''} ${q.title || ''}`.toLowerCase().includes(qSearch)
			  )
			: v.quarantine || [];
		const qSelCount = state.selectedQuarantineIds.size;
		const recoverableRuns = (v.history || []).filter((h) => Number(h.recoverable_count || 0) > 0).length;

		return `<div class="tco-stack">
			<div class="tco-kpi-grid">
				<div class="tco-kpi">
					<span class="tco-kpi__label">تصاویر آمادهٔ بازگردانی در قرنطینه</span>
					<span class="tco-kpi__val">${faNum(v.summary.quarantine_count)} تصویر</span>
					<span class="tco-kpi__meta">نگهداری خودکار ۳۰ روزه · حجم: ${formatBytes(v.summary.quarantine_bytes)}</span>
				</div>
				<div class="tco-kpi">
					<span class="tco-kpi__label">نوبت‌های اجرای قابل بازگردانی یک‌جا</span>
					<span class="tco-kpi__val">${faNum(recoverableRuns)} نوبت</span>
					<span class="tco-kpi__meta">بازگردانی کل یک عملیات با یک کلیک</span>
				</div>
				<div class="tco-kpi">
					<span class="tco-kpi__label">پشتیبان‌های خام قبل از فشرده‌سازی</span>
					<span class="tco-kpi__val">${faNum(v.summary.originals_count)} تصویر</span>
					<span class="tco-kpi__meta">حجم فایل‌های خام: ${formatBytes(v.summary.originals_bytes)}</span>
				</div>
				<div class="tco-kpi tco-kpi--accent">
					<span class="tco-kpi__label">تضمین بازگردانی ۱۰۰٪ بدون شکست لینک</span>
					<span class="tco-kpi__val">همان ID قبلی</span>
					<span class="tco-kpi__meta">بازسازی کامل فایل اصلی، زیرسایزها و متاها</span>
				</div>
			</div>

			<div class="tco-subnav">
				<button type="button" class="tco-subnav__btn${state.vaultTab === 'quarantine' ? ' is-active' : ''}" data-vault-tab="quarantine">قرنطینه و بازگردانی تصاویر حذف‌شده (${faNum(v.quarantine.length)})</button>
				<button type="button" class="tco-subnav__btn${state.vaultTab === 'history' ? ' is-active' : ''}" data-vault-tab="history">گزارش نوبت‌ها و بازگردانی گروهی (${faNum(v.history.length)})</button>
				<button type="button" class="tco-subnav__btn${state.vaultTab === 'originals' ? ' is-active' : ''}" data-vault-tab="originals">پشتیبان‌های اولیهٔ فشرده‌سازی / WebP (${faNum(v.originals.length)})</button>
			</div>

			<div class="tco-card">
				${
					state.vaultTab === 'quarantine'
						? `<div class="tco-card__head">
							<div>
								<h2 class="tco-card__title">پشتیبان و بازگردانی تصاویر قرنطینه‌شده (تکی، انتخابی یا بازگردانی همه با ۱ کلیک)</h2>
								<p class="tco-card__desc">هر تصویر دقیقاً به همان مسیر <span class="tco-code">uploads/</span>، همان <span class="tco-code">Attachment ID</span>، زیرسایزها و متاهای ووکامرس برمی‌گردد.</p>
							</div>
							<div class="tco-row-actions">
								${
									qSelCount > 0
										? `<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm" id="tco-restore-selected-q">بازگردانی انتخاب‌شده‌ها (${faNum(qSelCount)})</button>`
										: ''
								}
								<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm" id="tco-restore-all-quarantine" ${v.quarantine.length === 0 ? 'disabled title="خزانهٔ قرنطینه خالی است"' : 'title="بازگردانی همهٔ موارد قرنطینه به جای اول"'}>بازگردانی همهٔ موارد (${faNum(v.quarantine.length)})</button>
								<button type="button" class="tisa-btn tisa-btn--sm tco-btn-danger" id="tco-purge-all-quarantine" ${v.quarantine.length === 0 ? 'disabled title="چیزی برای پاک‌سازی نیست"' : 'title="پاک‌سازی دائمی همهٔ موارد قرنطینه (غیرقابل بازگشت)"}'}>تخلیهٔ دائمی قرنطینه</button>
							</div>
						</div>
						<div class="tco-bulkbar">
							<input type="search" class="tco-input" id="tco-vault-search" placeholder="جستجوی نام فایل، مسیر یا شناسهٔ پیوست در قرنطینه…" value="${esc(state.vaultSearch || '')}">
							<span>نمایش <strong>${faNum(filteredQ.length)}</strong> بسته از ${faNum(v.quarantine.length)}</span>
						</div>
						<div class="tco-table-wrap">
							<table class="tco-table">
								<thead><tr><th><input type="checkbox" id="tco-check-q-all"></th><th>فایل / پیوست</th><th>علت</th><th>حجم بسته</th><th>زمان قرنطینه</th><th>عملیات بازگردانی / حذف</th></tr></thead>
								<tbody>
									${
										filteredQ.length
											? filteredQ
													.map((q) => {
														const qck = state.selectedQuarantineIds.has(q.id);
														return `<tr class="${qck ? 'is-selected' : ''}">
														<td><input type="checkbox" class="tco-q-check" value="${esc(q.id)}" ${qck ? 'checked' : ''}></td>
														<td><strong class="tco-code">${esc(q.filename)}</strong><span class="tco-subtext">${q.attachment_id ? '#' + faNum(q.attachment_id) + ' · ' : ''}${esc(q.rel_path)}</span></td>
														<td><span class="tco-pill tco-pill--neutral">${esc(q.reason)}</span></td>
														<td><strong>${formatBytes(q.total_bytes)}</strong> (${faNum(q.files_count)} فایل)</td>
														<td><span class="tco-code">${esc(q.created_at)}</span></td>
														<td>
															<div class="tco-row-actions">
																<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm" data-restore-q="${esc(q.id)}">بازگردانی به جای اول</button>
																<button type="button" class="tisa-btn tisa-btn--sm tco-btn-danger" data-purge-q="${esc(q.id)}">حذف قطعی</button>
															</div>
														</td>
													</tr>`;
													})
													.join('')
											: '<tr><td colspan="6"><div class="tco-empty-state">هیچ بسته‌ای در قرنطینه وجود ندارد.</div></td></tr>'
									}
								</tbody>
							</table>
						</div>`
						: state.vaultTab === 'originals'
						? `<div class="tco-card__head">
							<div>
								<h2 class="tco-card__title">نسخه‌های پشتیبان پیش از فشرده‌سازی / جایگزینی WebP</h2>
								<p class="tco-card__desc">در صورت نیاز می‌توانید یک تصویر یا همهٔ تصاویر فشرده‌شده را با ۱ کلیک به فایل خام اولیه برگردانید.</p>
							</div>
							<div class="tco-row-actions">
								<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm" id="tco-restore-all-orig" ${v.originals.length === 0 ? 'disabled title="هیچ نسخهٔ پشتیبان اولیه‌ای وجود ندارد"' : 'title="بازگردانی همهٔ تصاویر به فایل خام اولیه"'}>بازگردانی همه به فایل اولیه (${faNum(v.originals.length)})</button>
								<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tco-purge-all-orig" ${v.originals.length === 0 ? 'disabled title="نسخهٔ پشتیبان اولیه‌ای برای آزادسازی نیست"' : 'title="آزادسازی فضای پشتیبان‌های اولیه"'}>آزادسازی فضای پشتیبان‌ها (${formatBytes(v.summary.originals_bytes)})</button>
							</div>
						</div>
						<div class="tco-table-wrap">
							<table class="tco-table">
								<thead><tr><th>شناسه و فایل اصلی</th><th>فرمت اولیه</th><th>حجم پشتیبان</th><th>تاریخ</th><th>بازگردانی</th></tr></thead>
								<tbody>
									${
										v.originals.length
											? v.originals
													.map(
														(o) => `<tr>
														<td><strong class="tco-code">#${o.attachment_id} · ${esc(o.rel_path)}</strong></td>
														<td><span class="tco-pill tco-pill--neutral">${esc(o.mime_type)}</span></td>
														<td><strong>${formatBytes(o.bytes)}</strong></td>
														<td><span class="tco-code">${esc(o.created_at)}</span></td>
														<td><button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm" data-restore-orig="${o.attachment_id}">بازگردانی فایل اولیه</button></td>
													</tr>`
													)
													.join('')
											: '<tr><td colspan="5"><div class="tco-empty-state">نسخهٔ پشتیبان اولیه‌ای ثبت نشده است.</div></td></tr>'
									}
								</tbody>
							</table>
						</div>`
						: `<div class="tco-card__head">
							<div>
								<h2 class="tco-card__title">گزارش نوبت‌های اجرا و بازگردانی یک‌جای هر نوبت</h2>
								<p class="tco-card__desc">هر نوبت پاک‌سازی در حالت قرنطینه دارای دکمهٔ «بازگردانی کامل این نوبت» است تا دقیقاً مثل سایر افزونه‌های تیساکیس با یک کلیک کل عملیات برگردد.</p>
							</div>
							<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" id="tco-clear-history-btn" ${v.history.length === 0 ? 'disabled title="گزارشی برای پاک کردن نیست"' : 'title="پاک کردن گزارش اجراها"'}>پاک کردن گزارش</button>
						</div>
						<div class="tco-table-wrap">
							<table class="tco-table">
								<thead><tr><th>عملیات انجام‌شده</th><th>تعداد موارد</th><th>فضای آزادشده</th><th>توسط / زمان</th><th>بازگردانی نوبت</th></tr></thead>
								<tbody>
									${
										v.history.length
											? v.history
													.map((h) => {
														const recN = Number(h.recoverable_count || 0);
														return `<tr>
														<td><strong>${esc(h.label)}</strong>${h.note ? `<span class="tco-subtext">${esc(h.note)}</span>` : ''}</td>
														<td>${faNum(h.items_count)} مورد</td>
														<td><strong>${formatBytes(h.saved_bytes)}</strong></td>
														<td>${esc(h.user)}<span class="tco-subtext tco-code">${esc(h.created_at)}</span></td>
														<td>
															${
																recN > 0 && h.batch_id
																	? `<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm" data-restore-batch="${esc(h.batch_id)}">بازگردانی کامل این نوبت (${faNum(recN)})</button>`
																	: '<span class="tco-pill tco-pill--neutral">—</span>'
															}
														</td>
													</tr>`;
													})
													.join('')
											: '<tr><td colspan="5"><div class="tco-empty-state">هنوز عملیاتی ثبت نشده است.</div></td></tr>'
									}
								</tbody>
							</table>
						</div>`
				}
			</div>
		</div>`;
	}

	/**
	 * کارت «چکاپ پیش از عملیات» (B7): وضعیت آمادگی سرور با توصیهٔ فارسی برای
	 * هر مورد. اگر چیزی مانع کار باشد، صریح و همان‌جا گفته می‌شود.
	 */
	function renderPreflightCard() {
		const pf = state.preflight;
		const head = `<div class="tco-card__head">
			<div>
				<h2 class="tco-card__title">چکاپ پیش از عملیات (آمادگی سرور)</h2>
				<p class="tco-card__desc">حافظه، موتور تصویر، دسترسی نوشتن، فضای دیسک و تازگی کاتالوگ — همه در یک نگاه.</p>
			</div>
			<div class="tco-row-actions">
				${pf ? `<span class="tco-pill ${pf.level === 'ok' ? 'tco-pill--ok' : pf.level === 'warn' ? 'tco-pill--warn' : 'tco-pill--danger'}">امتیاز آمادگی: ${faNum(pf.score)} از ۱۰۰</span>` : ''}
				<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tco-preflight-btn">${pf ? 'اجرای مجدد چکاپ' : 'اجرای چکاپ'}</button>
			</div>
		</div>`;

		if (!pf) {
			return `<div class="tco-card" style="margin-top:16px">${head}<p class="tco-muted">در حال بررسی سرور…</p></div>`;
		}

		const rows = (pf.checks || [])
			.map(
				(c) => `<tr>
					<td><strong>${esc(c.label)}</strong></td>
					<td><span class="tco-pill ${c.status === 'ok' ? 'tco-pill--ok' : c.status === 'warn' ? 'tco-pill--warn' : c.status === 'fail' ? 'tco-pill--danger' : 'tco-pill--neutral'}">${
						c.status === 'ok' ? 'سالم' : c.status === 'warn' ? 'هشدار' : c.status === 'fail' ? 'مانع' : 'اطلاع'
					}</span></td>
					<td class="tco-code">${esc(c.value)}</td>
					<td class="tco-advice__text">${esc(c.advice)}</td>
				</tr>`
			)
			.join('');

		return `<div class="tco-card" style="margin-top:16px">
			${head}
			<div class="tco-table-wrap">
				<table class="tco-table">
					<thead><tr><th>مورد</th><th>وضعیت</th><th>مقدار فعلی</th><th>توصیه</th></tr></thead>
					<tbody>${rows}</tbody>
				</table>
			</div>
			<p class="tco-muted">این چکاپ هیچ تنظیمی را تغییر نمی‌دهد؛ فقط می‌گوید کدام مورد ممکن است عملیات را متوقف کند.</p>
		</div>`;
	}

	function renderOtherModules() {
		const mod = (boot.modules || []).find((m) => m.id === state.module);
		if (!mod) return '';

		if (state.module === 'system') {
			const d = (boot.moduleBoot && boot.moduleBoot.system && boot.moduleBoot.system.diagnostics) || {};
			return `<div class="tco-card">
				<div class="tco-card__head">
					<div>
						<h2 class="tco-card__title">${esc(mod.title)}</h2>
						<p class="tco-card__desc">${esc(mod.subtitle)}</p>
					</div>
				</div>
				<div class="tco-wins-grid">
					<div class="tco-win"><strong>نسخهٔ PHP / وردپرس / ووکامرس</strong><span class="tco-code">PHP ${esc(d.php_version)} · WP ${esc(d.wp_version)} · WC ${esc(d.wc_version)}</span></div>
					<div class="tco-win"><strong>موتور تصویر و کدک‌های نسل جدید</strong><span>${esc(d.image_engine)} · WebP: ${d.webp_supported ? 'فعال ✓' : 'غیرفعال'} · AVIF: ${d.avif_supported ? 'فعال ✓' : 'ندارد'} · ICC: ${d.icc_supported ? 'فعال ✓' : '—'}</span></div>
					<div class="tco-win"><strong>حافظه و سقف آپلود سرور</strong><span class="tco-code">Memory: ${esc(d.memory_limit)} · Timeout: ${esc(d.max_execution)} · Max Upload: ${esc(d.upload_max)}</span></div>
					<div class="tco-win"><strong>کش سرور و رویدادهای WP-Cron</strong><span>OPcache: ${d.opcache_active ? 'فعال ✓' : 'غیرفعال'} · Object Cache: ${d.object_cache ? 'فعال ✓' : 'غیرفعال'} · ${faNum(d.cron_events)} کرون فعال</span></div>
				</div>
				${renderPreflightCard()}
			</div>`;
		}

		const dbSnap = state.module === 'database' && boot.moduleBoot && boot.moduleBoot.database && boot.moduleBoot.database.snapshot;
		return `<div class="tco-card">
			<div class="tco-card__head">
				<div>
					<h2 class="tco-card__title">${esc(mod.title)} — اسکلت هستهٔ ماژولار آمادهٔ توسعه</h2>
					<p class="tco-card__desc">${esc(mod.subtitle)}</p>
				</div>
				<span class="tco-pill tco-pill--info">${esc(mod.badge)}</span>
			</div>
			${
				dbSnap
					? `<div class="tco-kpi-grid" style="margin-bottom:16px">
						<div class="tco-kpi"><span class="tco-kpi__label">رونوشت‌های ذخیره شده (Revisions)</span><span class="tco-kpi__val">${faNum(dbSnap.revisions)}</span></div>
						<div class="tco-kpi"><span class="tco-kpi__label">پیش‌نویس‌های خودکار (Auto-Drafts)</span><span class="tco-kpi__val">${faNum(dbSnap.auto_drafts)}</span></div>
						<div class="tco-kpi"><span class="tco-kpi__label">حجم بارگذاری دائمی Autoload</span><span class="tco-kpi__val">${formatBytes(dbSnap.autoload_bytes)}</span></div>
						<div class="tco-kpi tco-kpi--accent"><span class="tco-kpi__label">زیرساخت ماژول</span><span class="tco-kpi__val">ثبت‌شده ✓</span></div>
					</div>`
					: ''
			}
			<div class="tco-wins-grid">
				${(mod.roadmap || [])
					.map(
						(item) => `<div class="tco-win">
						<h3 class="tco-win__title">${esc(item.title)}</h3>
						<p class="tco-win__desc">${esc(item.desc)}</p>
					</div>`
					)
					.join('')}
			</div>
		</div>`;
	}

	function render() {
		renderNav();
		const main = document.getElementById('tco-main');
		if (!main) return;

		if (state.module === 'images') {
			let body = '';
			if (state.subtab === 'overview') body = renderOverviewTab();
			else if (state.subtab === 'explorer') body = renderExplorerTab();
			else if (state.subtab === 'unused') body = renderUnusedTab();
			else if (state.subtab === 'restore') body = renderVaultModule();
			else if (state.subtab === 'compress') body = renderCompressTab();
			else if (state.subtab === 'duplicates') body = renderDuplicatesTab();
			else if (state.subtab === 'subsizes') body = renderSubsizesTab();
			else if (state.subtab === 'seo') body = renderSeoTab();
			else body = renderSettingsTab();

			main.innerHTML = renderImageSubnav() + renderResumeCard() + renderBatchProgress() + body;
		} else if (state.module === 'vault') {
			main.innerHTML = renderResumeCard() + renderBatchProgress() + renderVaultModule();
		} else {
			main.innerHTML = renderResumeCard() + renderOtherModules();
		}
	}

	function isVaultViewActive() {
		return state.module === 'vault' || (state.module === 'images' && state.subtab === 'restore');
	}

	async function runIncrementalScan(resetFromZero = false, immediateFastFinish = false) {
		if (immediateFastFinish) {
			state.scan.fastFinishRequested = true;
		}
		if (state.scan.running) return;
		state.loadError = '';
		state.scan = {
			running: true,
			fastFinishRequested: Boolean(immediateFastFinish),
			canSkip: false,
			stageNum: 1,
			stageLabel: 'گام ۱ از ۵: خواندن فهرست تصاویر کتابخانهٔ رسانه…',
			detailLabel: 'در حال شروع…',
			percent: 5,
			elapsedSec: 0,
		};
		render();

		// یک توکن برای کل اسکن (چند تیک پشت‌سرهم): نوار بین درخواست‌ها خاموش نمی‌شود.
		const scanToken = busyStart('اسکن رسانه — شروع…', { pct: 5 });
		busyProgress(5, 'شروع اسکن: خواندن فهرست تصاویر کتابخانهٔ رسانه…');

		let firstTick = Boolean(resetFromZero);
		let retries = 0;

		try {

		while (true) {
			try {
				const step = await api('scan_step', {
					reset: firstTick ? 1 : 0,
					fast_finish: state.scan.fastFinishRequested ? 1 : 0,
				});
				firstTick = false;
				retries = 0;

				state.scan.stageNum = step.stage_num || state.scan.stageNum;
				state.scan.stageLabel = step.stage_label || state.scan.stageLabel;
				state.scan.detailLabel = step.detail_label || '';
				state.scan.percent = Number(step.percent || state.scan.percent);
				state.scan.canSkip = Boolean(step.can_skip);
				state.scan.elapsedSec = Number(step.elapsed_sec || 0);

				busyProgress(
					Number(step.percent || 0),
					(state.scan.stageLabel || 'اسکن رسانه') + (state.scan.detailLabel ? ' · ' + state.scan.detailLabel : '')
				);

				if (step.done) {
					state.scan.running = false;
					state.scan.fastFinishRequested = false;
					if (step.overview) {
						state.overview = step.overview;
					} else {
						state.overview = await api('overview');
					}
					render();
					return;
				}
				render();
			} catch (err) {
				retries += 1;
				if (retries <= 2) {
					state.scan.detailLabel = `وقفهٔ موقت شبکه — تلاش خودکار مجدد (${faNum(retries)}/۲)…`;
					render();
					await new Promise((r) => setTimeout(r, 800));
					continue;
				}
				state.scan.running = false;
				state.loadError = err.message || 'خطا در حین اسکن سایت';
				render();
				throw err;
			}
		}

		} finally {
			busyEnd(scanToken);
		}
	}

	async function ensureDataForCurrentView(forceRescan = false, resumeSaved = false) {
		try {
			state.loadError = '';

			// بخش «پشتیبان و بازگردانی» و سایر ماژول‌ها فوراً در کمتر از ۱۵ میلی‌ثانیه باز می‌شوند و هرگز منتظر اسکن نمی‌مانند!
			if (isVaultViewActive()) {
				state.vault = await api('get_vault');
				render();
				return;
			}
			if (state.module !== 'images') {
				if (state.module === 'system' && !state.preflight) {
					state.preflight = await api('preflight');
				}
				render();
				return;
			}

			if (resumeSaved) {
				await runIncrementalScan(false);
			} else if (forceRescan) {
				await runIncrementalScan(true);
			} else if (!state.overview) {
				const ov = await api('overview');
				if (ov && ov.needs_scan) {
					await runIncrementalScan(false);
				} else {
					state.overview = ov;
				}
			}

			if (state.module === 'images') {
				if (state.subtab === 'explorer') {
					state.media = await api('query_media', Object.assign({}, state.explorerFilter, { per_page: state.perPage }));
				} else if (state.subtab === 'unused') {
					state.media = await api('query_media', Object.assign({}, state.unusedFilter, { per_page: state.perPage }));

				} else if (state.subtab === 'restore') {
					state.vault = await api('get_vault');
				} else if (state.subtab === 'duplicates') {
					state.duplicates = await api('get_duplicates');
				} else if (state.subtab === 'subsizes') {
					const d = await api('get_subsizes');
					state.subsizes = d.sizes || [];
				} else if (state.subtab === 'seo') {
					state.seo = await api('get_seo', { seo_filter: state.seoFilter, page: state.seoPage, per_page: state.perPage });
				}
			}
			render();
		} catch (err) {
			state.loadError = err.message || 'خطا در دریافت اطلاعات';
			render();
			toast(state.loadError, 'error');
		}
	}

	/**
	 * درصد پیشرفت دستهٔ جاری برای نوار بالای صفحه.
	 * @param {object} batch state.batch
	 * @param {string} [suffix] برچسب تکمیلی
	 */
	function batchPct(batch, suffix) {
		if (!batch || !batch.total) return null;
		const pct = Math.min(100, Math.round((batch.done / batch.total) * 100));
		busyProgress(pct, `${batch.title} — ${faNum(batch.done)} از ${faNum(batch.total)}${suffix ? ' · ' + suffix : ''}`);
		return pct;
	}

	/**
	 * حذف/قرنطینه با موتور کار «قابل ادامه» در سرور.
	 *
	 * تفاوت بنیادین با نسخهٔ قبل: صف در سرور است، نه در مرورگر. هر تیک فقط
	 * چند ثانیه کار می‌کند و «باقی‌مانده» را برمی‌گرداند؛ پس:
	 *   • هیچ شناسه‌ای گم نمی‌شود (باگ قبلی: سرور در میانهٔ بسته به سقف زمان
	 *     می‌رسید و بقیهٔ ۲۵تایی رها می‌شد).
	 *   • اگر مرورگر بسته شود/اینترنت قطع شود/سرور خطا بدهد، کار ذخیره است و
	 *     از همان نقطه ادامه می‌یابد.
	 *   • فشار روی سرور: هر تیک کوتاه + فاصلهٔ تنفس بین تصاویر.
	 *
	 * @param {Array}  ids      شناسه‌های پیوست.
	 * @param {Array}  relPaths مسیر فایل‌های یتیم.
	 * @param {string} resumeJobId شناسهٔ کار برای ادامه (اختیاری).
	 */
	async function runBatchRemove(ids, relPaths, resumeJobId) {
		const totalItems = (ids ? ids.length : 0) + (relPaths ? relPaths.length : 0);
		state.batch = {
			running: true,
			stopRequested: false,
			title: state.deleteMode === 'permanent' ? 'حذف قطعی تصاویر' : 'انتقال ایمن به قرنطینهٔ ۳۰ روزه (با قابلیت بازگردانی)',
			done: 0,
			total: totalItems,
			savedBytes: 0,
			blocked: 0,
			needsRescan: false,
			jobId: resumeJobId || '',
			verified: 0,
			phase: '',
			hint: '',
			blockReasons: [],
			etaSec: 0,
			remaining: totalItems,
			failed: 0,
			error: '',
		};
		render();
		const runToken = busyStart(state.batch.title, { pct: 0 });
		batchPct(state.batch, 'شروع…');

		let stoppedByUser = false;
		let announcedReclaim = false;

		try {
			let jobId = resumeJobId || '';
			let finished = false;
			let guard = 0;
			// ضدبی‌حرکت: اگر چند تیک پشت‌سرهم هیچ پیشرفتی ثبت نشود، به‌جای
			// چرخیدن بی‌پایان، صادقانه می‌ایستیم و دلیل را نشان می‌دهیم.
			let stallTicks = 0;
			let lastSignature = '';

			while (!finished && guard < 4000) {
				guard += 1;

				if (state.batch.stopRequested) {
					stoppedByUser = true;
					break;
				}

				const payload = jobId ? { job_id: jobId } : {
					ids: ids || [],
					rel_paths: relPaths || [],
					delete_mode: state.deleteMode,
					allow_draft_only: (state.unusedFilter && state.unusedFilter.usage === 'draft_only') ? 1 : 0,
				};

				const res = await api('remove_items', payload);
				if (!res || !res.job) break;

				// سرور ممکن است پیش از شروع، کار رهاشدهٔ قبلی را بسته باشد.
				if (res.reclaimed && !announcedReclaim) {
					announcedReclaim = true;
					const rec = res.reclaimed;
					toast(
						`کار قبلی (${opLabelOf(rec.op)}: ${faNum(rec.done)} از ${faNum(rec.total)}) که ${faNum(
							rec.idle_minutes
						)} دقیقه هیچ پیشرفتی نداشت رها شد؛ نتایج انجام‌شده‌اش ثبت ماند و این عملیات شروع شد.`
					);
				}

				const job = res.job;
				jobId = job.id;

				state.batch.jobId = job.id;
				state.batch.total = job.total || state.batch.total;
				state.batch.done = job.done || 0;
				state.batch.remaining = job.remaining || 0;
				state.batch.savedBytes = job.saved_bytes || 0;
				state.batch.blocked = job.blocked_count || 0;
				state.batch.failed = job.failed_count || 0;
				state.batch.etaSec = job.eta_sec || 0;
				state.batch.lastError = job.last_error || '';
				state.batch.verified = job.verified || 0;
				state.batch.phase = job.phase || '';
				state.batch.hint = job.hint || '';
				state.batch.blockReasons = job.block_reasons || [];
				state.batch.blockedSample = job.blocked || {};
				if (job.needs_rescan) state.batch.needsRescan = true;
				if (res.overview) state.overview = res.overview;

				finished = Boolean(job.finished);

				const signature = `${state.batch.done}|${state.batch.verified}|${state.batch.remaining}`;
				if (signature === lastSignature && !finished) {
					stallTicks += 1;
				} else {
					stallTicks = 0;
					lastSignature = signature;
				}

				render();
				const phaseLabel = state.batch.phase === 'verify'
					? `بازرسی ایمنی: ${faNum(state.batch.verified)} بررسی شد`
					: (state.batch.etaSec > 0 ? `حدود ${faNum(state.batch.etaSec)} ثانیه باقی‌مانده` : 'در حال پردازش…');
				batchPct(state.batch, phaseLabel);

				// اگر سرور خطا داده باشد، همان‌جا متوقف می‌شویم؛ کار ذخیره است.
				if (res.error) {
					state.batch.error = res.error;
					break;
				}

				if (stallTicks >= 3) {
					state.batch.error = state.batch.hint || 'سرور در سه درخواست پیاپی هیچ پیشرفتی ثبت نکرد؛ اگر ادامه دادید و باز هم متوقف شد، «اسکن کامل» را اجرا کنید.';
					break;
				}

				if (!finished) {
					// یک مکث کوتاه بین تیک‌ها تا مرورگر هم فرصت رندر داشته باشد.
					await new Promise((r) => setTimeout(r, 60));
				}
			}

			if (finished && state.batch.done === 0 && (state.batch.blocked > 0 || state.batch.failed > 0)) {
				// هیچ چیزی حذف نشد: هرگز «انجام شد» نگوییم؛ دلیل واقعی را بگوییم.
				state.selectedIds.clear();
				state.selectedPaths.clear();
				state.lastRun = {
					op: state.batch.op || 'remove',
					done: 0,
					blocked: state.batch.blocked,
					failed: state.batch.failed,
					blockReasons: state.batch.blockReasons || [],
					blockedItems: state.batch.blockedSample || {},
					needsRescan: Boolean(state.batch.needsRescan),
					at: Date.now(),
				};
				const top = (state.batch.blockReasons || []).slice(0, 2)
					.map((r) => `${faNum(r.count)} مورد: ${r.reason}`)
					.join(' · ');
				toast(
					`هیچ تصویری حذف نشد — ${faNum(state.batch.blocked)} مورد در بازرسی ایمنی رد شد` +
						(top ? ` (${top})` : '') +
						(state.batch.hint ? ` — ${state.batch.hint}` : ''),
					'error'
				);
				state.activeJob = null;
			} else if (finished) {
				state.selectedIds.clear();
				state.selectedPaths.clear();
				// جزئیات واقعی به‌جای جملهٔ مبهم: دقیقاً چه چیزی و چرا حذف نشد.
				const reasons = (state.batch.blockReasons || [])
					.map((x) => `${faNum(x.count)} مورد: ${x.reason}`)
					.join(' · ');
				const keptNote = state.batch.blocked > 0
					? ` · ${faNum(state.batch.blocked)} مورد حذف نشد${reasons ? ` (${reasons})` : ' — چون هنوز در سایت استفاده می‌شود'}.`
					: '';
				state.lastRun = {
					op: state.batch.op || 'remove',
					done: state.batch.done,
					blocked: state.batch.blocked,
					failed: state.batch.failed,
					blockReasons: state.batch.blockReasons || [],
					blockedItems: state.batch.blockedSample || {},
					needsRescan: Boolean(state.batch.needsRescan),
					at: Date.now(),
				};
				const failNote = state.batch.failed > 0
					? ` · ${faNum(state.batch.failed)} مورد با خطای فایل مواجه شد.`
					: '';
				toast(
					state.deleteMode === 'permanent'
						? `حذف قطعی ${faNum(state.batch.done)} تصویر انجام شد — ${formatBytes(state.batch.savedBytes)} آزاد شد${keptNote}${failNote}`
						: `${faNum(state.batch.done)} تصویر به قرنطینه منتقل شد (${formatBytes(state.batch.savedBytes)}) — از تب «پشتیبان و بازگردانی» قابل برگشت است${keptNote}${failNote}`
				);
				if (state.batch.needsRescan) {
					toast('برای حذف مطمئن، ابتدا «اسکن کامل تصاویر» را اجرا کنید؛ نتایج «نمایش فوری» فقط برای مرور است.', 'error');
				}
				state.activeJob = null;
			} else {
				// نیمه‌کاره: کار در سرور ذخیره است و کارت «ادامه» نمایش داده می‌شود.
				state.activeJob = {
					id: jobId,
					done: state.batch.done,
					total: state.batch.total,
					remaining: state.batch.remaining,
					blocked: state.batch.blocked,
					failed: state.batch.failed,
					verified: state.batch.verified,
					phase: state.batch.phase,
					hint: state.batch.hint,
					needsRescan: state.batch.needsRescan,
					blockReasons: state.batch.blockReasons,
					lastError: stoppedByUser ? 'توسط شما متوقف شد' : (state.batch.error || state.batch.lastError || 'ارتباط با سرور قطع شد'),
				};
				toast(
					`عملیات متوقف شد — ${faNum(state.batch.done)} از ${faNum(state.batch.total)} انجام شده است. ` +
					'می‌توانید از کارت «ادامهٔ عملیات» در بالای صفحه ادامه دهید.',
					'error'
				);
			}
		} catch (e) {
			// تعارض کار: عملیات تازه حتی شروع نشده؛ پیام «انجام شد» دادن دروغ است.
			if (e && e.isConflict) {
				await handleJobConflict(e, opts);
				return;
			}

			// کار در سرور ذخیره مانده است؛ فقط باید به کاربر بگوییم و امکان ادامه بدهیم.
			state.activeJob = {
				id: state.batch.jobId || '',
				done: state.batch.done,
				total: state.batch.total,
				remaining: state.batch.remaining,
				blocked: state.batch.blocked,
				failed: state.batch.failed,
				verified: state.batch.verified,
				phase: state.batch.phase,
				hint: state.batch.hint,
				needsRescan: state.batch.needsRescan,
				blockReasons: state.batch.blockReasons,
				lastError: e.message || 'خطای نامشخص',
			};
			// عددهای نمایش‌داده‌شده را با حقیقتِ سرور هم‌تراز می‌کنیم تا هیچ‌گاه
			// «چند مورد انجام شد» گمانه‌زنی نباشد.
			await refreshJobStatus();
			toast(
				`عملیات قطع شد (${e.message || 'خطای شبکه'}) — ${faNum(state.batch.done)} از ${faNum(state.batch.total)} انجام شده و ذخیره است.`,
				'error'
			);
		} finally {
			busyEnd(runToken);
			state.batch.running = false;
			render();
			const ov = await api('overview').catch(() => null);
			if (ov && !ov.needs_scan) state.overview = ov;
			await ensureDataForCurrentView(false);
		}
	}

	/**
	 * خواندن وضعیت کار ذخیره‌شده (برای کارت ادامه، بعد از رفرش یا قطعی).
	 */
	async function refreshJobStatus() {
		try {
			const res = await api('job_status');
			const job = res && res.job;
			if (!job || job.finished) {
				state.activeJob = null;
				return;
			}
			state.activeJob = {
				id: job.id,
				op: job.op || 'remove',
				mode: job.mode || '',
				done: job.done,
				total: job.total,
				remaining: job.remaining,
				blocked: job.blocked_count || 0,
				failed: job.failed_count || 0,
				verified: job.verified || 0,
				phase: job.phase || '',
				hint: job.hint || '',
				needsRescan: Boolean(job.needs_rescan),
				blockReasons: job.block_reasons || [],
				idleSec: Number(job.idle_sec || 0),
				stale: Boolean(job.stale),
				lastError: job.last_error || '',
			};
		} catch (e) {
			state.activeJob = null;
		}
	}

	/**
	 * مدیریت «تعارض کار»: عملیات تازه شروع نشد چون کار قبلی هنوز باز است.
	 *
	 * رفتار درست: هرگز پیام مبهم یا «انجام شد» دروغین ندهیم؛ کارت «ادامه یا
	 * رهاکردن» را با جزئیات واقعی همان کار نشان دهیم و اگر کاربر خواست، با
	 * یک کلیک کار قبلی را رها و همین عملیات را شروع کنیم.
	 *
	 * @param {Error}  error خطای دریافتی از سرور (status=409).
	 * @param {Object} opts  تنظیمات اجرای فعلی (برای شروع دوباره).
	 * @return {Promise<void>}
	 */
	async function handleJobConflict(error, opts) {
		const info = (error && error.data && error.data.job) || null;
		state.conflictRun = opts || null;

		if (info) {
			state.activeJob = {
				id: info.id,
				op: info.op || 'remove',
				mode: info.mode || '',
				done: Number(info.done || 0),
				total: Number(info.total || 0),
				remaining: Number(info.remaining || 0),
				blocked: Number(info.blocked_count || 0),
				failed: Number(info.failed_count || 0),
				verified: Number(info.verified || 0),
				phase: info.phase || '',
				hint: info.hint || '',
				needsRescan: Boolean(info.needs_rescan),
				blockReasons: info.block_reasons || [],
				idleSec: Number(info.idle_sec || 0),
				stale: Boolean(info.stale),
				lastError: '',
			};
		} else {
			await refreshJobStatus();
		}

		const j = state.activeJob;
		const name = j ? opLabelOf(j.op) : 'قبلی';
		const where = j ? ` — ${faNum(j.done)} از ${faNum(j.total)}` : '';
		toast(
			`این عملیات شروع نشد: کار نیمه‌تمام قبلی (${name}${where}) هنوز باز است. ` +
				'از کارت بالای صفحه «ادامهٔ عملیات» را بزنید، یا با دکمهٔ «رها کردن و شروع» همین عملیات را آغاز کنید.',
			'error'
		);
		render();
	}

	/**
	 * موتور مشترکِ «کار قابل ادامه» در سمت مرورگر.
	 *
	 * چرا یکی شد؟ پیش‌تر فقط حذف روی موتور کار بود؛ فشرده‌سازی و بازگردانی هر
	 * کدام یک حلقهٔ ساده در مرورگر داشتند: صف در مرورگر بود، و اگر کاربر
	 * صفحه را می‌بست یا اینترنت قطع می‌شد بقیهٔ صف گم می‌شد و هیچ‌کس نمی‌دانست
	 * چقدر انجام شده. حالا هر سه عملیات از یک مسیر می‌روند: صف در سرور، هر تیک
	 * گزارش واقعی سرور، و کارت «ادامهٔ عملیات» برای هر سه.
	 *
	 * @param {Object} opts تنظیمات اجرا.
	 * @return {Promise<void>}
	 */
	async function driveJob(opts) {
		const totalItems = Number(opts.total || 0);
		state.lastRun = opts;
		state.batch = {
			running: true,
			stopRequested: false,
			op: opts.op,
			title: opts.title,
			done: 0,
			total: totalItems,
			savedBytes: 0,
			blocked: 0,
			needsRescan: false,
			jobId: opts.resumeJobId || '',
			verified: 0,
			phase: '',
			hint: '',
			blockReasons: [],
			etaSec: 0,
			remaining: totalItems,
			failed: 0,
			error: '',
		};
		render();
		const runToken = busyStart(state.batch.title, { pct: 0 });
		batchPct(state.batch, 'شروع…');

		let stoppedByUser = false;
		let finished = false;
		let jobId = opts.resumeJobId || '';
		let announcedReclaim = false;

		try {
			let guard = 0;
			// ضدبی‌حرکت: اگر چند تیک پشت‌سرهم هیچ پیشرفتی ثبت نشود، به‌جای
			// چرخیدن بی‌پایان، صادقانه می‌ایستیم و دلیل را نشان می‌دهیم.
			let stallTicks = 0;
			let lastSignature = '';

			while (!finished && guard < 4000) {
				guard += 1;

				if (state.batch.stopRequested) {
					stoppedByUser = true;
					break;
				}

				const payload = jobId ? { job_id: jobId } : (opts.firstPayload || {});
				const res = await api(opts.action, payload);
				if (!res || !res.job) break;

				const job = res.job;
				jobId = job.id;

				state.batch.jobId = job.id;
				state.batch.total = job.total || state.batch.total;
				state.batch.done = job.done || 0;
				state.batch.remaining = job.remaining || 0;
				state.batch.savedBytes = job.saved_bytes || 0;
				state.batch.blocked = job.blocked_count || 0;
				state.batch.failed = job.failed_count || 0;
				state.batch.etaSec = job.eta_sec || 0;
				state.batch.lastError = job.last_error || '';
				state.batch.verified = job.verified || 0;
				state.batch.phase = job.phase || '';
				state.batch.hint = job.hint || '';
				state.batch.blockReasons = job.block_reasons || [];
				if (job.needs_rescan) state.batch.needsRescan = true;
				if (res.overview) state.overview = res.overview;
				if (res.vault) state.vault = res.vault;

				// سرور ممکن است پیش از شروع، یک کار رهاشدهٔ قدیمی را بسته باشد؛
				// این را صریح به کاربر بگوییم تا هیچ چیز بی‌دلیل عوض نشده باشد.
				if (res.reclaimed && !announcedReclaim) {
					announcedReclaim = true;
					const rec = res.reclaimed;
					toast(
						`کار قبلی (${opLabelOf(rec.op)}: ${faNum(rec.done)} از ${faNum(rec.total)}) که ${faNum(
							rec.idle_minutes
						)} دقیقه هیچ پیشرفتی نداشت رها شد؛ نتایج انجام‌شده‌اش ثبت ماند و این عملیات شروع شد.`
					);
				}

				finished = Boolean(job.finished);

				const signature = `${state.batch.done}|${state.batch.verified}|${state.batch.remaining}`;
				if (signature === lastSignature && !finished) {
					stallTicks += 1;
				} else {
					stallTicks = 0;
					lastSignature = signature;
				}

				render();
				const phaseLabel = state.batch.phase === 'verify'
					? `بازرسی ایمنی: ${faNum(state.batch.verified)} بررسی شد`
					: (state.batch.etaSec > 0 ? `حدود ${faNum(state.batch.etaSec)} ثانیه باقی‌مانده` : 'در حال پردازش…');
				batchPct(state.batch, phaseLabel);

				// اگر سرور خطا داده باشد، همان‌جا متوقف می‌شویم؛ کار ذخیره است.
				if (res.error) {
					state.batch.error = res.error;
					break;
				}

				if (stallTicks >= 3) {
					state.batch.error = state.batch.hint
						|| 'سرور در سه درخواست پیاپی هیچ پیشرفتی ثبت نکرد؛ اگر ادامه دادید و باز هم متوقف شد، «اسکن کامل» را اجرا کنید.';
					break;
				}

				if (!finished) {
					// یک مکث کوتاه بین تیک‌ها تا مرورگر هم فرصت رندر داشته باشد.
					await new Promise((r) => setTimeout(r, 60));
				}
			}

			if (finished) {
				state.activeJob = null;
				state.conflictRun = null;
				opts.onFinish(state.batch);
			} else {
				// نیمه‌کاره: کار در سرور ذخیره است و کارت «ادامه» نمایش داده می‌شود.
				state.activeJob = {
					id: jobId,
					op: opts.op,
					done: state.batch.done,
					total: state.batch.total,
					remaining: state.batch.remaining,
					blocked: state.batch.blocked,
					failed: state.batch.failed,
					verified: state.batch.verified,
					phase: state.batch.phase,
					hint: state.batch.hint,
					needsRescan: state.batch.needsRescan,
					blockReasons: state.batch.blockReasons,
					lastError: stoppedByUser ? 'توسط شما متوقف شد' : (state.batch.error || state.batch.lastError || 'ارتباط با سرور قطع شد'),
				};
				toast(
					`عملیات متوقف شد — ${faNum(state.batch.done)} از ${faNum(state.batch.total)} انجام شده است. ` +
					'می‌توانید از کارت «ادامهٔ عملیات» در بالای صفحه ادامه دهید.',
					'error'
				);
			}
		} catch (e) {
			// تعارض کار: عملیات تازه حتی شروع نشده؛ پیام «انجام شد» دادن دروغ است.
			if (e && e.isConflict) {
				await handleJobConflict(e, opts);
				return;
			}

			// کار در سرور ذخیره مانده است؛ فقط باید به کاربر بگوییم و امکان ادامه بدهیم.
			state.activeJob = {
				id: jobId,
				op: opts.op,
				done: state.batch.done,
				total: state.batch.total,
				remaining: state.batch.remaining,
				blocked: state.batch.blocked,
				failed: state.batch.failed,
				verified: state.batch.verified,
				phase: state.batch.phase,
				hint: state.batch.hint,
				needsRescan: state.batch.needsRescan,
				blockReasons: state.batch.blockReasons,
				lastError: e.message || 'خطای نامشخص',
			};
			// عددهای نمایش‌داده‌شده را با حقیقتِ سرور هم‌تراز می‌کنیم تا هیچ‌گاه
			// «چند مورد انجام شد» گمانه‌زنی نباشد.
			await refreshJobStatus();
			toast(
				`عملیات قطع شد (${e.message || 'خطای شبکه'}) — ${faNum(state.batch.done)} از ${faNum(state.batch.total)} انجام شده و ذخیره است.`,
				'error'
			);
		} finally {
			busyEnd(runToken);
			state.batch.running = false;
			render();
			const ov = await api('overview').catch(() => null);
			if (ov && !ov.needs_scan) state.overview = ov;
			await ensureDataForCurrentView(false);
		}
	}

	/**
	 * اجرای دوبارهٔ یک عملیات ذخیره‌شده (برای دکمهٔ «رها کردن و شروع»).
	 *
	 * @param {Object} opts همان تنظیمات driveJob.
	 * @return {Promise<void>}
	 */
	async function startRun(opts) {
		if (!opts) return;
		if (opts.op === 'optimize') {
			await runBatchOptimize(opts.ids || [], { resumeJobId: '', replaceMode: Boolean(opts.replaceMode) });
		} else if (opts.op === 'restore') {
			await runBatchRestoreQuarantine(opts.ids || [], '');
		} else if (opts.op === 'remove') {
			await runBatchRemove(opts.ids || [], opts.relPaths || [], '');
		}
	}

	/**
	 * رهاکردن کار فعال با گزارش صادقانه و بازکردن مسیر عملیات بعدی.
	 *
	 * @return {Promise<void>}
	 */
	async function discardActiveJob() {
		const res = await api('job_cancel');
		if (res && res.overview) state.overview = res.overview;
		const done = res && res.job ? Number(res.job.done || 0) : 0;
		state.activeJob = null;
		state.conflictRun = null;
		toast(`${faNum(done)} موردِ انجام‌شده ثبت شد؛ کار نیمه‌تمام رها شد و مسیر عملیات بعدی آزاد است.`);
		await ensureDataForCurrentView(false);
		render();
	}

	async function runBatchRestoreQuarantine(itemIds, resumeJobId) {
		const ids = (itemIds || []).filter(Boolean);
		if (!ids.length && !resumeJobId) {
			toast('هیچ موردی برای بازگردانی انتخاب نشده است.', 'error');
			return;
		}
		await driveJob({
			op: 'restore',
			action: 'restore_quarantine',
			title: 'بازگردانی تصاویر به مسیر و ID اصلی وردپرس',
			total: ids.length,
			resumeJobId: resumeJobId || '',
			ids,
			firstPayload: { item_ids: ids },
			onFinish(b) {
				state.selectedQuarantineIds.clear();
				if (b.done > 0) {
					const failNote = b.failed > 0 ? ` · ${faNum(b.failed)} مورد با خطا روبه‌رو شد.` : '';
					toast(`${faNum(b.done)} تصویر با همان ID و تمام زیرسایزها به جای اول بازگردانده شد!${failNote}`);
				} else {
					toast('هیچ موردی بازگردانده نشد — متن دلیل در گزارش عملیات آمده است.', 'error');
				}
			},
		});
	}

	async function runBatchOptimize(targetIds, opts = {}) {
		const replaceMode = Boolean(opts.replaceMode);
		const resumeJobId = opts.resumeJobId || '';
		let queue = targetIds && targetIds.length ? [...targetIds] : null;

		if (!queue && !resumeJobId) {
			const init = await api('optimize_batch', { ids: [] });
			if (init && Number(init.total || 0) === 0) {
				toast('همهٔ تصاویر از قبل بهینه هستند!');
				return;
			}
			queue = (init && init.queue_ids) || [];
		}
		if (!resumeJobId && (!queue || !queue.length)) {
			toast('همهٔ تصاویر از قبل بهینه هستند!');
			return;
		}

		await driveJob({
			op: 'optimize',
			action: 'optimize_batch',
			title: replaceMode
				? 'جایگزینی مستقیم با WebP و اصلاح لینک‌ها (کاهش حجم دیسک)'
				: 'فشرده‌سازی و تبدیل نسل جدید تصاویر',
			total: queue ? queue.length : 0,
			resumeJobId,
			ids: queue || [],
			replaceMode,
			firstPayload: {
				ids: queue || [],
				overrides: replaceMode ? { replace_mode: 1 } : {},
			},
			onFinish(b) {
				if (b.done === 0) {
					const top = (b.blockReasons || []).slice(0, 2)
						.map((r) => `${faNum(r.count)} مورد: ${r.reason}`)
						.join(' · ');
					toast(
						`هیچ تصویری بهینه نشد${b.failed > 0 ? ` — ${faNum(b.failed)} مورد با خطا مواجه شد` : ''}` +
							(top ? ` (${top})` : ''),
						'error'
					);
					return;
				}
				const extra = b.failed > 0 ? ` · ${faNum(b.failed)} مورد با خطا مواجه شد.` : '';
				if (replaceMode) {
					toast(
						`جایگزینی تمام شد — فایل اصل با WebP عوض شد و ${formatBytes(b.savedBytes)} از دیسک کم شد. لینک‌ها خودکار اصلاح شدند.${extra}`
					);
				} else {
					toast(`فشرده‌سازی تمام شد — ${formatBytes(b.savedBytes)} صرفه‌جویی شد.${extra}`);
				}
			},
		});
	}

	function openInspectModal(id) {
		const rows = (state.media && state.media.rows) || [];
		const r = rows.find((x) => Number(x.id) === Number(id));
		if (!r) return;
		const modal = document.getElementById('tco-modal');
		const title = document.getElementById('tco-modal-title');
		const body = document.getElementById('tco-modal-body');
		if (!modal || !body || !title) return;

		const estAfter = r.is_optimized
			? r.main_bytes
			: Math.round(r.main_bytes * (r.is_opaque_png ? 0.16 : 0.48));

		title.textContent = `بازرسی و مقایسهٔ زنده — ${r.filename} (#${faNum(r.id)})`;
		body.innerHTML = `
			<div class="tco-compare" id="tco-cmp-box">
				<div class="tco-compare__layer">
					${r.full_url ? `<img src="${esc(r.full_url)}" alt="">` : `<div class="tco-code">${esc(r.filename)}</div>`}
					<span class="tco-compare__tag tco-compare__tag--before">قبل: ${formatBytes(r.main_bytes + r.saved_bytes)}</span>
				</div>
				<div class="tco-compare__layer tco-compare__after" id="tco-cmp-after" style="clip-path: inset(0 0 0 50%)">
					${r.full_url ? `<img src="${esc(r.full_url)}" alt="">` : `<div class="tco-code">${esc(r.filename)}.webp</div>`}
					<span class="tco-compare__tag tco-compare__tag--after">${r.is_optimized ? 'بعد (فعلی): ' + formatBytes(r.main_bytes) : 'تخمین WebP: ~' + formatBytes(estAfter)}</span>
				</div>
			</div>
			<input type="range" min="0" max="100" value="50" class="tco-slider-range" id="tco-cmp-slider" aria-label="اسلایدر مقایسه قبل و بعد">
			<div class="tco-wins-grid" style="margin-top:12px">
				<div class="tco-win"><strong>مسیر نسبی و اثر انگشت SHA-1</strong><span class="tco-code">${esc(r.rel_path)}<br>${esc(r.sha1 || '—')}</span></div>
				<div class="tco-win">
					<strong>تفکیک دقیق دیسک</strong>
					<span>کل: <strong>${formatBytes(r.total_bytes)}</strong> · اصلی: ${formatBytes(r.main_bytes)} · زیرسایزها: <strong>${formatBytes(r.subsizes_bytes)}</strong> (${faNum(r.subsizes_count || 0)} فایل)</span>
				</div>
			</div>
			<div class="tco-subsize-box" style="margin-top:12px">
				<div class="tco-card__head" style="margin:0">
					<div>
						<strong>فایل‌های واقعی روی دیسک — ${formatBytes(r.total_bytes)} در ${faNum(r.subsizes_count + 1)} فایل</strong>
						<p class="tco-muted">وردپرس برای هر تصویر چند کپی کوچک‌تر (زیرسایز) می‌سازد و هر کپی یک فایل جدا در <span class="tco-code">uploads/</span> است. نسخهٔ WebP/AVIF هم دقیقاً کنار همان فایل با پسوند اضافه ساخته می‌شود: <span class="tco-code">نام.png.webp</span> — نه در پوشه‌ای دیگر و نه با نام تازه.</p>
					</div>
					<span class="tco-pill tco-pill--neutral" id="tco-subsize-count">در حال خواندن…</span>
				</div>
				<div id="tco-disk-note" class="tco-muted" style="margin:6px 0 0"></div>
				<div id="tco-subsize-detail" class="tco-subsize-detail">
					<div class="tco-loading-state"><span class="tco-spinner"></span><span>در حال خواندن فایل‌های همین تصویر…</span></div>
				</div>
			</div>
			<div class="tco-row-actions" style="margin-top:12px;justify-content:flex-end">
				${
					r.format === 'WEBP' || r.mime === 'image/webp'
						? '<span class="tco-pill tco-pill--ok">این تصویر از قبل با WebP جایگزین شده است</span>'
						: `<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm" id="tco-replace-one" data-replace-id="${r.id}" title="فایل اصل و زیرسایزها با WebP جایگزین می‌شوند، لینک‌ها اصلاح می‌شوند و از فایل قبلی در خزانه پشتیبان می‌ماند">جایگزینی مستقیم با WebP (کاهش حجم دیسک)</button>`
				}
			</div>
			<div style="margin-top:12px">${renderUsageCell(r)}</div>
		`;
		modal.hidden = false;
		loadSubsizeDetail(Number(r.id));
	}

	/**
	 * خواندن تفکیک زیرسایزهای همان تصویر از سرور و نمایش فهرست آن.
	 * (یک پیوست، نه اسکن سنگین.)
	 *
	 * @param {number} id شناسهٔ پیوست.
	 */
	async function loadSubsizeDetail(id) {
		try {
			const res = await api('inspect_sizes', { id });
			const box = document.getElementById('tco-subsize-detail');
			const chip = document.getElementById('tco-subsize-count');
			const note = document.getElementById('tco-disk-note');
			if (!box) return;

			const files = (res && res.files) || [];
			const mode = (res && res.webp_mode) || 'sidecar';
			const delivery = (res && res.delivery) || 'picture';
			const sidecars = Number((res && res.sidecar_count) || 0);

			if (chip) {
				chip.textContent = `${faNum(files.length)} فایل · ${formatBytes((res && res.total_bytes) || 0)}`;
			}

			if (note) {
				const parts = [];
				if (sidecars > 0 && mode === 'sidecar') {
					parts.push(
						`<strong>حالت فعلی: نسخهٔ موازی.</strong> ${faNum(sidecars)} فایل WebP/AVIF کنار اصل ساخته شده (${formatBytes(
							(res && res.sidecar_bytes) || 0
						)}) و فایل اصلی هم سر جایش است — یعنی حجم دیسک کم نشده، فقط مرورگرِ مدرن نسخهٔ سبک‌تر را می‌گیرد. اگر می‌خواهید حجم دیسک هم کم شود، حالت «جایگزینی مستقیم» را فعال کنید یا زیرسایزهای بی‌استفاده را پاک کنید.`
					);
				} else if (sidecars > 0 && mode === 'replace') {
					parts.push('<strong>حالت فعلی: جایگزینی مستقیم.</strong> فایل اصل با نسخهٔ نسل جدید جایگزین شده و لینک‌ها به همان فایل اشاره می‌کنند.');
				} else if (sidecars === 0) {
					parts.push('برای این تصویر هیچ نسخهٔ WebP/AVIF روی دیسک نیست. دکمهٔ «بهینه» را بزنید تا ساخته شود.');
				}
				if (sidecars > 0 && delivery === 'none') {
					parts.push('<span class="tco-pill tco-pill--warn">هشدار: روش تحویل روی «غیرفعال» است؛ فایل WebP ساخته شده ولی به مرورگر پیشنهاد نمی‌شود. در تنظیمات، «تحویل» را روی picture یا htaccess بگذارید.</span>');
				}
				note.innerHTML = parts.join(' ');
			}

			if (!files.length) {
				box.innerHTML = '<div class="tco-empty-state">هیچ فایلی برای این پیوست روی دیسک پیدا نشد.</div>';
				return;
			}

			const max = files.reduce((m, f) => Math.max(m, f.bytes || 0), 0) || 1;
			box.innerHTML = `<table class="tco-table tco-table--compact">
				<thead><tr><th>نقش فایل</th><th>مسیر داخل uploads/</th><th>حجم</th><th>سهم</th></tr></thead>
				<tbody>${files
					.map((f) => {
						const isSide = /موازی/.test(f.role || '');
						return `<tr class="${isSide ? 'is-sidecar' : ''}">
							<td>${isSide ? '<span class="tco-pill tco-pill--ok">WebP/AVIF</span>' : ''} ${esc(f.role)}</td>
							<td class="tco-code tco-path-cell" title="${esc(f.abs)}">${esc(f.rel)}</td>
							<td><strong>${f.exists ? formatBytes(f.bytes) : 'فایل نیست'}</strong></td>
							<td><span class="tco-mini-bar"><span style="width:${Math.max(2, Math.round(((f.bytes || 0) / max) * 100))}%"></span></span></td>
						</tr>`;
					})
					.join('')}</tbody>
			</table>
			<p class="tco-muted" style="margin:8px 2px 0">پوشهٔ این تصویر: <span class="tco-code">wp-content/uploads/${esc((res && res.dir) || '')}/</span></p>`;
		} catch (e) {
			const box = document.getElementById('tco-subsize-detail');
			if (box) box.innerHTML = `<div class="tco-empty-state">فهرست فایل‌ها خوانده نشد: ${esc(e.message || 'خطای شبکه')}</div>`;
		}
	}

	// رویدادهای کلیک و تعامل در سطح سند.
	async function handleActionClick(ev) {
		const modBtn = ev.target.closest('[data-go-module]');
		if (modBtn) {
			state.module = modBtn.getAttribute('data-go-module');
			state.selectedIds.clear();
			state.selectedPaths.clear();
			render();
			await ensureDataForCurrentView(false);
			return;
		}

		const subBtn = ev.target.closest('[data-go-subtab]');
		if (subBtn) {
			state.module = 'images';
			state.subtab = subBtn.getAttribute('data-go-subtab');
			state.selectedIds.clear();
			state.selectedPaths.clear();
			render();
			await ensureDataForCurrentView(false);
			return;
		}

		const bucketBtn = ev.target.closest('[data-unused-bucket]');
		if (bucketBtn) {
			state.unusedFilter.usage = bucketBtn.getAttribute('data-unused-bucket');
			state.unusedFilter.page = 1;
			state.selectedIds.clear();
			state.selectedPaths.clear();
			await ensureDataForCurrentView(false);
			return;
		}

		const vtabBtn = ev.target.closest('[data-vault-tab]');
		if (vtabBtn) {
			state.vaultTab = vtabBtn.getAttribute('data-vault-tab');
			render();
			return;
		}

		const delModeBtn = ev.target.closest('[data-set-delmode]');
		if (delModeBtn) {
			state.deleteMode = delModeBtn.getAttribute('data-set-delmode');
			state.armedKey = null;
			render();
			return;
		}

		if (ev.target.closest('#tco-fast-finish-btn')) {
			toast('در حال نهایی‌سازی فوری نتایج از روی متادیتای رسانه…');
			state.scan.fastFinishRequested = true;
			if (!state.scan.running) {
				await runIncrementalScan(false, true);
			}
			return;
		}

		if (ev.target.closest('#tco-resume-scan-btn')) {
			toast('در حال ادامهٔ اسکن از آخرین نقطهٔ ذخیره‌شده…');
			await ensureDataForCurrentView(false, true);
			return;
		}

		if (ev.target.closest('#tco-rescan-btn')) {
			toast('در حال اجرای اسکن مرحله‌ای ۱۲لایه روی کل سایت…');
			await ensureDataForCurrentView(true);
			toast('اسکن ۱۲لایه با موفقیت کامل شد.');
			return;
		}

		if (ev.target.closest('#tco-resume-job')) {
			const job = state.activeJob;
			if (job && job.id) {
				state.activeJob = null;
				if (job.op === 'optimize') {
					await runBatchOptimize([], { resumeJobId: job.id, replaceMode: job.mode === 'replace' });
				} else if (job.op === 'restore') {
					await runBatchRestoreQuarantine([], job.id);
				} else {
					await runBatchRemove([], [], job.id);
				}
			}
			return;
		}

		if (ev.target.closest('#tco-resume-rescan')) {
			const job = state.activeJob;
			state.activeJob = null;
			toast('در حال اجرای اسکن کامل تصاویر؛ پس از پایان، حذف را ادامه دهید.');
			await runIncrementalScan(true);
			if (job && job.id) {
				await runBatchRemove([], [], job.id);
			}
			return;
		}

		if (ev.target.closest('#tco-preflight-btn')) {
			state.preflight = await api('preflight');
			render();
			const pf = state.preflight || {};
			toast(
				pf.level === 'ok'
					? `چکاپ تمام شد — سرور آماده است (${faNum(pf.score)} از ۱۰۰).`
					: `چکاپ تمام شد — ${faNum(pf.score)} از ۱۰۰؛ موارد هشدار را ببینید.`,
				pf.level === 'ok' ? undefined : 'error'
			);
			return;
		}

		if (ev.target.closest('#tco-discard-and-run')) {
			const pending = state.conflictRun;
			if (!pending) return;
			state.conflictRun = null;
			const res = await api('job_cancel');
			if (res && res.overview) state.overview = res.overview;
			if (res && res.job) {
				toast(
					`کار قبلی رها شد — ${faNum(Number(res.job.done || 0))} موردِ انجام‌شده ثبت ماند. حالا همین عملیات شروع می‌شود.`
				);
			}
			state.activeJob = null;
			// همان عملیات درخواستی دوباره اجرا می‌شود (این بار مسیر آزاد است).
			await startRun(pending);
			return;
		}

		// «رها کردن» باید همیشه کارت را ببندد و مسیر عملیات تازه را باز کند؛
		// حتی وقتی هنوز کارت «ادامه» کامل رندر نشده باشد.
		if (ev.target.closest('#tco-discard-job')) {
			await discardActiveJob();
			return;
		}

		if (ev.target.closest('#tco-stop-batch')) {
			state.batch.stopRequested = true;
			toast('توقف پس از اتمام بستهٔ جاری اعمال می‌شود.');
			return;
		}

		if (ev.target.closest('#tco-quick-clean-unscaled')) {
			const res = await api('clean_unscaled', { ids: [] });
			if (res.overview) state.overview = res.overview;
			toast(`${faNum(res.cleaned)} فایل خام پشت -scaled پاک شد (${formatBytes(res.saved_bytes)} آزاد شد).`);
			await ensureDataForCurrentView(false);
			return;
		}

		if (ev.target.closest('#tco-replace-one')) {
			const id = Number(ev.target.closest('#tco-replace-one').getAttribute('data-replace-id'));
			if (!id) return;
			toast('در حال جایگزینی این تصویر با WebP و اصلاح لینک‌ها…');
			await runBatchOptimize([id], { replaceMode: true });
			const modalEl = document.getElementById('tco-modal');
			if (modalEl) modalEl.hidden = true;
			await ensureDataForCurrentView(false);
			return;
		}

		const optOne = ev.target.closest('[data-opt-one]');
		if (optOne) {
			await runBatchOptimize([Number(optOne.getAttribute('data-opt-one'))]);
			return;
		}

		const pinBtn = ev.target.closest('[data-pin-one]');
		if (pinBtn) {
			const id = Number(pinBtn.getAttribute('data-pin-one'));
			const isPinned = pinBtn.getAttribute('data-pinned') === '1';
			const res = await api('toggle_pin', { ids: [id], pin: isPinned ? 0 : 1 });
			if (res.overview) state.overview = res.overview;
			toast(isPinned ? 'از لیست سفید خارج شد.' : 'در لیست سفید محافظت دائمی پین شد ★');
			await ensureDataForCurrentView(false);
			return;
		}

		const remOne = ev.target.closest('[data-remove-one]');
		if (remOne) {
			const id = Number(remOne.getAttribute('data-remove-one'));
			if (state.deleteMode === 'permanent' && !armConfirm('del-' + id)) {
				return;
			}
			await runBatchRemove([id], []);
			return;
		}

		const remPath = ev.target.closest('[data-remove-path]');
		if (remPath) {
			await runBatchRemove([], [remPath.getAttribute('data-remove-path')]);
			return;
		}

		if (ev.target.closest('#tco-select-all-matching')) {
			((state.media && state.media.matching_ids) || []).forEach((id) => state.selectedIds.add(Number(id)));
			render();
			return;
		}

		if (ev.target.closest('#tco-clear-sel')) {
			state.selectedIds.clear();
			state.selectedPaths.clear();
			render();
			return;
		}

		if (ev.target.closest('#tco-bulk-opt')) {
			await runBatchOptimize([...state.selectedIds]);
			return;
		}

		if (ev.target.closest('#tco-bulk-opt-replace')) {
			// فشرده‌سازی + جایگزینی خودکار: دو کلیک (ضد اشتباه)، چون فایل اصل عوض می‌شود.
			if (!armConfirm('bulk-opt-replace')) {
				return;
			}
			await runBatchOptimize([...state.selectedIds], { replaceMode: true });
			return;
		}

		if (ev.target.closest('#tco-bulk-remove')) {
			if (state.deleteMode === 'permanent' && !armConfirm('bulk-remove')) {
				return;
			}
			await runBatchRemove([...state.selectedIds], [...state.selectedPaths]);
			return;
		}

		if (ev.target.closest('#tco-sweep-all')) {
			if (!armConfirm('sweep-all-unused')) {
				return;
			}
			if (state.media && state.media.mode === 'disk_orphans') {
				await runBatchRemove(
					[],
					(state.media.rows || []).map((o) => o.rel_path)
				);
			} else {
				await runBatchRemove((state.media && state.media.matching_ids) || [], []);
			}
			return;
		}

		const pageBtn = ev.target.closest('[data-page-delta]');
		if (pageBtn) {
			const delta = Number(pageBtn.getAttribute('data-page-delta'));
			const info = pageState();
			if (!info) return;
			await goToPage(info.get() + delta);
			return;
		}

		const edgeBtn = ev.target.closest('[data-page-edge]');
		if (edgeBtn) {
			await goToPage(edgeBtn.getAttribute('data-page-edge') === 'last' ? 999999 : 1);
			return;
		}

		if (ev.target.closest('#tco-rescan-unused')) {
			await ensureDataForCurrentView(true);
			return;
		}

		if (ev.target.closest('#tco-dismiss-run')) {
			state.lastRun = null;
			render();
			return;
		}

		if (ev.target.closest('[data-page-go]')) {
			const input = document.getElementById('tco-page-jump');
			await goToPage(input ? input.value : 1);
			return;
		}

		const pickMaster = ev.target.closest('[data-pick-master]');
		if (pickMaster) {
			state.masterChoices[pickMaster.getAttribute('data-group-hash')] = Number(pickMaster.getAttribute('data-pick-master'));
			render();
			return;
		}

		const mergeBtn = ev.target.closest('[data-merge-hash]');
		if (mergeBtn) {
			const master = Number(mergeBtn.getAttribute('data-master'));
			const dups = mergeBtn
				.getAttribute('data-dups')
				.split(',')
				.map(Number)
				.filter(Boolean);
			const res = await api('merge_duplicates', { master_id: master, dup_ids: dups, delete_mode: state.deleteMode });
			state.duplicates = res.duplicates;
			state.overview = res.overview;
			toast(`${faNum(res.merged)} تصویر تکراری ادغام شد (${formatBytes(res.saved_bytes)} آزاد شد).`);
			render();
			return;
		}

		if (ev.target.closest('#tco-merge-all-dups')) {
			const res = await api('merge_duplicates', { delete_mode: state.deleteMode });
			state.duplicates = res.duplicates;
			state.overview = res.overview;
			toast(`همهٔ گروه‌ها ادغام شدند — ${formatBytes(res.saved_bytes)} آزاد شد!`);
			render();
			return;
		}

		if (ev.target.closest('#tco-save-sizes-btn')) {
			const disabled = [];
			document.querySelectorAll('.tco-size-toggle').forEach((el) => {
				if (!el.checked) disabled.push(el.value);
			});
			const res = await api('save_subsizes', { disabled_sizes: disabled });
			state.settings = res.settings;
			state.subsizes = res.sizes;
			toast('وضعیت تولید زیرسایزها ذخیره شد.');
			render();
			return;
		}

		if (ev.target.closest('#tco-clean-disabled-sizes')) {
			const res = await api('clean_disabled_subsizes');
			state.subsizes = res.sizes;
			state.overview = res.overview;
			toast(`${faNum(res.result.deleted_files)} فایل زیرسایز اضافی حذف شد (${formatBytes(res.result.saved_bytes)} آزاد شد).`);
			render();
			return;
		}

		if (ev.target.closest('#tco-regen-all-btn')) {
			const init = await api('regen_batch', { ids: [] });
			const q = init.queue_ids || [];
			state.batch = { running: true, stopRequested: false, title: 'بازسازی زیرسایزهای بندانگشتی', done: 0, total: q.length, savedBytes: 0 };
			render();
			batchPct(state.batch, 'شروع…');
			const totalN = q.length;
			while (q.length > 0 && !state.batch.stopRequested) {
				const chunk = q.splice(0, 12);
				const r = await api('regen_batch', { ids: chunk, log_final: q.length === 0 ? 1 : 0, summary_count: totalN });
				state.batch.done += (r.done_ids || []).length;
				render();
				batchPct(state.batch);
			}
			state.batch.running = false;
			toast('بازسازی بندانگشتی‌ها با موفقیت تکمیل شد.');
			await ensureDataForCurrentView(false);
			return;
		}

		const saveSeoBtn = ev.target.closest('[data-save-seo]');
		if (saveSeoBtn) {
			const id = Number(saveSeoBtn.getAttribute('data-save-seo'));
			const input = document.getElementById('tco-alt-in-' + id);
			await api('save_single_seo', { id, alt: input ? input.value : '' });
			toast('متن جایگزین (Alt) ذخیره شد.');
			await ensureDataForCurrentView(false);
			return;
		}

		if (ev.target.closest('#tco-bulk-seo-btn')) {
			const pat = document.getElementById('tco-seo-pattern');
			const res = await api('bulk_generate_seo', {
				seo_alt_pattern: pat ? pat.value : '{product_title} — {site_name}',
				only_empty: 1,
			});
			state.seo = res.audit;
			toast(`متن جایگزین برای ${faNum(res.updated)} تصویر به‌طور خودکار تکمیل شد.`);
			await ensureDataForCurrentView(false);
			return;
		}

		if (ev.target.closest('#tco-start-replace-compress')) {
			if (!armConfirm('bulk-opt-replace-all')) {
				return;
			}
			// تنظیمات فعلی فرم را ذخیره کن، بعد کل کتابخانه را با جایگزینی مستقیم بهینه کن.
			const cfg = {
				quality_preset: document.getElementById('tco-s-preset').value,
				nextgen_format: document.getElementById('tco-s-nextgen').value,
				delivery_method: document.getElementById('tco-s-delivery').value,
				max_width: document.getElementById('tco-s-maxw').value,
				max_height: document.getElementById('tco-s-maxh').value,
				backup_originals: document.getElementById('tco-s-backuporig').checked ? 1 : 0,
				convert_opaque_png: document.getElementById('tco-s-opaque').checked ? 1 : 0,
				keep_icc_profile: document.getElementById('tco-s-icc').checked ? 1 : 0,
			};
			const savedCfg = await api('save_settings', { settings: cfg });
			state.settings = savedCfg.settings;
			await runBatchOptimize(null, { replaceMode: true });
			return;
		}

		if (ev.target.closest('#tco-save-compress-cfg') || ev.target.closest('#tco-start-bulk-compress')) {
			const startNow = Boolean(ev.target.closest('#tco-start-bulk-compress'));
			const payload = {
				quality_preset: document.getElementById('tco-s-preset').value,
				nextgen_format: document.getElementById('tco-s-nextgen').value,
				webp_mode: document.getElementById('tco-s-webpmode').value,
				delivery_method: document.getElementById('tco-s-delivery').value,
				max_width: document.getElementById('tco-s-maxw').value,
				max_height: document.getElementById('tco-s-maxh').value,
				auto_optimize_upload: document.getElementById('tco-s-autoupload').checked ? 1 : 0,
				backup_originals: document.getElementById('tco-s-backuporig').checked ? 1 : 0,
				convert_opaque_png: document.getElementById('tco-s-opaque').checked ? 1 : 0,
				keep_icc_profile: document.getElementById('tco-s-icc').checked ? 1 : 0,
			};
			const saved = await api('save_settings', { settings: payload });
			state.settings = saved.settings;
			toast('تنظیمات فشرده‌سازی و WebP ذخیره شد.');
			if (startNow) {
				await runBatchOptimize(null);
			}
			return;
		}

		if (ev.target.closest('#tco-save-general-cfg')) {
			const payload = {
				delete_mode: document.getElementById('tco-cfg-delmode').value,
				quarantine_days: document.getElementById('tco-cfg-qdays').value,
				excluded_dirs: document.getElementById('tco-cfg-excl').value,
			};
			const tickMs = document.getElementById('tco-cfg-tickms');
			const tickMax = document.getElementById('tco-cfg-tickmax');
			const throttle = document.getElementById('tco-cfg-throttle');
			const verifyChunk = document.getElementById('tco-cfg-verifychunk');
			if (tickMs) payload.delete_tick_ms = tickMs.value;
			if (tickMax) payload.delete_tick_max = tickMax.value;
			if (throttle) payload.delete_throttle_ms = throttle.value;
			if (verifyChunk) payload.verify_chunk = verifyChunk.value;
			const saved = await api('save_settings', { settings: payload });
			state.settings = saved.settings;
			state.deleteMode = saved.settings.delete_mode;
			toast('تنظیمات کلی با موفقیت ذخیره شد.');
			render();
			return;
		}

		const nextAct = ev.target.closest('[data-next-action]');
		if (nextAct) {
			const cta = nextAct.getAttribute('data-next-action');
			if (cta === 'scan') {
				await runIncrementalScan(true);
			} else if (cta === 'clean_unscaled') {
				const r = await api('clean_unscaled', {});
				if (r.overview) state.overview = r.overview;
				toast(`فایل‌های خام -scaled پاک شد — ${formatBytes(r.result ? r.result.saved_bytes : 0)} آزاد شد.`);
				state.media = null;
				render();
			} else if (cta === 'optimize_all') {
				await runBatchOptimize([], {});
			} else if (cta === 'quarantine') {
				state.module = 'vault';
				render();
			} else if (cta === 'unused' || cta === 'duplicates' || cta === 'seo') {
				state.subtab = cta;
				await ensureDataForCurrentView(false);
				render();
			}
			return;
		}

		const resQ = ev.target.closest('[data-restore-q]');
		if (resQ) {
			await runBatchRestoreQuarantine([resQ.getAttribute('data-restore-q')]);
			return;
		}

		if (ev.target.closest('#tco-restore-selected-q')) {
			await runBatchRestoreQuarantine([...state.selectedQuarantineIds]);
			return;
		}

		if (ev.target.closest('#tco-restore-all-quarantine')) {
			const allIds = ((state.vault && state.vault.quarantine) || []).map((q) => q.id).filter(Boolean);
			await runBatchRestoreQuarantine(allIds);
			return;
		}

		const resBatchBtn = ev.target.closest('[data-restore-batch]');
		if (resBatchBtn) {
			const bid = resBatchBtn.getAttribute('data-restore-batch');
			const matchIds = ((state.vault && state.vault.quarantine) || [])
				.filter((q) => q.batch_id === bid)
				.map((q) => q.id);
			if (matchIds.length) {
				await runBatchRestoreQuarantine(matchIds);
			} else {
				const r = await api('restore_quarantine', { batch_id: bid });
				state.vault = r.vault;
				if (r.overview) state.overview = r.overview;
				toast(`${faNum(r.restored)} مورد از این نوبت به جای اول بازگردانده شد!`);
				render();
			}
			return;
		}

		const purgeQ = ev.target.closest('[data-purge-q]');
		if (purgeQ) {
			const r = await api('purge_quarantine', { item_ids: [purgeQ.getAttribute('data-purge-q')] });
			state.vault = r.vault;
			toast('بستهٔ قرنطینه برای همیشه از دیسک پاک شد.');
			render();
			return;
		}

		if (ev.target.closest('#tco-purge-all-quarantine')) {
			const r = await api('purge_quarantine', { item_ids: [] });
			state.vault = r.vault;
			state.selectedQuarantineIds.clear();
			toast(`کل قرنطینه تخلیه شد (${formatBytes(r.saved_bytes)} آزاد شد).`);
			render();
			return;
		}

		const resOrig = ev.target.closest('[data-restore-orig]');
		if (resOrig) {
			const r = await api('restore_original', { id: Number(resOrig.getAttribute('data-restore-orig')) });
			state.vault = r.vault;
			if (r.overview) state.overview = r.overview;
			toast('فایل اولیهٔ تصویر با موفقیت بازگردانی شد.');
			render();
			return;
		}

		if (ev.target.closest('#tco-restore-all-orig')) {
			const r = await api('restore_original', { restore_all: 1 });
			state.vault = r.vault;
			if (r.overview) state.overview = r.overview;
			toast(`${faNum(r.restored)} تصویر به فایل خام اولیه بازگردانی شدند.`);
			render();
			return;
		}

		if (ev.target.closest('#tco-purge-all-orig')) {
			const r = await api('purge_originals');
			state.vault = r.vault;
			toast(`پشتیبان‌های اولیه پاک‌سازی شدند (${formatBytes(r.result.bytes)} آزاد شد).`);
			render();
			return;
		}

		if (ev.target.closest('#tco-clear-history-btn')) {
			const r = await api('clear_history');
			state.vault = r.vault;
			render();
			return;
		}

		const inspectEl = ev.target.closest('[data-inspect]');
		if (inspectEl) {
			openInspectModal(Number(inspectEl.getAttribute('data-inspect')));
			return;
		}

		if (ev.target.closest('[data-modal-close]')) {
			const modal = document.getElementById('tco-modal');
			if (modal) modal.hidden = true;
		}
	}

	// پوشش سراسری: هر کنترل کلیک‌پذیر، تا پایان کارش «در حال انجام» نشان داده می‌شود
	// و خطاهای شبکه/سرور به‌جای سکوت، به‌صورت اعلان فارسی دیده می‌شوند.
	const ACTION_SELECTOR = [
		'[data-go-module]',
		'[data-go-subtab]',
		'[data-unused-bucket]',
		'[data-vault-tab]',
		'[data-set-delmode]',
		'[data-opt-one]',
		'[data-pin-one]',
		'[data-remove-one]',
		'[data-remove-path]',
		'[data-inspect]',
		'[data-restore-q]',
		'[data-restore-batch]',
		'[data-purge-q]',
		'[data-restore-orig]',
		'[data-merge-hash]',
		'[data-pick-master]',
		'[data-page-delta]',
		'[data-page-edge]',
		'#tco-dismiss-run',
		'#tco-rescan-unused',
		'[data-page-go]',
		'[data-save-seo]',
		'[data-modal-close]',
		'#tco-fast-finish-btn',
		'#tco-resume-scan-btn',
		'#tco-rescan-btn',
		'#tco-quick-clean-unscaled',
		'#tco-bulk-opt',
		'#tco-bulk-opt-replace',
		'#tco-start-replace-compress',
		'#tco-bulk-remove',
		'#tco-sweep-all',
		'#tco-select-all-matching',
		'#tco-clear-sel',
		'#tco-merge-all-dups',
		'#tco-save-sizes-btn',
		'#tco-clean-disabled-sizes',
		'#tco-regen-all-btn',
		'#tco-bulk-seo-btn',
		'#tco-save-compress-cfg',
		'#tco-start-bulk-compress',
		'#tco-save-general-cfg',
		'#tco-restore-selected-q',
		'#tco-restore-all-quarantine',
		'#tco-purge-all-quarantine',
		'#tco-restore-all-orig',
		'#tco-purge-all-orig',
		'#tco-clear-history-btn',
		'[data-next-action]',
		'#tco-preflight-btn',
		'#tco-stop-batch',
		'#tco-resume-job',
		'#tco-discard-and-run',
		'#tco-discard-job',
		'#tco-resume-rescan',
		'#tco-replace-one',
	].join(',');

	document.addEventListener('click', (ev) => {
		const el = ev.target.closest(ACTION_SELECTOR);
		if (!el) return;

		// دکمهٔ «توقف محترمانه» باید در تمام مدت دسته در دسترس بماند و اسپینر
		// نگیرد؛ پس بدون withBusy و مستقیم اجرا می‌شود. (پیش‌تر این‌جا فقط
		// `return` بود و دکمهٔ توقف بی‌صدا هیچ کاری نمی‌کرد.)
		if (el.id === 'tco-stop-batch') {
			handleActionClick(ev);
			return;
		}

		const explicit = el.getAttribute('data-busy-label');
		const text = (el.textContent || '').replace(/\s+/g, ' ').trim();
		const label = explicit || (text ? text.slice(0, 44) : 'در حال انجام…');
		withBusy(el, label, () => handleActionClick(ev));
	});

	document.addEventListener('change', async (ev) => {
		if (ev.target.classList.contains('tco-q-check')) {
			const qid = ev.target.value;
			if (ev.target.checked) state.selectedQuarantineIds.add(qid);
			else state.selectedQuarantineIds.delete(qid);
			render();
			return;
		}

		if (ev.target.id === 'tco-check-q-all') {
			const check = ev.target.checked;
			((state.vault && state.vault.quarantine) || []).forEach((q) => {
				if (check) state.selectedQuarantineIds.add(q.id);
				else state.selectedQuarantineIds.delete(q.id);
			});
			render();
			return;
		}

		if (ev.target.classList.contains('tco-row-check')) {
			const id = Number(ev.target.value);
			if (ev.target.checked) state.selectedIds.add(id);
			else state.selectedIds.delete(id);
			render();
			return;
		}

		if (ev.target.classList.contains('tco-orphan-check')) {
			const p = ev.target.value;
			if (ev.target.checked) state.selectedPaths.add(p);
			else state.selectedPaths.delete(p);
			render();
			return;
		}

		if (ev.target.id === 'tco-check-page') {
			const check = ev.target.checked;
			((state.media && state.media.rows) || []).forEach((r) => {
				if (check) state.selectedIds.add(r.id);
				else state.selectedIds.delete(r.id);
			});
			render();
			return;
		}

		if (ev.target.id === 'tco-check-orphan-page') {
			const check = ev.target.checked;
			((state.media && state.media.rows) || []).forEach((o) => {
				if (check) state.selectedPaths.add(o.rel_path);
				else state.selectedPaths.delete(o.rel_path);
			});
			render();
			return;
		}

		if (ev.target.id === 'tco-per-page') {
			await applyPerPage(ev.target.value);
			return;
		}

		if (['tco-exp-sort', 'tco-exp-min', 'tco-exp-fmt', 'tco-exp-usage'].includes(ev.target.id)) {
			state.explorerFilter.sort_by = document.getElementById('tco-exp-sort').value;
			state.explorerFilter.min_bytes = Number(document.getElementById('tco-exp-min').value);
			state.explorerFilter.format = document.getElementById('tco-exp-fmt').value;
			state.explorerFilter.usage = document.getElementById('tco-exp-usage').value;
			state.explorerFilter.page = 1;
			await ensureDataForCurrentView(false);
		}
	});

	let searchDebounce = null;
	document.addEventListener('input', (ev) => {
		if (ev.target.id === 'tco-cmp-slider') {
			const afterLayer = document.getElementById('tco-cmp-after');
			if (afterLayer) {
				afterLayer.style.clipPath = `inset(0 0 0 ${Number(ev.target.value)}%)`;
			}
			return;
		}
		if (ev.target.id === 'tco-vault-search') {
			state.vaultSearch = ev.target.value;
			render();
			const el = document.getElementById('tco-vault-search');
			if (el) {
				el.focus();
				el.setSelectionRange(el.value.length, el.value.length);
			}
			return;
		}
		if (ev.target.id === 'tco-exp-q') {
			state.explorerFilter.search = ev.target.value;
			state.explorerFilter.page = 1;
			clearTimeout(searchDebounce);
			searchDebounce = setTimeout(() => {
				ensureDataForCurrentView(false);
			}, 260);
		}
	});

	// بوت اولیه.
	$(function () {
		render();
		refreshJobStatus()
			.catch(() => {})
			.then(() => {
				render();
				return ensureDataForCurrentView(false);
			});
	});
})(jQuery);
