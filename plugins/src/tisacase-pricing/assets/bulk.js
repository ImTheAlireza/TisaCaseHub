/**
 * TisaCase Pricing — تب تغییر گروهی و گزارش (پیش‌نمایش/اجرا/بازگردانی/ادامه)
 */
/* global TCP_BULK, jQuery, confirm, prompt */
(function ($) {
	'use strict';

	var D = window.TCP_BULK || {};
	var A = D.actions || {};

	/* ---------------- ابزار عمومی ---------------- */

	/** حذف جداکننده و تبدیل ارقام فارسی/عربی به انگلیسی. */
	function normNum(v) {
		if (v === null || v === undefined) { return ''; }
		var s = String(v).replace(/[\u0660-\u0669\u06F0-\u06F9]/g, function (c) {
			return String(c.charCodeAt(0) & 0xf);
		});
		return s.replace(/[,٬،\s]/g, '');
	}

	function esc(s) {
		return $('<div/>').text(s == null ? '' : String(s)).html();
	}

	function isNum(n) {
		var s = normNum(n);
		return s !== '' && isFinite(Number(s));
	}

	/* ---------------- دیالوگ ---------------- */

	function getDialog() {
		var $d = $('#tcp-dialog');
		if (!$d.length) {
			$d = $('<div class="tcp-dialog-backdrop" id="tcp-dialog" style="display:none">' +
				'<div class="tcp-dialog"><h3 id="tcp-dialog-title"></h3>' +
				'<div id="tcp-dialog-body" class="tcp-dialog-body"></div>' +
				'<div class="tcp-dialog-actions">' +
				'<button type="button" class="button button-primary" id="tcp-dialog-ok">تأیید</button>' +
				'<button type="button" class="button" id="tcp-dialog-cancel">انصراف</button>' +
				'</div></div></div>').appendTo('body');
		}
		return $d;
	}

	/**
	 * بازکردن دیالوگ؛ در صورت تأیید resolve با خروجی readValue (پیش‌فرض true) و در صورت انصراف null.
	 */
	function openDialog(opts) {
		var $d = getDialog();
		var $ok = $d.find('#tcp-dialog-ok');
		var $cancel = $d.find('#tcp-dialog-cancel');
		$d.find('#tcp-dialog-title').html(opts.title || '');
		$d.find('#tcp-dialog-body').html(opts.body || '');
		$ok.text(opts.okText || 'تأیید');
		$ok.attr('class', 'button button-large ' + (opts.okClass || 'button-primary'));
		$cancel.toggle(!(opts.noCancel));
		$d.show();

		return new Promise(function (resolve) {
			function done(val) {
				$ok.off('click', onOk);
				$cancel.off('click', onCancel);
				$d.hide();
				resolve(val);
			}
			function onOk() {
				var v = opts.readValue ? opts.readValue() : true;
				if (v === false) { return; } // خواندن نامعتبر؛ دیالوگ باز می‌ماند
				done(v);
			}
			function onCancel() { done(null); }
			$ok.one('click', onOk);
			$cancel.one('click', onCancel);
			$d.find('#tcp-dialog-cancel').css('display', opts.noCancel ? 'none' : '');
			setTimeout(function () { $d.find('input:first,button:first').focus(); }, 30);
		});
	}

	function inform(title, msg) {
		return openDialog({ title: title, body: '<p>' + msg + '</p>', okText: 'باشه', noCancel: true });
	}

	/* =====================================================================
	 * صفحهٔ اصلی — مدیریت گروهی قیمت
	 * ===================================================================== */

	if (D.tab === 'bulk') {

		var running = false;
		var stopNow = false;
		var runId = 0;
		var previewValid = false;
		var previewToken = '';
		var previewInfo = null;
		var totals = { parents: 0, updated: 0, skipped: 0, errors: 0 };
		var nameSearchState = { term: '', page: 0, pages: 0, total: 0, loaded: 0, bucket: '' };
		var nameSearchGeneration = 0;
		var nameSearchXhr = null;
		var nameSearchCurrentBucket = '';
		var selectedProducts = {
			retail: { items: Object.create(null), selected: Object.create(null), order: [] },
			wholesale: { items: Object.create(null), selected: Object.create(null), order: [] }
		};

		function targetMode() {
			return $('input[name="tcp_target"]:checked').val() || 'category';
		}
		function targetType() {
			return targetMode() === 'category' ? 'category' : 'products';
		}
		function currentOp() {
			return $('#tcp-op').val();
		}
		function opMeta(slug) {
			return (D.ops && D.ops[slug]) || { label: slug, kind: '', group: '' };
		}
		function isWholesaleOp() {
			return opMeta(currentOp()).group === 'wholesale';
		}
		function isSaleOp() {
			return opMeta(currentOp()).group === 'sale';
		}
		function selectionBucket() {
			return isWholesaleOp() ? 'wholesale' : 'retail';
		}
		function valueKind() {
			return opMeta(currentOp()).kind; // percent | amount | set | none
		}


		function productTypeLabel(type) {
			var labels = { simple: 'ساده', variable: 'متغیر', grouped: 'گروهی', external: 'خارجی' };
			return labels[String(type || '')] || '—';
		}

		function productSelection(bucket) {
			return selectedProducts[bucket || selectionBucket()];
		}

		function selectedProductIds(bucket) {
			var state = productSelection(bucket);
			return state.order.filter(function (id) {
				return !!state.items[id] && state.selected[id] !== false;
			}).map(function (id) {
				return String(id);
			});
		}

		function updateSelectedProductCount() {
			var state = productSelection();
			var total = state.order.length;
			var selected = selectedProductIds().length;
			var $selectAll = $('#tcp-product-select-all');

			$('#tcp-selected-product-count').text(selected + ' انتخاب‌شده از ' + total + ' محصول');
			$('#tcp-selected-product-badge').text(selected + ' انتخاب‌شده');
			$('#tcp-product-selection-empty').toggle(total === 0);
			$('#tcp-product-selection-table-wrap').toggle(total > 0);
			$('#tcp-clear-products').prop('disabled', total === 0 || running);
			$selectAll
				.prop('checked', total > 0 && selected === total)
				.prop('indeterminate', selected > 0 && selected < total)
				.prop('disabled', total === 0 || running);
		}

		function renderSelectedProducts() {
			var state = productSelection();
			var $body = $('#tcp-selected-products tbody').empty();

			state.order.forEach(function (id) {
				var item = state.items[id];
				if (!item) { return; }

				var name = String(item.name || ('محصول #' + id));
				var sku = String(item.sku || '').trim();
				var categories = String(item.categories || '').trim();
				var $check = $('<input type="checkbox" class="tcp-selected-product-checkbox">')
					.attr('data-product-id', id)
					.attr('aria-label', 'انتخاب ' + name)
					.prop('checked', state.selected[id] !== false)
					.prop('disabled', running);
				var $image;
				if (item.image_url) {
					$image = $('<img class="tcp-product-thumb" alt="">').attr('src', item.image_url).attr('loading', 'lazy');
				} else {
					$image = $('<span class="tcp-product-thumb tcp-product-thumb--empty" aria-hidden="true">□</span>');
				}
				var $product = $('<div class="tcp-product-cell"></div>')
					.append($image)
					.append($('<strong class="tcp-product-name"></strong>').text(name));
				var $sku = sku ? $('<code class="tcp-product-sku"></code>').text(sku) : $('<span class="tcp-product-meta"></span>').text('بدون SKU');
				var $identity = $('<div class="tcp-product-identity"></div>')
					.append($sku)
					.append($('<small></small>').attr('dir', 'ltr').text('#' + id));
				var $remove = $('<button type="button" class="button-link tcp-product-remove">حذف</button>')
					.attr('aria-label', 'حذف ' + name + ' از فهرست');
				var $row = $('<tr></tr>')
					.attr('data-product-id', id)
					.append($('<td class="tcp-product-check"></td>').append($check))
					.append($('<td></td>').append($product))
					.append($('<td></td>').append($identity))
					.append($('<td class="tcp-product-category"></td>').text(categories || '—'))
					.append($('<td class="tcp-product-type"></td>').text(productTypeLabel(item.type)))
					.append($('<td class="tcp-product-actions"></td>').append($remove));
				$body.append($row);
			});

			updateSelectedProductCount();
		}

		/** افزودن نتایج جستجو یا انتخاب دستی به فهرست مشترک محصولات. */
		function addProductItems(items, bucket, forceSelect) {
			var state = productSelection(bucket);
			var changed = false;

			(items || []).forEach(function (item) {
				var numericId = parseInt(item && item.id, 10);
				if (!numericId || numericId < 1) { return; }
				var id = String(numericId);
				if (!Object.prototype.hasOwnProperty.call(state.items, id)) {
					state.order.push(id);
					state.selected[id] = true;
				} else if (forceSelect) {
					state.selected[id] = true;
				}
				var previous = state.items[id] || {};
				var merged = $.extend({}, previous, item, { id: numericId });
				['sku', 'categories', 'type', 'image_url'].forEach(function (key) {
					if (!item[key] && previous[key]) { merged[key] = previous[key]; }
				});
				if (forceSelect && previous.name) { merged.name = previous.name; }
				state.items[id] = merged;
				changed = true;
			});

			if (bucket === selectionBucket()) {
				renderSelectedProducts();
			}
			if (changed) { invalidatePreview(); }
		}

		function addManualProductsFromSelect(event) {
			if (running) { return; }
			var $select = $(event.currentTarget);
			var ids = ($select.val() || []).map(String);
			if (!ids.length) { return; }

			var items = ids.map(function (id) {
				var $option = $select.find('option').filter(function () { return String(this.value) === id; }).first();
				return {
					id: parseInt(id, 10),
					name: $.trim($option.text()).replace(/\s+\(#\d+.*\)$/, '') || ('محصول #' + id),
					sku: '',
					categories: '',
					type: '',
					image_url: ''
				};
			});

			addProductItems(items, $select.attr('id') === 'tcp-wholesale-products' ? 'wholesale' : 'retail', true);
			// سلکت۲ در این بخش فقط نقش جستجوی افزودن را دارد؛ فهرست نهایی را جدول مدیریت می‌کند.
			$select.val(null).trigger('change.select2');
		}

		function resetNameSearchDisplay(message) {
			nameSearchGeneration++;
			if (nameSearchXhr) {
				nameSearchXhr.abort();
				nameSearchXhr = null;
			}
			nameSearchState = { term: '', page: 0, pages: 0, total: 0, loaded: 0, bucket: '' };
			$('#tcp-name-search-status').text(message || '');
			$('#tcp-name-load-more').hide().prop('disabled', false);
			$('#tcp-name-search-button').prop('disabled', false);
		}

		function renderNameSearchItems(items, bucket, page) {
			if (1 === page) { nameSearchState.loaded = 0; }
			nameSearchState.loaded += (items || []).length;
			addProductItems(items, bucket, false);

			var shown = Math.min(nameSearchState.loaded, nameSearchState.total);
			var status = 'پیدا شد: ' + nameSearchState.total + ' محصول؛ ' + shown + ' مورد به فهرست زیر اضافه شده است.';
			if (shown < nameSearchState.total) {
				status += ' برای افزودن صفحهٔ بعدی، دکمهٔ «نمایش موارد بعدی» را بزن.';
			}
			$('#tcp-name-search-status').text(status);
			$('#tcp-name-load-more')
				.toggle(shown < nameSearchState.total)
				.text('نمایش ' + Math.min(100, nameSearchState.total - shown) + ' مورد بعدی');
		}

		function searchProductsByName(page) {
			if (running) { return; }
			var bucket = selectionBucket();
			var term = page > 1 ? nameSearchState.term : String($('#tcp-name-search-term').val() || '').trim();
			var requestId = ++nameSearchGeneration;
			if (nameSearchXhr) {
				nameSearchXhr.abort();
				nameSearchXhr = null;
			}
			if (term.length < 2) {
				nameSearchState = { term: '', page: 0, pages: 0, total: 0, loaded: 0, bucket: bucket };
				$('#tcp-name-search-status').text('برای جستجو حداقل دو حرف بنویس.');
				$('#tcp-name-load-more').hide().prop('disabled', false);
				$('#tcp-name-search-button').prop('disabled', false);
				return;
			}

			var filters = filtersPayload();
			if (page === 1) {
				nameSearchState = { term: term, page: 0, pages: 0, total: 0, loaded: 0, bucket: bucket };
			}

			$('#tcp-name-search-status').text('در حال جستجو در نام محصولات...');
			$('#tcp-name-search-button').prop('disabled', true);
			$('#tcp-name-load-more').prop('disabled', true);
			nameSearchXhr = $.post(D.ajax, {
				action: A.nameSearch,
				nonce: D.nonce,
				term: term,
				page: page,
				filters: JSON.stringify(filters),
				wholesale_only: (isWholesaleOp() || filters.only_wholesale) ? '1' : '0'
			}, null, 'json');

			nameSearchXhr.done(function (r) {
				if (requestId !== nameSearchGeneration || bucket !== selectionBucket()) { return; }
				if (!r || !r.success) {
					$('#tcp-name-search-status').text((r && r.data && r.data.message) || 'جستجو انجام نشد.');
					return;
				}
				var data = r.data || {};
				nameSearchState.term = term;
				nameSearchState.page = Number(data.page || page);
				nameSearchState.pages = Number(data.pages || 0);
				nameSearchState.total = Number(data.total || 0);
				nameSearchState.bucket = bucket;
				if (!nameSearchState.total) {
					nameSearchState.loaded = 0;
					$('#tcp-name-search-status').text('محصولی با این عبارت پیدا نشد.');
					$('#tcp-name-load-more').hide();
					return;
				}
				renderNameSearchItems(data.items || [], bucket, page);
			});

			nameSearchXhr.fail(function (xhr, status) {
				if (status === 'abort' || requestId !== nameSearchGeneration) { return; }
				var message = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'ارتباط با سرور برای جستجو برقرار نشد.';
				$('#tcp-name-search-status').text(message);
			});

			nameSearchXhr.always(function () {
				if (requestId !== nameSearchGeneration) { return; }
				nameSearchXhr = null;
				$('#tcp-name-search-button').prop('disabled', false);
				$('#tcp-name-load-more').prop('disabled', false);
			});
		}

		function filtersPayload() {
			var f = {
				types: $('#tcp-filter-types').val() || [],
				statuses: $('#tcp-filter-statuses').val() || [],
				only_sale: $('#tcp-filter-only-sale').is(':checked'),
				only_wholesale: $('#tcp-filter-only-wholesale').is(':checked'),
				price_min: $('#tcp-price-min').val() ? normNum($('#tcp-price-min').val()) : null,
				price_max: $('#tcp-price-max').val() ? normNum($('#tcp-price-max').val()) : null
			};
			if (f.price_min === '') { f.price_min = null; }
			if (f.price_max === '') { f.price_max = null; }
			return f;
		}

		function commonPayload(extra) {
			var p = {
				action: '',
				nonce: D.nonce,
				target_type: targetType(),
				category_ids: ($('#tcp-cats').val() || []).join(','),
				product_ids: selectedProductIds().join(','),
				include_children: $('#tcp-children').is(':checked') ? '1' : '0',
				operation: currentOp(),
				value: $('#tcp-value').val() || '',
				round_mode: $('#tcp-round-jitter').is(':checked') ? 'jitter' : ($('#tcp-round').is(':checked') ? 'round' : 'none'),
				filters: JSON.stringify(filtersPayload())
			};
			return $.extend(p, extra || {});
		}

		function invalidatePreview() {
			if (running) { return; }
			previewValid = false;
			previewToken = '';
			previewInfo = null;
			$('#tcp-start').prop('disabled', true);
			$('#tcp-schedule').prop('disabled', true);
			$('#tcp-preview-box').hide();
			$('#tcp-preview-summary').empty();
			$('#tcp-sample-box tbody').empty();
		}

		function updateFilterVisibility() {
			$('#tcp-sale-only-row').toggle(isSaleOp());
			$('#tcp-wholesale-only-row').toggle(isWholesaleOp());
		}

		function updateProductSearch() {
			var bucket = selectionBucket();
			if (nameSearchCurrentBucket && nameSearchCurrentBucket !== bucket) {
				resetNameSearchDisplay('نوع قیمت عوض شد؛ برای جستجوی محصولاتِ این نوع دوباره جستجو کن.');
			}
			nameSearchCurrentBucket = bucket;
			if (isWholesaleOp()) {
				$('#tcp-retail-product-search').hide();
				$('#tcp-wholesale-product-search').show();
			} else {
				$('#tcp-wholesale-product-search').hide();
				$('#tcp-retail-product-search').show();
			}
			renderSelectedProducts();
			$(document.body).trigger('wc-enhanced-select-init');
		}

		function updateTarget() {
			var mode = targetMode();
			if (mode === 'category') {
				$('#tcp-product-box').hide();
				$('#tcp-cat-box').show();
			} else {
				$('#tcp-cat-box').hide();
				$('#tcp-product-box').show();
				$('#tcp-name-search-panel').toggle(mode === 'name');
				$('#tcp-direct-search-panel').toggle(mode === 'direct');
				updateProductSearch();
			}
			$('.tcp-target-mode').removeClass('is-active');
			$('input[name="tcp_target"]:checked').closest('.tcp-target-mode').addClass('is-active');
			invalidatePreview();
		}

		function updateOpUi() {
			var kind = valueKind();
			updateProductSearch();
			updateFilterVisibility();
			invalidatePreview();
			var m = opMeta(currentOp());
			$('#tcp-round-box').toggle(kind === 'percent' || kind === 'amount');
			$('#tcp-round-jitter-row').toggle(!!m.cap100);
			if (!m.cap100) { $('#tcp-round-jitter').prop('checked', false); }
			if (kind === 'none') { $('#tcp-value-box').hide(); return; }
			$('#tcp-value-box').show();
			if (kind === 'percent') {
				$('#tcp-value-label').text('مقدار درصد');
				$('#tcp-unit').text('%');
				$('#tcp-value').attr('placeholder', 'مثلاً 10');
			} else if (kind === 'set') {
				$('#tcp-value-label').text('قیمت موردنظر');
				$('#tcp-unit').text(D.currency || 'واحد پول');
				$('#tcp-value').attr('placeholder', 'مثلاً 688000');
			} else {
				$('#tcp-value-label').text('مبلغ تغییر');
				$('#tcp-unit').text(D.currency || 'واحد پول');
				$('#tcp-value').attr('placeholder', 'مثلاً 50000');
			}
		}

		function lockUI(v) {
			$('#tcp-preview').prop('disabled', v);
			$('#tcp-start').prop('disabled', v ? true : !previewValid);
			$('#tcp-schedule').prop('disabled', v ? true : !previewValid);
			$('input[name="tcp_target"],#tcp-children,#tcp-op,#tcp-value,#tcp-round,#tcp-round-jitter').prop('disabled', v);
			$('#tcp-cats,#tcp-products,#tcp-wholesale-products,#tcp-filter-types,#tcp-filter-statuses,#tcp-price-min,#tcp-price-max,#tcp-filter-only-sale,#tcp-filter-only-wholesale')
				.prop('disabled', v);
			$('#tcp-name-search-term,#tcp-name-search-button,#tcp-name-load-more,#tcp-clear-products,#tcp-product-select-all').prop('disabled', v);
			$('#tcp-selected-products .tcp-selected-product-checkbox,#tcp-selected-products .tcp-product-remove').prop('disabled', v);
			$('#tcp-cats,#tcp-products,#tcp-wholesale-products,#tcp-filter-types,#tcp-filter-statuses').trigger('change.select2');
			$('#tcp-stop').toggle(v);
			updateSelectedProductCount();
		}

		function resetProgress() {
			stopNow = false;
			totals = { parents: 0, updated: 0, skipped: 0, errors: 0 };
			$('#tcp-parent-count,#tcp-updated-count,#tcp-skipped-count,#tcp-error-count').text('0');
			$('#tcp-errors').hide().empty();
			$('#tcp-bar').css('width', '0%');
			$('#tcp-progress').show();
			$('#tcp-busy-note').hide();
		}

		function showFinal(msg, ok) {
			running = false;
			$('#tcp-status').html('<strong style="color:' + (ok ? '#008a20' : '#b32d2e') + '">' + esc(msg) + '</strong>');
			if (ok) { $('#tcp-bar').css('width', '100%'); }
			lockUI(false);
		}

		function addErrors(items) {
			if (!items || !items.length) { return; }
			var box = $('#tcp-errors').show();
			items.forEach(function (x) { $('<div/>').text(x).appendTo(box); });
		}

		/** اعتبارسنجی فرم سمت کلاینت. */
		function valid() {
			if (targetType() === 'category' && !($('#tcp-cats').val() || []).length) {
				return 'حداقل یک دسته‌بندی انتخاب کن.';
			}
			if (targetType() === 'products' && !selectedProductIds().length) {
				return isWholesaleOp() ? 'حداقل یک محصول دارای قیمت عمده انتخاب کن.' : 'حداقل یک محصول انتخاب کن.';
			}
			var kind = valueKind();
			if (kind !== 'none' && $('#tcp-value').val().trim() === '') {
				return 'مقدار را وارد کن.';
			}
			if (kind !== 'none') {
				var n = Number(normNum($('#tcp-value').val()));
				if (!isFinite(n) || n < 0) { return 'مقدار واردشده معتبر نیست.'; }
				if (kind === 'percent') {
					var cap = opMeta(currentOp()).cap100 ? 100 : (D.limits.percentMax || 100000);
					if (n > cap) { return 'مقدار درصد از حد مجاز (' + cap + ') بیشتر است.'; }
				} else {
					var maxA = D.limits.maxAmount || 1e9;
					if (n > maxA) { return 'مبلغ از سقف مجاز (' + maxA + ') بیشتر است.'; }
				}
			}
			var f = filtersPayload();
			if (f.price_min !== null && f.price_max !== null && Number(f.price_min) > Number(f.price_max)) {
				return 'محدودهٔ قیمت را درست وارد کن (از ≤ تا).';
			}
			return null;
		}

		/* ---- پیش‌نمایش ---- */
		function renderSamples(samples) {
			var $tb = $('#tcp-sample-box tbody').empty();
			if (!samples || !samples.length) {
				$('#tcp-sample-box').hide();
				return;
			}
			$('#tcp-sample-box').show();
			var cur = D.currency || '';
			samples.forEach(function (s) {
				var before = s.before === '' ? '—' : esc(s.before) + ' ' + cur;
				var after = s.after === '' ? '—' : esc(s.after) + ' ' + cur;
				var stateHtml = '';
				if (s.state === 'error') {
					stateHtml = '<span class="sample-error">خطا — ' + esc(s.note) + '</span>';
				} else if (s.state === 'skip') {
					stateHtml = '<span class="sample-skip">رد شد — ' + esc(s.note) + '</span>';
				} else if (s.state === 'updated') {
					stateHtml = '<span style="color:#008a20">✓ قابل اجرا</span>';
				} else {
					stateHtml = esc(s.note || '');
				}
				$tb.append($('<tr/>').append(
					$('<td/>').text(s.object_id),
					$('<td/>').text(s.label || ('#' + s.object_id)),
					$('<td/>').text(s.type || ''),
					$('<td class="sample-now"/>').html(before),
					$('<td class="sample-new"/>').html(after),
					$('<td/>').html(stateHtml)
				));
			});
		}

		function runPreview() {
			var err = valid();
			if (err) { inform('توجه', esc(err)); return; }
			invalidatePreview();
			var $btn = $('#tcp-preview').prop('disabled', true).text('در حال بررسی...');

			var payload = commonPayload({ action: A.preview });

			$.post(D.ajax, payload, null, 'json')
				.done(function (r) {
					if (!r || !r.success) {
						inform('خطا در بررسی', esc((r && r.data && r.data.message) || 'خطای نامشخص.'));
						return;
					}
					var d = r.data || {};
					previewValid = true;
					previewToken = String(d.preview_token || '');
					previewInfo = d;

					var html = '<p><strong>نوع عملیات:</strong> ' + esc(d.operation_label || '') + '</p>';
					if (d.round_mode === 'jitter') {
						html += '<p><strong>رند:</strong> تخفیف متغیر ±' + esc(d.jitter) + '٪ — هر آیتم درصدی می‌گیرد که قیمتش روی ' + esc(d.round_label) + ' بیفتد.</p>';
					} else if (d.round_mode === 'round') {
						html += '<p><strong>رند:</strong> قیمت نهایی به پایین روی ' + esc(d.round_label) + ' رند می‌شود.</p>';
					}
					html += '<p><strong>محدوده انتخاب:</strong> ' + (d.target_type === 'products' ? 'محصولات انتخاب‌شده به صورت مستقیم' : (d.include_children ? 'دسته‌بندی + تمام زیردسته‌ها' : 'فقط خود دسته‌بندی‌ها؛ بدون زیردسته')) + '</p>';
					if (d.category_labels && d.category_labels.length) {
						html += '<p><strong>دسته‌ها:</strong> ' + esc(d.category_labels.join(' ، ')) + '</p>';
					}
					html += '<p style="font-size:16px"><strong>تعداد محصولات مادر هدف: <span style="color:#b32d2e">' + Number(d.parent_count || 0) + '</span></strong></p>';
					html += '<p><strong>تعداد قیمت/متغیر واجد شرایط این عملیات:</strong> ' + Number(d.price_object_count || 0) + '</p>';
					if (d.include_children) {
						html += '<p style="color:#b32d2e"><strong>هشدار: زیردسته‌ها نیز لحاظ شده‌اند.</strong></p>';
					}
					if (Number(d.parent_count || 0) >= (D.limits.threshold || 500)) {
						html += '<p style="background:#fff2f0;border:1px solid #d63638;padding:9px"><strong>هشدار پرریسک:</strong> بیش از ' + (D.limits.threshold || 500) + ' محصول مادر در محدوده است. هنگام اجرا باید شمارهٔ دقیق را دستی تایپ کنی.</p>';
					}
					$('#tcp-preview-summary').html(html);
					$('#tcp-preview-box').show();
					renderSamples(d.samples);
					$('#tcp-start').prop('disabled', running);
					$('#tcp-schedule').prop('disabled', running || !(D.limits && D.limits.scheduledEnabled));
				})
				.fail(function (xhr) {
					inform('خطا در بررسی', esc((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'ارتباط با سرور برای بررسی قطع شد.'));
				})
				.always(function () {
					$btn.prop('disabled', false).text('بررسی قبل از اجرا');
				});
		}

		/* ---- اجرا ---- */
		function startRun(scheduled) {
			var err = valid();
			if (err) { inform('توجه', esc(err)); return; }
			if (!previewValid || !previewToken) {
				inform('توجه', 'ابتدا بررسی قبل از اجرا را انجام بده.');
				return;
			}
			var parents = Number(previewInfo && previewInfo.parent_count || 0);
			var prices = Number(previewInfo && previewInfo.price_object_count || 0);

			var body = '<p>قرار است عملیات روی <b>' + parents + '</b> محصول مادر و حدود <b>' + prices + '</b> قیمت/متغیر واجد شرایط اجرا شود.</p>';
			if (previewInfo && previewInfo.include_children) {
				body += '<p style="color:#b32d2e"><strong>هشدار: زیردسته‌ها هم شامل عملیات هستند.</strong></p>';
			}
			body += '<p>' + (scheduled ? 'اجرا به صف زمان‌بندی (WP-Cron) اضافه می‌شود و در پیشخوان اطلاع‌رسانی می‌گردد.' : 'همهٔ تغییرات برای بازگردانی بعدی ثبت می‌شوند.') + '</p>';

			// تأیید دستی تایپ‌شده برای تعداد زیاد.
			if (parents >= (D.limits.threshold || 500)) {
				var required = 'تایید ' + parents;
				openDialog({
					title: 'تأیید امنیتی',
					body: '<p>تعداد محصولات زیاد است. برای جلوگیری از اشتباه، عبارت زیر را دقیقاً تایپ کن:</p>' +
						'<p style="background:#f6f7f7;padding:8px;text-align:center;font-weight:700;font-size:16px" dir="ltr">' + esc(required) + '</p>' +
						'<input type="text" id="tcp-confirm-input" autocomplete="off" placeholder="' + esc(required) + '">',
					okText: scheduled ? 'ثبت در صف' : 'شروع اجرا',
					okClass: 'button-primary',
					readValue: function () {
						var typed = $('#tcp-confirm-input').val().trim();
						if (typed !== required) { return false; }
						return typed;
					}
				}).then(function (val) {
					if (val === null) { return; }
					doStart(scheduled);
				});
				return;
			}

			openDialog({
				title: 'تأیید نهایی',
				body: body,
				okText: scheduled ? 'ثبت در صف' : 'اجرا کن',
				okClass: 'button-primary'
			}).then(function (ok) {
				if (ok) { doStart(scheduled); }
			});
		}

		function doStart(scheduled) {
			var payload = commonPayload({
				action: A.run,
				preview_token: previewToken,
				schedule: scheduled ? '1' : '0'
			});

			$.post(D.ajax, payload, null, 'json')
				.done(function (r) {
					if (!r || !r.success) {
						inform('شروع اجرا ممکن نشد', esc((r && r.data && r.data.message) || 'خطای نامشخص.'));
						return;
					}
					if (scheduled) {
						inform('ثبت در صف', esc((r.data && r.data.message) || 'در صف قرار گرفت.'));
						$('#tcp-schedule').prop('disabled', true);
						return;
					}
					running = true;
					resetProgress();
					lockUI(true);
					$('#tcp-status').text('در حال پردازش مرحله ۱ ...');
					handleRunResponse(r, 'next');
				})
				.fail(function (xhr) {
					inform('شروع اجرا ممکن نشد', esc((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'ارتباط با سرور قطع شد.'));
				});
		}

		function handleRunResponse(r, mode) {
			if (!r || !r.success) {
				showFinal((r && r.data && r.data.message) || 'خطای نامشخص.', false);
				return;
			}
			var d = r.data || {};
			runId = Number(d.run_id || runId);
			totals.parents += Number(d.parents || 0);
			totals.updated += Number(d.updated || 0);
			totals.skipped += Number(d.skipped || 0);
			totals.errors += (d.errors || []).length;
			addErrors(d.errors || []);
			$('#tcp-parent-count').text(totals.parents);
			$('#tcp-updated-count').text(totals.updated);
			$('#tcp-skipped-count').text(totals.skipped);
			$('#tcp-error-count').text(totals.errors);
			$('#tcp-bar').css('width', Math.min(100, Number(d.progress || 0)) + '%');

			if (stopNow) {
				stopNow = false;
				finishRun('stopped');
				return;
			}
			if (d.done) {
				// جمع‌بندی نهایی بر اساس آمار قطعی سرور.
				if (d.totals) {
					$('#tcp-parent-count').text(totals.parents);
					$('#tcp-updated-count').text(d.totals.updated);
					$('#tcp-skipped-count').text(d.totals.skipped);
					$('#tcp-error-count').text(d.totals.errors);
				}
				var hadErrors = (d.totals && d.totals.errors > 0) || totals.errors > 0;
				if (hadErrors) {
					showFinal('عملیات تمام شد اما ' + ((d.totals && d.totals.errors) || totals.errors) + ' خطا ثبت شد — جزئیات در «گزارش و بازگردانی» موجود است.', false);
				} else {
					showFinal('عملیات با موفقیت تمام شد.', true);
				}
				return;
			}
			$('#tcp-status').text('در حال پردازش مرحله ' + Number(d.page || 0) + ' ...');
			runNext(runId);
		}

		function runNext(id) {
			if (!running) { return; }
			$.post(D.ajax, { action: A.run, nonce: D.nonce, run_id: id }, null, 'json')
				.done(function (r) { handleRunResponse(r, 'next'); })
				.fail(function (xhr) {
					var msg = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'ارتباط با سرور قطع شد.';
					// اگر نهایی‌شده باشد (race) فقط اطلاع بده.
					showFinal(msg, false);
				});
		}

		function finishRun(outcome) {
			if (!runId) { return; }
			$.post(D.ajax, { action: A.finish, nonce: D.nonce, run_id: runId }, null, 'json')
				.done(function (r) {
					showFinal((r && r.data && r.data.message) || 'عملیات متوقف شد.', true);
				})
				.fail(function (xhr) {
					showFinal((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'توقف ثبت نشد؛ گزارش را چک کن.', false);
				});
		}

		/* ---- رویدادها ---- */
		$('input[name="tcp_target"]').on('change', updateTarget);
		$('#tcp-round-jitter').on('change', function () { if ($(this).is(':checked')) { $('#tcp-round').prop('checked', true); } });
		$('#tcp-round').on('change', function () { if (!$(this).is(':checked')) { $('#tcp-round-jitter').prop('checked', false); } });
		$('#tcp-op').on('change', updateOpUi);
		$('#tcp-children').on('change', function () {
			$('#tcp-children-warning').toggle($(this).is(':checked'));
			invalidatePreview();
		});
		$('#tcp-cats,#tcp-products,#tcp-wholesale-products,#tcp-filter-types,#tcp-filter-statuses,#tcp-price-min,#tcp-price-max,#tcp-filter-only-sale,#tcp-filter-only-wholesale')
			.on('change', invalidatePreview);
		$('#tcp-products,#tcp-wholesale-products').on('change', addManualProductsFromSelect);
		$('#tcp-filter-types,#tcp-filter-statuses,#tcp-filter-only-wholesale').on('change', function () {
			if (!running && targetMode() === 'name' && nameSearchState.term) {
				searchProductsByName(1);
			}
		});
		$('#tcp-name-search-button').on('click', function () { searchProductsByName(1); });
		$('#tcp-name-search-term').on('keydown', function (event) {
			if (event.key === 'Enter' || event.which === 13) {
				event.preventDefault();
				searchProductsByName(1);
			}
		});
		$('#tcp-name-load-more').on('click', function () {
			if (nameSearchState.page < nameSearchState.pages) { searchProductsByName(nameSearchState.page + 1); }
		});
		$('#tcp-selected-products').on('change', '.tcp-selected-product-checkbox', function () {
			if (running) { return; }
			var id = String($(this).data('product-id'));
			productSelection().selected[id] = this.checked;
			renderSelectedProducts();
			invalidatePreview();
		});
		$('#tcp-product-select-all').on('change', function () {
			if (running) { return; }
			var selected = this.checked;
			var state = productSelection();
			state.order.forEach(function (id) { state.selected[id] = selected; });
			renderSelectedProducts();
			invalidatePreview();
		});
		$('#tcp-selected-products').on('click', '.tcp-product-remove', function () {
			if (running) { return; }
			var id = String($(this).closest('tr').data('product-id'));
			var state = productSelection();
			delete state.items[id];
			delete state.selected[id];
			state.order = state.order.filter(function (productId) { return productId !== id; });
			renderSelectedProducts();
			invalidatePreview();
		});
		$('#tcp-clear-products').on('click', function () {
			if (running || !confirm('تمام محصولات این فهرست پاک شوند؟')) { return; }
			var state = productSelection();
			state.items = Object.create(null);
			state.selected = Object.create(null);
			state.order = [];
			renderSelectedProducts();
			invalidatePreview();
		});
		$('#tcp-value').on('input', invalidatePreview);
		$('#tcp-price-min,#tcp-price-max').on('input', invalidatePreview);

		$('#tcp-preview').on('click', function () { if (!running) { runPreview(); } });
		$('#tcp-start').on('click', function () { if (!running) { startRun(false); } });
		$('#tcp-schedule').on('click', function () { if (!running) { startRun(true); } });
		$('#tcp-stop').on('click', function () {
			stopNow = true;
			$(this).prop('disabled', true).text('در حال توقف...');
		});

		updateTarget();
		updateOpUi();
		updateFilterVisibility();
		$('#tcp-children-warning').toggle($('#tcp-children').is(':checked'));
		if (!(D.limits && D.limits.scheduledEnabled)) { $('#tcp-schedule').hide(); }
		$(document.body).trigger('wc-enhanced-select-init');
	}

	/* =====================================================================
	 * صفحهٔ گزارش اجراها — بازگردانی / ادامه / انصراف
	 * ===================================================================== */

	if (D.tab === 'runs') {

		var rollbackRunId = 0;

		function rowRunId(btn) {
			return Number($(btn).closest('tr').data('run') || 0);
		}

		/** دیالوگ پیشرفت عمومی برای ادامه/بازگردانی. */
		function openProgress(title, onStop) {
			var $d = getDialog();
			$d.find('#tcp-dialog-title').html(esc(title));
			$d.find('#tcp-dialog-body').html(
				'<div class="tcp-progressbar-line"><div class="tcp-bar" style="flex:1"><div id="tcp-pg-bar" style="width:0"></div></div>' +
				'<span id="tcp-pg-pct">0٪</span></div>' +
				'<p id="tcp-pg-status" class="tcp-status" style="margin-top:8px">شروع...</p>' +
				'<div id="tcp-pg-errors" class="tcp-errors" style="display:none"></div>'
			);
			var $ok = $d.find('#tcp-dialog-ok').text('توقف').attr('class', 'button button-large').show();
			$d.find('#tcp-dialog-cancel').text('بستن').hide();
			$d.show();
			$ok.one('click', function () {
				onStop();
				$ok.prop('disabled', true).text('در حال توقف...');
			});
		}

		function setPg(pct, status) {
			$('#tcp-pg-bar').css('width', Math.min(100, Number(pct || 0)) + '%');
			$('#tcp-pg-pct').text(Math.min(100, Number(pct || 0)) + '٪');
			if (status) { $('#tcp-pg-status').text(status); }
		}

		function addPgErrors(items) {
			if (!items || !items.length) { return; }
			var box = $('#tcp-pg-errors').show();
			items.forEach(function (x) { $('<div/>').text(x).appendTo(box); });
		}

		function closeProgressAndReload(msg) {
			var $d = getDialog();
			$d.hide();
			$d.find('#tcp-pg-errors').empty();
			if (msg) {
				inform('نتیجه', esc(msg)).then(function () { window.location.reload(); });
				return;
			}
			window.location.reload();
		}

		/* ---- ادامهٔ اجرای ناتمام ---- */
		$(document).on('click', '.tcp-act-resume', function () {
			var rid = rowRunId(this);
			inform('ادامهٔ اجرا #' + rid, 'اجرا از همان جایی که قطع شده ادامه می‌یابد و پیشرفت آن در این پنجره نمایش داده می‌شود.').then(function (ok) {
				if (!ok) { return; }
				openProgress('ادامهٔ اجرای #' + rid, function () {
					// توقف بعد از صفحهٔ فعلی
					window.stopFlagResume = true;
					$('#tcp-dialog-ok').prop('disabled', true);
				});
				pollRun(rid, true);
			});
		});

		function pollRun(rid, resumeFirst) {
			var payload = { action: A.run, nonce: D.nonce, run_id: rid };
			if (resumeFirst) { payload.resume = '1'; }
			$.post(D.ajax, payload, null, 'json')
				.done(function (r) {
					if (!r || !r.success) {
						closeProgressAndReload((r && r.data && r.data.message) || 'خطا در ادامه‌ی اجرا.');
						return;
					}
					var d = r.data || {};
					if (window.stopFlagResume) {
						window.stopFlagResume = false;
						finishAndReload(rid);
						return;
					}
					if (d.done) {
						closeProgressAndReload('اجرا #' + rid + ' کامل شد.');
						return;
					}
					setPg(d.progress, 'مرحلهٔ ' + (d.page || 0) + ' — تاکنون ' + ((d.totals && d.totals.updated) || 0) + ' تغییر.');
					addPgErrors(d.errors || []);
					pollRun(rid, false);
				})
				.fail(function (xhr) {
					closeProgressAndReload((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'ارتباط با سرور قطع شد.');
				});
		}

		function finishAndReload(rid) {
			$.post(D.ajax, { action: A.finish, nonce: D.nonce, run_id: rid }, null, 'json')
				.done(function () { closeProgressAndReload('اجرا متوقف شد.'); })
				.fail(function (xhr) { closeProgressAndReload((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'توقف ثبت نشد.'); });
		}

		/* ---- توقف اجرای در جریان ---- */
		$(document).on('click', '.tcp-act-stop', function () {
			var rid = rowRunId(this);
			openDialog({
				title: 'توقف اجرای #' + rid,
				body: '<p>اجرا بعد از صفحهٔ جاری متوقف می‌شود و تاکنون ثبت‌شده‌ها برای بازگردانی/گزارش باقی می‌مانند.</p>',
				okText: 'توقف اجرا',
				okClass: 'button-primary'
			}).then(function (ok) {
				if (ok) { finishAndReload(rid); }
			});
		});

		/* ---- بازگردانی ---- */
		$(document).on('click', '.tcp-act-rollback', function () {
			var rid = rowRunId(this);
			var $tr = $(this).closest('tr');
			var updated = $tr.find('.tcp-run-stats b').eq(0).text();
			openDialog({
				title: 'بازگردانی اجرای #' + rid,
				body: '<p>همهٔ <b>' + esc(updated) + '</b> تغییری که این اجرا ثبت کرده به حالت «قبل» برگردانده می‌شود (حذف‌ها بازسازی و تغییرها معکوس می‌شوند).</p>' +
					'<p style="color:#b32d2e">این عمل روی قیمت‌ها اثر مستقیم دارد. مطمئن هستی؟</p>',
				okText: 'بله، بازگردانی کن',
				okClass: 'button-primary'
			}).then(function (ok) {
				if (!ok) { return; }
				$.post(D.ajax, { action: A.rollback_start, nonce: D.nonce, run_id: rid }, null, 'json')
					.done(function (r) {
						if (!r || !r.success) {
							inform('بازگردانی شروع نشد', esc((r && r.data && r.data.message) || 'خطای نامشخص.'));
							return;
						}
						rollbackRunId = Number(r.data.run_id || 0);
						openProgress('بازگردانی اجرای #' + rid + ' (' + r.data.rows + ' رکورد)', function () {
							window.stopFlagRollback = true;
							$('#tcp-dialog-ok').prop('disabled', true);
						});
						pollRollback(rollbackRunId);
					})
					.fail(function (xhr) {
						inform('بازگردانی شروع نشد', esc((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'ارتباط با سرور قطع شد.'));
					});
			});
		});

		function pollRollback(rbid) {
			$.post(D.ajax, { action: A.rollback_page, nonce: D.nonce, run_id: rbid }, null, 'json')
				.done(function (r) {
					if (!r || !r.success) {
						closeProgressAndReload((r && r.data && r.data.message) || 'خطا در بازگردانی.');
						return;
					}
					var d = r.data || {};
					if (window.stopFlagRollback) {
						window.stopFlagRollback = false;
						$.post(D.ajax, { action: A.finish, nonce: D.nonce, run_id: rbid }, null, 'json')
							.done(function () { closeProgressAndReload('بازگردانی متوقف شد.'); })
							.fail(function () { closeProgressAndReload('بازگردانی متوقف شد (گزارش را چک کن).'); });
						return;
					}
					if (d.done) {
						closeProgressAndReload('بازگردانی اجرا کامل شد و اجرای مبدأ «بازگردانی شده» شد.');
						return;
					}
					setPg(d.progress, 'در حال بازگردانی... ' + (d.updated || 0) + ' رکورد در این مرحله.');
					addPgErrors(d.errors || []);
					pollRollback(rbid);
				})
				.fail(function (xhr) {
					closeProgressAndReload((xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'ارتباط با سرور قطع شد.');
				});
		}

		/* ---- انصراف از صف ---- */
		$(document).on('click', '.tcp-act-cancel', function () {
			var rid = rowRunId(this);
			openDialog({
				title: 'انصراف از صف',
				body: '<p>اجرای زمان‌بندی‌شدهٔ #' + rid + ' از صف حذف می‌شود و اجرا نمی‌شود. مطمئن هستی؟</p>',
				okText: 'حذف از صف',
				okClass: 'button-primary'
			}).then(function (ok) {
				if (!ok) { return; }
				$.post(D.ajax, { action: A.cancel, nonce: D.nonce, run_id: rid }, null, 'json')
					.done(function (r) {
						if (r && r.success) { window.location.reload(); }
						else { inform('انصراف نشد', esc((r && r.data && r.data.message) || 'خطای نامشخص.')); }
					})
					.fail(function () { inform('انصراف نشد', 'ارتباط با سرور قطع شد.'); });
			});
		});

		$(document.body).trigger('wc-enhanced-select-init');
	}
})(jQuery);
