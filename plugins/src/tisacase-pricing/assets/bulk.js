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
			// پاک‌سازی هندلرهای باقی‌مانده از دیالوگ قبلی (مثلاً «توقف» پنجرهٔ پیشرفت)
			// و بازگرداندن دکمه‌ها به حالت فعال؛ وگرنه دکمهٔ تأیید بعد از توقف می‌ماند.
			$ok.off('click');
			$cancel.off('click');
			$ok.prop('disabled', false);
			$cancel.prop('disabled', false);
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
		 * تلاش مجدد خودکار — جلوگیری از مرگ حلقهٔ اجرا با اولین قطعی ارتباط
		 * ===================================================================== */

		/** وقفهٔ تلاش‌های متوالی (میلی‌ثانیه)؛ جمع ~۳.۵ دقیقه تلاش خودکار. */
		var RETRY_DELAYS = [1500, 3000, 6000, 10000, 15000, 20000, 30000, 30000, 30000, 30000, 30000, 30000];
		/** سقف زمان AJAX اجرا؛ هر مرحلهٔ پردازش حدود ۸ ثانیه بودجه دارد. */
		var RUN_AJAX_TIMEOUT = 60000;

		/**
		 * POST با تلاش مجدد خودکار.
		 * - خطای شبکه/تایم‌اوت/پاسخ HTML سرور → تکرار با وقفهٔ روبه‌رشد (onRetry).
		 * - پاسخ خطای منطقی سرور (JSON با success=false) → بی‌درنگ onServerError.
		 * - پایان همهٔ تلاش‌ها → onGiveUp با قابلیت تلاش دستی.
		 *
		 * opts: { data, timeout, shouldAbort, onRetry, onDone, onServerError, onGiveUp }
		 * @return {{cancel: Function, retryNow: Function, flush: Function}}
		 */
		function postWithRetry(opts) {
			var state = { attempt: 0, timer: null, dead: false };
			var payload = typeof opts.data === 'function' ? opts.data : function () { return opts.data; };

			function cancel() {
				state.dead = true;
				if (state.timer) { clearTimeout(state.timer); state.timer = null; }
			}
			function retryNow() {
				state.attempt = 0;
				send();
			}
			/** اگر تایمر تلاش مجدد در انتظار است، همین حالا اجرا کن (برای توقف فوری). */
			function flush() {
				if (state.dead) { return; }
				if (state.timer) {
					clearTimeout(state.timer);
					state.timer = null;
					send();
				}
			}
			function send() {
				if (state.dead) { return; }
				if (opts.shouldAbort && opts.shouldAbort()) { cancel(); return; }
				$.ajax({
					url: D.ajax,
					type: 'POST',
					dataType: 'json',
					timeout: opts.timeout || RUN_AJAX_TIMEOUT,
					data: payload()
				})
					.done(function (r) {
						state.attempt = 0;
						if (!r || r.success === false) {
							if (opts.onServerError) {
								opts.onServerError((r && r.data && r.data.message) || 'خطای نامشخص.', r);
							}
							return;
						}
						if (opts.onDone) { opts.onDone(r); }
					})
					.fail(function (xhr, status) {
						if (state.dead || status === 'abort') { return; }
						var rj = xhr && xhr.responseJSON;
						if (rj && rj.success === false) {
							if (opts.onServerError) {
								opts.onServerError((rj.data && rj.data.message) || 'خطای سرور.', xhr);
							}
							return;
						}
						if (state.attempt >= RETRY_DELAYS.length) {
							if (opts.onGiveUp) {
								opts.onGiveUp('ارتباط با سرور برقرار نشد و تلاش خودکار متوقف شد.', xhr, retryNow);
							}
							return;
						}
						var delay = RETRY_DELAYS[state.attempt++];
						if (opts.onRetry) { opts.onRetry(state.attempt, delay); }
						state.timer = setTimeout(send, delay);
					});
			}

			send();
			return { cancel: cancel, retryNow: retryNow, flush: flush };
		}

		/**
		 * دیالوگ پایان تلاش‌های خودکار: «تلاش مجدد» یا ترک (با راهنمای ادامه از گزارش).
		 */
		function offerRetryDialog(msg, onRetry, onCancel) {
			openDialog({
				title: 'قطع ارتباط با سرور',
				body: '<p>' + esc(msg) + '</p>' +
					'<p class="tcp-muted">اجرا روی سرور از بین نرفته است. اجرای «همهٔ محصولات» و دسته‌های خیلی بزرگ، اگر این پنجره را ببندی، در پس‌زمینه از همان متغیر ادامه پیدا می‌کند. بقیه را از تب «گزارش و بازگردانی» ادامه بده. دکمهٔ توقف، اجرا را واقعاً قطع می‌کند.</p>',
				okText: 'تلاش مجدد',
				okClass: 'button-primary'
			}).then(function (ok) {
				if (ok) {
					// اگر هندلر «توقف»ِ پنجرهٔ پیشرفت هم روی همین دکمه نشسته باشد، پرچم را پاک کن
					// تا «تلاش مجدد» واقعاً ادامه دهد، نه توقف.
					window.stopFlagResume = false;
					window.stopFlagRollback = false;
					onRetry();
				}
				else if (onCancel) { onCancel(); }
			});
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
		var skuSearchState = { term: '', page: 0, pages: 0, total: 0, loaded: 0, bucket: '' };
		var skuSearchGeneration = 0;
		var skuSearchXhr = null;
		var skuSearchCurrentBucket = '';
		var runLoop = null;   // حلقهٔ پردازش اجرا (برای توقف/تلاش مجدد)
		var startLoop = null; // درخواست شروع اجرا
		var selectedProducts = {
			retail: { items: Object.create(null), selected: Object.create(null), order: [] },
			wholesale: { items: Object.create(null), selected: Object.create(null), order: [] }
		};

		function targetMode() {
			return $('input[name="tcp_target"]:checked').val() || 'category';
		}
		function targetType() {
			var mode = targetMode();
			if (mode === 'category') { return 'category'; }
			if (mode === 'all') { return 'all'; }
			return 'products';
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

		function updateExclusionCount() {
			var products = ($('#tcp-excluded-products').val() || []).length;
			var cats = ($('#tcp-excluded-cats').val() || []).length;
			var total = products + cats;
			var label = total ? (total + ' استثنا (' + products + ' محصول، ' + cats + ' دسته)') : 'بدون استثنا';
			$('#tcp-exclusion-badge').text(label);
		}

		/**
		 * جستجوی «محصولات مستثنا» با صفحه‌بندی روی جستجوی سراسری افزونه.
		 * جستجوی خودِ ووکامرس (woocommerce_json_search_products) حداکثر ۳۰ نتیجه می‌دهد و
		 * صفحهٔ بعدی ندارد؛ این‌جا کل کاتالوگ با نام/توضیح/SKU/شناسه و ۱۰۰ نتیجه در هر
		 * صفحه جستجو می‌شود و با اسکرول، موارد بعدی بارگذاری می‌شوند.
		 */
		function initExcludedProductsSearch() {
			var $select = $('#tcp-excluded-products');
			if (!$select.length || typeof $.fn.select2 !== 'function') { return; }
			$select.select2({
				multiple: true,
				allowClear: true,
				placeholder: $select.attr('data-placeholder') || 'نام، SKU یا شناسهٔ محصول را جستجو کن…',
				minimumInputLength: 2,
				language: {
					inputTooShort: function () { return 'برای جستجو حداقل ۲ حرف بنویس…'; },
					noResults: function () { return 'موردی با این عبارت پیدا نشد.'; },
					searching: function () { return 'در حال جستجو در کل سایت…'; },
					errorLoading: function () { return 'نتایج بارگذاری نشد؛ دوباره تلاش کن.'; }
				},
				ajax: {
					url: D.ajax,
					dataType: 'json',
					type: 'POST',
					delay: 250,
					cache: false,
					data: function (params) {
						return {
							action: A.anySearch,
							nonce: D.nonce,
							term: params.term || '',
							page: params.page || 1
						};
					},
					processResults: function (response, params) {
						var data = (response && response.data) ? response.data : {};
						var items = (data.items || []).map(function (item) {
							var text = String(item.name || ('محصول #' + item.id));
							if (item.sku) { text += ' — SKU: ' + item.sku; }
							return { id: parseInt(item.id, 10), text: text + ' — #' + item.id };
						});
						var page = Number(data.page || (params && params.page) || 1);
						var pages = Number(data.pages || 0);
						return {
							results: items,
							pagination: { more: pages > page }
						};
					}
				}
			});
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

		function resetSkuSearchDisplay(message) {
			skuSearchGeneration++;
			if (skuSearchXhr) {
				skuSearchXhr.abort();
				skuSearchXhr = null;
			}
			skuSearchState = { term: '', page: 0, pages: 0, total: 0, loaded: 0, bucket: '' };
			$('#tcp-sku-search-status').text(message || '');
			$('#tcp-sku-load-more').hide().prop('disabled', false);
			$('#tcp-sku-search-button').prop('disabled', false);
		}

		function renderSkuSearchItems(items, bucket, page) {
			if (1 === page) { skuSearchState.loaded = 0; }
			skuSearchState.loaded += (items || []).length;
			addProductItems(items, bucket, false);

			var shown = Math.min(skuSearchState.loaded, skuSearchState.total);
			var status = 'پیدا شد: ' + skuSearchState.total + ' محصول؛ ' + shown + ' مورد به فهرست زیر اضافه شده است.';
			if (shown < skuSearchState.total) {
				status += ' برای افزودن صفحهٔ بعدی، دکمهٔ «نمایش موارد بعدی» را بزن.';
			}
			$('#tcp-sku-search-status').text(status);
			$('#tcp-sku-load-more')
				.toggle(shown < skuSearchState.total)
				.text('نمایش ' + Math.min(100, skuSearchState.total - shown) + ' مورد بعدی');
		}

		function searchProductsBySku(page) {
			if (running) { return; }
			var bucket = selectionBucket();
			var term = page > 1 ? skuSearchState.term : String($('#tcp-sku-search-term').val() || '').trim();
			var requestId = ++skuSearchGeneration;
			if (skuSearchXhr) {
				skuSearchXhr.abort();
				skuSearchXhr = null;
			}
			if (term.length < 2) {
				skuSearchState = { term: '', page: 0, pages: 0, total: 0, loaded: 0, bucket: bucket };
				$('#tcp-sku-search-status').text('برای جستجو حداقل دو حرف بنویس.');
				$('#tcp-sku-load-more').hide().prop('disabled', false);
				$('#tcp-sku-search-button').prop('disabled', false);
				return;
			}

			var filters = filtersPayload();
			if (page === 1) {
				skuSearchState = { term: term, page: 0, pages: 0, total: 0, loaded: 0, bucket: bucket };
			}

			$('#tcp-sku-search-status').text('در حال جستجو در SKU محصولات...');
			$('#tcp-sku-search-button').prop('disabled', true);
			$('#tcp-sku-load-more').prop('disabled', true);
			skuSearchXhr = $.post(D.ajax, {
				action: A.skuSearch,
				nonce: D.nonce,
				term: term,
				page: page,
				filters: JSON.stringify(filters),
				wholesale_only: (isWholesaleOp() || filters.only_wholesale) ? '1' : '0'
			}, null, 'json');

			skuSearchXhr.done(function (r) {
				if (requestId !== skuSearchGeneration || bucket !== selectionBucket()) { return; }
				if (!r || !r.success) {
					$('#tcp-sku-search-status').text((r && r.data && r.data.message) || 'جستجو انجام نشد.');
					return;
				}
				var data = r.data || {};
				skuSearchState.term = term;
				skuSearchState.page = Number(data.page || page);
				skuSearchState.pages = Number(data.pages || 0);
				skuSearchState.total = Number(data.total || 0);
				skuSearchState.bucket = bucket;
				if (!skuSearchState.total) {
					skuSearchState.loaded = 0;
					$('#tcp-sku-search-status').text('محصولی با این SKU پیدا نشد.');
					$('#tcp-sku-load-more').hide();
					return;
				}
				renderSkuSearchItems(data.items || [], bucket, page);
			});

			skuSearchXhr.fail(function (xhr, status) {
				if (status === 'abort' || requestId !== skuSearchGeneration) { return; }
				var message = (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) || 'ارتباط با سرور برای جستجو برقرار نشد.';
				$('#tcp-sku-search-status').text(message);
			});

			skuSearchXhr.always(function () {
				if (requestId !== skuSearchGeneration) { return; }
				skuSearchXhr = null;
				$('#tcp-sku-search-button').prop('disabled', false);
				$('#tcp-sku-load-more').prop('disabled', false);
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
				excluded_category_ids: ($('#tcp-excluded-cats').val() || []).join(','),
				excluded_product_ids: ($('#tcp-excluded-products').val() || []).join(','),
				exclude_category_children: $('#tcp-exclude-children').is(':checked') ? '1' : '0',
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
			if (skuSearchCurrentBucket && skuSearchCurrentBucket !== bucket) {
				resetSkuSearchDisplay('نوع قیمت عوض شد؛ برای جستجوی محصولاتِ این نوع دوباره جستجو کن.');
			}
			skuSearchCurrentBucket = bucket;
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
			$('#tcp-all-box').toggle(mode === 'all');
			if (mode === 'category') {
				$('#tcp-product-box').hide();
				$('#tcp-cat-box').show();
			} else if (mode === 'all') {
				$('#tcp-cat-box').hide();
				$('#tcp-product-box').hide();
			} else {
				$('#tcp-cat-box').hide();
				$('#tcp-product-box').show();
				$('#tcp-name-search-panel').toggle(mode === 'name');
				$('#tcp-sku-search-panel').toggle(mode === 'sku');
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
			$('#tcp-cats,#tcp-products,#tcp-wholesale-products,#tcp-excluded-products,#tcp-excluded-cats,#tcp-filter-types,#tcp-filter-statuses,#tcp-price-min,#tcp-price-max,#tcp-filter-only-sale,#tcp-filter-only-wholesale,#tcp-exclude-children')
				.prop('disabled', v);
			$('#tcp-name-search-term,#tcp-name-search-button,#tcp-name-load-more,#tcp-clear-products,#tcp-product-select-all').prop('disabled', v);
			$('#tcp-sku-search-term,#tcp-sku-search-button,#tcp-sku-load-more').prop('disabled', v);
			$('#tcp-selected-products .tcp-selected-product-checkbox,#tcp-selected-products .tcp-product-remove').prop('disabled', v);
			$('#tcp-cats,#tcp-products,#tcp-wholesale-products,#tcp-excluded-products,#tcp-excluded-cats,#tcp-filter-types,#tcp-filter-statuses').trigger('change.select2');
			$('#tcp-stop').toggle(v);
			updateSelectedProductCount();
			updateExclusionCount();
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
			if (targetType() === 'all') {
				// انتخاب جداگانه‌ای لازم نیست؛ فیلترهای پایین محدوده را محدود می‌کنند.
			} else if (targetType() === 'products' && !selectedProductIds().length) {
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

			function restoreBtn() {
				$btn.prop('disabled', false).text('بررسی قبل از اجرا');
			}

			postWithRetry({
				data: payload,
				onRetry: function (attempt) {
					$btn.text('تلاش مجدد ارتباط (' + attempt + ')...');
				},
				onServerError: function (msg) {
					restoreBtn();
					inform('خطا در بررسی', esc(msg));
				},
				onGiveUp: function (msg) {
					restoreBtn();
					inform('خطا در بررسی', esc('ارتباط با سرور برای بررسی برقرار نشد.'));
				},
				onDone: function (r) {
					restoreBtn();
					var d = r.data || {};
					previewValid = true;
					previewToken = String(d.preview_token || '');
					previewInfo = d;

					var html = '<p><strong>نوع عملیات:</strong> ' + esc(d.operation_label || '') + '</p>';
					if (d.round_mode === 'jitter') {
						html += '<p><strong>رند:</strong> تخفیف متغیر ±' + esc(d.jitter) + '٪ — هر آیتم درصدی می‌گیرد که قیمتش روی ' + esc(d.round_label) + ' بیفتد.</p>';
					} else if (d.round_mode === 'round') {
						html += '<p><strong>رند:</strong> قیمت نهایی به نزدیک‌ترین ' + esc(d.round_label) + ' می‌رود (اگر به عدد بالاتر نزدیک‌تر باشد، بالا).</p>';
					}
					var scopeText = d.target_type === 'all'
						? 'همهٔ محصولات سایت — نوشتن دائمی قیمت در دیتابیس، متغیر‌به‌متغیر'
						: (d.target_type === 'products' ? 'محصولات انتخاب‌شده به صورت مستقیم' : (d.include_children ? 'دسته‌بندی + تمام زیردسته‌ها' : 'فقط خود دسته‌بندی‌ها؛ بدون زیردسته'));
					html += '<p><strong>محدوده انتخاب:</strong> ' + scopeText + '</p>';
					if (d.category_labels && d.category_labels.length) {
						html += '<p><strong>دسته‌های هدف:</strong> ' + esc(d.category_labels.join(' ، ')) + '</p>';
					}
					var excludedCategories = d.excluded_category_labels || [];
					var excludedProducts = Number(d.excluded_product_count || 0);
					if (excludedCategories.length || excludedProducts) {
						html += '<div class="tcp-alert tcp-alert--warn"><strong>استثناهای فعال:</strong> ' + excludedProducts + ' محصول' +
							(excludedCategories.length ? ' و دسته‌های «' + esc(excludedCategories.join('»، «')) + '»' : '') +
							(excludedCategories.length ? (d.exclude_category_children ? ' (همراه زیردسته‌ها)' : ' (فقط همین دسته‌ها)') : '') +
							'. استثنا بر انتخاب هدف اولویت دارد.</div>';
					}
					html += '<p style="font-size:16px"><strong>تعداد محصولات مادر هدف پس از فیلتر و استثنا: <span style="color:#b32d2e">' + Number(d.parent_count || 0) + '</span></strong></p>';
					var objectCount = Number(d.price_object_count);
					html += '<p><strong>تعداد قیمت/متغیر واجد شرایط این عملیات:</strong> ' + (objectCount < 0 ? 'برای جلوگیری از شمارش سنگین، دقیق محاسبه نشد؛ اجرا در دسته‌های کوچک و قابل‌ادامه انجام می‌شود' : objectCount) + '</p>';
					if (d.include_children) {
						html += '<p style="color:#b32d2e"><strong>هشدار: زیردسته‌های دسته‌های هدف نیز لحاظ شده‌اند.</strong></p>';
					}
					if (d.target_type === 'all' && !d.rollback_available) {
						html += '<div class="tcp-alert tcp-alert--danger"><strong>اجرا مسدود است:</strong> برای تغییر همهٔ محصولات، ثبت لاگ و بازگردانی باید در تنظیمات فعال و جدول لاگ در دسترس باشد.</div>';
					}
					if (d.chunked) {
						html += '<p class="tcp-muted">اجرا به‌صورت ترتیبی و تکه‌ای انجام می‌شود؛ پردازش هر مرحله حدود ۸ ثانیه بودجه دارد و اندازهٔ دسته از «تنظیمات ← اجرا» می‌آید.</p>';
					}
					if (Number(d.parent_count || 0) >= (D.limits.threshold || 500)) {
						html += '<p style="background:#fff2f0;border:1px solid #d63638;padding:9px"><strong>هشدار پرریسک:</strong> بیش از ' + (D.limits.threshold || 500) + ' محصول مادر در محدوده است. هنگام اجرا باید شمارهٔ دقیق را دستی تایپ کنی.</p>';
					}
					$('#tcp-preview-summary').html(html);
					$('#tcp-preview-box').show();
					renderSamples(d.samples);
					var missingRecovery = d.target_type === 'all' && !d.rollback_available;
					$('#tcp-start').prop('disabled', running || missingRecovery);
					$('#tcp-schedule').prop('disabled', running || missingRecovery || !(D.limits && D.limits.scheduledEnabled));
				}
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
			if (targetType() === 'all' && !(previewInfo && previewInfo.rollback_available)) {
				inform('اجرا مسدود است', 'برای تغییر همهٔ محصولات، ثبت لاگ و بازگردانی را در تنظیمات فعال کن و در دسترس بودن جدول لاگ را بررسی کن؛ سپس دوباره پیش‌نمایش بگیر.');
				return;
			}
			var parents = Number(previewInfo && previewInfo.parent_count || 0);
			var prices = Number(previewInfo && previewInfo.price_object_count);
			var catalog = targetType() === 'all';
			var priceText = prices < 0 ? 'تعداد قیمت‌ها روی این کاتالوگ شمرده نشد' : (prices + ' قیمت/متغیر واجد شرایط');

			var body = '<p>قرار است عملیات روی <b>' + parents + '</b> محصول مادر و ' + (prices < 0 ? priceText : ('حدود <b>' + prices + '</b> قیمت/متغیر واجد شرایط')) + ' اجرا شود.</p>';
			if (catalog) {
				body += '<p style="color:#b32d2e"><strong>این تغییر دائمی است و در دیتابیس نوشته می‌شود</strong> — قانون داینامیک نیست. هر متغیر از روی قیمت خودش محاسبه می‌شود. اگر اینترنت قطع شود، از همان متغیر ادامه پیدا می‌کند و دوباره اعمال نمی‌شود.</p>';
			}
				if (previewInfo && previewInfo.include_children) {
					body += '<p style="color:#b32d2e"><strong>هشدار: زیردسته‌های هدف هم شامل عملیات هستند.</strong></p>';
				}
				var excludedCategories = (previewInfo && previewInfo.excluded_category_labels) || [];
				var excludedProducts = Number(previewInfo && previewInfo.excluded_product_count || 0);
				if (excludedCategories.length || excludedProducts) {
					body += '<div class="tcp-alert tcp-alert--warn"><strong>استثناها:</strong> ' + excludedProducts + ' محصول' +
						(excludedCategories.length ? '، دسته‌های «' + esc(excludedCategories.join('»، «')) + '»' : '') +
						(excludedCategories.length ? (previewInfo.exclude_category_children ? ' و زیردسته‌هایشان' : ' (فقط همین دسته‌ها)') : '') +
						'. این موارد تغییر نمی‌کنند.</div>';
				}
				if (previewInfo && previewInfo.chunked) {
					body += '<p class="tcp-muted">اجرا به‌صورت تکه‌ای انجام می‌شود تا فشار هر درخواست محدود بماند.</p>';
				}
				if (scheduled) {
					body += '<p>اجرا به صف زمان‌بندی (WP-Cron) اضافه می‌شود و در پیشخوان اطلاع‌رسانی می‌گردد.</p>';
				}
				if (previewInfo && previewInfo.rollback_available) {
					body += '<p>همهٔ تغییرات برای گزارش و بازگردانی بعدی ثبت می‌شوند.</p>';
				} else {
					body += '<p style="color:#b32d2e"><strong>هشدار:</strong> ثبت لاگ/بازگردانی خاموش است؛ این عملیات قابل بازگشت نخواهد بود.</p>';
				}

			// تأیید دستی تایپ‌شده برای کل فروشگاه یا تعداد زیاد.
			if (catalog || parents >= (D.limits.threshold || 500)) {
				var required = 'تایید ' + parents;
				openDialog({
					title: catalog ? 'تأیید نوشتن دائمی روی همهٔ محصولات' : 'تأیید امنیتی',
					body: body + '<p>برای جلوگیری از اشتباه، عبارت زیر را دقیقاً تایپ کن:</p>' +
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
					doStart(scheduled, val);
				});
				return;
			}

			openDialog({
				title: 'تأیید نهایی',
				body: body,
				okText: scheduled ? 'ثبت در صف' : 'اجرا کن',
				okClass: 'button-primary'
			}).then(function (ok) {
				if (ok) { doStart(scheduled, ''); }
			});
		}

		function doStart(scheduled, confirmation) {
			var payload = commonPayload({
				action: A.run,
				preview_token: previewToken,
				preview_parent_count: String(previewInfo && previewInfo.preview_parent_count || 0),
				preview_ceiling: String(previewInfo && previewInfo.preview_ceiling || 0),
				confirmation: confirmation || '',
				schedule: scheduled ? '1' : '0'
			});

			// درخواست شروع سبک است (پردازش صفحهٔ اول جداگانه با run_id انجام می‌شود)
			// و تا دریافت پاسخ تکرار می‌شود؛ اگر پاسخ گم شده باشد، سرور با خطای 409
			// همان اجرای فعال را برمی‌گرداند و «ادامه» می‌دهیم.
			if (startLoop) { startLoop.cancel(); }
			startLoop = postWithRetry({
				data: payload,
				onServerError: function (msg, r) {
					var data = r && r.data;
					if (data && data.run_id && data.mine && data.type === 'rollback') {
						inform('بازگردانی در جریان است', 'بازگردانی #' + Number(data.run_id) + ' هم‌اکنون در حال اجراست؛ اول آن را از تب «گزارش و بازگردانی» تمام کن، بعد این اجرا را شروع کن.');
						return;
					}
					if (data && data.run_id && data.mine && !scheduled) {
						resumeConflictedRun(Number(data.run_id), scheduled);
						return;
					}
					if (data && data.run_id && data.mine && scheduled) {
						inform('یک اجرا فعال است', 'هم‌اکنون اجرای #' + Number(data.run_id) + ' در جریان است؛ اول آن را تمام کن، بعد اجرا را در صف زمان‌بندی بگذار.');
						return;
					}
					inform('شروع اجرا ممکن نشد', esc(msg));
				},
				onGiveUp: function (msg, xhr, retry) {
					offerRetryDialog('شروع اجرا تأیید نشد: ' + msg, function () { retry(); });
				},
				onDone: function (r) {
					if (scheduled) {
						inform('ثبت در صف', esc((r.data && r.data.message) || 'در صف قرار گرفت.'));
						$('#tcp-schedule').prop('disabled', true);
						return;
					}
					var d = r.data || {};
					runId = Number(d.run_id || 0);
					if (!runId) {
						inform('شروع اجرا ممکن نشد', 'شناسهٔ اجرا دریافت نشد؛ دوباره تلاش کن.');
						return;
					}
					running = true;
					resetProgress();
					lockUI(true);
					$('#tcp-status').text('در حال پردازش... 0٪');
					runNextLoop();
				}
			});
		}

		/** اجرای فعالِ خودِ ما (شروعی که پاسخش گم شده بود) را از همان‌جا ادامه بده. */
		function resumeConflictedRun(id, scheduled) {
			openDialog({
				title: 'اجرای فعال',
				body: '<p>اجرای <b>#' + id + '</b> هم‌اکنون فعال است (احتمالاً همان شروعی است که پاسخش به دست نرسید). ادامهٔ همان اجرا؟</p>',
				okText: 'ادامهٔ اجرا',
				okClass: 'button-primary'
			}).then(function (ok) {
				if (!ok) { return; }
				if (scheduled) {
					inform('یک اجرا فعال است', 'هم‌اکنون اجرای دیگری در جریان است؛ اول آن را تمام کن، بعد اجرا را در صف زمان‌بندی بگذار.');
					return;
				}
				runId = id;
				running = true;
				resetProgress();
				lockUI(true);
				$('#tcp-status').text('ادامهٔ اجرای #' + id + ' ...');
				runNextLoop();
			});
		}

		function handleRunResponse(r) {
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
			var pct = Math.min(100, Number(d.progress || 0));
			$('#tcp-bar').css('width', pct + '%');

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
				if (d.status === 'stopped') {
					showFinal('اجرا متوقف شد.', true);
					return;
				}
				var hadErrors = (d.totals && d.totals.errors > 0) || totals.errors > 0;
				if (hadErrors) {
					showFinal('عملیات تمام شد اما ' + ((d.totals && d.totals.errors) || totals.errors) + ' خطا ثبت شد — جزئیات در «گزارش و بازگردانی» موجود است.', false);
				} else {
					showFinal('عملیات با موفقیت تمام شد.', true);
				}
				return;
			}
			var statusText = 'در حال پردازش... ' + pct + '٪';
			if (d.pages) {
				statusText += ' (' + Number(d.page || 0) + ' از ' + Number(d.pages) + ' محصول)';
			}
			if (d.partial) {
				statusText += ' — ادامه از همان محصول';
			}
			$('#tcp-status').text(statusText);
			runNextLoop();
		}

		/**
		 * حلقهٔ ادامهٔ اجرا: هر پاسخ، درخواست بعدی را می‌زند؛ خطاهای شبکه به‌صورت
		 * خودکار با وقفهٔ روبه‌رشد تکرار می‌شوند و فقط خطای منطقی سرور حلقه را می‌بندد.
		 */
		function runNextLoop() {
			if (!running) { return; }
			if (runLoop) { runLoop.cancel(); }
			runLoop = postWithRetry({
				data: function () { return { action: A.run, nonce: D.nonce, run_id: runId }; },
				shouldAbort: function () {
					if (stopNow) {
						stopNow = false;
						finishRun(runId);
						return true;
					}
					return !running;
				},
				onRetry: function (attempt) {
					$('#tcp-status').text('ارتباط با سرور قطع شد؛ تلاش مجدد ' + attempt + ' از ' + RETRY_DELAYS.length + ' ...');
				},
				onServerError: function (msg) {
					showFinal(msg, false);
				},
				onGiveUp: function (msg, xhr, retry) {
					offerRetryDialog(msg, function () { retry(); }, function () {
						// ترک موقت: اجرا «ناتمام» ثبت می‌شود تا از تب گزارش «ادامه» فعال شود.
						if (stopNow) {
							stopNow = false;
							finishRun(runId);
							return;
						}
						$.post(D.ajax, { action: A.finish, nonce: D.nonce, run_id: runId, leave: '1' }, null, 'json')
							.done(function (r) {
								var bg = r && r.data && r.data.background;
								showFinal((r && r.data && r.data.message) || 'ارتباط برقرار نشد؛ اجرا ناتمام ثبت شد و از تب «گزارش و بازگردانی» دکمهٔ «ادامه» آن را ادامه می‌دهد.', !!bg);
							})
							.fail(function () {
								showFinal('ارتباط برقرار نشد؛ اجرا ناتمام ثبت شد و از تب «گزارش و بازگردانی» دکمهٔ «ادامه» آن را ادامه می‌دهد.', false);
							});
					});
				},
				onDone: function (r) { handleRunResponse(r); }
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
		$('#tcp-cats,#tcp-products,#tcp-wholesale-products,#tcp-excluded-products,#tcp-excluded-cats,#tcp-exclude-children,#tcp-filter-types,#tcp-filter-statuses,#tcp-price-min,#tcp-price-max,#tcp-filter-only-sale,#tcp-filter-only-wholesale')
			.on('change', function () {
				if ($(this).is('#tcp-excluded-products,#tcp-excluded-cats')) { updateExclusionCount(); }
				invalidatePreview();
			});
		$('#tcp-products,#tcp-wholesale-products').on('change', addManualProductsFromSelect);
			$('#tcp-filter-types,#tcp-filter-statuses,#tcp-filter-only-wholesale').on('change', function () {
				if (running) { return; }
				if (targetMode() === 'name' && nameSearchState.term) {
					searchProductsByName(1);
				}
				if (targetMode() === 'sku' && skuSearchState.term) {
					searchProductsBySku(1);
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
			$('#tcp-sku-search-button').on('click', function () { searchProductsBySku(1); });
			$('#tcp-sku-search-term').on('keydown', function (event) {
				if (event.key === 'Enter' || event.which === 13) {
					event.preventDefault();
					searchProductsBySku(1);
				}
			});
			$('#tcp-sku-load-more').on('click', function () {
				if (skuSearchState.page < skuSearchState.pages) { searchProductsBySku(skuSearchState.page + 1); }
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
			// اگر حلقهٔ اجرا بین دو تلاش مجدد در انتظار است، همین حالا توقف را بفرست.
			if (runLoop) { runLoop.flush(); }
		});

		if (new URLSearchParams(window.location.search).get('target') === 'all') {
			$('input[name="tcp_target"][value="all"]').prop('checked', true);
		}
		updateTarget();
		updateOpUi();
		updateFilterVisibility();
		updateExclusionCount();
		$('#tcp-children-warning').toggle($('#tcp-children').is(':checked'));
		if (!(D.limits && D.limits.scheduledEnabled)) { $('#tcp-schedule').hide(); }
		initExcludedProductsSearch();
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
			// هر بار بازشدن پنجرهٔ پیشرفت، نیتِ توقف قبلی پاک می‌شود.
			window.stopFlagResume = false;
			window.stopFlagRollback = false;
			var $d = getDialog();
			$d.find('#tcp-dialog-title').html(esc(title));
			$d.find('#tcp-dialog-body').html(
				'<div class="tcp-progressbar-line"><div class="tcp-bar" style="flex:1"><div id="tcp-pg-bar" style="width:0"></div></div>' +
				'<span id="tcp-pg-pct">0٪</span></div>' +
				'<p id="tcp-pg-status" class="tcp-status" style="margin-top:8px">شروع...</p>' +
				'<div id="tcp-pg-errors" class="tcp-errors" style="display:none"></div>'
			);
			var $ok = $d.find('#tcp-dialog-ok').off('click').prop('disabled', false).text('توقف').attr('class', 'button button-large').show();
			$d.find('#tcp-dialog-cancel').off('click').prop('disabled', false).text('بستن').hide();
			$d.show();
			$ok.one('click', function () {
				onStop();
				$ok.prop('disabled', true).text('در حال توقف...');
			});
		}

		function setPg(pct, status) {
			if (pct !== null && pct !== undefined) {
				var v = Math.min(100, Number(pct || 0));
				$('#tcp-pg-bar').css('width', v + '%');
				$('#tcp-pg-pct').text(v + '٪');
			}
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
			var isRollback = String($(this).closest('tr').data('type') || '') === 'rollback';
			inform(isRollback ? 'ادامهٔ بازگردانی #' + rid : 'ادامهٔ اجرا #' + rid, 'عملیات از همان جایی که قطع شده ادامه می‌یابد و پیشرفت آن در این پنجره نمایش داده می‌شود.').then(function (ok) {
				if (!ok) { return; }
				if (isRollback) {
					openProgress('ادامهٔ بازگردانی #' + rid, function () {
						window.stopFlagRollback = true;
						$('#tcp-dialog-ok').prop('disabled', true);
					});
					pollRollback(rid);
					return;
				}
				openProgress('ادامهٔ اجرای #' + rid, function () {
					// توقف بعد از مرحلهٔ فعلی
					window.stopFlagResume = true;
					$('#tcp-dialog-ok').prop('disabled', true);
				});
				pollRun(rid, true);
			});
		});

		function pollRun(rid, resumeFirst) {
			var first = !!resumeFirst;
			postWithRetry({
				data: function () {
					var payload = { action: A.run, nonce: D.nonce, run_id: rid };
					if (first) { first = false; payload.resume = '1'; }
					return payload;
				},
				shouldAbort: function () {
					if (window.stopFlagResume) {
						window.stopFlagResume = false;
						finishAndReload(rid);
						return true;
					}
					return false;
				},
				onRetry: function (attempt) {
					setPg(null, 'ارتباط با سرور قطع شد؛ تلاش مجدد ' + attempt + ' از ' + RETRY_DELAYS.length + ' ...');
				},
				onServerError: function (msg) {
					closeProgressAndReload(msg);
				},
				onGiveUp: function (msg, xhr, retry) {
					offerRetryDialog(msg, function () {
						// دیالوگ پیشرفت با متن «قطع ارتباط» جایگزین شده؛ دوباره بسازش.
						openProgress('ادامهٔ اجرای #' + rid, function () {
							window.stopFlagResume = true;
							$('#tcp-dialog-ok').prop('disabled', true);
						});
						retry();
					}, function () {
						closeProgressAndReload('ارتباط برقرار نشد؛ صفحه را تازه کن و از همین‌جا دوباره ادامه بده.');
					});
				},
				onDone: function (r) {
					var d = r.data || {};
					if (window.stopFlagResume) {
						window.stopFlagResume = false;
						finishAndReload(rid);
						return;
					}
					if (d.done) {
						var finalMsg = 'اجرا #' + rid + ' کامل شد.';
						if (d.totals && d.totals.errors > 0) {
							finalMsg = 'اجرا #' + rid + ' کامل شد اما ' + Number(d.totals.errors) + ' خطا ثبت شد.';
						}
						closeProgressAndReload(finalMsg);
						return;
					}
					var pct = Math.min(100, Number(d.progress || 0));
					setPg(pct, 'پیشرفت ' + pct + '٪ — تاکنون ' + ((d.totals && d.totals.updated) || 0) + ' تغییر ثبت شده است.');
					addPgErrors(d.errors || []);
					pollRun(rid, false);
				}
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
				postWithRetry({
					data: { action: A.rollback_start, nonce: D.nonce, run_id: rid },
					onServerError: function (msg, r) {
						var data = r && r.data;
						if (data && data.run_id && data.mine && data.type === 'rollback') {
							// بازگردانی قبلیِ خودِ ما هنوز فعال است (پاسخ شروع گم شده بود) → ادامه.
							rollbackRunId = Number(data.run_id);
							openProgress('ادامهٔ بازگردانی #' + rollbackRunId, function () {
								window.stopFlagRollback = true;
								$('#tcp-dialog-ok').prop('disabled', true);
							});
							pollRollback(rollbackRunId);
							return;
						}
						inform('بازگردانی شروع نشد', esc(msg));
					},
					onGiveUp: function (msg, xhr, retry) {
						offerRetryDialog('بازگردانی شروع نشد: ' + msg, function () { retry(); });
					},
					onDone: function (r) {
						rollbackRunId = Number(r.data.run_id || 0);
						openProgress('بازگردانی اجرای #' + rid + ' (' + r.data.rows + ' رکورد)', function () {
							window.stopFlagRollback = true;
							$('#tcp-dialog-ok').prop('disabled', true);
						});
						pollRollback(rollbackRunId);
					}
				});
			});
		});

		function pollRollback(rbid) {
			postWithRetry({
				data: { action: A.rollback_page, nonce: D.nonce, run_id: rbid },
				shouldAbort: function () {
					if (window.stopFlagRollback) {
						window.stopFlagRollback = false;
						stopRollback(rbid);
						return true;
					}
					return false;
				},
				onRetry: function (attempt) {
					setPg(null, 'ارتباط با سرور قطع شد؛ تلاش مجدد ' + attempt + ' از ' + RETRY_DELAYS.length + ' ...');
				},
				onServerError: function (msg) {
					closeProgressAndReload(msg);
				},
				onGiveUp: function (msg, xhr, retry) {
					offerRetryDialog(msg, function () {
						openProgress('ادامهٔ بازگردانی #' + rbid, function () {
							window.stopFlagRollback = true;
							$('#tcp-dialog-ok').prop('disabled', true);
						});
						retry();
					}, function () {
						closeProgressAndReload('ارتباط برقرار نشد؛ صفحه را تازه کن و از همین‌جا دوباره ادامه بده.');
					});
				},
				onDone: function (r) {
					var d = r.data || {};
					if (window.stopFlagRollback) {
						window.stopFlagRollback = false;
						stopRollback(rbid);
						return;
					}
					if (d.done) {
						closeProgressAndReload('بازگردانی اجرا کامل شد و اجرای مبدأ «بازگردانی شده» شد.');
						return;
					}
					var pct = Math.min(100, Number(d.progress || 0));
					setPg(pct, 'در حال بازگردانی... ' + pct + '٪ — ' + (d.updated || 0) + ' رکورد در این مرحله.');
					addPgErrors(d.errors || []);
					pollRollback(rbid);
				}
			});
		}

		function stopRollback(rbid) {
			// outcome=interrupted تا بازگردانی متوقف‌شده هم قابل «ادامه» بماند، نه بن‌بست.
			$.post(D.ajax, { action: A.finish, nonce: D.nonce, run_id: rbid, outcome: 'interrupted' }, null, 'json')
				.done(function () { closeProgressAndReload('بازگردانی متوقف شد و از همین صفحه قابل ادامه است.'); })
				.fail(function () { closeProgressAndReload('بازگردانی متوقف شد (گزارش را چک کن).'); });
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
