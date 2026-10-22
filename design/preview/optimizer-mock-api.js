/**
 * شبیه‌ساز stateful و کامل سرور وردپرس/ووکامرس برای پیش‌نمایش زنده و تست یکپارچهٔ TisaCase Optimizer.
 * تمام ۱۶ عملیات `op` کلاس `TisaCase_Optimizer_Ajax` را با دادهٔ واقعی فروشگاه قاب موبایل شبیه‌سازی می‌کند.
 */
(function () {
	'use strict';

	// اگر فایل HTML مستقیم روی سیستم بدون node_modules باز شد، یک شیم سبک سازگار با jQuery فراهم می‌کنیم.
	if (typeof window.jQuery === 'undefined') {
		const miniJq = function (arg) {
			if (typeof arg === 'function') {
				if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', arg);
				else arg();
				return;
			}
			const els = typeof arg === 'string' ? [...document.querySelectorAll(arg)] : [arg].filter(Boolean);
			return {
				on(events, handler) {
					const evs = events.split(/\s+/).map((e) => e.split('.')[0]);
					els.forEach((el) => evs.forEach((ev) => el.addEventListener(ev, handler)));
					return this;
				},
			};
		};
		window.jQuery = miniJq;
		window.$ = miniJq;
	}

	function svgCaseThumb(bg1, bg2, label, accent) {
		const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="320" height="320" viewBox="0 0 320 320">
			<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0%" stop-color="${bg1}"/><stop offset="100%" stop-color="${bg2}"/></linearGradient></defs>
			<rect width="320" height="320" rx="28" fill="url(#g)"/>
			<rect x="86" y="34" width="148" height="252" rx="30" fill="#1F2A2E" opacity=".9"/>
			<rect x="94" y="42" width="132" height="236" rx="24" fill="${accent}"/>
			<rect x="108" y="56" width="52" height="52" rx="14" fill="#1F2A2E" opacity=".24"/>
			<circle cx="160" cy="166" r="34" fill="none" stroke="#FFFFFF" stroke-width="5" opacity=".82"/>
			<text x="160" y="252" text-anchor="middle" fill="#FFFFFF" font-family="sans-serif" font-size="15" font-weight="700">${label}</text>
		</svg>`;
		return 'data:image/svg+xml;utf8,' + encodeURIComponent(svg);
	}

	const db = {
		settings: {
			delete_mode: 'quarantine',
			quarantine_days: 30,
			quality_preset: 'balanced',
			jpeg_quality: 82,
			webp_quality: 80,
			avif_quality: 68,
			nextgen_format: 'webp',
			webp_mode: 'sidecar',
			delivery_method: 'picture',
			auto_optimize_upload: 1,
			resize_large_images: 1,
			max_width: 1920,
			max_height: 1920,
			keep_exif: 0,
			keep_icc_profile: 1,
			convert_opaque_png: 1,
			backup_originals: 1,
			seo_alt_pattern: '{product_title} — {site_name}',
			disabled_sizes: ['1536x1536', '2048x2048', 'woodmart_shop_catalog_x2'],
			excluded_dirs: 'tisa-opt-vault\ntisa-exports\nwc-logs\nelementor',
		},
		lifetime: {
			saved_bytes: 184549376,
			optimized_count: 142,
			quarantined_count: 38,
			deleted_count: 19,
			dedup_merged: 14,
			seo_fixed: 96,
		},
		items: [
			{
				id: 5012,
				filename: 'iphone-15-promax-magsafe-titanium-scaled.png',
				rel_path: '2026/10/iphone-15-promax-magsafe-titanium-scaled.png',
				folder: '2026/10',
				title: 'IMG_8842_RAW',
				alt: '',
				mime_type: 'image/png',
				format: 'PNG',
				date: '2026-10-19',
				width: 2560,
				height: 2560,
				main_bytes: 6180000,
				subsizes_bytes: 4920000,
				unscaled_bytes: 8450000,
				subsizes_count: 11,
				is_opaque_png: true,
				is_optimized: false,
				has_webp: false,
				saved_bytes: 0,
				is_pinned: false,
				sha1: 'a91f00112233445566778899aabbccddeeff0001',
				usage_status: 'used',
				refs: [
					{ type: 'featured', post_id: 4812, label: 'تصویر شاخص محصول: قاب مگ‌سیف تیتانیوم آیفون ۱۵ پرومکس' },
					{ type: 'variation', post_id: 4815, label: 'تصویر متغیر #4815 (رنگ تیتانیوم طبیعی)' },
				],
				thumb_url: svgCaseThumb('#064E46', '#129786', 'IP15PM TITAN', '#0E7C6B'),
			},
			{
				id: 5011,
				filename: 'old-banner-summer-campaign-v1-unused.png',
				rel_path: '2026/08/old-banner-summer-campaign-v1-unused.png',
				folder: '2026/08',
				title: 'old-banner-summer-campaign-v1',
				alt: '',
				mime_type: 'image/png',
				format: 'PNG',
				date: '2026-08-04',
				width: 2400,
				height: 1600,
				main_bytes: 7340032,
				subsizes_bytes: 3984588,
				unscaled_bytes: 0,
				subsizes_count: 10,
				is_opaque_png: true,
				is_optimized: false,
				has_webp: false,
				saved_bytes: 0,
				is_pinned: false,
				sha1: 'b77e00112233445566778899aabbccddeeff0002',
				usage_status: 'strict_unused',
				refs: [],
				thumb_url: svgCaseThumb('#7A2E2E', '#B5453A', 'UNUSED 10.8MB', '#B5453A'),
			},
			{
				id: 5010,
				filename: 'samsung-s24-ultra-leather-olive-scaled.jpg',
				rel_path: '2026/10/samsung-s24-ultra-leather-olive-scaled.jpg',
				folder: '2026/10',
				title: 'قاب چرمی سامسونگ S24 Ultra',
				alt: 'قاب چرمی سامسونگ S24 Ultra — تیساکیس',
				mime_type: 'image/jpeg',
				format: 'JPEG',
				date: '2026-10-17',
				width: 2560,
				height: 2560,
				main_bytes: 3420000,
				subsizes_bytes: 2890000,
				unscaled_bytes: 6920000,
				subsizes_count: 11,
				is_opaque_png: false,
				is_optimized: false,
				has_webp: false,
				saved_bytes: 0,
				is_pinned: false,
				sha1: 'dup99887766554433221100ffeeddccbbaa99887',
				usage_status: 'used',
				refs: [
					{ type: 'featured', post_id: 4790, label: 'تصویر شاخص محصول: قاب چرمی S24 Ultra' },
					{ type: 'term_meta', post_id: 0, label: 'تصویر دسته/برند ووکامرس (قاب سامسونگ)' },
				],
				thumb_url: svgCaseThumb('#1D4E4E', '#0E7C6B', 'S24 ULTRA', '#1A7F37'),
			},
			{
				id: 5009,
				filename: 'samsung-s24-ultra-leather-olive-copy.jpg',
				rel_path: '2026/10/samsung-s24-ultra-leather-olive-copy.jpg',
				folder: '2026/10',
				title: 'photo_2026-10-17_telegram',
				alt: '',
				mime_type: 'image/jpeg',
				format: 'JPEG',
				date: '2026-10-17',
				width: 2560,
				height: 2560,
				main_bytes: 3420000,
				subsizes_bytes: 2890000,
				unscaled_bytes: 0,
				subsizes_count: 11,
				is_opaque_png: false,
				is_optimized: false,
				has_webp: false,
				saved_bytes: 0,
				is_pinned: false,
				sha1: 'dup99887766554433221100ffeeddccbbaa99887',
				usage_status: 'used',
				refs: [{ type: 'gallery', post_id: 4801, label: 'گالری محصول: پک ویژه S24 Ultra' }],
				thumb_url: svgCaseThumb('#1D4E4E', '#0E7C6B', 'S24 DUP #2', '#1A7F37'),
			},
			{
				id: 5008,
				filename: 'deleted-product-mockup-case-04.jpg',
				rel_path: '2026/07/deleted-product-mockup-case-04.jpg',
				folder: '2026/07',
				title: 'deleted-product-mockup-case-04',
				alt: '',
				mime_type: 'image/jpeg',
				format: 'JPEG',
				date: '2026-07-11',
				width: 2000,
				height: 2000,
				main_bytes: 4194304,
				subsizes_bytes: 2621440,
				unscaled_bytes: 0,
				subsizes_count: 9,
				is_opaque_png: false,
				is_optimized: false,
				has_webp: false,
				saved_bytes: 0,
				is_pinned: false,
				sha1: 'c11223344556677889900aabbccddeeff1122334',
				usage_status: 'strict_unused',
				refs: [],
				thumb_url: svgCaseThumb('#8A6116', '#B5453A', 'ORPHAN 6.5MB', '#8A6116'),
			},
			{
				id: 5007,
				filename: 'telegram-bot-draft-import-ip16-silicone.jpg',
				rel_path: '2026/10/telegram-bot-draft-import-ip16-silicone.jpg',
				folder: '2026/10',
				title: 'photo_2026_10_20_bot',
				alt: '',
				mime_type: 'image/jpeg',
				format: 'JPEG',
				date: '2026-10-20',
				width: 1800,
				height: 1800,
				main_bytes: 1890000,
				subsizes_bytes: 1120000,
				unscaled_bytes: 0,
				subsizes_count: 8,
				is_opaque_png: false,
				is_optimized: false,
				has_webp: false,
				saved_bytes: 0,
				is_pinned: false,
				sha1: 'draft77889900aabbccddeeff1122334455667788',
				usage_status: 'draft_only',
				refs: [{ type: 'featured', post_id: 4990, label: 'پیش‌نویس ایمپورت ربات تلگرام: قاب سیلیکونی آیفون ۱۶' }],
				thumb_url: svgCaseThumb('#135E96', '#0E7C6B', 'DRAFT SAFE', '#135E96'),
			},
			{
				id: 5006,
				filename: 'xiaomi-14t-pro-clear-armor.webp',
				rel_path: '2026/10/xiaomi-14t-pro-clear-armor.webp',
				folder: '2026/10',
				title: 'قاب شفاف ضربه‌گیر شیائومی 14T Pro',
				alt: 'قاب شفاف ضربه‌گیر شیائومی 14T Pro — تیساکیس',
				mime_type: 'image/webp',
				format: 'WEBP',
				date: '2026-10-18',
				width: 1600,
				height: 1600,
				main_bytes: 284000,
				subsizes_bytes: 310000,
				unscaled_bytes: 0,
				subsizes_count: 7,
				is_opaque_png: false,
				is_optimized: true,
				has_webp: true,
				saved_bytes: 1940000,
				is_pinned: true,
				sha1: 'webp11223344556677889900aabbccddeeff5566',
				usage_status: 'pinned',
				refs: [{ type: 'featured', post_id: 4850, label: 'تصویر شاخص محصول: قاب شفاف شیائومی 14T Pro' }],
				thumb_url: svgCaseThumb('#0A5F52', '#129786', 'OPTIMIZED', '#0E7C6B'),
			},
		],
		diskOrphans: [
			{
				id: 0,
				rel_path: '2026/06/unregistered-raw-shoot-01.png',
				filename: 'unregistered-raw-shoot-01.png',
				ext: 'PNG',
				bytes: 5452595,
				modified: '2026-06-14 19:22',
			},
			{
				id: 0,
				rel_path: '2026/06/temp-telegram-extract-leftover.jpg',
				filename: 'temp-telegram-extract-leftover.jpg',
				ext: 'JPG',
				bytes: 3145728,
				modified: '2026-06-29 11:05',
			},
		],
		subsizes: [
			{ key: 'thumbnail', width: 150, height: 150, crop: true, source: 'هستهٔ وردپرس', essential: true, files_count: 412, bytes: 14680064 },
			{ key: 'medium', width: 300, height: 300, crop: false, source: 'هستهٔ وردپرس', essential: true, files_count: 412, bytes: 28311552 },
			{ key: 'woocommerce_thumbnail', width: 430, height: 430, crop: true, source: 'ووکامرس', essential: true, files_count: 410, bytes: 46137344 },
			{ key: 'woocommerce_single', width: 700, height: 700, crop: false, source: 'ووکامرس', essential: true, files_count: 398, bytes: 79691776 },
			{ key: '1536x1536', width: 1536, height: 1536, crop: false, source: 'هستهٔ وردپرس', essential: false, files_count: 289, bytes: 138412032 },
			{ key: '2048x2048', width: 2048, height: 2048, crop: false, source: 'هستهٔ وردپرس', essential: false, files_count: 244, bytes: 192937984 },
			{ key: 'woodmart_shop_catalog_x2', width: 860, height: 860, crop: true, source: 'قالب WoodMart', essential: false, files_count: 350, bytes: 112197632 },
		],
		quarantine: [
			{
				id: 'q_20261020_5901',
				attachment_id: 4901,
				filename: 'legacy-slider-spring-1404.jpg',
				rel_path: '2026/03/legacy-slider-spring-1404.jpg',
				reason: 'manual_unused',
				files_count: 9,
				total_bytes: 6815744,
				created_at: '2026-10-20 21:15:00',
				snapshot: null,
			},
		],
		originals: [
			{
				attachment_id: 5006,
				rel_path: '2026/10/xiaomi-14t-pro-clear-armor.png',
				mime_type: 'image/png',
				bytes: 2224000,
				created_at: '2026-10-18 16:40:00',
			},
		],
		history: [
			{
				type: 'compress',
				label: 'فشرده‌سازی خودکار و تبدیل WebP هنگام ایمپورت تلگرام',
				items_count: 28,
				saved_bytes: 64200000,
				user: 'علیرضا شعبان زاده',
				created_at: '2026-10-20 22:10:00',
				note: 'پروفایل متعادل فروشگاهی (۸۰)',
			},
		],
	};

	function hydrateRow(item) {
		const total = item.main_bytes + item.subsizes_bytes + item.unscaled_bytes;
		const status = item.is_pinned ? 'pinned' : item.usage_status;
		return Object.assign({}, item, {
			total_bytes: total,
			subsizes_total_bytes: item.subsizes_bytes + item.unscaled_bytes,
			usage_status: status,
			refs_count: (item.refs || []).length,
			full_url: item.thumb_url,
		});
	}

	function computeDuplicates() {
		const byHash = {};
		db.items.forEach((it) => {
			if (!it.sha1) return;
			byHash[it.sha1] = byHash[it.sha1] || [];
			byHash[it.sha1].push(hydrateRow(it));
		});
		const groups = [];
		let waste = 0;
		let count = 0;
		Object.keys(byHash).forEach((h) => {
			const members = byHash[h];
			if (members.length < 2) return;
			const master = members[0];
			const w = members.slice(1).reduce((s, m) => s + m.total_bytes, 0);
			waste += w;
			count += members.length - 1;
			groups.push({
				hash: h,
				short_hash: h.slice(0, 12),
				master_id: master.id,
				count: members.length,
				waste_bytes: w,
				total_bytes: members.reduce((s, m) => s + m.total_bytes, 0),
				dimensions: `${master.width}×${master.height}`,
				format: master.format,
				members,
			});
		});
		return { groups, groups_count: groups.length, extra_files: count, waste_bytes: waste };
	}

	function computeOverview() {
		const rows = db.items.map(hydrateRow);
		const mainB = rows.reduce((s, r) => s + r.main_bytes, 0);
		const subB = rows.reduce((s, r) => s + r.subsizes_bytes, 0) + db.subsizes.reduce((s, z) => s + z.bytes, 0);
		const unsB = rows.reduce((s, r) => s + r.unscaled_bytes, 0);
		const qB = db.quarantine.reduce((s, q) => s + q.total_bytes, 0) + db.originals.reduce((s, o) => s + o.bytes, 0);
		const orphanB = db.diskOrphans.reduce((s, o) => s + o.bytes, 0);

		const formats = {};
		rows.forEach((r) => {
			formats[r.format] = formats[r.format] || { count: 0, bytes: 0 };
			formats[r.format].count += 1;
			formats[r.format].bytes += r.total_bytes;
		});

		const strictUnused = rows.filter((r) => r.usage_status === 'strict_unused');
		const drafts = rows.filter((r) => r.usage_status === 'draft_only');
		const unscaled = rows.filter((r) => r.unscaled_bytes > 0);
		const unopt = rows.filter((r) => !r.is_optimized);
		const dups = computeDuplicates();

		return {
			uploads_bytes: mainB + subB + unsB + orphanB,
			total_images: rows.length,
			total_inodes: rows.reduce((s, r) => s + 1 + r.subsizes_count, 0) + db.diskOrphans.length,
			layers: {
				main_bytes: mainB,
				subsizes_bytes: subB,
				unscaled_bytes: unsB,
				quarantine_bytes: qB,
			},
			formats,
			quick_wins: {
				unused: {
					count: strictUnused.length,
					bytes: strictUnused.reduce((s, r) => s + r.total_bytes, 0),
					orphan_count: db.diskOrphans.length,
					orphan_bytes: orphanB,
					draft_count: drafts.length,
					draft_bytes: drafts.reduce((s, r) => s + r.total_bytes, 0),
					missing_db: 0,
				},
				unscaled: {
					count: unscaled.length,
					bytes: unsB,
				},
				compress: {
					count: unopt.length,
					opaque_png: rows.filter((r) => r.is_opaque_png && !r.is_optimized).length,
					est_bytes: Math.round(unopt.reduce((s, r) => s + r.main_bytes * (r.is_opaque_png ? 0.72 : 0.45), 0)),
				},
				duplicates: {
					groups: dups.groups_count,
					count: dups.extra_files,
					bytes: dups.waste_bytes,
				},
				seo: {
					missing_alt: rows.filter((r) => !r.alt).length,
				},
			},
			vault: {
				quarantine_count: db.quarantine.length,
				quarantine_bytes: db.quarantine.reduce((s, q) => s + q.total_bytes, 0),
				originals_count: db.originals.length,
				originals_bytes: db.originals.reduce((s, o) => s + o.bytes, 0),
			},
			lifetime_stats: Object.assign({}, db.lifetime),
			scan_built_at: '2026-10-21 15:40:00',
			scan_ms: 42,
		};
	}

	function parseBody(bodyStr) {
		const p = new URLSearchParams(bodyStr || '');
		const out = {};
		for (const [k, v] of p.entries()) {
			if (k.endsWith('[]')) {
				const base = k.slice(0, -2);
				out[base] = out[base] || [];
				out[base].push(v);
			} else {
				const m = k.match(/^([^[]+)\[([^\]]+)\](\[\])?$/);
				if (m) {
					out[m[1]] = out[m[1]] || {};
					if (m[3]) {
						out[m[1]][m[2]] = out[m[1]][m[2]] || [];
						out[m[1]][m[2]].push(v);
					} else {
						out[m[1]][m[2]] = v;
					}
				} else {
					out[k] = v;
				}
			}
		}
		return out;
	}

	window.tcoBoot = {
		ajaxUrl: '/wp-admin/admin-ajax.php',
		editPostBase: '#post-',
		action: 'tisacase_optimizer',
		nonce: 'preview-nonce-1405',
		version: '1.0.2',
		initialModule: 'images',
		initialTab: 'overview',
		settings: Object.assign({}, db.settings),
		stats: Object.assign({}, db.lifetime),
		modules: [
			{
				id: 'images',
				title: 'تصاویر و رسانه',
				icon: 'image',
				badge: 'فعال · ۸ ابزار کامل',
				subtabs: {
					overview: 'نمای کلی دیسک',
					explorer: 'سنگین‌ترین تصاویر',
					unused: 'تصاویر بدون استفاده',
					restore: 'پشتیبان و بازگردانی',
					compress: 'فشرده‌سازی و WebP',
					duplicates: 'تصاویر تکراری',
					subsizes: 'زیرسایزها و بازسازی',
					seo: 'سئو و Alt خودکار',
					settings: 'تنظیمات و اتوماسیون',
				},
			},
			{ id: 'vault', title: 'پشتیبان و بازیابی', icon: 'shield', badge: 'بازگردانی ۳۰ روزه' },
			{ id: 'system', title: 'سلامت سرور و کرون', icon: 'activity', badge: 'رادار زنده + فاز ۴' },
			{
				id: 'database',
				title: 'دیتابیس و ووکامرس',
				icon: 'database',
				badge: 'فاز ۲',
				subtitle: 'پاک‌سازی امن رونوشت‌ها، Action Scheduler ووکامرس، متاهای یتیم و تحلیل سنگین‌ترین Autoloadها.',
				roadmap: [
					{ title: 'پاک‌سازی Action Scheduler و سشن‌های منقضی ووکامرس', desc: 'تخلیهٔ امن لاگ‌های حجیم بدون لمس سفارش‌ها.' },
					{ title: 'پروفایلر Autoload جدول wp_options با کلید بازگردانی', desc: 'شناسایی آپشن‌های سنگین در رم.' },
				],
			},
			{
				id: 'frontend',
				title: 'شتاب‌دهندهٔ فرانت‌اند',
				icon: 'bolt',
				badge: 'فاز ۳',
				subtitle: 'محافظت خودکار از LCP تصویر اول محصول، رفع CLS و حذف اسکریپت‌های اضافی.',
				roadmap: [
					{ title: 'محافظت هوشمند از تصویر شاخص محصول (fetchpriority=high)', desc: 'بهبود مستقیم Core Web Vitals موبایل.' },
					{ title: 'مهار Heartbeat، ایموجی و Bloat هسته', desc: 'کاهش مصرف CPU هاست در ساعات اوج ترافیک.' },
				],
			},
		],
		moduleBoot: {
			images: { capabilities: { imagick: true, gd: true, webp: true, avif: true, icc: true, engine: 'Imagick' } },
			system: {
				diagnostics: {
					php_version: '8.2.24',
					wp_version: '6.6.2',
					wc_version: '9.5.0',
					memory_limit: '512M',
					max_execution: '120s',
					upload_max: '128 MB',
					image_engine: 'Imagick',
					webp_supported: true,
					avif_supported: true,
					icc_supported: true,
					opcache_active: true,
					object_cache: true,
					cron_events: 29,
				},
			},
			database: { snapshot: { revisions: 642, auto_drafts: 19, autoload_bytes: 942080 } },
		},
	};

	const origFetch = window.fetch;
	window.fetch = async function (url, opts) {
		if (String(url).indexOf('admin-ajax.php') === -1) {
			return origFetch ? origFetch(url, opts) : Promise.reject(new Error('No fetch'));
		}
		const req = parseBody(opts && opts.body);
		const op = req.op;
		let data = {};

		if (op === 'overview') {
			data = Number(req.force_rescan) === 1 ? { needs_scan: true } : computeOverview();
		} else if (op === 'scan_step') {
			const ov = computeOverview();
			data = {
				done: true,
				stage: 'done',
				stage_num: 5,
				stage_label: 'تحلیل ۱۲لایه و محاسبهٔ دیسک کامل شد!',
				detail_label: '',
				percent: 100,
				elapsed_sec: 1,
				overview: ov,
			};
		} else if (op === 'query_media') {
			if (req.usage === 'unregistered_disk') {
				const rows = [...db.diskOrphans];
				data = {
					mode: 'disk_orphans',
					rows,
					total: rows.length,
					page: 1,
					per_page: 25,
					pages: 1,
					matched_bytes: rows.reduce((s, r) => s + r.bytes, 0),
				};
			} else {
				let rows = db.items.map(hydrateRow);
				if (req.search) {
					const q = String(req.search).toLowerCase();
					rows = rows.filter((r) => (r.filename + ' ' + r.title + ' ' + r.id).toLowerCase().includes(q));
				}
				if (req.format && req.format !== 'ALL') {
					rows = rows.filter((r) => (req.format === 'OPAQUE_PNG' ? r.is_opaque_png : r.format === req.format));
				}
				if (req.usage && req.usage !== 'all') {
					rows = rows.filter((r) => r.usage_status === req.usage);
				}
				if (Number(req.min_bytes) > 0) {
					rows = rows.filter((r) => r.total_bytes >= Number(req.min_bytes));
				}
				const sb = req.sort_by || 'total_bytes';
				rows.sort((a, b) => (b[sb] || 0) - (a[sb] || 0));
				data = {
					mode: 'attachments',
					rows,
					matching_ids: rows.map((r) => r.id),
					total: rows.length,
					page: 1,
					per_page: 25,
					pages: 1,
					matched_bytes: rows.reduce((s, r) => s + r.total_bytes, 0),
				};
			}
		} else if (op === 'toggle_pin') {
			const ids = (req.ids || []).map(Number);
			db.items.forEach((it) => {
				if (ids.includes(it.id)) it.is_pinned = Number(req.pin) === 1;
			});
			data = { pinned_ids: db.items.filter((i) => i.is_pinned).map((i) => i.id) };
		} else if (op === 'remove_items') {
			const ids = (req.ids || []).map(Number).filter(Boolean);
			const paths = (req.rel_paths || []).filter(Boolean);
			const batchId = req.batch_id || 'run' + Date.now();
			let saved = 0;
			const doneIds = [];
			const donePaths = [];

			ids.forEach((id) => {
				const idx = db.items.findIndex((x) => x.id === id && !x.is_pinned);
				if (idx === -1) return;
				const item = db.items.splice(idx, 1)[0];
				const bytes = item.main_bytes + item.subsizes_bytes + item.unscaled_bytes;
				saved += bytes;
				doneIds.push(id);
				if (req.delete_mode !== 'permanent') {
					db.quarantine.unshift({
						id: 'q_' + Date.now() + '_' + id,
						batch_id: batchId,
						attachment_id: item.id,
						filename: item.filename,
						rel_path: item.rel_path,
						reason: 'unused',
						files_count: 1 + item.subsizes_count,
						total_bytes: bytes,
						created_at: '2026-10-21 15:45:00',
						snapshot: item,
					});
					db.lifetime.quarantined_count += 1;
				} else {
					db.lifetime.deleted_count += 1;
				}
			});

			paths.forEach((p) => {
				const idx = db.diskOrphans.findIndex((o) => o.rel_path === p);
				if (idx === -1) return;
				const o = db.diskOrphans.splice(idx, 1)[0];
				saved += o.bytes;
				donePaths.push(p);
				if (req.delete_mode !== 'permanent') {
					db.quarantine.unshift({
						id: 'qf_' + Date.now() + '_' + Math.random().toString(36).slice(2, 6),
						batch_id: batchId,
						attachment_id: 0,
						filename: o.filename,
						rel_path: o.rel_path,
						reason: 'disk_orphan',
						files_count: 1,
						total_bytes: o.bytes,
						created_at: '2026-10-21 15:45:00',
					});
					db.lifetime.quarantined_count += 1;
				}
			});

			db.lifetime.saved_bytes += saved;
			db.history.unshift({
				batch_id: batchId,
				type: req.delete_mode === 'permanent' ? 'delete' : 'quarantine',
				label: req.delete_mode === 'permanent' ? 'حذف قطعی تصاویر انتخابی' : 'انتقال ایمن تصاویر به قرنطینهٔ ۳۰ روزه (قابل بازگردانی)',
				items_count: doneIds.length + donePaths.length,
				saved_bytes: saved,
				user: 'مدیر فروشگاه',
				created_at: '2026-10-21 15:45:12',
				note: '',
			});
			data = {
				batch_id: batchId,
				done_ids: doneIds,
				done_paths: donePaths,
				remaining_ids: [],
				remaining_paths: [],
				saved_bytes: saved,
				done: true,
				overview: computeOverview(),
			};
		} else if (op === 'clean_unscaled') {
			let cleaned = 0;
			let saved = 0;
			db.items.forEach((it) => {
				if (it.unscaled_bytes > 0) {
					saved += it.unscaled_bytes;
					it.unscaled_bytes = 0;
					cleaned += 1;
				}
			});
			db.lifetime.saved_bytes += saved;
			data = { cleaned, saved_bytes: saved };
		} else if (op === 'optimize_batch') {
			const ids = (req.ids || []).map(Number).filter(Boolean);
			if (!ids.length) {
				data = { queue_ids: db.items.filter((i) => !i.is_optimized).map((i) => i.id) };
			} else {
				let saved = 0;
				ids.forEach((id) => {
					const it = db.items.find((x) => x.id === id);
					if (!it || it.is_optimized) return;
					const cut = Math.round(it.main_bytes * (it.is_opaque_png ? 0.72 : 0.45));
					it.main_bytes -= cut;
					it.saved_bytes += cut;
					it.is_optimized = true;
					it.has_webp = true;
					if (db.settings.webp_mode === 'replace') {
						it.format = 'WEBP';
						it.filename = it.filename.replace(/\.(png|jpe?g)$/i, '.webp');
					}
					saved += cut;
					db.lifetime.optimized_count += 1;
				});
				db.lifetime.saved_bytes += saved;
				data = { processed: ids, remaining_ids: [], saved_bytes: saved, done: true };
			}
		} else if (op === 'get_duplicates') {
			data = computeDuplicates();
		} else if (op === 'merge_duplicates') {
			const current = computeDuplicates();
			let saved = 0;
			let merged = 0;
			const targetGroups = req.master_id
				? current.groups.filter((g) => g.members.some((m) => m.id === Number(req.master_id)))
				: current.groups;

			targetGroups.forEach((g) => {
				const masterId = req.master_id ? Number(req.master_id) : g.master_id;
				const master = db.items.find((x) => x.id === masterId);
				g.members.forEach((m) => {
					if (m.id === masterId) return;
					const dupIdx = db.items.findIndex((x) => x.id === m.id);
					if (dupIdx === -1) return;
					const removed = db.items.splice(dupIdx, 1)[0];
					if (master && removed.refs.length) {
						master.refs = master.refs.concat(removed.refs);
					}
					saved += removed.main_bytes + removed.subsizes_bytes;
					merged += 1;
				});
			});
			db.lifetime.saved_bytes += saved;
			db.lifetime.dedup_merged += merged;
			data = { merged, rewired: merged, saved_bytes: saved, duplicates: computeDuplicates(), overview: computeOverview() };
		} else if (op === 'get_subsizes') {
			data = { sizes: db.subsizes };
		} else if (op === 'save_subsizes') {
			db.settings.disabled_sizes = Array.isArray(req.disabled_sizes) ? req.disabled_sizes : [];
			data = { settings: db.settings, sizes: db.subsizes };
		} else if (op === 'clean_disabled_subsizes') {
			const dis = new Set(db.settings.disabled_sizes || []);
			let saved = 0;
			let files = 0;
			db.subsizes.forEach((sz) => {
				if (dis.has(sz.key) && sz.bytes > 0) {
					saved += sz.bytes;
					files += sz.files_count;
					sz.bytes = 0;
					sz.files_count = 0;
				}
			});
			db.lifetime.saved_bytes += saved;
			data = { result: { deleted_files: files, saved_bytes: saved }, sizes: db.subsizes, overview: computeOverview() };
		} else if (op === 'regen_batch') {
			const ids = (req.ids || []).map(Number).filter(Boolean);
			data = ids.length ? { done_ids: ids, remaining_ids: [], done: true } : { queue_ids: db.items.map((i) => i.id) };
		} else if (op === 'get_seo' || op === 'bulk_generate_seo' || op === 'save_single_seo') {
			if (op === 'save_single_seo') {
				const it = db.items.find((x) => x.id === Number(req.id));
				if (it) it.alt = String(req.alt || '');
			}
			let updated = 0;
			if (op === 'bulk_generate_seo') {
				db.items.forEach((it) => {
					if (!it.alt) {
						const prod = (it.refs && it.refs[0] && it.refs[0].label.split(':').pop().trim()) || it.filename.replace(/\.[^.]+$/, '');
						it.alt = (req.seo_alt_pattern || '{product_title} — تیساکیس')
							.replace('{product_title}', prod)
							.replace('{site_name}', 'تیساکیس')
							.replace('{category}', 'قاب گوشی')
							.replace('{sku}', 'TC-' + it.id);
						updated += 1;
					}
				});
			}
			const missing = db.items.filter((i) => !i.alt);
			const audit = {
				summary: {
					total_images: db.items.length,
					missing_alt: missing.length,
					bad_titles: 2,
					coverage_pct: Math.round(((db.items.length - missing.length) / Math.max(1, db.items.length)) * 100),
				},
				rows: missing.map((m) =>
					Object.assign(hydrateRow(m), {
						suggested_alt: ((m.refs[0] && m.refs[0].label) || m.filename.replace(/\.[^.]+$/, '')) + ' — تیساکیس',
					})
				),
				total: missing.length,
				page: 1,
				pages: 1,
			};
			data = op === 'bulk_generate_seo' ? { updated, audit } : audit;
		} else if (op === 'get_vault' || op === 'restore_quarantine' || op === 'purge_quarantine' || op === 'restore_original' || op === 'purge_originals' || op === 'clear_history') {
			let savedBytes = 0;
			let restoredCount = 0;
			if (op === 'restore_quarantine') {
				let targetIds = (req.item_ids || []).filter(Boolean);
				if (!targetIds.length && req.batch_id) {
					targetIds = db.quarantine.filter((q) => q.batch_id === req.batch_id).map((q) => q.id);
				} else if (!targetIds.length && Number(req.restore_all) === 1) {
					targetIds = db.quarantine.map((q) => q.id);
				}
				targetIds.forEach((qid) => {
					const idx = db.quarantine.findIndex((q) => q.id === qid);
					if (idx > -1) {
						const q = db.quarantine.splice(idx, 1)[0];
						restoredCount += 1;
						db.items.unshift(
							q.snapshot || {
								id: q.attachment_id || 4901,
								filename: q.filename,
								rel_path: q.rel_path,
								folder: '2026/03',
								title: q.filename,
								alt: '',
								mime_type: 'image/jpeg',
								format: 'JPEG',
								date: '2026-03-10',
								width: 1920,
								height: 1080,
								main_bytes: q.total_bytes,
								subsizes_bytes: 0,
								unscaled_bytes: 0,
								subsizes_count: 8,
								is_opaque_png: false,
								is_optimized: false,
								has_webp: false,
								saved_bytes: 0,
								is_pinned: false,
								sha1: 'restored998877',
								usage_status: 'strict_unused',
								refs: [],
								thumb_url: svgCaseThumb('#0E7C6B', '#129786', 'RESTORED', '#0A5F52'),
							}
						);
					}
				});
			} else if (op === 'restore_original') {
				if (Number(req.restore_all) === 1) {
					restoredCount = db.originals.length;
					db.originals = [];
				} else {
					const oid = Number(req.id);
					const oidx = db.originals.findIndex((o) => o.attachment_id === oid);
					if (oidx > -1) {
						db.originals.splice(oidx, 1);
						restoredCount = 1;
					}
				}
			} else if (op === 'purge_quarantine') {
				const ids = req.item_ids || [];
				if (!ids.length) {
					savedBytes = db.quarantine.reduce((s, q) => s + q.total_bytes, 0);
					db.quarantine = [];
				} else {
					db.quarantine = db.quarantine.filter((q) => !ids.includes(q.id));
				}
			} else if (op === 'purge_originals') {
				savedBytes = db.originals.reduce((s, o) => s + o.bytes, 0);
				db.originals = [];
			} else if (op === 'clear_history') {
				db.history = [];
			}
			const enrichedHistory = db.history.map((h) =>
				Object.assign({}, h, {
					recoverable_count: h.batch_id ? db.quarantine.filter((q) => q.batch_id === h.batch_id).length : 0,
				})
			);
			const vaultPayload = {
				quarantine: db.quarantine,
				originals: db.originals,
				history: enrichedHistory,
				summary: {
					quarantine_count: db.quarantine.length,
					quarantine_bytes: db.quarantine.reduce((s, q) => s + q.total_bytes, 0),
					originals_count: db.originals.length,
					originals_bytes: db.originals.reduce((s, o) => s + o.bytes, 0),
				},
			};
			data =
				op === 'get_vault'
					? vaultPayload
					: {
							restored: restoredCount,
							vault: vaultPayload,
							overview: computeOverview(),
							saved_bytes: savedBytes,
							result: { bytes: savedBytes },
					  };
		} else if (op === 'save_settings') {
			Object.assign(db.settings, req.settings || {});
			data = { settings: Object.assign({}, db.settings) };
		}

		const serialized = JSON.stringify({ success: true, data });
		return {
			ok: true,
			status: 200,
			text: async () => serialized,
			json: async () => ({ success: true, data }),
		};
	};
})();
