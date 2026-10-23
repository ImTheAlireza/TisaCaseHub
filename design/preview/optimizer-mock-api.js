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

	// ارقام فارسی برای متن‌های راهنما (هم‌سان با رابط کاربری).
	// دسترسی تست به یک آیتم (برای سنجش نتیجهٔ جایگزینی در E2E).
	window.__tcoMockItem = (id) => db.items.find((x) => x.id === Number(id));

	// ابزار آزمون: افزودن تصویر ساختگی (برای سناریوهای انبوه در E2E).
	window.__tcoMockSeedItems = (n, usageStatus) => {
		const base = db.items[0];
		let id = 9000;
		for (let i = 0; i < Number(n); i += 1) {
			id += 1;
			db.items.unshift(
				Object.assign({}, base, {
					id,
					filename: `seed-${id}.jpg`,
					rel_path: `2026/03/seed-${id}.jpg`,
					title: `تصویر آزمون ${id}`,
					alt: '',
					format: 'JPEG',
					mime: 'image/jpeg',
					is_optimized: false,
					has_webp: false,
					saved_bytes: 0,
					saved_ratio: 0,
					is_pinned: false,
					sha1: `seed${id}`,
					usage_status: usageStatus || 'used',
					refs: [],
				})
			);
		}
		return db.items.length;
	};

	// ابزار آزمون: ساخت گروه‌های تکراری (برای سنجش صفحه‌بندی فهرست تکراری‌ها).
	window.__tcoMockSeedDuplicates = (groups) => {
		const base = db.items[0];
		let id = 9500;
		for (let g = 0; g < Number(groups); g += 1) {
			const sha = `dupseed${g}`;
			for (let k = 0; k < 2; k += 1) {
				id += 1;
				db.items.unshift(
					Object.assign({}, base, {
						id,
						filename: `dup-${id}.jpg`,
						rel_path: `2026/05/dup-${id}.jpg`,
						title: `تصویر تکراری ${id}`,
						alt: 'تکراری',
						format: 'JPEG',
						mime: 'image/jpeg',
						is_optimized: true,
						has_webp: false,
						saved_bytes: 0,
						saved_ratio: 0,
						is_pinned: false,
						sha1: sha,
						usage_status: 'used',
						refs: [],
					})
				);
			}
		}
		return db.items.length;
	};

	// ابزار آزمون: چند «رکورد بدون فایل» قطعی می‌سازد (رکورد هست، فایل روی دیسک نیست).
	window.__tcoMockSeedMissing = (n = 2) => {
		db.items = db.items.filter((i) => i.usage_status !== 'missing_file');
		const count = Math.max(1, Number(n));
		for (let i = 0; i < count; i += 1) {
			const id = 7700 + i + 1;
			const month = String(7 + i).padStart(2, '0');
			db.items.unshift({
				id,
				filename: `missing-photo-${id}.png`,
				rel_path: `2026/${month}/missing-photo-${id}.png`,
				folder: `2026/${month}`,
				title: `missing-photo-${id}`,
				alt: '',
				mime_type: 'image/png',
				format: 'PNG',
				date: `2026-${month}-10`,
				width: 1200,
				height: 1200,
				main_bytes: 0,
				subsizes_bytes: 0,
				unscaled_bytes: 0,
				subsizes_count: 0,
				is_opaque_png: false,
				is_optimized: false,
				has_webp: false,
				saved_bytes: 0,
				is_pinned: false,
				sha1: '',
				exists: false,
				usage_status: 'missing_file',
				refs: [],
				thumb_url: '',
				full_url: `https://example.test/wp-content/uploads/2026/${month}/missing-photo-${id}.png`,
			});
		}
		return db.items.filter((i) => i.usage_status === 'missing_file').length;
	};

	// ابزار آزمون: افزودن موارد ساختگی به قرنطینه (تا دکمهٔ «بازگردانی همه» موجود باشد).
	window.__tcoMockSeedQuarantine = (n, reason) => {
		const why = reason || 'manual_unused';
		for (let i = 0; i < Number(n); i += 1) {
			const aid = 9500 + i;
			db.quarantine.unshift({
				id: `q_seed_${aid}`,
				batch_id: 'runseed',
				attachment_id: aid,
				filename: `seed-quarantine-${aid}.jpg`,
				rel_path: `2026/03/seed-quarantine-${aid}.jpg`,
				reason: why,
				files_count: 1,
				total_bytes: 512000,
				created_at: '2026-10-20 21:15:00',
				snapshot: null,
			});
		}
		return db.quarantine.length;
	};
	const faNum = (n) => String(Number(n) || 0).replace(/\d/g, (d) => '۰۱۲۳۴۵۶۷۸۹'[Number(d)]);

	const db = {
		// پوشه‌های محافظت‌شده (وایت‌لیست پوشه) — همان چیزی که سرور در آپشن ذخیره می‌کند.
		whitelistFolders: [],
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
			per_page: 25,
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
			// رکورد بدون فایل: رکورد هست، فایل روی دیسک نیست (۴۰۴).
			{
				id: 5321,
				filename: 'galaxy-s24-ultra-titanium-gray.png',
				rel_path: '2026/05/galaxy-s24-ultra-titanium-gray.png',
				folder: '2026/05',
				title: 'galaxy-s24-ultra',
				alt: 'گلکسی اس ۲۴ اولترا',
				mime_type: 'image/png',
				format: 'PNG',
				date: '2026-05-11',
				width: 1200,
				height: 1200,
				main_bytes: 0,
				subsizes_bytes: 0,
				unscaled_bytes: 0,
				subsizes_count: 0,
				is_opaque_png: false,
				is_optimized: false,
				has_webp: false,
				saved_bytes: 0,
				is_pinned: false,
				sha1: '',
				exists: false,
				usage_status: 'missing_file',
				refs: [],
				thumb_url: '',
				full_url: 'https://example.test/wp-content/uploads/2026/05/galaxy-s24-ultra-titanium-gray.png',
			},
			{
				id: 5288,
				filename: 'xiaomi-redmi-note-13-banner.jpg',
				rel_path: '2025/12/xiaomi-redmi-note-13-banner.jpg',
				folder: '2025/12',
				title: 'redmi-note-13-banner',
				alt: '',
				mime_type: 'image/jpeg',
				format: 'JPG',
				date: '2025-12-02',
				width: 1600,
				height: 900,
				main_bytes: 0,
				subsizes_bytes: 0,
				unscaled_bytes: 0,
				subsizes_count: 0,
				is_opaque_png: false,
				is_optimized: false,
				has_webp: false,
				saved_bytes: 0,
				is_pinned: false,
				sha1: '',
				exists: false,
				usage_status: 'missing_file',
				refs: [],
				thumb_url: '',
				full_url: 'https://example.test/wp-content/uploads/2025/12/xiaomi-redmi-note-13-banner.jpg',
			},
		],
		diskOrphans: [
			{
				id: 0,
				rel_path: 'receipt_upload/factor-1404-001.jpg',
				filename: 'factor-1404-001.jpg',
				ext: 'JPG',
				bytes: 262144,
				modified: '2026-07-02 09:10',
			},
			{
				id: 0,
				rel_path: 'receipt_upload/receipt-1404-bank.jpg',
				filename: 'receipt-1404-bank.jpg',
				ext: 'JPG',
				bytes: 188416,
				modified: '2026-07-02 09:12',
			},
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
			full_url: item.full_url || item.thumb_url,
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
		const missing = rows.filter((r) => r.usage_status === 'missing_file');
		const pinnedRows = rows.filter((r) => r.is_pinned);
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
					missing_db: missing.length,
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
			whitelist: {
				count: pinnedRows.length,
				bytes: pinnedRows.reduce((s, r) => s + r.total_bytes, 0),
				missing: pinnedRows.filter((r) => r.exists === false).length,
				folders: db.whitelistFolders.slice(),
				folder_stats: mockFolderStats(),
				folder_items: rows.filter((r) => mockIsWhitelistedPath(r.rel_path)).length,
				folder_bytes: 0,
			},
			vault: {
				quarantine_count: db.quarantine.length,
				quarantine_bytes: db.quarantine.reduce((s, q) => s + q.total_bytes, 0),
				originals_count: db.originals.length,
				originals_bytes: db.originals.reduce((s, o) => s + o.bytes, 0),
			},
			lifetime_stats: Object.assign({}, db.lifetime),
			next_actions: buildNextActions(rows, strictUnused, unscaled, unopt, dups, missing),
			scan_built_at: '2026-10-21 15:40:00',
			scan_ms: 42,
		};
	}

	// همان فهرست «قدم بعدی» که سرور واقعی می‌سازد (برای هم‌رفتاری پیش‌نمایش و افزونه).
	function buildNextActions(rows, strictUnused, unscaled, unopt, dups, missing) {
		const fa = faNum;
		const out = [];
		if (strictUnused.length) {
			out.push({
				key: 'unused',
				tone: 'ok',
				title: 'تصاویر مطلقاً بدون استفاده را مرور کنید',
				desc: 'هیچ‌جای سایت استفاده نمی‌شوند؛ انتقال به قرنطینه ۳۰ روزه با یک کلیک قابل بازگشت است.',
				count: strictUnused.length,
				bytes: strictUnused.reduce((s, r) => s + r.total_bytes, 0),
				cta: 'unused',
				label: 'دیدن فهرست',
			});
		}
		if (missing && missing.length) {
			out.push({
				key: 'missing_file',
				tone: 'warn',
				title: 'رکوردهای بدون فایل (۴۰۴) را مرتب کنید',
				desc: `${fa(missing.length)} رکورد در کتابخانه هست ولی فایلش روی هاست نیست؛ در صفحهٔ سایت جای عکس، تصویر شکسته دیده می‌شود. با یک کلیک فقط رکورد پاک/قرنطینه می‌شود (فایلی برای حذف وجود ندارد).`,
				count: missing.length,
				bytes: 0,
				cta: 'missing_file',
				label: 'دیدن فهرست',
			});
		}
		if (unopt.length) {
			out.push({
				key: 'compress',
				tone: 'ok',
				title: 'تصاویر سنگین را فشرده کنید',
				desc: 'با «فشرده‌سازی و جایگزینی» فایل سبک‌تر می‌شود و حجم دیسک واقعاً کم می‌شود.',
				count: unopt.length,
				bytes: Math.round(unopt.reduce((s, r) => s + r.main_bytes * (r.is_opaque_png ? 0.72 : 0.45), 0)),
				cta: 'optimize_all',
				label: 'فشرده‌سازی همه',
			});
		}
		if (unscaled.length) {
			out.push({
				key: 'unscaled',
				tone: 'ok',
				title: 'فایل‌های خام «-scaled» را پاک کنید',
				desc: 'وردپرس نسخهٔ اصلی سنگین را نگه می‌دارد؛ اگر به آن نیاز ندارید، پاک شود.',
				count: unscaled.length,
				bytes: unscaled.reduce((s, r) => s + r.unscaled_bytes, 0),
				cta: 'clean_unscaled',
				label: 'پاک‌سازی فایل‌های خام',
			});
		}
		if (dups.groups_count) {
			out.push({
				key: 'duplicates',
				tone: 'ok',
				title: 'تصاویر تکراری را ادغام کنید',
				desc: 'فایل‌هایی با هش یکسان؛ ادغام بدون شکستن لینک‌ها انجام می‌شود.',
				count: dups.extra_files,
				bytes: dups.waste_bytes,
				cta: 'duplicates',
				label: 'بررسی تکراری‌ها',
			});
		}
		if (db.quarantine.length) {
			out.push({
				key: 'quarantine',
				tone: 'info',
				title: 'قرنطینه را بازبینی کنید',
				desc: 'موارد قرنطینه‌شده تا ۳۰ روز با همان شناسه و زیرسایزها قابل بازگردانی هستند.',
				count: db.quarantine.length,
				bytes: db.quarantine.reduce((s, q) => s + q.total_bytes, 0),
				cta: 'quarantine',
				label: 'باز کردن قرنطینه',
			});
		}
		if (!out.length) {
			out.push({
				key: 'done',
				tone: 'ok',
				title: 'همه‌چیز مرتب است',
				desc: 'در این لحظه کار ضروری باقی نمانده؛ با «اسکن کامل» وضعیت را تازه نگه دارید.',
				count: 0,
				bytes: 0,
				cta: 'scan',
				label: 'اسکن تازه',
			});
		}
		void fa;
		void rows;
		return out;
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
				badge: 'فعال · ۹ ابزار کامل',
				subtabs: {
					overview: 'نمای کلی دیسک',
					explorer: 'سنگین‌ترین تصاویر',
					unused: 'تصاویر بدون استفاده',
					whitelist: 'لیست سفید (پین‌شده‌ها و پوشه‌ها)',
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
	/**
	 * شبیه‌ساز «موتور کار» برای فشرده‌سازی و بازگردانی — دقیقاً مثل سرور واقعی:
	 *   • بدون job_id ⇒ کار تازه ساخته می‌شود و صف در «سرور» می‌ماند.
	 *   • با job_id  ⇒ از همان نقطه ادامه می‌دهد (حتی بعد از رفرش صفحه).
	 *   • هر تیک فقط چند مورد کار می‌کند و همیشه «چند تا انجام شد / چند تا ماند»
	 *     را برمی‌گرداند، تا هیچ عددی گمانه‌زنی نباشد.
	 */
	const MOCK_STALE_AFTER = 180;

	function mockNowSec() {
		return Math.floor(Date.now() / 1000);
	}

	// همهٔ کارهای «در جریان» (هم کار حذف و هم کارهای فشرده‌سازی/بازگردانی).
	function mockRunningJobs() {
		const out = [];
		if (window.__tcoMockJob && window.__tcoMockJob.status === 'running') out.push(window.__tcoMockJob);
		Object.keys(window.__tcoMockJobs || {}).forEach((k) => {
			const j = window.__tcoMockJobs[k];
			if (j.status === 'running') out.push(j);
		});
		return out;
	}

	function mockIdleSec(job) {
		const last = Number(job.updated || job.startedAt || 0);
		return last > 0 ? Math.max(0, mockNowSec() - last) : 0;
	}

	function mockJobSnapshot(job) {
		const idle = mockIdleSec(job);
		const queueIds = job.queue_ids || job.queue || [];
		const total = job.total || 0;
		return {
			id: job.id,
			op: job.op || 'remove',
			op_label: job.op === 'optimize' ? 'فشرده‌سازی' : job.op === 'restore' ? 'بازگردانی' : 'حذف',
			mode: job.mode || '',
			phase: job.phase || 'run',
			status: job.status,
			total,
			done: job.done || 0,
			remaining: job.phase === 'verify'
				? queueIds.length + (job.queue_paths || []).length
				: queueIds.length + (job.queue_paths || []).length + (job.safe_ids || []).length + (job.safe_paths || []).length,
			verified: job.verified || 0,
			blocked_count: Object.keys(job.blocked || {}).length,
			blocked: job.blocked || {},
			blocked_paths: job.blocked_paths || {},
			failed_count: job.failed_count || job.failed || 0,
			failed: [],
			saved_bytes: job.saved_bytes || job.saved || 0,
			elapsed_sec: 1,
			idle_sec: idle,
			stale: idle >= MOCK_STALE_AFTER,
			stale_after: MOCK_STALE_AFTER,
			eta_sec: 0,
			coverage: 'full',
			needs_rescan: Boolean(job.needs_rescan),
			last_error: job.last_error || '',
			hint: '',
			block_reasons: [],
			finished: false,
		};
	}

	/**
	 * همان قاعدهٔ سرور واقعی: فقط یک کار هم‌زمان.
	 *   • کار «رهاشده» (۳ دقیقه بی‌خبر) خودکار بسته می‌شود و اطلاعاتش برمی‌گردد.
	 *   • کار زنده ⇒ پاسخ ۴۰۹ با جزئیات کامل (تا رابط کارت «ادامه یا رهاکردن» را نشان دهد).
	 */
	function mockGuardNewJob() {
		const running = mockRunningJobs();
		if (!running.length) return { reclaimed: null };
		const job = running[0];
		const idle = mockIdleSec(job);
		if (idle >= MOCK_STALE_AFTER) {
			const info = {
				op: job.op || 'remove',
				op_label: job.op === 'optimize' ? 'فشرده‌سازی' : job.op === 'restore' ? 'بازگردانی' : 'حذف',
				done: job.done || 0,
				total: job.total || 0,
				age_sec: idle,
				idle_minutes: Math.ceil(idle / 60),
			};
			job.status = 'cancelled';
			job.queue = [];
			job.queue_ids = [];
			job.queue_paths = [];
			job.safe_ids = [];
			job.safe_paths = [];
			return { reclaimed: info };
		}
		return {
			blocked: {
				job: mockJobSnapshot(job),
				conflict: true,
				message: `یک عملیات نیمه‌تمام در جریان است؛ ابتدا آن را ادامه دهید یا رها کنید. (${
					job.op === 'optimize' ? 'فشرده‌سازی' : job.op === 'restore' ? 'بازگردانی' : 'حذف'
				}: ${faNum(job.done || 0)} از ${faNum(job.total || 0)} — آخرین پیشرفت ${faNum(
					Math.max(1, Math.ceil(idle / 60))
				)} دقیقه پیش)`,
			},
		};
	}

	// شکست HTTP واقعی (با کد وضعیت و دادهٔ کامل) تا رفتار ۴۰۹ در پیش‌نمایش هم آزمون‌پذیر باشد.
	function mockHttpFailure(status, payload) {
		const serialized = JSON.stringify({ success: false, data: payload });
		return {
			ok: false,
			status,
			text: async () => serialized,
			json: async () => ({ success: false, data: payload }),
		};
	}

	function mockJobStep(op, req, extra, worker) {
		window.__tcoMockJobs = window.__tcoMockJobs || {};
		const opts = extra || {};
		const perTick = Math.max(1, Number(window.__tcoMockTickMax || 6));

		let job = req.job_id ? window.__tcoMockJobs[req.job_id] : null;
		if (req.job_id && !job) {
			throw new Error('این کار دیگر فعال نیست یا منقضی شده است.');
		}
		let reclaimed = null;
		if (!job) {
			const guard = mockGuardNewJob();
			if (guard.blocked) {
				// پاسخ HTTP با کد ۴۰۹ (نه دادهٔ موفق) — دقیقاً مثل سرور واقعی.
				return { __conflict: { status: 409, payload: guard.blocked } };
			}
			reclaimed = guard.reclaimed;
			job = {
				id: 'jobmock' + Date.now(),
				op,
				mode: opts.mode || '',
				queue: (opts.queue || []).slice(),
				total: (opts.queue || []).length,
				done: 0,
				failed: 0,
				saved: 0,
				status: 'running',
				last_error: '',
				updated: mockNowSec(),
			};
			window.__tcoMockJobs[job.id] = job;
		}

		const chunk = job.queue.splice(0, perTick);
		let payload = null;
		let saved = 0;
		if (chunk.length) {
			const res = worker(chunk) || {};
			saved = Number(res.saved || 0);
			job.done += Number(res.done != null ? res.done : chunk.length);
			job.failed += Number(res.failed || 0);
			job.saved += saved;
			payload = res.payload || null;
		}
		if (!job.queue.length) job.status = 'done';
		job.updated = mockNowSec();
		if (job.status === 'done') job.updated = mockNowSec();

		const finished = job.status === 'done';
		const idle = mockIdleSec(job);
		const summary = {
			id: job.id,
			op,
			op_label: op === 'optimize' ? 'فشرده‌سازی' : 'بازگردانی',
			mode: job.mode,
			phase: 'run',
			status: job.status,
			batch_id: 'run' + job.id,
			total: job.total,
			done: job.done,
			remaining: job.queue.length,
			verified: 0,
			blocked_count: 0,
			blocked: {},
			blocked_paths: {},
			failed_count: job.failed,
			failed: [],
			saved_bytes: job.saved,
			elapsed_sec: 1,
			idle_sec: idle,
			stale: idle >= MOCK_STALE_AFTER,
			stale_after: MOCK_STALE_AFTER,
			eta_sec: 0,
			coverage: 'full',
			needs_rescan: false,
			last_error: job.last_error,
			hint: '',
			block_reasons: [],
			finished,
		};

		const out = {
			job: summary,
			processed: chunk,
			failed: [],
			saved_bytes: job.saved,
			processed_now: chunk.length,
			overview: finished ? computeOverview() : null,
			reclaimed: reclaimed || null,
		};
		if (finished && payload) Object.assign(out, payload);
		return out;
	}

	// همان «توصیهٔ هوشمند» سرور واقعی برای هر زیرسایز.
	function withAdvice(sizes) {
		const total = sizes.filter((z) => z.files_count > 0).reduce((sum, z) => sum + z.bytes, 0);
		return sizes.map((z) => {
			const avg = z.files_count > 0 ? Math.round(z.bytes / z.files_count) : 0;
			const share = total > 0 ? Math.round((z.bytes / total) * 100) : 0;
			let advice = 'keep';
			let advice_text = 'حجم کمی دارد؛ نگه‌داشتنش بی‌خطر است.';
			if (z.essential) {
				advice_text = 'سایز ضروری فروشگاه (کارت محصول/سبد خرید) — هرگز خاموش نکنید.';
			} else if (/مانده/.test(z.source) && z.bytes > 0) {
				advice = 'disable';
				advice_text = `این سایز در قالب فعلی ثبت نشده؛ با خاموش‌کردن و پاک‌سازی، ${formatBytesM(z.bytes)} آزاد می‌شود.`;
			} else if (avg > 200 * 1024 && z.bytes > 5 * 1024 * 1024) {
				advice = 'review';
				advice_text = `میانگین هر فایل ${formatBytesM(avg)} است و ${faNum(z.files_count)} فایل دارد؛ اگر در طراحی استفاده نمی‌شود، خاموش کردنش بیشترین صرفه را دارد.`;
			} else if (z.width > 1600 || z.height > 1600) {
				advice = 'oversized';
				advice_text = `ابعاد ${faNum(z.width)}×${faNum(z.height)} بزرگ‌تر از سقف نمایش معمول است.`;
			}
			return Object.assign({}, z, {
				avg_bytes: avg,
				share_pct: share,
				advice,
				advice_text,
				reclaimable: advice === 'disable' || advice === 'review' ? z.bytes : 0,
			});
		});
	}

	function formatBytesM(n) {
		const b = Number(n) || 0;
		if (b >= 1024 * 1024 * 1024) return `${(b / 1024 / 1024 / 1024).toFixed(1)} GB`;
		if (b >= 1024 * 1024) return `${(b / 1024 / 1024).toFixed(1)} MB`;
		if (b >= 1024) return `${Math.round(b / 1024)} KB`;
		return `${b} B`;
	}

	// بسته‌ٔ «گاوصندوق» (قرنطینه + نسخهٔ اصل + تاریخچه) — یک‌جا ساخته می‌شود تا
	// هم شبیه‌ساز بازگردانی و هم get_vault دقیقاً یک شکل بدهند.
	// همان برچسب‌های فارسی «علت حذف» که سرور واقعی می‌فرستد.
	const REASON_LABELS = {
		unused: 'بدون استفاده در سایت (۰ مرجع)',
		draft_only: 'فقط در پیش‌نویس یا زباله‌دان',
		duplicate: 'تصویر تکراری ادغام‌شده',
		disk_orphan: 'فایل رهاشده روی دیسک (بدون رکورد)',
		manual_unused: 'قرنطینهٔ دستی توسط شما',
		missing_file: 'رکورد بدون فایل (۴۰۴)',
	};

	function mockQuarantineEnriched() {
		return db.quarantine.map((q) => {
			const rkey = q.reason || 'unused';
			return Object.assign({}, q, {
				reason_key: rkey,
				reason_label: REASON_LABELS[rkey] || rkey,
				is_image: /\.(jpe?g|png|gif|webp|avif|bmp)$/i.test(String(q.filename || '')),
				thumb_url: `/mock-thumb/${encodeURIComponent(q.id)}.jpg`,
				preview_url: `/mock-preview/${encodeURIComponent(q.id)}.jpg`,
			});
		});
	}

	/**
	 * نرمال‌سازی مسیر پوشه دقیقاً مثل سرور حقیقی.
	 *
	 * @param {string} raw ورودی.
	 * @return {string}
	 */
	function mockNormalizeFolder(raw) {
		let p = String(raw || '').trim();
		if (!p) return '';
		p = p.replace(/^[a-z]+:\/\/[^/]+/i, '').replace(/\\/g, '/').replace(/\/+/g, '/');
		p = decodeURIComponent(p);
		const idx = p.indexOf('wp-content/uploads/');
		if (idx !== -1) p = p.slice(idx + 'wp-content/uploads/'.length);
		else p = p.replace(/^\/?uploads\//, '');
		p = p.replace(/\.\./g, '').replace(/^\/+|\/+$/g, '');
		return p ? p.split('/').filter(Boolean).map((seg) => seg.replace(/^\.+|\.+$/g, '')).join('/') : '';
	}

	function mockIsWhitelistedPath(rel) {
		const r = String(rel || '').replace(/^\/+/, '').toLowerCase();
		if (!r) return '';
		for (const folder of db.whitelistFolders) {
			const n = String(folder).toLowerCase();
			if (!n) continue;
			if (r === n || r.indexOf(n + '/') === 0 || r.indexOf('/' + n + '/') !== -1) return folder;
		}
		return '';
	}

	function mockFolderStats() {
		return db.whitelistFolders.map((folder) => ({ folder, files: 0, bytes: 0, truncated: false, found: true }));
	}

	function mockVaultPayload() {
		const enrichedHistory = db.history.map((h) =>
			Object.assign({}, h, {
				recoverable_count: h.batch_id ? db.quarantine.filter((q) => q.batch_id === h.batch_id).length : 0,
			})
		);
		const quarantine = mockQuarantineEnriched();
		const reasonMap = {};
		quarantine.forEach((q) => {
			if (!reasonMap[q.reason_key]) {
				reasonMap[q.reason_key] = { key: q.reason_key, label: q.reason_label, count: 0, bytes: 0 };
			}
			reasonMap[q.reason_key].count += 1;
			reasonMap[q.reason_key].bytes += Number(q.total_bytes || 0);
		});
		const reasons = Object.values(reasonMap).sort((a, b) => b.count - a.count);
		return {
			quarantine,
			reasons,
			originals: db.originals.map((o) =>
				Object.assign({}, o, {
					thumb_url: `/mock-thumb/orig-${o.attachment_id}.jpg`,
					preview_url: `/mock-preview/orig-${o.attachment_id}.jpg`,
				})
			),
			history: enrichedHistory,
			summary: {
				quarantine_count: db.quarantine.length,
				quarantine_bytes: db.quarantine.reduce((s, q) => s + q.total_bytes, 0),
				originals_count: db.originals.length,
				originals_bytes: db.originals.reduce((s, o) => s + o.bytes, 0),
			},
		};
	}

	// یک تصویر را «بهینه» می‌کند و حجم صرفه‌جویی‌شده را برمی‌گرداند.
	function mockOptimizeOne(it, replaceMode) {
		if (!it) return 0;
		if (!replaceMode && it.is_optimized) return 0;
		let saved = 0;
		if (replaceMode) {
			// فشرده‌سازی + جایگزینی: فایل اصل و زیرسایزها کوچک و WebP می‌شوند.
			const before = it.main_bytes + it.subsizes_bytes;
			it.main_bytes = Math.round(it.main_bytes * (it.is_opaque_png ? 0.22 : 0.3));
			it.subsizes_bytes = Math.round(it.subsizes_bytes * 0.3);
			it.total_bytes = it.main_bytes + it.subsizes_bytes + it.unscaled_bytes;
			it.format = 'WEBP';
			it.mime = 'image/webp';
			it.has_webp = true;
			it.filename = it.filename.replace(/\.(png|jpe?g|PNG|JPG|JPEG)$/, '.webp');
			it.rel_path = it.rel_path.replace(/\.(png|jpe?g|PNG|JPG|JPEG)$/, '.webp');
			saved = Math.max(0, before - (it.main_bytes + it.subsizes_bytes));
		} else {
			saved = Math.round(it.main_bytes * (it.is_opaque_png ? 0.72 : 0.45));
			it.main_bytes -= saved;
			it.total_bytes = it.main_bytes + it.subsizes_bytes + it.unscaled_bytes;
			it.has_webp = true;
		}
		it.is_optimized = true;
		it.saved_bytes += saved;
		db.lifetime.optimized_count += 1;
		db.lifetime.saved_bytes += saved;
		return saved;
	}

	window.fetch = async function (url, opts) {
		if (String(url).indexOf('admin-ajax.php') === -1) {
			return origFetch ? origFetch(url, opts) : Promise.reject(new Error('No fetch'));
		}
		const req = parseBody(opts && opts.body);
		const op = req.op;

		// تأخیر اختیاری برای آزمون «توقف وسط کار» (پیش‌فرض صفر = پاسخ فوری).
		if (window.__tcoMockLatency && (op === 'optimize_batch' || op === 'restore_quarantine')) {
			await new Promise((r) => setTimeout(r, Number(window.__tcoMockLatency)));
		}
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
			// «تعداد در هر صفحه» دقیقاً مثل سرور: مقدار ارسالی کاربر، وگرنه تنظیمات،
			// و صفر یعنی همهٔ موارد در یک صفحه.
			const mockPerRaw =
				req.per_page !== undefined && req.per_page !== ''
					? Number(req.per_page)
					: Number(db.settings.per_page || 25);
			const mockPer = mockPerRaw > 0 ? Math.max(10, Math.min(500, mockPerRaw)) : 0;
			const mockPage = Math.max(1, Number(req.page) || 1);

			if (req.usage === 'unregistered_disk') {
				const rows = db.diskOrphans.filter((o) => !mockIsWhitelistedPath(o.rel_path));
				const per = mockPer > 0 ? mockPer : Math.max(1, rows.length);
				data = {
					mode: 'disk_orphans',
					rows: rows.slice((mockPage - 1) * per, (mockPage - 1) * per + per),
					total: rows.length,
					page: mockPage,
					per_page: per,
					pages: Math.max(1, Math.ceil(rows.length / per)),
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
				if (req.usage === 'folder_whitelisted') {
					rows = rows.filter((r) => mockIsWhitelistedPath(r.rel_path));
				} else if (req.usage === 'pinned') {
					rows = rows.filter((r) => r.is_pinned);
				} else if (req.usage && req.usage !== 'all') {
					rows = rows.filter((r) => r.usage_status === req.usage);
				}

				if (Number(req.min_bytes) > 0) {
					rows = rows.filter((r) => r.total_bytes >= Number(req.min_bytes));
				}
				const sb = req.sort_by || 'total_bytes';
				rows.sort((a, b) => (b[sb] || 0) - (a[sb] || 0));
				const per = mockPer > 0 ? mockPer : Math.max(1, rows.length);
				data = {
					mode: 'attachments',
					rows: rows.slice((mockPage - 1) * per, (mockPage - 1) * per + per),
					matching_ids: rows.map((r) => r.id).slice(0, 5000),
					total: rows.length,
					page: mockPage,
					per_page: per,
					pages: Math.max(1, Math.ceil(rows.length / per)),
					matched_bytes: rows.reduce((s, r) => s + r.total_bytes, 0),
				};
			}
		} else if (op === 'save_whitelist_folders') {
			const before = db.whitelistFolders.slice();
			const next = [];
			const rejected = [];
			(req.folders || []).forEach((raw) => {
				const norm = mockNormalizeFolder(raw);
				if (!norm) rejected.push(String(raw));
				else if (!next.includes(norm)) next.push(norm);
			});
			db.whitelistFolders = next;
			data = {
				folders: next,
				rejected,
				added: next.filter((f) => !before.includes(f)),
				removed: before.filter((f) => !next.includes(f)),
				stats: mockFolderStats(),
				overview: computeOverview(),
			};
		} else if (op === 'whitelist_folder_stats') {
			data = { folders: db.whitelistFolders.slice(), stats: mockFolderStats() };
		} else if (op === 'toggle_pin') {
			const ids = (req.ids || []).map(Number);
			db.items.forEach((it) => {
				if (ids.includes(it.id)) it.is_pinned = Number(req.pin) === 1;
			});
			data = {
				pinned_ids: db.items.filter((i) => i.is_pinned).map((i) => i.id),
				overview: computeOverview(),
			};
		} else if (op === 'remove_items') {
			// شبیه‌ساز موتور کار «قابل ادامه» با فاز راستی‌آزمایی (verify) و اجرا (run).
			const tickMax = Number(window.__tcoMockTickMax || 6);
			const failAfter = Number(window.__tcoMockFailAfter || 0);
			const verifyMax = Number(window.__tcoMockVerifyMax || tickMax);
			const blockedIds = (window.__tcoMockBlockedIds || []).map(Number);
			const needsRescan = Boolean(window.__tcoMockNeedsRescan);

			const makeJob = (ids, paths, mode) => ({
				id: 'jobmock' + Date.now(),
				op: 'remove',
				updated: mockNowSec(),
				mode,
				batch_id: 'run' + Date.now(),
				phase: 'verify',
				total: ids.length + paths.length,
				verified: 0,
				done: 0,
				saved_bytes: 0,
				queue_ids: [...ids],
				queue_paths: [...paths],
				safe_ids: [],
				safe_paths: [],
				done_ids: [],
				done_paths: [],
				blocked: {},
				blocked_paths: {},
				failed_count: 0,
				needs_rescan: needsRescan,
				last_error: '',
				status: 'running',
			});

			const summarize = (job, finished) => {
				const remaining = job.phase === 'verify'
					? job.queue_ids.length + job.queue_paths.length
					: job.queue_ids.length + job.queue_paths.length + job.safe_ids.length + job.safe_paths.length;
				const counts = {};
				Object.values(job.blocked).forEach((r) => {
					const key = Array.isArray(r) ? r[0] : String(r);
					counts[key] = (counts[key] || 0) + 1;
				});
				const blockReasons = Object.keys(counts)
					.sort((a, b) => counts[b] - counts[a])
					.slice(0, 4)
					.map((reason) => ({ reason, count: counts[reason] }));

				let hint = '';
				if (job.phase === 'verify') {
					hint = `در حال بازرسی ایمنی پیش از حذف: ${faNum(job.verified)} از ${faNum(job.total)} مورد بررسی شد.`;
				} else if (finished && job.done === 0 && Object.keys(job.blocked).length > 0) {
					hint = job.needs_rescan
						? 'برای حذف ایمن، ابتدا «اسکن کامل تصاویر» را اجرا کنید؛ بازرسی ایمنی بدون کاتالوگ کامل اجازهٔ حذف نمی‌دهد.'
						: 'این موردها هنوز در سایت استفاده می‌شوند (مثلاً تصویر شاخص، گالری یا متن محصول)؛ عمداً حذف نشدند.';
				}

				return {
					id: job.id,
					op: 'remove',
					mode: job.mode,
					phase: job.phase,
					status: job.status,
					batch_id: job.batch_id,
					total: job.total,
					done: job.done,
					remaining,
					verified: job.verified,
					blocked_count: Object.keys(job.blocked).length,
					blocked: job.blocked,
					blocked_paths: job.blocked_paths,
					failed_count: job.failed_count,
					failed: [],
					saved_bytes: job.saved_bytes,
					elapsed_sec: 1,
					idle_sec: mockIdleSec(job),
					stale: mockIdleSec(job) >= MOCK_STALE_AFTER,
					stale_after: MOCK_STALE_AFTER,
					eta_sec: 0,
					coverage: job.needs_rescan ? 'partial' : 'full',
					needs_rescan: job.needs_rescan,
					last_error: job.last_error || '',
					hint,
					block_reasons: blockReasons,
					finished: Boolean(finished),
				};
			};

			let reclaimedRemove = null;
			if (req.job_id) {
				if (!window.__tcoMockJob || window.__tcoMockJob.id !== req.job_id) {
					throw new Error('این کار دیگر فعال نیست یا منقضی شده است.');
				}
			} else {
				const guard = mockGuardNewJob();
				if (guard.blocked) {
					return mockHttpFailure(409, guard.blocked);
				}
				reclaimedRemove = guard.reclaimed;
				window.__tcoMockJob = makeJob(
					(req.ids || []).map(Number).filter(Boolean),
					(req.rel_paths || []).filter(Boolean),
					req.delete_mode === 'permanent' ? 'permanent' : 'quarantine'
				);
				window.__tcoMockTicks = 0;
			}

			const job = window.__tcoMockJob;
			job.updated = mockNowSec();
			let saved = 0;
			const newlyDone = [];

			// فاز ۱: بازرسی ایمنی (بسته‌بندی‌شده؛ پیشرفت ذخیره می‌شود).
			if (job.phase === 'verify') {
				for (let n = 0; n < verifyMax && job.queue_ids.length; n += 1) {
					const id = job.queue_ids.shift();
					if (blockedIds.includes(Number(id)) || needsRescan) {
						job.blocked[id] = [needsRescan ? 'کاتالوگ اسکن کامل نیست' : 'هنوز در سایت استفاده می‌شود: تصویر شاخص محصول #۱۲۳'];
					} else {
						job.safe_ids.push(id);
					}
					job.verified += 1;
				}
				if (!job.queue_ids.length && !job.queue_paths.length) job.phase = 'run';
				window.__tcoMockTicks = (window.__tcoMockTicks || 0) + 1;
			}

			// فاز ۲: اجرا.
			if (job.phase === 'run') {
				for (let n = 0; n < tickMax && job.safe_ids.length; n += 1) {
					const id = job.safe_ids.shift();
					const idx = db.items.findIndex((x) => x.id === id && !x.is_pinned);
					if (idx === -1) {
						job.blocked[id] = ['مورد محافظت‌شده یا حذف‌شده'];
						continue;
					}
					const item = db.items.splice(idx, 1)[0];
					const bytes = item.main_bytes + item.subsizes_bytes + item.unscaled_bytes;
					saved += bytes;
					job.done += 1;
					job.done_ids.push(id);
					newlyDone.push(id);
					if (job.mode !== 'permanent') {
						db.quarantine.unshift({
							id: 'q_' + Date.now() + '_' + id + '_' + (window.__tcoMockQSeq = (window.__tcoMockQSeq || 0) + 1),
							batch_id: job.batch_id,
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
				}
				window.__tcoMockTicks = (window.__tcoMockTicks || 0) + 1;
			}

			job.saved_bytes += saved;
			db.lifetime.saved_bytes += saved;

			// شبیه‌سازی قطع ناگهانی (برای تست «ادامه از همان نقطه»).
			if (failAfter > 0 && window.__tcoMockTicks >= failAfter) {
				job.last_error = 'تایم‌اوت موقت سرور (شبیه‌سازی برای آزمون بازیابی)';
				window.__tcoMockTicks = 0;
				window.__tcoMockFailAfter = 0;
				window.__tcoMockVerifyMax = 0;
				throw new Error(job.last_error);
			}

			const finished = job.phase === 'run' && job.safe_ids.length === 0 && job.safe_paths.length === 0;

			if (finished) {
				job.status = 'done';
				if (job.done > 0) {
					db.history.unshift({
						batch_id: job.batch_id,
						type: job.mode === 'permanent' ? 'delete' : 'quarantine',
						label: job.mode === 'permanent' ? 'حذف قطعی تصاویر انتخابی' : 'انتقال ایمن به قرنطینهٔ ۳۰ روزه',
						items_count: job.done,
						saved_bytes: job.saved_bytes,
						user: 'مدیر فروشگاه',
						created_at: '2026-10-21 15:45:12',
						note: '',
					});
				} else if (!job.last_error) {
					job.last_error = `هیچ تصویری حذف نشد: هر ${job.verified} مورد در بازرسی ایمنی رد شد`;
				}
			}

			data = {
				job: summarize(job, finished),
				done_ids: newlyDone,
				done_paths: [],
				saved_bytes: job.saved_bytes,
				batch_id: job.batch_id,
				mode: job.mode,
				blocked: job.blocked,
				blocked_paths: {},
				blocked_count: Object.keys(job.blocked).length,
				needs_rescan: job.needs_rescan,
				coverage: job.needs_rescan ? 'partial' : 'full',
				overview: computeOverview(),
				reclaimed: reclaimedRemove || null,
			};
		} else if (op === 'job_status') {
			if (window.__tcoMockJob && window.__tcoMockJob.status === 'running') {
				const j = window.__tcoMockJob;
				const counts = {};
				Object.values(j.blocked).forEach((r) => {
					const key = Array.isArray(r) ? r[0] : String(r);
					counts[key] = (counts[key] || 0) + 1;
				});
				data = {
					job: {
						id: j.id,
						op: 'remove',
						mode: j.mode,
						phase: j.phase,
						status: j.status,
						batch_id: j.batch_id,
						total: j.total,
						done: j.done,
						remaining: j.phase === 'verify' ? j.queue_ids.length + j.queue_paths.length : j.safe_ids.length,
						verified: j.verified,
						blocked_count: Object.keys(j.blocked).length,
						blocked: j.blocked,
						blocked_paths: {},
						failed_count: j.failed_count,
						failed: [],
						saved_bytes: j.saved_bytes,
						elapsed_sec: 1,
						idle_sec: mockIdleSec(j),
						stale: mockIdleSec(j) >= MOCK_STALE_AFTER,
						stale_after: MOCK_STALE_AFTER,
						eta_sec: 0,
						coverage: j.needs_rescan ? 'partial' : 'full',
						needs_rescan: j.needs_rescan,
						last_error: j.last_error || '',
						hint: j.phase === 'verify' ? `در حال بازرسی ایمنی پیش از حذف: ${faNum(j.verified)} از ${faNum(j.total)} مورد بررسی شد.` : '',
						block_reasons: Object.keys(counts).map((reason) => ({ reason, count: counts[reason] })),
						finished: false,
					},
				};
			} else {
				// کارهای فشرده‌سازی/بازگردانی هم در «سرور» ذخیره‌اند؛ بعد از رفرش
				// صفحه باید همان کارت «ادامهٔ عملیات» برگردد.
				const other = Object.keys(window.__tcoMockJobs || {})
					.map((k) => window.__tcoMockJobs[k])
					.find((j) => j.status === 'running');
				data = other
					? {
							job: {
								id: other.id,
								op: other.op,
								mode: other.mode,
								phase: 'run',
								status: other.status,
								total: other.total,
								done: other.done,
								remaining: other.queue.length,
								verified: 0,
								blocked_count: 0,
								blocked: {},
								failed_count: other.failed,
								failed: [],
								saved_bytes: other.saved,
								elapsed_sec: 1,
								idle_sec: mockIdleSec(other),
								stale: mockIdleSec(other) >= MOCK_STALE_AFTER,
								stale_after: MOCK_STALE_AFTER,
								eta_sec: 0,
								coverage: 'full',
								needs_rescan: false,
								last_error: other.last_error || '',
								hint: '',
								block_reasons: [],
								finished: false,
							},
					  }
					: { job: null };
			}
		} else if (op === 'job_cancel') {
			const job = window.__tcoMockJob;
			// پیش از هر تغییری، کار «در جریان» را نگه می‌داریم تا گزارش دقیق بدهیم.
			const cancelled = (job && job.status === 'running' ? job : null)
				|| Object.values(window.__tcoMockJobs || {}).find((j) => j.status === 'running')
				|| job;
			if (job && job.status === 'running') {
				job.status = 'cancelled';
				job.queue_ids = [];
				job.queue_paths = [];
				job.safe_ids = [];
				job.safe_paths = [];
			}
			// کارهای فشرده‌سازی/بازگردانی هم رها می‌شوند.
			Object.keys(window.__tcoMockJobs || {}).forEach((k) => {
				const other = window.__tcoMockJobs[k];
				if (other.status === 'running') {
					other.status = 'cancelled';
					other.queue = [];
				}
			});
			// همان کاری که سرور واقعی می‌کند: کار فعال پاک می‌شود تا عملیات بعدی
			// دیگر با خطای «در جریان است» روبه‌رو نشود.
			window.__tcoMockJob = null;
			data = {
				job: cancelled ? { id: cancelled.id, done: cancelled.done || 0, total: cancelled.total || 0, status: 'cancelled', finished: true } : null,
				overview: computeOverview(),
			};
		} else if (op === 'inspect_sizes') {
			const id = Number(req.id);
			const item = db.items.find((x) => x.id === id);
			if (!item) throw new Error('تصویر پیدا نشد.');
			const total = item.subsizes_bytes || 0;
			const names = [
				['thumbnail', 150, 150],
				['woocommerce_thumbnail', 300, 300],
				['medium', 300, 200],
				['woocommerce_single', 600, 600],
				['large', 1024, 683],
				['woodmart_shop_catalog', 400, 400],
			];
			const n = Math.max(1, Math.min(names.length, item.subsizes_count || 3));
			const chosen = names.slice(0, n);
			const weights = chosen.map((_, i) => 1 / Math.pow(1.7, i));
			const sum = weights.reduce((a, b) => a + b, 0);
			const dir = item.rel_path.replace(/[^/]+$/, '').replace(/\/$/, '');
			const sizes = chosen.map((c, i) => ({
				name: c[0],
				w: c[1],
				h: c[2],
				bytes: Math.round((total * weights[i]) / sum),
				exists: true,
				rel: `${dir ? dir + '/' : ''}${c[0]}.png`,
			}));

			// فهرست فایل‌های واقعی: اصل + هر زیرسایز + نسخهٔ موازی webp.
			const fmt = (bytes) => {
				if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' مگابایت';
				if (bytes >= 1024) return Math.round(bytes / 1024) + ' کیلوبایت';
				return bytes + ' بایت';
			};
			const files = [
				{
					role: 'فایل اصلی',
					rel: item.rel_path,
					abs: `/home/site/public_html/wp-content/uploads/${item.rel_path}`,
					bytes: item.main_bytes,
					exists: true,
				},
				...sizes.map((sz) => ({
					role: `زیرسایز ${sz.name}`,
					rel: sz.rel,
					abs: `/home/site/public_html/wp-content/uploads/${sz.rel}`,
					bytes: sz.bytes,
					exists: true,
				})),
			].map((f) => ({ ...f, _size: fmt(f.bytes) }));

			const sidecars = window.__tcoMockSidecars === false ? [] : [
				{
					role: 'نسخهٔ موازی WEBP از فایل اصلی',
					rel: `${item.rel_path}.webp`,
					abs: `/home/site/public_html/wp-content/uploads/${item.rel_path}.webp`,
					bytes: Math.round(item.main_bytes * 0.22),
					exists: true,
				},
				{
					role: 'نسخهٔ موازی WEBP از زیرسایز thumbnail',
					rel: `${sizes[0].rel}.webp`,
					abs: `/home/site/public_html/wp-content/uploads/${sizes[0].rel}.webp`,
					bytes: Math.round(sizes[0].bytes * 0.24),
					exists: true,
				},
			];

			const all = [...files, ...sidecars];
			const totalAll = all.reduce((a, f) => a + f.bytes, 0);

			data = {
				id,
				main_bytes: item.main_bytes,
				subsizes_bytes: total,
				sidecar_bytes: sidecars.reduce((a, f) => a + f.bytes, 0),
				sidecar_count: sidecars.length,
				total_bytes: totalAll,
				count: sizes.length,
				dir,
				sizes,
				files: all,
				webp_mode: window.__tcoMockWebpMode || 'sidecar',
				delivery: window.__tcoMockDelivery || 'picture',
				nextgen: 'webp',
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
			const replaceMode = Boolean(req.overrides && req.overrides.replace_mode) || db.settings.webp_mode === 'replace';
			const ids = (req.ids || []).map(Number).filter(Boolean);
			if (!ids.length && !req.job_id) {
				// حالت «صف»: فهرست کاری که با یک کلیک پردازش می‌شود.
				// در حالت جایگزینی، حتی بهینه‌شده‌های «نسخهٔ موازی» هم صف می‌شوند.
				const queue = db.items
					.filter((i) => (replaceMode ? i.format !== 'WEBP' : !i.is_optimized))
					.map((i) => i.id);
				data = { queue_ids: queue, total: queue.length, replace_mode: replaceMode };
			} else {
				const step = mockJobStep(
					'optimize',
					req,
					{
						mode: replaceMode ? 'replace' : 'sidecar',
						queue: ids.length ? ids : db.items.filter((i) => !i.is_optimized).map((i) => i.id),
					},
					(chunk) => {
						let saved = 0;
						chunk.forEach((id) => {
							saved += mockOptimizeOne(db.items.find((x) => x.id === Number(id)), replaceMode);
						});
						return { done: chunk.length, saved, payload: { replace_mode: replaceMode } };
					}
				);
				if (step && step.__conflict) {
					return mockHttpFailure(step.__conflict.status, step.__conflict.payload);
				}
				data = step;
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
			data = { sizes: withAdvice(db.subsizes) };
		} else if (op === 'save_subsizes') {
			db.settings.disabled_sizes = Array.isArray(req.disabled_sizes) ? req.disabled_sizes : [];
			data = { settings: db.settings, sizes: withAdvice(db.subsizes) };
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
			data = { result: { deleted_files: files, saved_bytes: saved }, sizes: withAdvice(db.subsizes), overview: computeOverview() };
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
			const seoPerRaw =
				req.per_page !== undefined && req.per_page !== ''
					? Number(req.per_page)
					: Number(db.settings.per_page || 25);
			const seoPer = seoPerRaw > 0 ? Math.max(10, Math.min(500, seoPerRaw)) : Math.max(1, missing.length);
			const seoPage = Math.max(1, Number(req.page) || 1);
			const audit = {
				summary: {
					total_images: db.items.length,
					missing_alt: missing.length,
					bad_titles: 2,
					coverage_pct: Math.round(((db.items.length - missing.length) / Math.max(1, db.items.length)) * 100),
				},
				rows: missing
					.slice((seoPage - 1) * seoPer, (seoPage - 1) * seoPer + seoPer)
					.map((m) =>
						Object.assign(hydrateRow(m), {
							suggested_alt: ((m.refs[0] && m.refs[0].label) || m.filename.replace(/\.[^.]+$/, '')) + ' — تیساکیس',
						})
					),
				total: missing.length,
				page: seoPage,
				per_page: seoPer,
				pages: Math.max(1, Math.ceil(missing.length / seoPer)),
			};
			data = op === 'bulk_generate_seo' ? { updated, audit } : audit;
		} else if (op === 'restore_quarantine') {
			let targetIds = (req.item_ids || []).filter(Boolean);
			if (!req.job_id) {
				if (!targetIds.length && req.batch_id) {
					targetIds = db.quarantine.filter((q) => q.batch_id === req.batch_id).map((q) => q.id);
				} else if (!targetIds.length && Number(req.restore_all) === 1) {
					targetIds = db.quarantine.map((q) => q.id);
				} else if (!targetIds.length && req.reason) {
					// «بازگردانی همهٔ موارد یک علت» — فهرست روی سرور ساخته می‌شود.
					targetIds = db.quarantine.filter((q) => (q.reason || 'unused') === req.reason).map((q) => q.id);
				}
			}
			const stepRestore = mockJobStep('restore', req, { mode: 'restore', queue: targetIds }, (qids) => {
				let restored = 0;
				let skipped = 0;
				qids.forEach((qid) => {
					const idx = db.quarantine.findIndex((q) => q.id === qid);
					if (idx === -1) {
						skipped += 1;
						return;
					}
					const q = db.quarantine.splice(idx, 1)[0];
					restored += 1;
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
				});
				// هر شناسهٔ صف «پردازش‌شده» حساب می‌شود (حتی اگر رکوردش پیدا نشود)،
				// وگرنه کار برای همیشه روی «۶ از ۷» گیر می‌کند.
				return { done: qids.length, failed: skipped, payload: { vault: mockVaultPayload(), restored: restored } };
			});
			if (stepRestore && stepRestore.__conflict) {
				return mockHttpFailure(stepRestore.__conflict.status, stepRestore.__conflict.payload);
			}
			data = stepRestore;
		} else if (op === 'get_vault' || op === 'purge_quarantine' || op === 'restore_original' || op === 'purge_originals' || op === 'clear_history') {
			let savedBytes = 0;
			let restoredCount = 0;

			if (op === 'restore_original') {
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
			const vaultPayload = mockVaultPayload();
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
		} else if (op === 'preflight') {
			const checks = [
				{ key: 'php', label: 'نسخهٔ PHP', status: 'ok', value: '8.2.18', advice: 'نسخهٔ مناسب است.' },
				{ key: 'memory', label: 'حافظهٔ PHP', status: 'ok', value: '512 MB', advice: 'مقدار کافی است.' },
				{ key: 'max_execution', label: 'سقف زمان هر درخواست', status: 'warn', value: '20s', advice: 'هر تیک کوتاه است ولی برای اطمینان، سقف را روی ۳۰ ثانیه بگذارید.' },
				{ key: 'engine', label: 'موتور پردازش تصویر', status: 'ok', value: 'Imagick · WebP: بله', advice: 'WebP و AVIF پشتیبانی می‌شوند.' },
				{ key: 'uploads_writable', label: 'اجازهٔ نوشتن در پوشهٔ uploads', status: 'ok', value: 'دارد', advice: 'فایل‌های سبک‌تر و نسخهٔ WebP کنار فایل اصلی ساخته می‌شوند.' },
				{ key: 'vault_writable', label: 'پوشهٔ امن قرنطینه و پشتیبان', status: 'ok', value: 'آماده', advice: 'بازگردانی و undo همیشه در دسترس است.' },
				{ key: 'disk_space', label: 'فضای آزاد دیسک', status: 'ok', value: '48.2 GB', advice: 'فضای کافی برای ساخت نسخه‌های جدید وجود دارد.' },
				{ key: 'catalog', label: 'کاتالوگ اسکن', status: 'ok', value: '۱٬۴۸۰ تصویر · ۲ ساعت پیش', advice: 'کاتالوگ تازه است؛ آمار داشبورد دقیق است.' },
				{ key: 'quarantine', label: 'حجم قرنطینه و پشتیبان', status: 'ok', value: '۳ مورد · ۱۲ MB', advice: 'قرنطینه فضای زیادی اشغال نکرده است.' },
				{ key: 'cron', label: 'زمان‌بند وردپرس (WP-Cron)', status: 'ok', value: 'فعال', advice: 'پاک‌سازی خودکار قرنطینهٔ منقضی انجام می‌شود.' },
			];
			const score = 92;
			data = { score, level: 'ok', checked_at: '2026-10-22 10:00:00', checks };
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
