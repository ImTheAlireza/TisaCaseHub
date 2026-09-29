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
		var nameSearchSelections = { retail: Object.create(null), wholesale: Object.create(null) };
		var nameSearchItems = { retail: Object.create(null), wholesale: Object.create(null) };

		function targetType() {
			return $('input[name="tcp_target"]:checked').val();
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
		function activeSelect() {
			return isWholesaleOp() ? $('#tcp-wholesale-products') : $('#tcp-products');
		}
		function selectionBucket() {
			return isWholesaleOp() ? 'wholesale' : 'retail';
		}
		function valueKind() {
			return opMeta(currentOp()).kind; // percent | amount | set | none
		}

		function productOptionLabel(item) {
			return String(item.name || ('محصول #' + item.id)) + ' (#' + item.id + ')';
		}

		function setProductOption($select, item, selected) {
			var id = String(item.id);
			var $option = $select.find('option').filter(function () {
				return String(this.value) === id;
			}).first();

			if (selected) {
				if ($option.length) {
					$option.prop('selected', true);
				} else {
					$select.append(new Option(productOptionLabel(item), id, true, true));
				}
			} else if ($option.length) {
				$option.remove();
			}
		}

		function updateSelectedProductCount() {
			var count = (activeSelect().val() || []).length;
			$('#tcp-selected-product-count').text('انتخاب نهایی: ' + count + ' محصول');
		}

		function updateNameSearchPageCount() {
			var $checks = $('#tcp-name-search-results .tcp-name-product-checkbox');
			if (!$checks.length) {
				$('#tcp-name-page-count').empty();
				return;
			}
			var selected = $checks.filter(':checked').length;
			$('#tcp-name-page-count').text(selected + ' از ' + $checks.length + ' مورد نمایش‌داده‌شده انتخاب است.');
		}

		function applyNameSearchItems(items, bucket) {
			var $select = bucket === 'wholesale' ? $('#tcp-wholesale-products') : $('#tcp-products');
			var selections = nameSearchSelections[bucket];
			var knownItems = nameSearchItems[bucket];

			(items || []).forEach(function (item) {
				var id = String(item.id);
				if (!Object.prototype.hasOwnProperty.call(selections, id)) {
					selections[id] = true; // همهٔ نتیجه‌های تازه به‌طور پیش‌فرض انتخاب می‌شوند.
				}
				knownItems[id] = item;
				setProductOption($select, item, !!selections[id]);
			});

			$select.trigger('change');
			updateSelectedProductCount();
		}

		function syncNameSearchFromSelect(event) {
			var bucket = event.currentTarget.id === 'tcp-wholesale-products' ? 'wholesale' : 'retail';
			var selected = Object.create(null);
			($(event.currentTarget).val() || []).forEach(function (id) { selected[String(id)] = true; });
			var selections = nameSearchSelections[bucket];
			Object.keys(selections).forEach(function (id) {
				selections[id] = !!selected[id];
			});

			if (bucket === selectionBucket()) {
				$('#tcp-name-search-results .tcp-name-product-checkbox').each(function () {
					var id = String($(this).data('product-id'));
					$(this).prop('checked', !!selected[id]);
				});
				updateNameSearchPageCount();
				updateSelectedProductCount();
			}
		}

		function resetNameSearchDisplay(message) {
			nameSearchGeneration++;
			if (nameSearchXhr) {
				nameSearchXhr.abort();
				nameSearchXhr = null;
			}
			nameSearchState = { term: '', page: 0, pages: 0, total: 0, loaded: 0, bucket: '' };
			$('#tcp-name-search-results tbody').empty();
			$('#tcp-name-search-results').hide();
			$('#tcp-name-search-status').text(message || '');
			$('#tcp-name-load-more').hide().prop('disabled', false);
			$('#tcp-name-search-button').prop('disabled', false);
			$('#tcp-name-page-count').empty();
		}

		function renderNameSearchItems(items, bucket, page) {
			var $body = $('#tcp-name-search-results tbody');
			var selections = nameSearchSelections[bucket];
			var knownItems = nameSearchItems[bucket];

			if (1 === page) {
				$body.empty();
				nameSearchState.loaded = 0;
			}

			(items || []).forEach(function (item) {
				var id = String(item.id);
				if (!Object.prototype.hasOwnProperty.call(selections, id)) {
					selections[id] = true;
				}
				knownItems[id] = item;

				var $checkbox = $('<input type="checkbox" class="tcp-name-product-checkbox">')
					.attr('data-product-id', id)
					.attr('aria-label', 'انتخاب ' + (item.name || ('محصول #' + id)))
					.prop('checked', !!selections[id]);
				var $name = $('<span/>').text(item.name || ('محصول #' + id));
				var $row = $('<tr/>').append(
					$('<td/>').append($checkbox),
					$('<td/>').append($name),
					$('<td/>').attr('dir', 'ltr').text('#' + id)
				);
				$body.append($row);
			});

			nameSearchState.loaded += (items || []).length;
			applyNameSearchItems(items, bucket);
			$('#tcp-name-search-results').toggle(nameSearchState.loaded > 0);

			var shown = Math.min(nameSearchState.loaded, nameSearchState.total);
			var status = 'پیدا شد: ' + nameSearchState.total + ' محصول؛ ' + shown + ' مورد نمایش داده شده است. موارد تیک‌خورده به انتخاب نهایی اضافه می‌شوند.';
			if (shown < nameSearchState.total) {
				status += ' برای دیدن و انتخاب بقیهٔ نتیجه‌ها، «نمایش موارد بعدی» را بزن.';
			}
			$('#tcp-name-search-status').text(status);
			$('#tcp-name-load-more')
				.toggle(shown < nameSearchState.total)
				.text('نمایش ' + Math.min(100, nameSearchState.total - shown) + ' مورد بعدی');
			updateNameSearchPageCount();
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
				$('#tcp-name-search-status').text('برای جستجو حداقل دو حرف بنویس.');
				$('#tcp-name-search-results').hide();
				$('#tcp-name-load-more').hide().prop('disabled', false);
				$('#tcp-name-search-button').prop('disabled', false);
				return;
			}

			var filters = filtersPayload();
			if (page === 1) {
				nameSearchState = { term: term, page: 0, pages: 0, total: 0, loaded: 0, bucket: bucket };
				$('#tcp-name-search-results tbody').empty();
				$('#tcp-name-search-results').hide();
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
					$('#tcp-name-search-results tbody').empty();
					$('#tcp-name-search-results').hide();
					$('#tcp-name-search-status').text('محصولی با این عبارت پیدا نشد.');
					$('#tcp-name-load-more').hide();
					$('#tcp-name-page-count').empty();
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

		function setVisibleNameSearchSelection(selected) {
			if (running) { return; }
			var bucket = selectionBucket();
			var selections = nameSearchSelections[bucket];
			var knownItems = nameSearchItems[bucket];
			var items = [];
			$('#tcp-name-search-results .tcp-name-product-checkbox').each(function () {
				var id = String($(this).data('product-id'));
				$(this).prop('checked', selected);
				selections[id] = selected;
				if (knownItems[id]) { items.push(knownItems[id]); }
			});
			applyNameSearchItems(items, bucket);
			updateNameSearchPageCount();
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
				product_ids: (activeSelect().val() || []).join(','),
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
			updateSelectedProductCount();
			$(document.body).trigger('wc-enhanced-select-init');
		}

		function updateTarget() {
			if (targetType() === 'products') {
				$('#tcp-cat-box').hide();
				$('#tcp-product-box').show();
				updateProductSearch();
			} else {
				$('#tcp-product-box').hide();
				$('#tcp-cat-box').show();
			}
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
			$('#tcp-name-search-term,#tcp-name-search-button,#tcp-name-select-page,#tcp-name-deselect-page,#tcp-name-load-more,#tcp-name-search-results .tcp-name-product-checkbox').prop('disabled', v);
			$('#tcp-cats,#tcp-products,#tcp-wholesale-products,#tcp-filter-types,#tcp-filter-statuses').trigger('change.select2');
			$('#tcp-stop').toggle(v);
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
			if (targetType() === 'products' && !(activeSelect().val() || []).length) {
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
		$('#tcp-products,#tcp-wholesale-products').on('change', syncNameSearchFromSelect);
		$('#tcp-filter-types,#tcp-filter-statuses,#tcp-filter-only-wholesale').on('change', function () {
			if (!running && targetType() === 'products' && nameSearchState.term) {
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
		$('#tcp-name-select-page').on('click', function () { setVisibleNameSearchSelection(true); });
		$('#tcp-name-deselect-page').on('click', function () { setVisibleNameSearchSelection(false); });
		$('#tcp-name-search-results').on('change', '.tcp-name-product-checkbox', function () {
			if (running) { return; }
			var bucket = selectionBucket();
			var id = String($(this).data('product-id'));
			var item = nameSearchItems[bucket][id];
			if (!item) { return; }
			nameSearchSelections[bucket][id] = this.checked;
			applyNameSearchItems([item], bucket);
			updateNameSearchPageCount();
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
