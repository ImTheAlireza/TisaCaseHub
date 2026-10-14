/**
 * TisaCase Bulk Stock Manager — Client Controller
 *
 * دو حالت کار:
 *  ۱) تک‌محصول: جست‌وجوی محصول ← کارت‌های متغیر ← فیلتر دسته ← ویرایش ← اعمال.
 *  ۲) گروهی: محدودهٔ محصولات (چند محصول / دسته / شناسه) ← ویژگی‌ها و مقدارهای
 *     همان محدوده ← کارت‌های متغیر همان مدل ← ویرایش تکی یا همگانی ← اعمال.
 *
 * @package TisaCase_Bulk_Stock_Manager
 */

(function ($) {
	'use strict';

	var TBSM = {

		product: null,      // {id, name, sku, type, image, variations, status}
		variations: [],     // [{id, name, sku, stock, status, manage_stock, image, cat}]
		categories: {},     // tax => {label, values: [{label, slug, count}]}
		lastResults: [],
		busy: false,
		confirmMode: false,
		confirmTimer: null,
		unifiedConfirm: false,
		unifiedConfirmTimer: null,
		loadSeq: 0,

		mode: 'single',     // 'single' = تک‌محصول | 'group' = گروهی (چند محصول)

		// حالت گروهی — گام ۱: محدودهٔ محصولات
		scopeType: 'products',   // products | category | sku
		scopeProducts: {},       // id => {id, name, sku} محصولات تیک‌خورده
		scopeResults: [],        // آخرین نتایج جست‌وجوی گام محدوده
		scopeCategory: 0,        // شناسهٔ دستهٔ انتخابی
		scopeLimit: 200,         // سقف محصولات انتخابی (از سرور می‌آید)
		scopeOptionsLoaded: false,
		scopeCategories: [],     // فهرست کامل دسته‌ها (برای فیلتر جست‌وجو)
		categoryFilter: '',      // عبارت جست‌وجوی دسته‌ها

		// حالت گروهی — گام ۲ و ۳
		scope: null,        // محدودهٔ تأییدشده از سرور {type, label, products, variations, ...}
		scopeAttrs: [],     // ویژگی‌های همان محدوده [{label, keys[], products, variations}]
		valueGroups: [],    // مقدارهای ویژگی انتخاب‌شده [{label, values[], products, variations}]
		valueGroupIndex: [],// گروه‌های برندِ مقدارها [{label, indexes[]}] (اختیاری)
		valueTruncated: false,
		valueFilter: '',    // عبارت جست‌وجوی مقدارها
		valueHay: [],       // متن قابل جست‌وجوی هر مقدار (کش‌شده)
		group: null,        // {attrLabel, termLabel, keys[], values[]} مدل انتخاب‌شده
		groupNoteHtml: '',

		/* ----------------------------------------------------------
		 * ابزارها
		 * ---------------------------------------------------------- */

		i18n: function (key) {
			var t = window.tbsmData && window.tbsmData.i18n ? window.tbsmData.i18n : {};
			return t[key] || key;
		},

		/**
		 * نرمال‌سازی متن برای جست‌وجو: نیم‌فاصله/نشانه‌های نامرئی حذف، «ي/ك»
		 * عربی و ارقام فارسی/عربی به شکل لاتین، حروف کوچک — تا «A55»، «a55» و
		 * «a۵۵» یک نتیجه بدهند و «iphone 11» با «iPhone 11» بخواند.
		 *
		 * @param {string} text متن خام.
		 * @return {string}
		 */
		normalizeQuery: function (text) {
			var fa = '۰۱۲۳۴۵۶۷۸۹';
			var ar = '٠١٢٣٤٥٦٧٨٩';
			return String(text === null || text === undefined ? '' : text)
				.replace(/[\u200B-\u200F\u202A-\u202E\u2060\uFEFF]/g, '')
				.replace(/[يﻯﻰ]/g, 'ی')
				.replace(/[كﻙ]/g, 'ک')
				.replace(/[۰-۹]/g, function (d) { return String(fa.indexOf(d)); })
				.replace(/[٠-٩]/g, function (d) { return String(ar.indexOf(d)); })
				.toLowerCase()
				.replace(/\s+/g, ' ')
				.trim();
		},

		/** ارقام به فارسی. */
		fa: function (n) {
			var f = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
			return String(n).replace(/[0-9]/g, function (d) {
				return f[d];
			});
		},

		/** عدد با جداکنندهٔ هزارگان و ارقام فارسی. */
		fmt: function (n) {
			var s = String(Number(n) || 0).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
			return this.fa(s);
		},

		/** پاک‌سازی خروجی HTML. */
		esc: function (s) {
			return String(s === null || s === undefined ? '' : s)
				.replace(/&/g, '&amp;')
				.replace(/</g, '&lt;')
				.replace(/>/g, '&gt;')
				.replace(/"/g, '&quot;')
				.replace(/'/g, '&#039;');
		},

		debounce: function (fn, wait) {
			var t = null;
			return function () {
				var ctx = this, args = arguments;
				clearTimeout(t);
				t = setTimeout(function () {
					fn.apply(ctx, args);
				}, wait);
			};
		},

		/** اعلان بالای صفحه. */
		notice: function (msg, type) {
			var $n = $('#tbsm-notice');
			if (!msg) {
				$n.prop('hidden', true).attr('class', 'tbsm-notice');
				return;
			}
			$n
				.prop('hidden', false)
				.attr('class', 'tbsm-notice tbsm-notice--' + (type || 'info'))
				.html(this.esc(msg));
		},

		boxIcon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 8l9-5 9 5v8l-9 5-9-5zM3 8l9 5 9-5M12 13v8"/></svg>',

		checkIcon: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12.5l5 5L20 6.5"/></svg>',

		/* ----------------------------------------------------------
		 * راه‌اندازی
		 * ---------------------------------------------------------- */

		init: function () {
			if (!window.tbsmData) {
				return;
			}
			var self = this;

			$('#tbsm-search').on('input', this.debounce(function () {
				self.doSearch();
			}, 320));
			$('#tbsm-search').on('keydown', function (e) {
				if (e.key === 'Enter') {
					self.doSearch();
				}
			});

			$('#tbsm-results').on('click', '.tbsm-result', function () {
				self.selectProduct($(this).data('id'));
			});

			$('#tbsm-selected').on('click', '#tbsm-change', function () {
				self.clearSelection();
			});

			$('#tbsm-cat-select').on('change', function () {
				self.applyCategory($(this).val());
			});

			$('#tbsm-grid').on('click', function (e) {
				var $card = $(e.target).closest('.tbsm-var');
				if (!$card.length) {
					return;
				}
				// دکمه‌های +/− استپر
				var $btn = $(e.target).closest('.tbsm-stepbtn');
				if ($btn.length) {
					self.doStep($btn, e);
					return;
				}
				// فقط خودِ چک‌باکس کارت را خاموش/روشن می‌کند — کلیک روی بقیهٔ کارت نه
				if ($(e.target).closest('.tbsm-var-check').length) {
					self.toggleCard($card);
					return;
				}
				// داخل باکس ورودی و سلکت حالت: دست نزن
				if ($(e.target).closest('.tbsm-var-stockbox').length) {
					return;
				}
				if ($(e.target).closest('.tbsm-var-mode').length) {
					return;
				}
			}).on('input', '.tbsm-var-stock', function () {
				var $card = $(this).closest('.tbsm-var');
				$card.removeClass('is-invalid');
				self.paintStockbox($card);
			}).on('change', '.tbsm-var-mode', function () {
				self.setCardMode($(this).closest('.tbsm-var'), this.value);
			});

			$('#tbsm-quickset-btn').on('click', function () {
				self.quickSet();
			});

			$('#tbsm-unified').on('click', function () {
				self.applyUnified();
			});

			$('#tbsm-quickset').on('keydown', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					self.applyUnified();
				}
			});

			$('#tbsm-apply').on('click', function () {
				self.apply();
			});

			// تب‌ها: تک‌محصول ↔ گروهی با مدل
			$('.tbsm-tab').on('click', function () {
				self.setMode($(this).attr('data-mode'));
			});

			// حالت گروهی — گام ۱: محدودهٔ محصولات
			$('.tbsm-seg-btn').on('click', function () {
				self.setScopeType($(this).attr('data-scope'));
			});

			$('#tbsm-scope-search').on('input', this.debounce(function () {
				self.scopeSearch();
			}, 320));
			$('#tbsm-scope-search').on('keydown', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					self.scopeSearch();
				}
			});
			$('#tbsm-scope-results').on('click', '.tbsm-scope-row', function () {
				self.toggleScopeProduct($(this).data('id'));
			});
			$('#tbsm-scope-results').on('click', '#tbsm-scope-addall', function (e) {
				e.stopPropagation();
				self.addAllScopeResults();
			});
			$('#tbsm-scope-picked').on('click', '.tbsm-pick-chip', function () {
				self.toggleScopeProduct($(this).data('id'));
			});
			$('#tbsm-scope-picked').on('click', '#tbsm-scope-clear', function () {
				self.clearScopeProducts();
			});
			$('#tbsm-scope-category').on('change', function () {
				self.scopeCategory = parseInt($(this).val(), 10) || 0;
				self.refreshScopeApply();
			});
			$('#tbsm-scope-category-search').on('input', function () {
				self.categoryFilter = self.normalizeQuery(this.value);
				self.paintScopeCategories();
			});
			$('#tbsm-scope-sku').on('input', function () {
				self.refreshScopeApply();
			});
			$('#tbsm-scope-sku').on('keydown', function (e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					self.applyScope();
				}
			});
			$('#tbsm-scope-apply').on('click', function () {
				self.applyScope();
			});

			// حالت گروهی — گام ۲: ویژگی و مقدار مدل
			$('#tbsm-group-attr').on('change', function () {
				self.loadGroupValues(parseInt(this.value, 10));
			});
			$('#tbsm-group-term').on('change', function () {
				self.findGroup(parseInt(this.value, 10));
			});
			$('#tbsm-group-term-search').on('input', function () {
				self.valueFilter = self.normalizeQuery(this.value);
				self.paintGroupValues(true);
			});
		},

		/* ----------------------------------------------------------
		 * حالت کار (تب): تک‌محصول / گروهی با مدل
		 * ---------------------------------------------------------- */

		setMode: function (mode) {
			mode = (mode === 'group') ? 'group' : 'single';
			if (mode === this.mode) {
				return;
			}
			this.mode = mode;
			this.loadSeq++; // پاسخ‌های دیررس بی‌اثر می‌شوند

			$('.tbsm-tab')
				.removeClass('is-active')
				.attr('aria-selected', 'false')
				.filter('[data-mode="' + mode + '"]')
				.addClass('is-active')
				.attr('aria-selected', 'true');

			$('#tbsm-panel-single').prop('hidden', mode !== 'single');
			$('#tbsm-panel-group').prop('hidden', mode !== 'group');

			this.clearSelection(true);
			this.notice('');

			if (mode === 'group') {
				this.loadScopeOptions();
				this.refreshScopeApply();
				$('#tbsm-scope-search').trigger('focus');
			} else {
				$('#tbsm-group-note').prop('hidden', true).empty();
				$('#tbsm-search').trigger('focus');
			}

			this.updateBottomHint();
		},

		/* ----------------------------------------------------------
		 * حالت گروهی — گام ۱: محدودهٔ محصولات
		 * ---------------------------------------------------------- */

		/** گزینه‌های محدوده (دسته‌بندی‌ها + سقف‌ها) — یک‌بار. */
		loadScopeOptions: function () {
			var self = this;
			if (this.scopeOptionsLoaded) {
				return;
			}
			this.scopeOptionsLoaded = true;

			$.post(window.tbsmData.ajaxUrl, {
				action: 'tbsm_scope_options',
				nonce: window.tbsmData.nonce
			})
				.done(function (res) {
					if (!res || !res.success) {
						self.scopeOptionsLoaded = false;
						return;
					}
					var data = res.data || {};
					if (data.limits && data.limits.products) {
						self.scopeLimit = data.limits.products;
					}
					self.renderScopeCategories(data.categories || []);
				})
				.fail(function () {
					self.scopeOptionsLoaded = false;
				});
		},

		/** پرکردن سلکت دسته‌بندی‌ها (با تورفتگی سلسله‌مراتب). */
		renderScopeCategories: function (list) {
			this.scopeCategories = list || [];
			this.paintScopeCategories();
		},

		/**
		 * سلکت دسته‌ها با فیلتر جست‌وجو. در حالت فیلتر، والدهای نتیجه‌ها هم
		 * می‌مانند تا تورفتگی سلسله‌مراتب معنی‌دار بماند و انتخاب فعلی کاربر
		 * هم از دست نرود.
		 */
		paintScopeCategories: function () {
			var list = this.scopeCategories || [];
			var filter = this.categoryFilter || '';
			var keep = {};
			var matches = 0;
			var i;

			for (i = 0; i < list.length; i++) {
				keep[list[i].id] = !filter;
			}

			if (filter) {
				var byId = {};
				for (i = 0; i < list.length; i++) {
					byId[list[i].id] = list[i];
				}

				var mark = function (id) {
					var guard = 0;
					while (id && byId[id] && !keep[id] && guard < 30) {
						keep[id] = true;
						id = byId[id].parent;
						guard++;
					}
				};

				for (i = 0; i < list.length; i++) {
					if (this.normalizeQuery(this.catLabel(list[i].name)).indexOf(filter) === -1) {
						continue;
					}
					matches++;
					keep[list[i].id] = true;
					mark(list[i].parent);
				}

				// انتخاب فعلی کاربر با فیلتر پاک نشود.
				if (this.scopeCategory && byId[this.scopeCategory]) {
					keep[this.scopeCategory] = true;
					mark(byId[this.scopeCategory].parent);
				}
			}

			var html = '<option value="">— انتخاب کنید —</option>';
			for (i = 0; i < list.length; i++) {
				var row = list[i];
				if (!keep[row.id]) {
					continue;
				}
				var pad = '';
				for (var d = 0; d < (row.depth || 0); d++) {
					pad += '\u00A0\u00A0';
				}
				html += '<option value="' + this.esc(row.id) + '">'
					+ this.esc(pad + this.catLabel(row.name))
					+ (row.count ? ' (' + this.fmt(row.count) + ')' : '')
					+ '</option>';
			}

			$('#tbsm-scope-category').html(html).val(this.scopeCategory ? String(this.scopeCategory) : '');

			var hint = '';
			if (filter && !matches) {
				hint = this.i18n('scopeCategoryEmpty');
			} else if (filter) {
				// شمار «نتیجه»ها می‌آید، نه چیزهایی که فقط برای زمینه (والدها) مانده‌اند.
				hint = this.i18n('scopeCategoryFound')
					.replace('%1$d', this.fmt(matches))
					.replace('%2$d', this.fmt(list.length));
			}
			$('#tbsm-scope-category-hint').prop('hidden', !hint).html(hint ? this.esc(hint) : '');
		},

		/** تغییر روش انتخاب محدوده (چند محصول / دسته / شناسه). */
		setScopeType: function (type) {
			if (type !== 'products' && type !== 'category' && type !== 'sku') {
				type = 'products';
			}
			this.scopeType = type;

			$('.tbsm-seg-btn')
				.removeClass('is-active')
				.attr('aria-selected', 'false')
				.filter('[data-scope="' + type + '"]')
				.addClass('is-active')
				.attr('aria-selected', 'true');

			$('.tbsm-scope-pane').prop('hidden', true);
			$('#tbsm-scope-pane-' + type).prop('hidden', false);

			if (type === 'products') {
				$('#tbsm-scope-search').trigger('focus');
			} else if (type === 'sku') {
				$('#tbsm-scope-sku').trigger('focus');
			}

			this.refreshScopeApply();
		},

		/** جست‌وجوی محصول در گام محدوده (چند-انتخابی). */
		scopeSearch: function () {
			var self = this;
			var term = $.trim($('#tbsm-scope-search').val());

			if (term.length < 2) {
				$('#tbsm-scope-results').prop('hidden', true).empty();
				return;
			}

			$('#tbsm-scope-results').prop('hidden', false).html(
				'<div class="tbsm-results-empty">' + this.esc(this.i18n('scopeSearching')) + '</div>'
			);

			$.get(window.tbsmData.ajaxUrl, {
				action: 'tbsm_search_products',
				nonce: window.tbsmData.nonce,
				term: term
			})
				.done(function (res) {
					if (!res || !res.success) {
						self.renderScopeResults([]);
						return;
					}
					self.renderScopeResults(res.data.results || []);
				})
				.fail(function () {
					self.renderScopeResults([]);
					self.notice(self.i18n('scopeSearchError'), 'error');
				});
		},

		/** فهرست نتایج جست‌وجو به شکل ردیف‌های تیک‌خورده. */
		renderScopeResults: function (results) {
			this.scopeResults = results;
			var $box = $('#tbsm-scope-results');

			if (!results.length) {
				$box.prop('hidden', false).html(
					'<div class="tbsm-results-empty">' + this.esc(this.i18n('scopeSearchEmpty')) + '</div>'
				);
				return;
			}

			var html = '<div class="tbsm-scope-results-head">'
				+ '<span>' + this.esc(this.i18n('scopePickHint')) + '</span>'
				+ '<button type="button" class="tbsm-link-btn" id="tbsm-scope-addall">' + this.esc(this.i18n('scopeSelectAll')) + '</button>'
				+ '</div>';

			for (var i = 0; i < results.length; i++) {
				var p = results[i];
				var picked = !!this.scopeProducts[p.id];
				var badge = (p.type === 'variable')
					? '<span class="tbsm-badge">' + this.fmt(p.variations) + ' ' + this.esc(this.i18n('variationsUnit')) + '</span>'
					: '<span class="tbsm-badge tbsm-badge--muted">' + this.esc(this.i18n('simple')) + '</span>';

				html += '<button type="button" class="tbsm-scope-row' + (picked ? ' is-picked' : '') + '" data-id="' + this.esc(p.id) + '">'
					+ '<span class="tbsm-scope-check">' + this.checkIcon + '</span>'
					+ '<span class="tbsm-scope-name">' + this.esc(p.name) + '</span>'
					+ (p.sku ? '<span class="tbsm-scope-sku">' + this.esc(p.sku) + '</span>' : '')
					+ badge
					+ '</button>';
			}

			$box.prop('hidden', false).html(html);
		},

		/** افزودن/برداشتن یک محصول از محدوده. */
		toggleScopeProduct: function (id) {
			id = parseInt(id, 10) || 0;
			if (!id) {
				return;
			}

			if (this.scopeProducts[id]) {
				delete this.scopeProducts[id];
			} else {
				var brief = null;
				var list = this.scopeResults || [];
				for (var i = 0; i < list.length; i++) {
					if (list[i].id === id) {
						brief = list[i];
						break;
					}
				}
				if (Object.keys(this.scopeProducts).length >= this.scopeLimit) {
					this.notice(this.i18n('scopeTooMany').replace('%d', this.fmt(this.scopeLimit)), 'error');
					return;
				}
				this.scopeProducts[id] = brief || { id: id, name: '#' + id, sku: '' };
			}

			$('#tbsm-scope-results .tbsm-scope-row[data-id="' + id + '"]').toggleClass('is-picked', !!this.scopeProducts[id]);
			this.renderScopePicked();
			this.refreshScopeApply();
		},

		/** افزودن همهٔ نتایج فعلی جست‌وجو. */
		addAllScopeResults: function () {
			var list = this.scopeResults || [];
			for (var i = 0; i < list.length; i++) {
				if (Object.keys(this.scopeProducts).length >= this.scopeLimit) {
					this.notice(this.i18n('scopeTooMany').replace('%d', this.fmt(this.scopeLimit)), 'error');
					break;
				}
				this.scopeProducts[list[i].id] = list[i];
			}
			this.renderScopeResults(list);
			this.renderScopePicked();
			this.refreshScopeApply();
		},

		/** حذف همهٔ محصولات انتخاب‌شده. */
		clearScopeProducts: function () {
			this.scopeProducts = {};
			$('#tbsm-scope-results .tbsm-scope-row').removeClass('is-picked');
			this.renderScopePicked();
			this.refreshScopeApply();
		},

		/** چیپ‌های محصولات انتخاب‌شده. */
		renderScopePicked: function () {
			var $box = $('#tbsm-scope-picked');
			var ids = Object.keys(this.scopeProducts);

			if (!ids.length) {
				$box.prop('hidden', true).empty();
				return;
			}

			var html = '<span class="tbsm-picked-count">'
				+ this.esc(this.i18n('scopePicked').replace('%d', this.fmt(ids.length)))
				+ '</span>';

			for (var i = 0; i < ids.length; i++) {
				var p = this.scopeProducts[ids[i]];
				html += '<button type="button" class="tbsm-pick-chip" data-id="' + this.esc(p.id) + '" title="' + this.esc('حذف: ' + (p.name || '')) + '">'
					+ '<span>' + this.esc(p.name || ('#' + p.id)) + '</span>'
					+ '<span class="tbsm-pick-x" aria-hidden="true">&times;</span>'
					+ '</button>';
			}

			html += '<button type="button" class="tbsm-link-btn" id="tbsm-scope-clear">' + this.esc(this.i18n('scopeClear')) + '</button>';

			$box.prop('hidden', false).html(html);
		},

		/** دکمهٔ «نمایش ویژگی‌ها» بر اساس وضعیت محدوده. */
		refreshScopeApply: function () {
			var ready = false;
			if (this.scopeType === 'products') {
				ready = Object.keys(this.scopeProducts).length > 0;
			} else if (this.scopeType === 'category') {
				ready = this.scopeCategory > 0;
			} else {
				ready = true; // شناسهٔ خالی = همهٔ محصولات
			}
			$('#tbsm-scope-apply').prop('disabled', !ready || this.busy);
		},

		/** بدنهٔ درخواست محدوده برای همهٔ endpointهای گروهی. */
		scopePayload: function () {
			return {
				scope_type: this.scopeType,
				product_ids: Object.keys(this.scopeProducts),
				category_id: this.scopeCategory,
				sku: (this.scopeType === 'sku') ? $.trim($('#tbsm-scope-sku').val() || '') : '',
				sku_mode: ($('#tbsm-scope-sku-mode').val() || 'prefix')
			};
		},

		/**
		 * تأیید محدوده: سرور محصولات/متغیرها را می‌شمارد و ویژگی‌های همان محدوده
		 * را برمی‌گرداند؛ سپس گام ۲ باز می‌شود.
		 */
		applyScope: function () {
			var self = this;
			if (this.busy) {
				return;
			}

			if (this.scopeType === 'products' && !Object.keys(this.scopeProducts).length) {
				this.notice(this.i18n('scopeNeedProducts'), 'error');
				return;
			}
			if (this.scopeType === 'category' && this.scopeCategory <= 0) {
				this.notice(this.i18n('scopeNeedCategory'), 'error');
				return;
			}

			var seq = ++this.loadSeq;
			this.busy = true;
			this.notice('');
			this.resetGroup(false);
			$('#tbsm-scope-apply').prop('disabled', true).text(this.i18n('scopeApplying'));

			var payload = this.scopePayload();
			payload.action = 'tbsm_scope_attributes';
			payload.nonce = window.tbsmData.nonce;

			$.post(window.tbsmData.ajaxUrl, payload)
				.done(function (res) {
					if (seq !== self.loadSeq) {
						return;
					}
					if (!res || !res.success) {
						var msg = (res && res.data && (res.data.message || res.data.error)) || self.i18n('scopeError');
						self.notice(msg, 'error');
						return;
					}

					var data = res.data || {};
					self.scope = data.scope || null;
					self.scopeAttrs = data.attributes || [];
					self.renderScopeSummary();
					self.renderGroupAttrs();

					if (self.scopeAttrs.length) {
						$('#tbsm-step-model').removeClass('tbsm-step-locked');
						$('#tbsm-group-attr').prop('disabled', false).trigger('focus');
					} else {
						self.notice(self.i18n('groupAttrEmpty'), 'info');
					}
				})
				.fail(function () {
					if (seq === self.loadSeq) {
						self.notice(self.i18n('scopeError'), 'error');
					}
				})
				.always(function () {
					if (seq !== self.loadSeq) {
						return;
					}
					self.busy = false;
					$('#tbsm-scope-apply').text(self.i18n('scopeApply'));
					self.refreshScopeApply();
				});
		},

		/** خط خلاصهٔ محدوده زیر دکمه. */
		renderScopeSummary: function () {
			var $box = $('#tbsm-scope-summary');
			if (!this.scope) {
				$box.prop('hidden', true).empty();
				return;
			}

			var html = this.i18n('scopeSummary')
				.replace('%1$s', this.scope.label || '')
				.replace('%2$d', this.fmt(this.scope.products || 0))
				.replace('%3$d', this.fmt(this.scope.variations || 0));

			$box.prop('hidden', false).html('<strong>' + this.esc(html) + '</strong>');
		},

		/* ----------------------------------------------------------
		 * حالت گروهی — گام ۲: ویژگی و مقدار مدل
		 * ---------------------------------------------------------- */

		/**
		 * فهرست ویژگی‌های محدوده. ویژگی‌هایی که سرور آن‌ها را «یکی» شناخته
		 * (برچسب خوانای یکسان؛ مثل `pa_model` و `مدل` و `d9%85d8%af%d9%84`) در یک
		 * گزینه آمده‌اند و کلیدهای خامشان در `data-keys` است؛ کنار نام، تعداد
		 * محصول (و در صورت ادغام، شمار کلیدها) نمایش داده می‌شود.
		 */
		renderGroupAttrs: function () {
			var $sel = $('#tbsm-group-attr');
			var list = this.scopeAttrs || [];

			if (!list.length) {
				$sel.prop('disabled', true).html('<option value="">—</option>');
				this.renderGroupValues([]);
				return;
			}

			var html = '<option value="">' + this.esc(this.i18n('groupTermPick')) + '</option>';
			for (var i = 0; i < list.length; i++) {
				var attr = list[i];
				var label = this.catLabel(attr.label);
				var keys = attr.keys || [];
				var text = label
					+ ' — ' + this.fmt(attr.products) + ' ' + this.esc(this.i18n('scopeProductsUnit'));

				if (keys.length > 1) {
					text += ' · ' + this.esc(this.i18n('groupMergedKeys').replace('%d', this.fmt(keys.length)));
				}

				html += '<option value="' + i + '" data-label="' + this.esc(label) + '"'
					+ ' data-keys="' + this.esc(keys.join('|')) + '"'
					+ ' data-products="' + this.fmt(attr.products) + '">'
					+ this.esc(text)
					+ '</option>';
			}

			$sel.html(html).val('');
			this.renderGroupValues([]);
		},

		/**
		 * بارگذاری مقدارهای ویژگی انتخاب‌شده در همین محدوده (فقط کلیدهای همان
		 * ویژگی فرستاده می‌شوند).
		 */
		loadGroupValues: function (index) {
			var self = this;
			var attr = (this.scopeAttrs || [])[index];

			if (!attr || !attr.keys || !attr.keys.length) {
				this.renderGroupValues([]);
				return;
			}

			var $term = $('#tbsm-group-term');
			$term.prop('disabled', true).html('<option value="">' + this.esc(this.i18n('groupAttrLoading')) + '</option>');

			var payload = this.scopePayload();
			payload.action = 'tbsm_scope_values';
			payload.nonce = window.tbsmData.nonce;
			payload.attr_keys = attr.keys;

			var seq = this.loadSeq;
			$.post(window.tbsmData.ajaxUrl, payload)
				.done(function (res) {
					if (seq !== self.loadSeq) {
						return;
					}
					if (!res || !res.success) {
						self.renderGroupValues([]);
						self.notice(self.i18n('groupValuesError'), 'error');
						return;
					}
					self.renderGroupValues(res.data || {});
				})
				.fail(function () {
					if (seq === self.loadSeq) {
						self.renderGroupValues([]);
						self.notice(self.i18n('groupValuesError'), 'error');
					}
				});
		},

		/**
		 * مقدارهای مدل: `{values, groups, truncated}` از سرور می‌آید.
		 * `groups` گروه‌های برندِ صفحهٔ محصول است (آیفون/سامسونگ/…) و به شکل
		 * `optgroup` رندر می‌شود؛ `data-values` نوشتارهای خامِ هر گزینه را
		 * نگه می‌دارد تا هر دو نوشتار (کدشده و سالم) پوشش داده شوند.
		 */
		renderGroupValues: function (payload) {
			if (Array.isArray(payload)) {
				payload = { values: payload };
			}
			payload = payload || {};

			this.valueGroups = payload.values || [];
			this.valueGroupIndex = payload.groups || [];
			this.valueTruncated = !!payload.truncated;
			this.valueFilter = '';
			this.valueHay = this.valueGroups.map(this.termHaystack, this); // یک‌بار، برای فیلتر زنده

			$('#tbsm-group-term-search')
				.val('')
				.prop('disabled', !this.valueGroups.length);

			this.paintGroupValues(false);
			this.resetGroupCards();
		},

		/** متن و گزینهٔ یک مقدار (بر پایهٔ اندیس ثابت در فهرست کامل). */
		termOptionHtml: function (index) {
			var v = (this.valueGroups || [])[index];
			if (!v) {
				return '';
			}
			var label = this.catLabel(v.label);
			return '<option value="' + index + '" data-label="' + this.esc(label) + '"'
				+ ' data-values="' + this.esc((v.values || []).join('|')) + '">'
				+ this.esc(label + ' — ' + this.fmt(v.products) + ' ' + this.i18n('scopeProductsUnit'))
				+ '</option>';
		},

		/** متن قابل جست‌وجوی یک مقدار: برچسب + نوشتارهای خام. */
		termHaystack: function (group) {
			group = group || {};
			return this.normalizeQuery(this.catLabel(group.label) + ' ' + (group.values || []).join(' '));
		},

		/**
		 * رندر گزینه‌های مقدار با فیلتر جست‌وجو؛ با فیلتر، فهرست تخت می‌شود و
		 * بدون فیلتر، اگر گروه برند داشته باشیم `optgroup`ها می‌آیند.
		 *
		 * @param {boolean} keepSelection انتخاب فعلی حفظ شود؟ (برای فیلتر زنده)
		 */
		paintGroupValues: function (keepSelection) {
			var $term = $('#tbsm-group-term');
			var list = this.valueGroups || [];
			var groups = this.valueGroupIndex || [];
			var filter = this.valueFilter || '';
			var previous = keepSelection ? String($term.val() || '') : '';
			var found = [];
			var i;

			for (i = 0; i < list.length; i++) {
				var hay = this.valueHay && this.valueHay[i] !== undefined ? this.valueHay[i] : this.termHaystack(list[i]);
				if (!filter || hay.indexOf(filter) !== -1) {
					found.push(i);
				}
			}

			var html = '<option value="">' + this.esc(this.i18n('groupTermPick')) + '</option>';

			if (filter || !groups.length) {
				for (i = 0; i < found.length; i++) {
					html += this.termOptionHtml(found[i]);
				}
			} else {
				var used = {};
				for (var g = 0; g < groups.length; g++) {
					var group = groups[g] || {};
					var indexes = group.indexes || [];
					var inner = '';
					for (var k = 0; k < indexes.length; k++) {
						inner += this.termOptionHtml(indexes[k]);
						used[indexes[k]] = true;
					}
					if (!inner) {
						continue;
					}
					html += '<optgroup label="' + this.esc(this.catLabel(group.label) + ' (' + this.fmt(indexes.length) + ')') + '">'
						+ inner + '</optgroup>';
				}
				// مقدارهایی که در هیچ گروهی جا نگرفته‌اند (نباید پیش بیاید) در پایان.
				for (i = 0; i < found.length; i++) {
					if (!used[found[i]]) {
						html += this.termOptionHtml(found[i]);
					}
				}
			}

			$term.prop('disabled', !list.length).html(html);

			if (previous && $term.find('option[value="' + previous + '"]').length) {
				$term.val(previous);
			} else {
				$term.val('');
				if (previous) {
					this.resetGroupCards();
				}
			}

			var hint = [];
			if (this.valueTruncated) {
				hint.push(this.i18n('groupTermTruncated'));
			}
			if (filter) {
				hint.push(
					this.i18n('groupTermFound')
						.replace('%1$d', this.fmt(found.length))
						.replace('%2$d', this.fmt(list.length))
				);
			}
			$('#tbsm-group-term-hint')
				.prop('hidden', !hint.length)
				.html(hint.length ? this.esc(hint.join(' — ')) : '');
		},

		/** پاک‌کردن کارت‌ها وقتی مقدار مدل ناتمام است. */
		resetGroupCards: function () {
			this.group = null;
			$('#tbsm-grid').prop('hidden', true).empty();
			$('#tbsm-group-note').prop('hidden', true).empty();
			$('#tbsm-counts').empty();
			$('#tbsm-report').prop('hidden', true).empty();
			if (this.mode === 'group') {
				$('#tbsm-step-cards').addClass('tbsm-step-locked');
				$('#tbsm-apply').prop('disabled', true).text(this.i18n('applyBtn').replace('%d', '—'));
				$('#tbsm-quickset-btn').prop('disabled', true);
				$('#tbsm-unified').prop('disabled', true).removeClass('tisa-btn--confirm').text(this.i18n('unifiedBtn'));
			}
			this.updateBottomHint();
		},

		/* ----------------------------------------------------------
		 * حالت گروهی — گام ۳: کارت‌های همان مدل در همان محدوده
		 * ---------------------------------------------------------- */

		/** فهرست‌کردن متغیرهای مدل انتخاب‌شده (کلید/مقدارهای هم‌معنا با هم). */
		findGroup: function (termIndex) {
			var self = this;
			var attrIndex = parseInt($('#tbsm-group-attr').val(), 10);
			var attr = (this.scopeAttrs || [])[attrIndex];
			var term = (this.valueGroups || [])[termIndex];

			if (!this.scope) {
				return;
			}
			if (!attr || !term) {
				this.notice(this.i18n('groupNeedSelection'), 'error');
				return;
			}

			var attrLabel = attr.label;
			var termLabel = term.label;

			var seq = ++this.loadSeq;
			this.clearSelection(true);
			this.notice('');
			this.busy = true;
			$('#tbsm-grid').prop('hidden', false).html('<div class="tbsm-grid-empty">' + this.esc(this.i18n('groupSearching')) + '</div>');

			var payload = this.scopePayload();
			payload.action = 'tbsm_find_scope';
			payload.nonce = window.tbsmData.nonce;
			payload.attr_keys = attr.keys || [];
			payload.term_values = term.values || [];

			$.post(window.tbsmData.ajaxUrl, payload)
				.done(function (res) {
					if (seq !== self.loadSeq) {
						return;
					}
					if (!res || !res.success) {
						var errMsg = (res && res.data && (res.data.message || res.data.error))
							? (res.data.message || res.data.error)
							: self.i18n('groupFindError');
						self.notice(errMsg, 'error');
						self.renderGrid();
						return;
					}

					var data = res.data || {};
					self.group = {
						attrLabel: attrLabel,
						termLabel: termLabel,
						keys: attr.keys || [],
						values: term.values || []
					};
					if (data.scope) {
						self.scope = data.scope;
						self.renderScopeSummary();
					}
					self.variations = data.rows || [];
					self.categories = {};

					if (!self.variations.length) {
						self.renderGrid();
						self.notice(self.i18n('groupEmpty'), 'info');
					} else {
						self.renderGrid();
						$('#tbsm-step-cards').removeClass('tbsm-step-locked');
					}

					self.renderGroupNote(data);
					self.refreshCounts();
				})
				.fail(function () {
					if (seq !== self.loadSeq) {
						return;
					}
					self.notice(self.i18n('groupFindError'), 'error');
					self.renderGrid();
				})
				.always(function () {
					if (seq !== self.loadSeq) {
						return;
					}
					self.busy = false;
					self.refreshCounts();
				});
		},

		/** خط اطلاعات بالای کارت‌ها: محدوده + مدل + شمارش‌ها. */
		renderGroupNote: function (data) {
			var $note = $('#tbsm-group-note');
			if (this.mode !== 'group' || !this.group || !this.scope) {
				$note.prop('hidden', true).empty();
				return;
			}

			var total = (data && typeof data.total !== 'undefined') ? data.total : this.variations.length;
			var products = (data && typeof data.products !== 'undefined') ? data.products : 0;

			var html = '<span class="tbsm-group-note-main">'
				+ this.esc(this.i18n('groupNote')
					.replace('%1$s', this.scope.label || '')
					.replace('%2$s', this.group.attrLabel + ' · ' + this.group.termLabel)
					.replace('%3$d', this.fmt(total))
					.replace('%4$d', this.fmt(products)))
				+ '</span>';

			if (data && data.truncated) {
				html += '<span class="tbsm-group-note-warn">'
					+ this.esc(this.i18n('groupTruncated').replace('%d', this.fmt(data.limit || 0)))
					+ '</span>';
			}

			this.groupNoteHtml = html;
			$note.prop('hidden', false).html(html);
		},

		/** بازنشانی حالت گروهی (محدوده دست‌نخورده می‌ماند اگر keepScope باشد). */
		resetGroup: function (keepScope) {
			this.group = null;
			this.groupNoteHtml = '';
			this.variations = [];

			$('#tbsm-grid').prop('hidden', true).empty();
			$('#tbsm-group-note').prop('hidden', true).empty();
			$('#tbsm-counts').empty();
			$('#tbsm-report').prop('hidden', true).empty();
			$('#tbsm-step-cards').addClass('tbsm-step-locked');

			if (!keepScope) {
				this.scope = null;
				this.scopeAttrs = [];
				$('#tbsm-scope-summary').prop('hidden', true).empty();
				$('#tbsm-step-model').addClass('tbsm-step-locked');
				this.valueGroups = [];
				$('#tbsm-group-attr').prop('disabled', true).html('<option value="">' + this.esc(this.i18n('groupAttrLoading')) + '</option>');
				$('#tbsm-group-term').prop('disabled', true).html('<option value="">' + this.esc(this.i18n('groupTermPick')) + '</option>');
			}
		},

		/** راهنمای نوار پایین بر اساس حالت و وضعیت فهرست. */
		updateBottomHint: function () {
			var $cards = $('#tbsm-grid .tbsm-var');
			var selected = $cards.filter(':not(.is-hidden).is-selected').length;
			if (selected > 0) {
				$('#tbsm-bottombar-hint').text(this.i18n('bottomHint'));
			} else if (this.mode === 'group' && !$cards.length) {
				$('#tbsm-bottombar-hint').text(this.i18n('groupHintEmpty'));
			} else {
				$('#tbsm-bottombar-hint').text(this.i18n('selectAtLeastOne'));
			}
		},

		/* ----------------------------------------------------------
		 * گام ۱ — جست‌وجو و انتخاب محصول
		 * ---------------------------------------------------------- */

		doSearch: function () {
			var self = this;
			var term = $.trim($('#tbsm-search').val());

			if (term.length < 2) {
				$('#tbsm-results').prop('hidden', true).empty();
				return;
			}

			$('#tbsm-results').prop('hidden', false).html(
				'<div class="tbsm-results-empty">' + this.esc(this.i18n('searching')) + '</div>'
			);

			$.get(window.tbsmData.ajaxUrl, {
				action: 'tbsm_search_products',
				nonce: window.tbsmData.nonce,
				term: term
			})
				.done(function (res) {
					if (!res || !res.success) {
						self.renderNoResults();
						return;
					}
					self.renderResults(res.data.results || []);
				})
				.fail(function () {
					self.renderNoResults();
					self.notice(self.i18n('searchError'), 'error');
				});
		},

		renderNoResults: function () {
			this.lastResults = [];
			$('#tbsm-results')
				.prop('hidden', false)
				.html('<div class="tbsm-results-empty">' + this.esc(this.i18n('noResults')) + '</div>');
		},

		renderResults: function (results) {
			this.lastResults = results;
			var $box = $('#tbsm-results');

			if (!results.length) {
				$box.prop('hidden', false).html(
					'<div class="tbsm-results-empty">' + this.esc(this.i18n('noResults')) + '</div>'
				);
				return;
			}

			var html = '';
			for (var i = 0; i < results.length; i++) {
				var p = results[i];
				var typeBadge = p.type === 'variable'
					? '<span class="tbsm-badge">' + this.fmt(p.variations) + ' ' + this.esc(this.i18n('variationsUnit')) + '</span>'
					: '<span class="tbsm-badge tbsm-badge--muted">' + this.esc(this.i18n('simple')) + '</span>';
				var statusBadge = '';
				if (p.status && p.status !== 'publish') {
					statusBadge = '<span class="tbsm-badge tbsm-badge--muted">' + this.esc(this.i18n(p.status) || p.status) + '</span>';
				}
				var img = p.image
					? '<img class="tbsm-result-img" src="' + this.esc(p.image) + '" alt="">'
					: '<span class="tbsm-result-img tbsm-result-img--none">' + this.boxIcon + '</span>';

				html += '<button type="button" class="tbsm-result" data-id="' + p.id + '">'
					+ img
					+ '<span class="tbsm-result-name">' + this.esc(p.name) + '</span>'
					+ (p.sku ? '<span class="tbsm-result-sku">' + this.esc(p.sku) + '</span>' : '')
					+ typeBadge
					+ statusBadge
					+ '</button>';
			}

			$box.prop('hidden', false).html(html);
		},

		selectProduct: function (id) {
			var self = this;
			var brief = null;
			for (var i = 0; i < this.lastResults.length; i++) {
				if (this.lastResults[i].id === id) {
					brief = this.lastResults[i];
					break;
				}
			}

			// نشانهٔ درخواست: پاسخ‌های دیررسِ درخواست‌های قبلی نادیده گرفته می‌شوند
			var seq = ++this.loadSeq;

			this.clearSelection(true);
			$('#tbsm-results').prop('hidden', true).empty();
			this.notice('');

			$.post(window.tbsmData.ajaxUrl, {
				action: 'tbsm_load_product',
				nonce: window.tbsmData.nonce,
				product_id: id
			})
				.done(function (res) {
					if (seq !== self.loadSeq) {
						return; // پاسخِ محصول قبلی — دیگر مهم نیست
					}
					if (!res || !res.success) {
						self.notice(self.i18n('loadError'), 'error');
						return;
					}
					self.product = res.data.product || brief;
					self.variations = res.data.variations || [];
					self.categories = res.data.categories || {};
					self.renderSelected();
					self.renderCategorySelect();
					self.renderGrid();
					$('#tbsm-step-category').removeClass('tbsm-step-locked');
					$('#tbsm-step-cards').removeClass('tbsm-step-locked');
					self.refreshCounts();
				})
				.fail(function () {
					if (seq !== self.loadSeq) {
						return;
					}
					self.notice(self.i18n('loadError'), 'error');
				});
		},

		renderSelected: function () {
			var p = this.product;
			if (!p) {
				return;
			}

			var img = p.image
				? '<img class="tbsm-selected-img" src="' + this.esc(p.image) + '" alt="">'
				: '<span class="tbsm-selected-img" style="display:inline-flex;align-items:center;justify-content:center;color:var(--tisa-muted,#77828A)">' + this.boxIcon + '</span>';

			var typeBadge = p.type === 'variable'
				? '<span class="tbsm-badge">' + this.fmt(p.variations) + ' ' + this.esc(this.i18n('variationsUnit')) + '</span>'
				: '<span class="tbsm-badge tbsm-badge--muted">' + this.esc(this.i18n('simpleNote')) + '</span>';

			var html = ''
				+ img
				+ '<div class="tbsm-selected-info">'
				+   '<div class="tbsm-selected-name">' + this.esc(p.name) + '</div>'
				+   '<div class="tbsm-selected-meta">'
				+     (p.sku ? '<span class="tbsm-selected-sku">' + this.esc(p.sku) + '</span>' : '')
				+     typeBadge
				+   '</div>'
				+ '</div>'
				+ '<button type="button" class="tisa-btn tisa-btn--secondary" id="tbsm-change">' + this.esc(this.i18n('changeProduct')) + '</button>';

			$('#tbsm-selected').prop('hidden', false).html(html);
		},

		clearSelection: function (keepSearch) {
			this.product = null;
			this.variations = [];
			this.categories = {};
			this.group = null;
			this.groupNoteHtml = '';
			this.confirmMode = false;
			clearTimeout(this.confirmTimer);
			this.unifiedConfirm = false;
			clearTimeout(this.unifiedConfirmTimer);

			$('#tbsm-selected').prop('hidden', true).empty();
			$('#tbsm-grid').prop('hidden', true).empty();
			$('#tbsm-report').prop('hidden', true).empty();
			$('#tbsm-group-note').prop('hidden', true).empty();
			$('#tbsm-counts').empty();
			$('#tbsm-cat-select').prop('disabled', true).html('<option value="all">—</option>');
			$('#tbsm-step-category').addClass('tbsm-step-locked');
			$('#tbsm-step-cards').addClass('tbsm-step-locked');
			$('#tbsm-apply').prop('disabled', true).removeClass('tisa-btn--confirm').text(this.i18n('applyBtn').replace('%d', '—'));
			$('#tbsm-quickset').val('');
			$('#tbsm-quickset-btn').prop('disabled', true);
			$('#tbsm-unified').prop('disabled', true).removeClass('tisa-btn--confirm').text(this.i18n('unifiedBtn'));
			this.updateBottomHint();
			if (!keepSearch) {
				$('#tbsm-search').val('').trigger('focus');
			}
		},

		/* ----------------------------------------------------------
		 * گام ۲ — سلکت دسته
		 * ---------------------------------------------------------- */

		renderCategorySelect: function () {
			var $sel = $('#tbsm-cat-select').prop('disabled', false);
			var total = this.variations.length;
			var html = '<option value="all">' + this.esc(this.i18n('allVariations')) + ' (' + this.fmt(total) + ')</option>';

			for (var tax in this.categories) {
				if (!this.categories.hasOwnProperty(tax)) {
					continue;
				}
				var cat = this.categories[tax];
				html += '<optgroup label="' + this.esc(this.catLabel(cat.label)) + '">';
				for (var i = 0; i < cat.values.length; i++) {
					var v = cat.values[i];
					html += '<option value="' + this.esc(tax) + ':' + this.esc(v.slug) + '">'
						+ this.esc(v.label) + ' (' + this.fmt(v.count) + ')</option>';
				}
				html += '</optgroup>';
			}

			$sel.html(html).val('all');
		},

		/** فیلتر کارت‌ها با دستهٔ انتخاب‌شده؛ کارت‌های مطابق تیک می‌خورند. */
		applyCategory: function (val) {
			if (val === 'all') {
				$('#tbsm-grid .tbsm-var').removeClass('is-hidden');
			} else {
				var needle = '|' + val + '|';
				$('#tbsm-grid .tbsm-var').each(function () {
					var $card = $(this);
					var cat = '|' + ($card.attr('data-cat') || '') + '|';
					var match = cat.indexOf(needle) !== -1;
					$card.toggleClass('is-hidden', !match);
					if (match) {
						$card.addClass('is-selected');
					}
				});
			}

			this.refreshCounts();
		},

		/* ----------------------------------------------------------
		 * گام ۳ — کارت‌ها
		 * ---------------------------------------------------------- */

		renderGrid: function () {
			var $grid = $('#tbsm-grid');

			if (!this.variations.length) {
				var emptyText = (this.mode === 'group') ? this.i18n('groupEmpty') : '—';
				$grid.prop('hidden', false).html('<div class="tbsm-grid-empty">' + this.esc(emptyText) + '</div>');
				return;
			}

			var html = '';
			for (var i = 0; i < this.variations.length; i++) {
				var v = this.variations[i];
				var mode = (v.mode === 'in_stock' || v.mode === 'out_of_stock') ? v.mode : 'qty';

				// بج وضعیت
				var stockBadge;
				if (mode === 'qty') {
					stockBadge = (v.stock > 0 || v.status === 'instock')
						? '<span class="tbsm-badge tbsm-badge--ok" data-badge>' + this.esc(this.i18n('stockLabel')) + ': ' + this.fmt(v.stock) + '</span>'
						: '<span class="tbsm-badge tbsm-badge--out" data-badge>' + this.esc(this.i18n('stockLabel')) + ': ' + this.fmt(v.stock) + '</span>';
				} else if (mode === 'in_stock') {
					stockBadge = '<span class="tbsm-badge tbsm-badge--ok" data-badge>' + this.esc(this.i18n('inStockLabel')) + '</span>';
				} else {
					stockBadge = '<span class="tbsm-badge tbsm-badge--out" data-badge>' + this.esc(this.i18n('outOfStockLabel')) + '</span>';
				}

				// یادداشت «موجودی‌گیری خاموش» فقط در حالت عددی (وقتی با اعمال روشن می‌شود)
				var manageNote = (!v.manage_stock && mode === 'qty')
					? '<span class="tbsm-var-manage" title="' + this.esc(this.i18n('manageOffNote')) + '">' + this.esc(this.i18n('manageOffNote')) + '</span>'
					: '';

				var selQty = mode === 'qty' ? ' selected' : '';
				var selIn  = mode === 'in_stock' ? ' selected' : '';
				var selOut = mode === 'out_of_stock' ? ' selected' : '';

				// در حالت گروهی، نام محصول والد در تیتر و برچسب متغیر + شناسه‌ها در خط دوم می‌آید.
				var title = v.pname ? v.pname : v.name;
				var sub = v.pname
					? (v.name + (v.sku ? ' — ' + v.sku : (v.psku ? ' — ' + v.psku : '')))
					: (v.sku ? v.sku : '#' + v.id);

				html += '<div class="tbsm-var is-selected" data-id="' + v.id + '" data-cat="' + this.esc(v.cat || '') + '" data-mode="' + mode + '" data-manage="' + (v.manage_stock ? 1 : 0) + '">'
					+ '<span class="tbsm-var-check">' + this.checkIcon + '</span>'
					+ '<span class="tbsm-var-name">' + this.esc(title) + '</span>'
					+ '<span class="tbsm-var-sku">' + this.esc(sub) + '</span>'
					+ '<span class="tbsm-var-foot">' + stockBadge + manageNote + '</span>'
					+ '<select class="tbsm-var-mode" aria-label="' + this.esc(this.i18n('modeLabel')) + '">'
					+   '<option value="qty"' + selQty + '>' + this.esc(this.i18n('modeQty')) + '</option>'
					+   '<option value="in_stock"' + selIn + '>' + this.esc(this.i18n('modeInStock')) + '</option>'
					+   '<option value="out_of_stock"' + selOut + '>' + this.esc(this.i18n('modeOutOfStock')) + '</option>'
					+ '</select>'
					+ '<span class="tbsm-var-stockbox" dir="ltr"' + (mode === 'qty' ? '' : ' hidden') + '>'
					+   '<button type="button" class="tbsm-stepbtn tbsm-step-down" aria-label="-1">−</button>'
					+   '<input type="number" class="tbsm-var-stock" min="0" step="1" inputmode="numeric" value="' + v.stock + '"' + (mode === 'qty' ? '' : ' disabled') + ' aria-label="' + this.esc(this.i18n('stockLabel')) + '">'
					+   '<button type="button" class="tbsm-stepbtn tbsm-step-up" aria-label="+1">+</button>'
					+ '</span>'
					+ (mode === 'in_stock'
						? '<span class="tbsm-var-status-chip tbsm-var-status-chip--in">' + this.esc(this.i18n('modeInStock')) + '</span>'
						: mode === 'out_of_stock'
							? '<span class="tbsm-var-status-chip tbsm-var-status-chip--out">' + this.esc(this.i18n('modeOutOfStock')) + '</span>'
							: '<span class="tbsm-var-status-chip" hidden></span>')
					+ '</div>';
				}

			$grid.prop('hidden', false).html(html);

			// رنگ اولیهٔ باکس‌های عددی (سبز/قرمز بر اساس مقدار)
			var self = this;
			$grid.find('.tbsm-var').each(function () {
				self.paintStockbox($(this));
			});
		},

		toggleCard: function ($card) {
			$card.toggleClass('is-selected');
			$card.attr('data-touched', '1');
			this.refreshCounts();
		},

		/**
		 * دکمه‌های استپر (− / +) روی باکس موجودی.
		 * با Shift، ۱۰ تا جابه‌جا می‌کند؛ کف صفر.
		 * @param {jQuery} $btn دکمهٔ کلیک‌شده
		 * @param {Event}  e    رویداد کلیک
		 */
		doStep: function ($btn, e) {
			var $card = $btn.closest('.tbsm-var');
			var $input = $card.find('.tbsm-var-stock');
			var current = parseInt($input.val(), 10);
			if (isNaN(current) || current < 0) {
				current = 0;
			}
			var delta = ($btn.hasClass('tbsm-step-up') ? 1 : -1) * ((e && e.shiftKey) ? 10 : 1);
			var next = current + delta;
			if (next < 0) {
				next = 0;
			}
			$input.val(next).removeClass('is-invalid').trigger('focus');
			this.paintStockbox($card);
		},

		/**
		 * تغییر حالت موجودی یک کارت: qty (عددی) / in_stock / out_of_stock.
		 * @param {jQuery} $card
		 * @param {string} mode
		 */
		setCardMode: function ($card, mode) {
			if (mode !== 'qty' && mode !== 'in_stock' && mode !== 'out_of_stock') {
				mode = 'qty';
			}
			$card.attr('data-mode', mode);
			$card.find('.tbsm-var-mode').val(mode);

			var $box  = $card.find('.tbsm-var-stockbox');
			var $chip = $card.find('.tbsm-var-status-chip');
			var $in   = $card.find('.tbsm-var-stock');

			if (mode === 'qty') {
				$box.prop('hidden', false);
				$in.prop('disabled', false);
				$chip.prop('hidden', true).attr('class', 'tbsm-var-status-chip').text('');
				this.paintStockbox($card);
				if ($card.attr('data-manage') === '0') {
					// ردیابی خاموش است؛ یادآوری که با اعمال روشن می‌شود
					if ($card.find('.tbsm-var-manage').length === 0) {
						$card.find('.tbsm-var-foot').append('<span class="tbsm-var-manage">' + this.esc(this.i18n('manageOffNote')) + '</span>');
					}
				} else {
					$card.find('.tbsm-var-manage').remove();
				}
			} else {
				$box.prop('hidden', true);
				$in.prop('disabled', true);
				$card.find('.tbsm-var-manage').remove();
				$chip
					.prop('hidden', false)
					.attr('class', 'tbsm-var-status-chip ' + (mode === 'in_stock' ? 'tbsm-var-status-chip--in' : 'tbsm-var-status-chip--out'))
					.text(this.esc(this.i18n(mode === 'in_stock' ? 'modeInStock' : 'modeOutOfStock')));
			}

			// بج وضعیت با حالت جدید
			var $badge = $card.find('[data-badge]');
			if (mode === 'qty') {
				var val = parseInt($in.val(), 10);
				if (isNaN(val) || val < 0) {
					val = 0;
				}
				$badge
					.removeClass('tbsm-badge--ok tbsm-badge--out')
					.addClass(val > 0 ? 'tbsm-badge--ok' : 'tbsm-badge--out')
					.text(this.i18n('stockLabel') + ': ' + this.fmt(val));
			} else if (mode === 'in_stock') {
				$badge.removeClass('tbsm-badge--ok tbsm-badge--out').addClass('tbsm-badge--ok').text(this.i18n('inStockLabel'));
			} else {
				$badge.removeClass('tbsm-badge--ok tbsm-badge--out').addClass('tbsm-badge--out').text(this.i18n('outOfStockLabel'));
			}
		},

		/** برچسب فارسی وضعیت موجودی. */
		statusLabel: function (status) {
			return status === 'instock' ? this.i18n('inStockLabel') : this.i18n('outOfStockLabel');
		},

		/**
		 * بازیابی برچسبِ کدشده: برخی افزونه‌ها نام/برچسب ویژگی‌ها و مقادیر فارسی را
		 * percent-encoded می‌سازند — آن هم به شکل «ناقص»:
		 *   - نه هر بایت % دارد: d9%85d8%af%d9%84
		 *   - پیشوند داخلی چسبیده است: pa_d9%85d8%af%d9%84 / attribute_…
		 *   - جداکننده‌ها (_ - + فاصله) جای فاصله نشسته‌اند
		 *   - رشته ممکن است وسط یک نویسهٔ چندبایتی بریده باشد
		 *   - گاهی دو بار کد شده است (double-encoding)
		 * decode فقط وقتی تلاش می‌شود که رشته واقعاً «شبیه blobِ hexی» باشد و خروجی
		 * هم UTF-8 معتبر و متنِ چاپ‌پذیر باشد؛ برچسب‌های عادی دست‌نخورده می‌مانند.
		 */
		catLabel: function (label) {
			var s = this.stripInvisible(String(label === null || label === undefined ? '' : label));
			if (!s || !this.looksEncoded(s)) {
				return s;
			}

			// پیشوندهای داخلی (pa_/attribute_) بخشی از برچسب نمایشی نیستند.
			var body = s.replace(/^attribute_/i, '').replace(/^pa_/i, '');
			var out = '';
			if (body && body !== s) {
				out = this.decodeLabelText(body);
			}
			if (!out) {
				out = this.decodeLabelText(s);
			}

			return out || s;
		},

		/** حذف کاراکترهای صفرعرض/کنترلی — پیش از هر decode ای (نیم‌فاصله حفظ می‌شود). */
		stripInvisible: function (s) {
			return String(s === null || s === undefined ? '' : s)
				.replace(/[\u200B\u200E\u200F\u202A-\u202E\u2060\uFEFF\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/g, '');
		},

		/** آیا رشته شبیه blobِ کدشدهٔ hexی است؟ */
		looksEncoded: function (s) {
			s = String(s || '');
			if (s.indexOf('%') === -1 || s.length < 4) {
				return false;
			}
			var hexed = (s.match(/[0-9a-fA-F]/g) || []).length;
			var seps = (s.match(/[%+ _\-.~]/g) || []).length;
			return (hexed + seps) >= Math.ceil(s.length * 0.9);
		},

		/**
		 * یک یا چند پاس decode (پشتیبانی از double-encoding).
		 * @param {string} text
		 * @return {string} متنِ بازیابی‌شده یا '' اگر قابل‌بازیابی نبود
		 */
		decodeLabelText: function (text) {
			text = String(text || '');
			if (!this.looksEncoded(text)) {
				return '';
			}

			var current = text;
			for (var pass = 0; pass < 3; pass++) {
				var decoded = this.decodeLabelOnce(current);
				if (!decoded) {
					return (pass > 0) ? current : '';
				}
				current = decoded;
				if (!this.looksEncoded(current)) {
					break;
				}
			}

			return current;
		},

		/** یک پاس decode (+ نجات رشته‌ای که وسط نویسهٔ چندبایتی بریده شده). */
		decodeLabelOnce: function (text) {
			text = String(text);
			var marks = (text.match(/%/g) || []).length;
			var len = text.length;

			// ۱) decode استاندارد (فقط %xx بایت می‌شود و بقیه دست‌نخورده می‌ماند) —
			//    لازم برای کدشده‌های کامل و double-encoded مثل %25d9%2585…
			var strict = this.strictDecode(text);
			if (strict && strict !== text) {
				var strictOut = this.bytesToText(strict, marks);
				if (strictOut) {
					return strictOut.trim();
				}
			}

			// ۲) decode جفت‌های hex (کدشدهٔ ناقص) + نجات رشتهٔ بریده‌شده
			for (var drop = 0; drop <= 6; drop++) {
				var src = (drop > 0) ? String(text).slice(0, Math.max(0, len - drop)) : String(text);
				if (!src) {
					break;
				}
				var bytes = this.hexStream(src);
				if (!bytes || !bytes.length) {
					continue;
				}
				var out = this.bytesToText(bytes, marks);
				if (!out) {
					continue;
				}
				return out.trim();
			}

			return '';
		},

		/**
		 * decode استاندارد (معادل rawurldecode): فقط `%xx` بایت می‌شود و بقیهٔ
		 * نویسه‌ها دست‌نخورده می‌مانند. برای `%25d9%2585…` و کدشده‌های کامل.
		 *
		 * @param {string} src
		 * @return {number[]|null}
		 */
		strictDecode: function (src) {
			src = String(src || '');
			var out = [];
			for (var i = 0; i < src.length; i++) {
				var c = src.charAt(i);
				if (c === '%') {
					var pair = src.substr(i + 1, 2);
					if (/^[0-9a-fA-F]{2}$/.test(pair)) {
						out.push(parseInt(pair, 16));
						i += 2;
						continue;
					}
					out.push(37); // % بدون بایت معتبر
					continue;
				}
				var code = src.charCodeAt(i);
				if (code < 128) {
					out.push(code);
				} else {
					// نویسهٔ غیر-ASCII را دست‌نخورده با بایت‌های UTF-8 نگه می‌داریم
					var enc = unescape(encodeURIComponent(c));
					for (var k = 0; k < enc.length; k++) {
						out.push(enc.charCodeAt(k) & 0xFF);
					}
				}
			}
			return out;
		},

		/**
		 * جریان کدشده → آرایهٔ بایت‌ها: `%xx` و جفت‌های hex بدون `%` هر دو بایت
		 * می‌شوند، جداکننده‌ها (_ - + فاصله) فاصله می‌شوند و بقیه دور ریخته می‌شود.
		 */
		hexStream: function (src) {
			src = String(src || '');
			var out = [];
			var i = 0;
			var n = src.length;

			while (i < n) {
				var c = src.charAt(i);

				if (c === '%') {
					var pair = src.substr(i + 1, 2);
					if (/^[0-9a-fA-F]{2}$/.test(pair)) {
						out.push(parseInt(pair, 16));
						i += 3;
						continue;
					}
					i++;
					continue;
				}

				if (/[0-9a-fA-F]/.test(c) && (i + 1) < n && /[0-9a-fA-F]/.test(src.charAt(i + 1))) {
					out.push(parseInt(src.substr(i, 2), 16));
					i += 2;
					continue;
				}

				if (c === ' ' || c === '\t' || c === '+' || c === '-' || c === '_') {
					out.push(32);
					i++;
					continue;
				}

				i++;
			}

			return out;
		},

		/**
		 * بایت‌ها → متن (فقط اگر UTF-8 معتبر و نمایشی باشد).
		 * @param {number[]} bytes
		 * @param {number}   marks تعداد % در ورودی
		 * @return {string}
		 */
		bytesToText: function (bytes, marks) {
			try {
				var bin = '';
				for (var i = 0; i < bytes.length; i++) {
					bin += String.fromCharCode(bytes[i]);
				}
				var text = decodeURIComponent(escape(bin));
				if (!text) {
					return '';
				}
				if (/[\u0000-\u0008\u000B\u000C\u000E-\u001F\u007F]/.test(text)) {
					return ''; // نویسهٔ کنترلی → احتمالاً آشغال hex است
				}
				var hasHigh = /[\u0080-\uFFFF]/.test(text);
				if (!hasHigh && !(marks >= 3 && /[A-Za-z0-9]/.test(text))) {
					return ''; // خروجی لاتین فقط با نشانهٔ قویِ کدشدگی
				}
				return text;
			} catch (e) {
				return '';
			}
		},

		/** رنگ باکس عددی بر اساس مقدار: سبز (بیش از صفر) / قرمز (صفر). */
		paintStockbox: function ($card) {
			var $box = $card.find('.tbsm-var-stockbox');
			if (!$box.length) {
				return;
			}
			var v = parseInt($card.find('.tbsm-var-stock').val(), 10);
			$box
				.removeClass('tbsm-var-stockbox--in tbsm-var-stockbox--out')
				.addClass(isNaN(v) || v <= 0 ? 'tbsm-var-stockbox--out' : 'tbsm-var-stockbox--in');
		},

		/** مقدار را در ورودی همهٔ کارت‌های انتخابی (نمایشی) درج می‌کند. */
		quickSet: function () {
			var self = this;
			var raw = $('#tbsm-quickset').val();
			if (raw === '' || raw === null || isNaN(Number(raw)) || Number(raw) < 0) {
				return;
			}
			var val = Math.round(Number(raw));
			var n = 0;

			$('#tbsm-grid .tbsm-var:not(.is-hidden).is-selected').each(function () {
					var $card = $(this);
					if ($card.attr('data-mode') !== 'qty') {
						self.setCardMode($card, 'qty');
					}
					$('.tbsm-var-stock', this).val(val).removeClass('is-invalid');
					self.paintStockbox($card);
					n++;
				});

			if (n) {
				this.notice(this.i18n('fillDone').replace('%d', this.fmt(n)), 'info');
			}
		},

		refreshCounts: function () {
			var $cards = $('#tbsm-grid .tbsm-var');
			var shown = $cards.filter(':not(.is-hidden)').length;
			var selected = $cards.filter(':not(.is-hidden).is-selected').length;
			var total = $cards.length;

			$('#tbsm-counts').html(total ? (
				'<span>' + this.esc(this.i18n('shownOf')).replace('%1$d', this.fmt(shown)).replace('%2$d', this.fmt(total)) + '</span>'
				+ '<span>' + this.esc(this.i18n('selectedCount')).replace('%d', this.fmt(selected)) + '</span>'
			) : '');

			var $apply = $('#tbsm-apply');
			var $unified = $('#tbsm-unified');
			var $fill = $('#tbsm-quickset-btn');

			if (this.busy) {
				$apply.prop('disabled', true);
				$unified.prop('disabled', true);
				$fill.prop('disabled', true);
				return;
			}

			if (selected > 0) {
				// در حالت تأیید، برچسب دکمه‌ها دست‌نخورده می‌ماند
				if (!this.confirmMode) {
					$apply
						.removeClass('tisa-btn--confirm')
						.text(this.esc(this.i18n('applyBtn').replace('%d', this.fmt(selected))));
				}
				if (!this.unifiedConfirm) {
					$unified.removeClass('tisa-btn--confirm').text(this.i18n('unifiedBtn'));
				}
				$apply.prop('disabled', false);
				$fill.prop('disabled', false);
				$unified.prop('disabled', false);
			} else {
				$apply
					.prop('disabled', true)
					.removeClass('tisa-btn--confirm')
					.text(this.i18n('applyBtn').replace('%d', '—'));
				$fill.prop('disabled', true);
				$unified.prop('disabled', true);
				if (this.confirmMode) {
					this.confirmMode = false;
					clearTimeout(this.confirmTimer);
				}
				if (this.unifiedConfirm) {
					this.unifiedConfirm = false;
					clearTimeout(this.unifiedConfirmTimer);
				}
			}

			this.updateBottomHint();
		},

		/* ----------------------------------------------------------
		 * اعمال موجودی
		 * ---------------------------------------------------------- */

		apply: function () {
			var self = this;
			if (this.busy || !this.readyContext()) {
				return;
			}

			// جمع‌آوری ورودی‌های انتخابی
			var items = {};
			var bad = 0;

			$('#tbsm-grid .tbsm-var:not(.is-hidden)').each(function () {
					var $card = $(this);
					if (!$card.hasClass('is-selected')) {
						return;
					}
					var mode = $card.attr('data-mode') || 'qty';
					if (mode === 'qty') {
						var $in = $('.tbsm-var-stock', this);
						var raw = $in.val();
						var num = Number(raw);
						if (raw === '' || raw === null || isNaN(num) || num < 0) {
							$card.addClass('is-invalid');
							bad++;
							return;
						}
						items[$card.attr('data-id')] = { mode: 'qty', qty: Math.round(num) };
					} else {
						items[$card.attr('data-id')] = { mode: mode };
					}
				});

			if (bad > 0) {
				this.notice(this.i18n('invalidInputs').replace('%d', this.fmt(bad)), 'error');
				return;
			}

			var count = Object.keys(items).length;
			if (!count) {
				this.notice(this.i18n('selectAtLeastOne'), 'error');
				return;
			}

			// تأیید درون‌خطی (بدون confirm() بومی)
			var $btn = $('#tbsm-apply');
			if (!this.confirmMode) {
				this.confirmMode = true;
				$btn.addClass('tisa-btn--confirm').text(this.applyConfirmText(count));
				clearTimeout(this.confirmTimer);
				this.confirmTimer = setTimeout(function () {
					TBSM.confirmMode = false;
					TBSM.refreshCounts();
				}, 5000);
				return;
			}

			this.confirmMode = false;
			clearTimeout(this.confirmTimer);
			this.busy = true;
			$btn.prop('disabled', true).text(this.i18n('applying'));
			$('#tbsm-report').prop('hidden', true).empty();

			$.post(window.tbsmData.ajaxUrl, this.applyPayload(items))
				.done(function (res) {
					if (res && res.success) {
						self.renderReport(res.data);
					} else {
						self.notice(self.i18n('applyError').replace('%s', self.applyMsg(res.data)), 'error');
					}
				})
				.fail(function (xhr) {
					self.notice(self.i18n('applyError').replace('%s', self.applyFailMsg(xhr)), 'error');
				})
				.always(function () {
					self.busy = false;
					self.refreshCounts();
				});
		},

		/** متن تأیید دکمهٔ اعمال بر اساس حالت کار. */
		applyConfirmText: function (count) {
			var key = (this.mode === 'group') ? 'groupApplyConfirm' : 'applyConfirm';
			return this.i18n(key).replace('%d', this.fmt(count));
		},

		/** آیا بافت لازم برای اعمال آماده است؟ (محصول در حالت تکی، محدوده+مدل در حالت گروهی) */
		readyContext: function () {
			return (this.mode === 'group') ? (!!this.group && !!this.scope) : !!this.product;
		},

		/** بدنهٔ درخواست اعمال بر اساس حالت کار. */
		applyPayload: function (items) {
			var payload = {
				nonce: window.tbsmData.nonce,
				items: items
			};

			if (this.mode === 'group') {
				var scope = this.scopePayload();
				payload.action = 'tbsm_apply_scope_stock';
				payload.attr_keys = this.group.keys || [];
				payload.term_values = this.group.values || [];
				payload.scope_type = scope.scope_type;
				payload.product_ids = scope.product_ids;
				payload.category_id = scope.category_id;
				payload.sku = scope.sku;
				payload.sku_mode = scope.sku_mode;
			} else {
				payload.action = 'tbsm_apply_stock';
				payload.product_id = this.product.id;
			}

			return payload;
		},

		/** پیام خطای درخواست اعمال از پاسخ (JSON یا HTTP). */
		applyFailMsg: function (xhr) {
			var msg = '';
			try {
				var parsed = xhr && xhr.responseJSON;
				if (parsed) {
					msg = this.applyMsg(parsed.data);
				}
			} catch (e) {
				// پاسخ غیر-JSON
			}
			return msg || ('HTTP ' + ((xhr && xhr.status) || 0));
		},

		/**
		 * استخراج پیام خطا از دادهٔ پاسخ (شکل‌های مختلف wp_send_json_error).
		 * @param {*} data
		 * @return {string}
		 */
		applyMsg: function (data) {
			if (typeof data === 'string' && data !== '') {
				// مثال: «-1» از check_ajax_referer یا پیام فارسی سرور
				return data;
			}
			if (data && typeof data === 'object') {
				if (data.error) {
					return data.error;
				}
				if (data.message) {
					return data.message;
				}
			}
			return this.i18n('errorApplyEmpty');
		},

		/**
		 * اعمال «موجودی همگانی»: یک عدد، روی همهٔ متغیرهای انتخاب‌شده.
		 * همان مسیر سروری apply را با مقادیر یکسان می‌فرستد.
		 */
		applyUnified: function () {
			var self = this;
			if (this.busy || !this.readyContext()) {
				return;
			}

			var $input = $('#tbsm-quickset');
			var raw = $input.val();
			var num = Number(raw);
			if (raw === '' || raw === null || !isFinite(num) || num < 0 || Math.floor(num) !== num) {
				this.notice(this.i18n('unifiedInvalid'), 'error');
				$input.trigger('focus');
				return;
			}
			var val = Math.round(num);

			var ids = [];
			$('#tbsm-grid .tbsm-var:not(.is-hidden).is-selected').each(function () {
				ids.push($(this).attr('data-id'));
			});
			if (!ids.length) {
				this.notice(this.i18n('selectAtLeastOne'), 'error');
				return;
			}

			// تأیید درون‌خطی (بدون confirm() بومی)
			var $btn = $('#tbsm-unified');
			if (!this.unifiedConfirm) {
				this.unifiedConfirm = true;
				$btn.addClass('tisa-btn--confirm')
					.text(this.i18n('unifiedConfirm').replace('%1$s', this.fmt(val)).replace('%2$d', this.fmt(ids.length)));
				clearTimeout(this.unifiedConfirmTimer);
				this.unifiedConfirmTimer = setTimeout(function () {
					TBSM.unifiedConfirm = false;
					TBSM.refreshCounts();
				}, 5000);
				return;
			}

			this.unifiedConfirm = false;
			clearTimeout(this.unifiedConfirmTimer);
			this.busy = true;
			$btn.prop('disabled', true).text(this.i18n('applying'));
			$('#tbsm-report').prop('hidden', true).empty();

			// همهٔ انتخابی‌ها را به حالت عددی با همین مقدار نشان می‌دهیم
			var items = {};
			for (var i = 0; i < ids.length; i++) {
				items[ids[i]] = { mode: 'qty', qty: val };
			}
			$('#tbsm-grid .tbsm-var:not(.is-hidden).is-selected').each(function () {
				var $card = $(this);
				if ($card.attr('data-mode') !== 'qty') {
					self.setCardMode($card, 'qty');
				}
				$('.tbsm-var-stock', this).val(val).removeClass('is-invalid');
				self.paintStockbox($card);
			});

			$.post(window.tbsmData.ajaxUrl, this.applyPayload(items))
				.done(function (res) {
					if (res && res.success) {
						self.renderReport(res.data);
					} else {
						self.notice(self.i18n('applyError').replace('%s', self.applyMsg(res.data)), 'error');
					}
				})
				.fail(function (xhr) {
					self.notice(self.i18n('applyError').replace('%s', self.applyFailMsg(xhr)), 'error');
				})
				.always(function () {
					self.busy = false;
					self.refreshCounts();
				});
		},

		/** گزارش نتیجهٔ اعمال + به‌روزرسانی کارت‌ها. */
		renderReport: function (data) {
			var rows = '';
			for (var i = 0; i < data.report.length; i++) {
				var it = data.report[i];
				var mode = (it.mode === 'in_stock' || it.mode === 'out_of_stock') ? it.mode : 'qty';

				// تفاوت: عددی «۰ ← ۷» یا وضعیت «موجود ← ناموجود»
				// فلش از «قدیمی» (راست) به «جدید» (چپ)؛ جهت راست‌به‌چپِ صفحه
				var diff = (mode === 'qty')
					? this.fmt(it.from) + ' \u2190 ' + this.fmt(it.to)
					: this.statusLabel(it.from_status) + ' \u2190 ' + this.statusLabel(it.to_status);

				// در حالت گروهی، نام محصول والد هم پشت نام متغیر می‌آید تا ردیف‌ها قابل تفکیک باشند.
				var rowLabel = it.product ? (it.product + ' — ' + it.name) : it.name;

				rows += '<div class="tbsm-report-row">'
					+ '<span>' + this.esc(rowLabel) + '</span>'
					+ (it.sku ? '<span class="tbsm-report-sku">' + this.esc(it.sku) + '</span>' : '')
					+ '<span class="tbsm-report-diff">' + this.esc(diff) + '</span>'
					+ '</div>';

					// به‌روزرسانی کارت: حالت، بج، مقدار ورودی
					var $card = $('#tbsm-grid .tbsm-var[data-id="' + it.id + '"]');
					if ($card.length) {
						if (mode === 'qty') {
							$card.attr('data-manage', '1'); // با اعمال، ردیابی روشن شد
						}
						this.setCardMode($card, mode);
						if (mode === 'qty') {
							$card.find('.tbsm-var-stock').val(it.to).removeClass('is-invalid');
							$card.find('[data-badge]')
								.removeClass('tbsm-badge--ok tbsm-badge--out')
								.addClass(it.to > 0 ? 'tbsm-badge--ok' : 'tbsm-badge--out')
								.text(this.i18n('stockLabel') + ': ' + this.fmt(it.to));
							this.paintStockbox($card);
						}
					}
			}

			var html = '<div class="tbsm-report-title">'
				+ this.esc(this.i18n('reportTitle').replace('%d', this.fmt(data.applied)))
				+ '</div>'
				+ '<div class="tbsm-report-list">' + rows + '</div>';

			if (data.parent_status) {
				var label = data.parent_status === 'instock'
					? this.i18n('inStockLabel')
					: this.i18n('outOfStockLabel');
				html += '<div>' + this.esc(this.i18n('parentSynced').replace('%s', label)) + '</div>';
			} else if (data.parents_synced) {
				html += '<div>' + this.esc(this.i18n('parentsSynced').replace('%d', this.fmt(data.parents_synced))) + '</div>';
			}

			if (data.enabled_manage) {
				html += '<div class="tbsm-report-note">' + this.esc(this.i18n('enabledManage').replace('%d', this.fmt(data.enabled_manage))) + '</div>';
			}

			if (data.errors && data.errors.length) {
				var errs = '';
				for (var j = 0; j < data.errors.length; j++) {
					errs += '<div>• ' + this.esc(data.errors[j]) + '</div>';
				}
				html += '<div class="tbsm-report-errors">'
					+ this.esc(this.i18n('errorCount').replace('%d', this.fmt(data.errors.length)))
					+ errs
					+ '</div>';
			}

			var $report = $('#tbsm-report').prop('hidden', false).html(html);
			$report[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}
	};

	// در دسترس گذاشتن کنترلر برای پیش‌نمایش/دیباگ (بدون تغییر رفتار افزونه).
	window.TBSM = TBSM;

	$(function () {
		TBSM.init();
	});
})(jQuery);
