/**
 * TisaCase Bulk Stock Manager — Client Controller
 * جست‌وجوی محصول ← کارت‌های متغیر ← فیلتر دسته ← ویرایش موجودی ← اعمال.
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

		/* ----------------------------------------------------------
		 * ابزارها
		 * ---------------------------------------------------------- */

		i18n: function (key) {
			var t = window.tbsmData && window.tbsmData.i18n ? window.tbsmData.i18n : {};
			return t[key] || key;
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
				$(this).closest('.tbsm-var').removeClass('is-invalid');
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
			this.confirmMode = false;
			clearTimeout(this.confirmTimer);
			this.unifiedConfirm = false;
			clearTimeout(this.unifiedConfirmTimer);

			$('#tbsm-selected').prop('hidden', true).empty();
			$('#tbsm-grid').prop('hidden', true).empty();
			$('#tbsm-report').prop('hidden', true).empty();
			$('#tbsm-counts').empty();
			$('#tbsm-cat-select').prop('disabled', true).html('<option value="all">—</option>');
			$('#tbsm-step-category').addClass('tbsm-step-locked');
			$('#tbsm-step-cards').addClass('tbsm-step-locked');
			$('#tbsm-apply').prop('disabled', true).removeClass('tisa-btn--confirm').text(this.i18n('applyBtn').replace('%d', '—'));
			$('#tbsm-quickset').val('');
			$('#tbsm-quickset-btn').prop('disabled', true);
			$('#tbsm-unified').prop('disabled', true).removeClass('tisa-btn--confirm').text(this.i18n('unifiedBtn'));
			$('#tbsm-bottombar-hint').text(this.i18n('selectAtLeastOne'));
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
				html += '<optgroup label="' + this.esc(cat.label) + '">';
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
				$grid.prop('hidden', false).html('<div class="tbsm-grid-empty">—</div>');
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

				html += '<div class="tbsm-var is-selected" data-id="' + v.id + '" data-cat="' + this.esc(v.cat) + '" data-mode="' + mode + '" data-manage="' + (v.manage_stock ? 1 : 0) + '">'
					+ '<span class="tbsm-var-check">' + this.checkIcon + '</span>'
					+ '<span class="tbsm-var-name">' + this.esc(v.name) + '</span>'
					+ (v.sku ? '<span class="tbsm-var-sku">' + this.esc(v.sku) + '</span>' : '<span class="tbsm-var-sku">#' + v.id + '</span>')
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

			$('#tbsm-bottombar-hint').text(selected > 0 ? this.i18n('bottomHint') : this.i18n('selectAtLeastOne'));
		},

		/* ----------------------------------------------------------
		 * اعمال موجودی
		 * ---------------------------------------------------------- */

		apply: function () {
			var self = this;
			if (this.busy || !this.product) {
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
				$btn.addClass('tisa-btn--confirm').text(this.i18n('applyConfirm').replace('%d', this.fmt(count)));
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

			$.post(window.tbsmData.ajaxUrl, {
				action: 'tbsm_apply_stock',
				nonce: window.tbsmData.nonce,
				product_id: this.product.id,
				items: items
			})
				.done(function (res) {
					if (res && res.success) {
						self.renderReport(res.data);
					} else {
						self.notice(self.i18n('applyError').replace('%s', self.applyMsg(res.data)), 'error');
					}
				})
				.fail(function (xhr) {
					var msg = '';
					try {
						var parsed = xhr.responseJSON;
						if (parsed) {
							msg = self.applyMsg(parsed.data);
						}
					} catch (e) {
						// پاسخ غیر-JSON
					}
					self.notice(self.i18n('applyError').replace('%s', msg || 'HTTP ' + (xhr.status || 0)), 'error');
				})
				.always(function () {
					self.busy = false;
					self.refreshCounts();
				});
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
			if (this.busy || !this.product) {
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
			});

			$.post(window.tbsmData.ajaxUrl, {
				action: 'tbsm_apply_stock',
				nonce: window.tbsmData.nonce,
				product_id: this.product.id,
				items: items
			})
				.done(function (res) {
					if (res && res.success) {
						self.renderReport(res.data);
					} else {
						self.notice(self.i18n('applyError').replace('%s', self.applyMsg(res.data)), 'error');
					}
				})
				.fail(function (xhr) {
					var msg = '';
					try {
						var parsed = xhr.responseJSON;
						if (parsed) {
							msg = self.applyMsg(parsed.data);
						}
					} catch (e) {
						// پاسخ غیر-JSON
					}
					self.notice(self.i18n('applyError').replace('%s', msg || 'HTTP ' + (xhr.status || 0)), 'error');
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

				rows += '<div class="tbsm-report-row">'
					+ '<span>' + this.esc(it.name) + '</span>'
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

	$(function () {
		TBSM.init();
	});
})(jQuery);
