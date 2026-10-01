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

			$('#tbsm-grid')
				.on('click', function (e) {
					var $card = $(e.target).closest('.tbsm-var');
					if (!$card.length) {
						return;
					}
					if ($(e.target).hasClass('tbsm-var-stock')) {
						return; // ویرایش ورودی عدد
					}
					self.toggleCard($card);
				})
				.on('input', '.tbsm-var-stock', function () {
					$(this).closest('.tbsm-var').removeClass('is-invalid');
				});

			$('#tbsm-quickset-btn').on('click', function () {
				self.quickSet();
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

			this.clearSelection(true);
			$('#tbsm-results').prop('hidden', true).empty();
			this.notice('');

			$.post(window.tbsmData.ajaxUrl, {
				action: 'tbsm_load_product',
				nonce: window.tbsmData.nonce,
				product_id: id
			})
				.done(function (res) {
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

			$('#tbsm-selected').prop('hidden', true).empty();
			$('#tbsm-grid').prop('hidden', true).empty();
			$('#tbsm-report').prop('hidden', true).empty();
			$('#tbsm-counts').empty();
			$('#tbsm-cat-select').prop('disabled', true).html('<option value="all">—</option>');
			$('#tbsm-step-category').addClass('tbsm-step-locked');
			$('#tbsm-step-cards').addClass('tbsm-step-locked');
			$('#tbsm-apply').prop('disabled', true).removeClass('tisa-btn--confirm').text(this.i18n('applyBtn').replace('%d', '—'));
			$('#tbsm-quickset-btn').prop('disabled', true);
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
				var stockBadge = v.status === 'instock'
					? '<span class="tbsm-badge tbsm-badge--ok" data-badge>' + this.esc(this.i18n('stockLabel')) + ': ' + this.fmt(v.stock) + '</span>'
					: '<span class="tbsm-badge tbsm-badge--out" data-badge>' + this.esc(this.i18n('stockLabel')) + ': ' + this.fmt(v.stock) + '</span>';
				var manageNote = v.manage_stock ? ''
					: '<span class="tbsm-var-manage" title="' + this.esc(this.i18n('manageOffNote')) + '">' + this.esc(this.i18n('manageOffNote')) + '</span>';

				html += '<div class="tbsm-var is-selected" data-id="' + v.id + '" data-cat="' + this.esc(v.cat) + '">'
					+ '<span class="tbsm-var-check">' + this.checkIcon + '</span>'
					+ '<span class="tbsm-var-name">' + this.esc(v.name) + '</span>'
					+ (v.sku ? '<span class="tbsm-var-sku">' + this.esc(v.sku) + '</span>' : '<span class="tbsm-var-sku">#' + v.id + '</span>')
					+ '<span class="tbsm-var-foot">' + stockBadge + manageNote + '</span>'
					+ '<input type="number" class="tbsm-var-stock" min="0" step="1" inputmode="numeric" value="' + v.stock + '" aria-label="' + this.esc(this.i18n('stockLabel')) + '">'
					+ '</div>';
			}

			$grid.prop('hidden', false).html(html);
		},

		toggleCard: function ($card) {
			$card.toggleClass('is-selected');
			$card.attr('data-touched', '1');
			this.refreshCounts();
		},

		/** مقدار را در ورودی همهٔ کارت‌های انتخابی (نمایشی) درج می‌کند. */
		quickSet: function () {
			var raw = $('#tbsm-quickset').val();
			if (raw === '' || raw === null || isNaN(Number(raw)) || Number(raw) < 0) {
				return;
			}
			var val = Math.round(Number(raw));
			var n = 0;

			$('#tbsm-grid .tbsm-var:not(.is-hidden).is-selected').each(function () {
				$('.tbsm-var-stock', this).val(val).removeClass('is-invalid');
				n++;
			});

			if (n) {
				this.notice(this.i18n('selectedCount').replace('%d', this.fmt(n)), 'info');
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
			if (this.busy) {
				return;
			}
			if (selected > 0) {
				$apply
					.prop('disabled', false)
					.removeClass('tisa-btn--confirm')
					.text(this.esc(this.i18n('applyBtn').replace('%d', this.fmt(selected))));
				$('#tbsm-quickset-btn').prop('disabled', false);
			} else {
				$apply.prop('disabled', true).text(this.i18n('applyBtn').replace('%d', '—'));
			}
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
				var $in = $('.tbsm-var-stock', this);
				var raw = $in.val();
				var num = Number(raw);
				if (raw === '' || raw === null || isNaN(num) || num < 0) {
					$card.addClass('is-invalid');
					bad++;
					return;
				}
				items[$card.attr('data-id')] = Math.round(num);
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
						var msg = res && res.data && res.data.error ? res.data.error : self.i18n('errorApplyEmpty');
						self.notice(self.i18n('applyError').replace('%s', msg), 'error');
					}
				})
				.fail(function (xhr) {
					var msg = '';
					try {
						var parsed = xhr.responseJSON;
						if (parsed && parsed.data && parsed.data.error) {
							msg = parsed.data.error;
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

		/** گزارش نتیجهٔ اعمال + به‌روزرسانی بج‌های کارت‌ها. */
		renderReport: function (data) {
			var rows = '';
			for (var i = 0; i < data.report.length; i++) {
				var it = data.report[i];
				rows += '<div class="tbsm-report-row">'
					+ '<span>' + this.esc(it.name) + '</span>'
					+ (it.sku ? '<span class="tbsm-report-sku">' + this.esc(it.sku) + '</span>' : '')
					+ '<span class="tbsm-report-diff">' + this.fmt(it.from) + ' \u2192 ' + this.fmt(it.to) + '</span>'
					+ '</div>';

				// به‌روزرسانی بج کارت
				var $card = $('#tbsm-grid .tbsm-var[data-id="' + it.id + '"]');
				var $badge = $card.find('[data-badge]');
				$badge
					.removeClass('tbsm-badge--ok tbsm-badge--out')
					.addClass(it.to > 0 ? 'tbsm-badge--ok' : 'tbsm-badge--out')
					.text(this.i18n('stockLabel') + ': ' + this.fmt(it.to));
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
