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
		seo: null,
		vault: null,
		vaultTab: 'quarantine',
		selectedIds: new Set(),
		selectedPaths: new Set(),
		masterChoices: {},
		deleteMode: (boot.settings && boot.settings.delete_mode) || 'quarantine',
		armedKey: null,
		armedTimer: null,
		batch: {
			running: false,
			stopRequested: false,
			title: '',
			done: 0,
			total: 0,
			savedBytes: 0,
		},
		scan: {
			running: false,
			stageNum: 1,
			stageLabel: 'آماده‌سازی موتور اسکن ۱۲لایه…',
			detailLabel: '',
			percent: 4,
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
			const timedOut = netErr && netErr.name === 'AbortError';
			throw new Error(
				timedOut
					? 'پاسخ سرور بیش از ۲۸ ثانیه طول کشید (Timeout شبکه/فایروال).'
					: 'ارتباط با سرور قطع شد؛ اتصال اینترنت یا فایروال هاست را بررسی کنید.'
			);
		}
		if (timer) clearTimeout(timer);

		const rawText = await res.text();
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
			const msg = (json && json.data && json.data.message) || `خطا در پردازش درخواست سرور (HTTP ${res.status})`;
			throw new Error(msg);
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

	function renderImageSubnav() {
		const imgMod = (boot.modules || []).find((m) => m.id === 'images');
		const subtabs = (imgMod && imgMod.subtabs) || {};
		const ov = state.overview;
		const counts = {
			explorer: ov ? faNum(ov.total_images) : '',
			unused: ov ? faNum(ov.quick_wins.unused.count + ov.quick_wins.unused.orphan_count) : '',
			compress: ov ? faNum(ov.quick_wins.compress.count) : '',
			duplicates: ov ? faNum(ov.quick_wins.duplicates.groups) : '',
			seo: ov ? faNum(ov.quick_wins.seo.missing_alt) : '',
		};

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

	function renderBatchProgress() {
		if (!state.batch.running) return '';
		const pct = state.batch.total > 0 ? Math.min(100, Math.round((state.batch.done / state.batch.total) * 100)) : 15;
		return `<div class="tco-progress-box">
			<div class="tco-win__foot">
				<strong>${esc(state.batch.title)} — ${faNum(state.batch.done)} از ${faNum(state.batch.total)} (${faNum(pct)}٪)</strong>
				<div class="tco-row-actions">
					<span>آزادشده تا این لحظه: <strong>${formatBytes(state.batch.savedBytes)}</strong></span>
					<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tco-stop-batch">توقف محترمانه</button>
				</div>
			</div>
			<div class="tco-progress-bar"><span style="width:${pct}%"></span></div>
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

		const pct = Math.max(4, Math.min(99, Number(state.scan.percent || 5)));
		return `<div class="tco-card">
			<div class="tco-card__head">
				<div>
					<h2 class="tco-card__title">${esc(state.scan.stageLabel || 'در حال اسکن مرحله‌ای ۱۲لایه و محاسبهٔ دیسک…')}</h2>
					<p class="tco-card__desc">
						${state.scan.detailLabel ? esc(faTextDigits(state.scan.detailLabel)) + ' · ' : ''}
						اجرای تکه‌تکهٔ ۱٫۷۵ ثانیه‌ای ضد-تایم‌اوت (بدون فشار روی رم و CPU هاست)
						${state.scan.elapsedSec > 0 ? ' · زمان سپری‌شده: ' + faNum(state.scan.elapsedSec) + ' ثانیه' : ''}
					</p>
				</div>
				<span class="tco-pill tco-pill--ok">${faNum(pct)}٪</span>
			</div>
			<div class="tco-progress-bar"><span style="width:${pct}%"></span></div>
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
							<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm" id="tco-quick-clean-unscaled" ${qw.unscaled.count === 0 ? 'disabled' : ''}>آزادسازی فوری با صفر افت کیفیت</button>
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
								${r.has_webp ? '<span class="tco-pill tco-pill--ok">WebP فعال</span>' : ''}
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
					<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm" id="tco-bulk-opt" ${selCount === 0 ? 'disabled' : ''}>فشرده‌سازی و WebP (${faNum(selCount)})</button>
					<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm ${state.deleteMode === 'permanent' ? 'tco-btn-danger' : ''} ${bulkArm ? 'is-armed' : ''}" id="tco-bulk-remove" ${selCount === 0 ? 'disabled' : ''}>
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

			<div class="tco-pager">
				<span>صفحهٔ ${faNum(data ? data.page : 1)} از ${faNum(data ? data.pages : 1)}</span>
				<div class="tco-row-actions">
					<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" data-page-delta="-1" ${!data || data.page <= 1 ? 'disabled' : ''}>قبلی</button>
					<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" data-page-delta="1" ${!data || data.page >= data.pages ? 'disabled' : ''}>بعدی</button>
				</div>
			</div>
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

		return `<div class="tco-stack">
			<div class="tco-card">
				<div class="tco-card__head">
					<div>
						<h2 class="tco-card__title">اسکنر ۱۲لایهٔ تصاویر بدون استفاده و فایل‌های رهاشده روی دیسک</h2>
						<p class="tco-card__desc">تمام محصولات، گالری‌ها، متغیرها، سواچ‌ها و دسته‌ها (` + '`wp_termmeta`' + `)، محتوای گوتنبرگ، المنتور (` + '`\\/`' + `) و تنظیمات قالب بررسی شده‌اند.</p>
					</div>
					<div class="tco-row-actions">
						${renderDeleteModeSwitch()}
						<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tco-rescan-btn">اجرای اسکن عمیق تازه</button>
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
						<button type="button" class="tisa-btn ${state.deleteMode === 'permanent' ? 'tco-btn-danger' : 'tisa-btn--primary'} tisa-btn--sm ${sweepArm ? 'is-armed' : ''}" id="tco-sweep-all" ${!data || data.total === 0 ? 'disabled' : ''}>
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
						<button type="button" class="tisa-btn tisa-btn--primary" id="tco-start-bulk-compress">شروع بهینه‌سازی انبوه کل کتابخانه</button>
					</div>
				</div>
			</div>
		</div>`;
	}

	function renderDuplicatesTab() {
		const dup = state.duplicates;
		if (!dup) {
			return '<div class="tco-card"><div class="tco-loading-state"><span class="tco-spinner"></span><span>در حال محاسبهٔ اثر انگشت محتوایی SHA-1 روی فایل‌ها…</span></div></div>';
		}

		return `<div class="tco-card">
			<div class="tco-card__head">
				<div>
					<h2 class="tco-card__title">تشخیص تصاویر ۱۰۰٪ تکراری (SHA-1) و ادغام هوشمند بدون خرابی محصولات</h2>
					<p class="tco-card__desc">با کلیک روی «ادغام هوشمند»، تمام محصولات، متغیرها، گالری‌ها، دسته‌ها و برگه‌های المنتور به تصویر مرجع منتقل شده و نسخه‌های اضافی پاک/قرنطینه می‌شوند.</p>
				</div>
				<div class="tco-row-actions">
					${renderDeleteModeSwitch()}
					<button type="button" class="tisa-btn tisa-btn--primary tisa-btn--sm" id="tco-merge-all-dups" ${dup.groups_count === 0 ? 'disabled' : ''}>
						ادغام هوشمند همهٔ ${faNum(dup.groups_count)} گروه (${formatBytes(dup.waste_bytes)})
					</button>
				</div>
			</div>

			${
				dup.groups_count === 0
					? '<div class="tco-empty-state">هیچ تصویر تکراری بایت‌به‌بایتی در کتابخانهٔ رسانه وجود ندارد.</div>'
					: `<div class="tco-stack">
						${dup.groups
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
					<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tco-clean-disabled-sizes" ${disabledSet.size === 0 ? 'disabled' : ''}>حذف فایل‌های سایزهای غیرفعال از دیسک</button>
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
									<td>${faNum(sz.files_count)} فایل</td>
									<td><strong>${formatBytes(sz.bytes)}</strong></td>
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
			return '<div class="tco-card"><div class="tco-loading-state"><span class="tco-spinner"></span><span>در حال خواندن خزانهٔ قرنطینه و تاریخچهٔ عملیات‌ها…</span></div></div>';
		}

		return `<div class="tco-stack">
			<div class="tco-kpi-grid">
				<div class="tco-kpi">
					<span class="tco-kpi__label">موارد موجود در قرنطینهٔ ۳۰ روزه</span>
					<span class="tco-kpi__val">${faNum(v.summary.quarantine_count)} بسته</span>
					<span class="tco-kpi__meta">حجم قابل تخلیهٔ نهایی: ${formatBytes(v.summary.quarantine_bytes)}</span>
				</div>
				<div class="tco-kpi">
					<span class="tco-kpi__label">پشتیبان‌های اولیهٔ فشرده‌سازی</span>
					<span class="tco-kpi__val">${faNum(v.summary.originals_count)} تصویر</span>
					<span class="tco-kpi__meta">حجم: ${formatBytes(v.summary.originals_bytes)}</span>
				</div>
				<div class="tco-kpi">
					<span class="tco-kpi__label">عملیات‌های ثبت‌شده در تاریخچه</span>
					<span class="tco-kpi__val">${faNum((v.history || []).length)} نوبت</span>
					<span class="tco-kpi__meta">قابل ممیزی کامل توسط مدیر</span>
				</div>
				<div class="tco-kpi tco-kpi--accent">
					<span class="tco-kpi__label">بازگردانی دقیق با همان ID</span>
					<span class="tco-kpi__val">۱۰۰٪ فعال</span>
					<span class="tco-kpi__meta">همراه تمام زیرسایزها و متاهای قبلی</span>
				</div>
			</div>

			<div class="tco-subnav">
				<button type="button" class="tco-subnav__btn${state.vaultTab === 'quarantine' ? ' is-active' : ''}" data-vault-tab="quarantine">قرنطینهٔ ۳۰ روزه (${faNum(v.quarantine.length)})</button>
				<button type="button" class="tco-subnav__btn${state.vaultTab === 'originals' ? ' is-active' : ''}" data-vault-tab="originals">پشتیبان‌های اولیهٔ فشرده‌سازی (${faNum(v.originals.length)})</button>
				<button type="button" class="tco-subnav__btn${state.vaultTab === 'history' ? ' is-active' : ''}" data-vault-tab="history">تاریخچهٔ عملیات‌ها (${faNum(v.history.length)})</button>
			</div>

			<div class="tco-card">
				${
					state.vaultTab === 'quarantine'
						? `<div class="tco-card__head">
							<div>
								<h2 class="tco-card__title">بسته‌های قرنطینه‌شده (امکان بازگردانی تک‌کلیکی به مسیر و ID قبلی)</h2>
								<p class="tco-card__desc">پوشهٔ <span class="tco-code">uploads/tisa-opt-vault/quarantine/</span> از بیرون وب‌سرور کاملاً مسدود است.</p>
							</div>
							<button type="button" class="tisa-btn tisa-btn--sm tco-btn-danger" id="tco-purge-all-quarantine" ${v.quarantine.length === 0 ? 'disabled' : ''}>تخلیهٔ دائمی کل قرنطینه و آزادسازی کامل دیسک</button>
						</div>
						<div class="tco-table-wrap">
							<table class="tco-table">
								<thead><tr><th>فایل / پیوست</th><th>علت</th><th>حجم بسته</th><th>زمان قرنطینه</th><th>عملیات</th></tr></thead>
								<tbody>
									${
										v.quarantine.length
											? v.quarantine
													.map(
														(q) => `<tr>
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
													</tr>`
													)
													.join('')
											: '<tr><td colspan="5"><div class="tco-empty-state">قرنطینه خالی است.</div></td></tr>'
									}
								</tbody>
							</table>
						</div>`
						: state.vaultTab === 'originals'
						? `<div class="tco-card__head">
							<div>
								<h2 class="tco-card__title">نسخه‌های پشتیبان پیش از فشرده‌سازی / جایگزینی WebP</h2>
								<p class="tco-card__desc">هر زمان از کیفیت تمام تصاویر مطمئن شدید، می‌توانید این پوشه را تخلیه کنید.</p>
							</div>
							<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" id="tco-purge-all-orig" ${v.originals.length === 0 ? 'disabled' : ''}>حذف همهٔ پشتیبان‌های اولیه (${formatBytes(v.summary.originals_bytes)})</button>
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
														<td><button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm" data-restore-orig="${o.attachment_id}">بازگردانی فایل اولیه</button></td>
													</tr>`
													)
													.join('')
											: '<tr><td colspan="5"><div class="tco-empty-state">نسخهٔ پشتیبان اولیه‌ای ثبت نشده است.</div></td></tr>'
									}
								</tbody>
							</table>
						</div>`
						: `<div class="tco-card__head">
							<h2 class="tco-card__title">تاریخچهٔ ممیزی عملیات‌ها</h2>
							<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" id="tco-clear-history-btn" ${v.history.length === 0 ? 'disabled' : ''}>پاک کردن لاگ</button>
						</div>
						<div class="tco-table-wrap">
							<table class="tco-table">
								<thead><tr><th>عملیات</th><th>تعداد موارد</th><th>فضای آزادشده</th><th>توسط</th><th>زمان</th></tr></thead>
								<tbody>
									${
										v.history.length
											? v.history
													.map(
														(h) => `<tr>
														<td><strong>${esc(h.label)}</strong>${h.note ? `<span class="tco-subtext">${esc(h.note)}</span>` : ''}</td>
														<td>${faNum(h.items_count)}</td>
														<td><strong>${formatBytes(h.saved_bytes)}</strong></td>
														<td>${esc(h.user)}</td>
														<td><span class="tco-code">${esc(h.created_at)}</span></td>
													</tr>`
													)
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
			else if (state.subtab === 'compress') body = renderCompressTab();
			else if (state.subtab === 'duplicates') body = renderDuplicatesTab();
			else if (state.subtab === 'subsizes') body = renderSubsizesTab();
			else if (state.subtab === 'seo') body = renderSeoTab();
			else body = renderSettingsTab();

			main.innerHTML = renderImageSubnav() + renderBatchProgress() + body;
		} else if (state.module === 'vault') {
			main.innerHTML = renderBatchProgress() + renderVaultModule();
		} else {
			main.innerHTML = renderOtherModules();
		}
	}

	async function runIncrementalScan(resetFromZero = false) {
		if (state.scan.running) return;
		state.loadError = '';
		state.scan = {
			running: true,
			stageNum: 1,
			stageLabel: 'گام ۱ از ۵: استخراج فهرست پیوست‌ها و آماده‌سازی اسکن ۱۲لایه…',
			detailLabel: 'در حال شروع…',
			percent: 4,
			elapsedSec: 0,
		};
		render();

		let firstTick = Boolean(resetFromZero);
		let retries = 0;

		while (true) {
			try {
				const step = await api('scan_step', { reset: firstTick ? 1 : 0 });
				firstTick = false;
				retries = 0;

				state.scan.stageNum = step.stage_num || state.scan.stageNum;
				state.scan.stageLabel = step.stage_label || state.scan.stageLabel;
				state.scan.detailLabel = step.detail_label || '';
				state.scan.percent = Number(step.percent || state.scan.percent);
				state.scan.elapsedSec = Number(step.elapsed_sec || 0);

				if (step.done) {
					state.scan.running = false;
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
				state.loadError = err.message || 'خطا در حین اسکن مرحله‌ای سایت';
				render();
				throw err;
			}
		}
	}

	async function ensureDataForCurrentView(forceRescan = false, resumeSaved = false) {
		try {
			state.loadError = '';
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
					state.media = await api('query_media', state.explorerFilter);
				} else if (state.subtab === 'unused') {
					state.media = await api('query_media', state.unusedFilter);
				} else if (state.subtab === 'duplicates') {
					state.duplicates = await api('get_duplicates');
				} else if (state.subtab === 'subsizes') {
					const d = await api('get_subsizes');
					state.subsizes = d.sizes || [];
				} else if (state.subtab === 'seo') {
					state.seo = await api('get_seo', { seo_filter: state.seoFilter, page: state.seoPage });
				}
			} else if (state.module === 'vault') {
				state.vault = await api('get_vault');
			}
			render();
		} catch (err) {
			state.loadError = err.message || 'خطا در دریافت اطلاعات';
			render();
			toast(state.loadError, 'error');
		}
	}

	async function runBatchRemove(ids, relPaths) {
		const queueIds = [...ids];
		const queuePaths = [...relPaths];
		state.batch = {
			running: true,
			stopRequested: false,
			title: state.deleteMode === 'permanent' ? 'حذف قطعی تصاویر' : 'انتقال ایمن به قرنطینهٔ ۳۰ روزه',
			done: 0,
			total: queueIds.length + queuePaths.length,
			savedBytes: 0,
		};
		render();

		try {
			while ((queueIds.length > 0 || queuePaths.length > 0) && !state.batch.stopRequested) {
				const chunkIds = queueIds.splice(0, 25);
				const chunkPaths = queuePaths.splice(0, 25);
				const isLast = queueIds.length === 0 && queuePaths.length === 0;
				const res = await api('remove_items', {
					ids: chunkIds,
					rel_paths: chunkPaths,
					delete_mode: state.deleteMode,
					log_final: isLast ? 1 : 0,
				});
				state.batch.done += (res.done_ids || []).length + (res.done_paths || []).length;
				state.batch.savedBytes += Number(res.saved_bytes || 0);
				if (res.overview) {
					state.overview = res.overview;
				}
				render();
			}
			state.selectedIds.clear();
			state.selectedPaths.clear();
			toast(`عملیات با موفقیت انجام شد — ${formatBytes(state.batch.savedBytes)} آزاد شد.`);
		} catch (e) {
			toast(e.message, 'error');
		} finally {
			state.batch.running = false;
			state.overview = await api('overview');
			await ensureDataForCurrentView(false);
		}
	}

	async function runBatchOptimize(targetIds) {
		let queue = targetIds && targetIds.length ? [...targetIds] : null;
		if (!queue) {
			const init = await api('optimize_batch', { ids: [] });
			queue = init.queue_ids || [];
		}
		if (!queue.length) {
			toast('همهٔ تصاویر از قبل بهینه هستند!');
			return;
		}
		state.batch = {
			running: true,
			stopRequested: false,
			title: 'فشرده‌سازی و تبدیل نسل جدید تصاویر',
			done: 0,
			total: queue.length,
			savedBytes: 0,
		};
		const totalCount = queue.length;
		render();

		try {
			while (queue.length > 0 && !state.batch.stopRequested) {
				const chunk = queue.splice(0, 8);
				const res = await api('optimize_batch', {
					ids: chunk,
					log_final: queue.length === 0 ? 1 : 0,
					summary_count: totalCount,
					summary_bytes: state.batch.savedBytes,
				});
				state.batch.done += (res.processed || []).length;
				state.batch.savedBytes += Number(res.saved_bytes || 0);
				render();
			}
			state.selectedIds.clear();
			toast(`فشرده‌سازی تمام شد — ${formatBytes(state.batch.savedBytes)} صرفه‌جویی شد.`);
		} catch (e) {
			toast(e.message, 'error');
		} finally {
			state.batch.running = false;
			state.overview = await api('overview');
			await ensureDataForCurrentView(false);
		}
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
				<div class="tco-win"><strong>تفکیک دقیق دیسک</strong><span>کل: <strong>${formatBytes(r.total_bytes)}</strong> · اصلی: ${formatBytes(r.main_bytes)} · زیرسایزها: ${formatBytes(r.subsizes_bytes)}</span></div>
			</div>
			<div style="margin-top:12px">${renderUsageCell(r)}</div>
		`;
		modal.hidden = false;
	}

	// رویدادهای کلیک و تعامل در سطح سند.
	document.addEventListener('click', async (ev) => {
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
			state.explorerFilter.page = Math.max(1, state.explorerFilter.page + delta);
			await ensureDataForCurrentView(false);
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
			const totalN = q.length;
			while (q.length > 0 && !state.batch.stopRequested) {
				const chunk = q.splice(0, 12);
				const r = await api('regen_batch', { ids: chunk, log_final: q.length === 0 ? 1 : 0, summary_count: totalN });
				state.batch.done += (r.done_ids || []).length;
				render();
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
			const saved = await api('save_settings', { settings: payload });
			state.settings = saved.settings;
			state.deleteMode = saved.settings.delete_mode;
			toast('تنظیمات کلی با موفقیت ذخیره شد.');
			render();
			return;
		}

		const resQ = ev.target.closest('[data-restore-q]');
		if (resQ) {
			const r = await api('restore_quarantine', { item_ids: [resQ.getAttribute('data-restore-q')] });
			state.vault = r.vault;
			state.overview = r.overview;
			toast('تصویر با همان ID و مسیر اصلی به کتابخانه بازگردانده شد!');
			render();
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
			toast(`کل قرنطینه تخلیه شد (${formatBytes(r.saved_bytes)} آزاد شد).`);
			render();
			return;
		}

		const resOrig = ev.target.closest('[data-restore-orig]');
		if (resOrig) {
			const r = await api('restore_original', { id: Number(resOrig.getAttribute('data-restore-orig')) });
			state.vault = r.vault;
			toast('فایل اولیهٔ تصویر با موفقیت بازگردانی شد.');
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
	});

	document.addEventListener('change', async (ev) => {
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
		ensureDataForCurrentView(false);
	});
})(jQuery);
