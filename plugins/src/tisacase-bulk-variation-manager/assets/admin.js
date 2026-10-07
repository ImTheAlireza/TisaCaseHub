/**
 * TisaCase Bulk Variation Manager — Client Controller
 *
 * @package TisaCase_Bulk_Variation_Manager
 */

(function($) {
	'use strict';

	/**
	 * آبجکت مرکزی مدیریت متغیرهای گروهی
	 */
		const TCBVM = {
		selectedProducts: {},
		isExecuting: false,
		cancelRequested: false,

		init: function() {
			this.bindTargetMode();
			this.bindProductSearch();
			this.bindProductTable();
			this.bindModelsInput();
			this.bindOperationMode();
			this.bindPriceFormat();
			this.bindActions();
			this.bindPresets();
			this.bindRollback();
			this.bindCacheFlush();
			this.bindPurgeAttr();
			this.initSelect2();
		},

		/**
		 * راه‌اندازی Select2 ووکامرس برای دسته‌بندی‌ها
		 */
		initSelect2: function() {
			if ($.fn.select2) {
				$('#tcbvm-cat-select').select2({
					dir: 'rtl',
					placeholder: $('#tcbvm-cat-select').data('placeholder') || 'دسته‌بندی‌ها را انتخاب کنید…',
					allowClear: true,
					width: '100%'
				});
			}
		},

		/**
		 * فرمت ارقام با جداکننده سه‌رقمی
		 */
		formatNumber: function(num) {
			if (!num && num !== 0) return '0';
			return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
		},

		escapeHtml: function(value) {
			return String(value === null || value === undefined ? '' : value).replace(/[&<>"']/g, function(char) {
				return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char];
			});
		},

		/**
		 * تبدیل ارقام به فارسی
		 */
		toPersianDigits: function(str) {
			if (str === null || str === undefined) return '';
			const farsi = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
			return str.toString().replace(/[0-9]/g, function(d) {
				return farsi[d];
			});
		},

		/**
		 * لاگ کردن پیام در کنسول زنده
		 */
		log: function(msg, type, consoleId) {
			const $console = $(consoleId || '#tcbvm-log-console');
			if (!$console.length) return;

			const time = new Date().toLocaleTimeString('fa-IR');
			let prefix = '';
			if (type === 'success') prefix = '✓ ';
			else if (type === 'error') prefix = '✗ ';
			else if (type === 'info') prefix = 'ℹ ';

			const line = '[' + time + '] ' + prefix + msg + '\n';
			$console.append(line);
			$console.scrollTop($console[0].scrollHeight);
		},

		/**
		 * سوییچ بین شیوه‌های انتخاب محصول (دسته‌بندی، شناسه SKU، مستقیم، شناسه عددی)
		 */
		bindTargetMode: function() {
			$('input[name="tcbvm_target_mode"]').on('change', function() {
				const mode = $(this).val();
				$('.tcbvm-seg-item').removeClass('is-active');
				$(this).closest('.tcbvm-seg-item').addClass('is-active');

				$('.tcbvm-tab-pane').addClass('tcbvm-hidden');
				if (mode === 'category') {
					$('#tcbvm-cat-box').removeClass('tcbvm-hidden');
				} else if (mode === 'sku') {
					$('#tcbvm-sku-box').removeClass('tcbvm-hidden');
				} else if (mode === 'direct') {
					$('#tcbvm-direct-box').removeClass('tcbvm-hidden');
				} else if (mode === 'manual') {
					$('#tcbvm-manual-box').removeClass('tcbvm-hidden');
				}
			});
		},

		/**
		 * جستجو و استخراج محصولات
		 */
		bindProductSearch: function() {
			const self = this;

			// جستجو بر اساس دسته‌بندی و فیلترها (شامل فیلتر SKU اختیاری)
			$('#tcbvm-btn-search').on('click', function(e) {
				e.preventDefault();
				const $btn = $(this);
				const catIds = $('#tcbvm-cat-select').val() || [];
				const includeChildren = $('#tcbvm-cat-children').is(':checked') ? 1 : 0;
				const keywords = $('#tcbvm-keywords').val();
				const excludeKeywords = $('#tcbvm-exclude-keywords').val();
				const catSku = $('#tcbvm-cat-sku').val();

				$btn.prop('disabled', true).addClass('is-busy');
				$('#tcbvm-search-counter').text('در حال واکشی محصولات…');

				$.ajax({
					url: tcbvmData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tcbvm_search_products',
						nonce: tcbvmData.nonce,
						filters: {
							category_ids: catIds,
							include_children: includeChildren,
							keywords: keywords,
							exclude_keywords: excludeKeywords,
							sku: catSku
						}
					},
					success: function(resp) {
						$btn.prop('disabled', false).removeClass('is-busy');
						if (resp.success && resp.data) {
							self.addSearchResults(resp.data, $('#tcbvm-search-counter'), 'محصولی یافت نشد.');
						} else {
							$('#tcbvm-search-counter').text(resp.data && resp.data.message ? resp.data.message : 'محصولی یافت نشد.');
						}
					},
					error: function() {
						$btn.prop('disabled', false).removeClass('is-busy');
						$('#tcbvm-search-counter').text('خطا در برقراری ارتباط با سرور.');
					}
				});
			});

			// جستجو اختصاصی بر اساس شناسه / کد محصول (SKU)
			$('#tcbvm-btn-sku-search').on('click', function(e) {
				e.preventDefault();
				const $btn = $(this);
				const skuVal = $('#tcbvm-sku-input').val().trim();
				const skuMode = $('#tcbvm-sku-mode').val();

				if (!skuVal) {
					alert('لطفاً پیشوند یا مقدار شناسه (SKU) مورد نظر را وارد نمایید (مثلاً: CH).');
					return;
				}

				$btn.prop('disabled', true).addClass('is-busy');
				$('#tcbvm-sku-counter').text('در حال استخراج محصولات با شناسه ' + skuVal + '…');

				$.ajax({
					url: tcbvmData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tcbvm_search_products',
						nonce: tcbvmData.nonce,
						filters: {
							mode: 'sku',
							sku: skuVal,
							sku_mode: skuMode
						}
					},
					success: function(resp) {
						$btn.prop('disabled', false).removeClass('is-busy');
						if (resp.success && resp.data) {
							self.addSearchResults(resp.data, $('#tcbvm-sku-counter'), 'هیچ محصولی با این شناسه یافت نشد.');
						} else {
							$('#tcbvm-sku-counter').text(resp.data && resp.data.message ? resp.data.message : 'هیچ محصولی با این شناسه یافت نشد.');
						}
					},
					error: function() {
						$btn.prop('disabled', false).removeClass('is-busy');
						$('#tcbvm-sku-counter').text('خطا در برقراری ارتباط با سرور.');
					}
				});
			});

			// افزودن دستی شناسه‌ها
			$('#tcbvm-btn-manual-add').on('click', function(e) {
				e.preventDefault();
				const $btn = $(this);
				const rawIds = $('#tcbvm-manual-ids').val();
				if (!rawIds.trim()) {
					alert('لطفاً حداقل یک شناسه محصول وارد کنید.');
					return;
				}

				$btn.prop('disabled', true);
				$.ajax({
					url: tcbvmData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tcbvm_search_products',
						nonce: tcbvmData.nonce,
						filters: {
							mode: 'manual',
							manual_ids: rawIds
						}
					},
					success: function(resp) {
						$btn.prop('disabled', false);
						if (resp.success && resp.data && (resp.data.items || (resp.data.ids || []).length)) {
							self.addSearchResults(resp.data, null, 'محصول معتبری یافت نشد.');
							$('#tcbvm-manual-ids').val('');
						} else {
							alert('محصول معتبری با این شناسه‌ها یافت نشد.');
						}
					},
					error: function() {
						$btn.prop('disabled', false);
						alert('خطا در بررسی شناسه‌ها.');
					}
				});
			});

			// جستجوی مستقیم تک‌محصول با Autocomplete
			let searchTimeout = null;
			$('#tcbvm-single-search').on('input keyup', function() {
				const query = $(this).val().trim();
				clearTimeout(searchTimeout);
				if (query.length < 2) {
					$('#tcbvm-single-dropdown').addClass('tcbvm-hidden').empty();
					return;
				}

				searchTimeout = setTimeout(function() {
					$.ajax({
						url: tcbvmData.ajaxUrl,
						type: 'GET',
						data: {
							action: 'tcbvm_search_single_products',
							nonce: tcbvmData.nonce,
							term: query
						},
						success: function(resp) {
							const $drop = $('#tcbvm-single-dropdown');
							$drop.empty();
							if (resp.success && resp.data && resp.data.results && resp.data.results.length) {
								resp.data.results.forEach(function(item) {
									const $it = $('<div class="tcbvm-autocomplete-item"></div>');
									$it.append('<img src="' + item.image_url + '" class="tcbvm-thumb" alt="">');
									$it.append('<div><strong>' + item.name + '</strong><br><small class="tcbvm-muted">SKU: ' + item.sku + ' | شناسه: #' + item.id + ' (' + item.variation_count + ' متغیر فعلی)</small></div>');
									$it.on('click', function() {
										self.selectedProducts[item.id] = item;
										self.renderProductTable();
										$drop.addClass('tcbvm-hidden').empty();
										$('#tcbvm-single-search').val('');
									});
									$drop.append($it);
								});
								$drop.removeClass('tcbvm-hidden');
							} else {
								$drop.append('<div class="tcbvm-autocomplete-item"><small class="tcbvm-muted">محصولی یافت نشد.</small></div>').removeClass('tcbvm-hidden');
							}
						}
					});
				}, 300);
			});

			$('#tcbvm-btn-single-search').on('click', function() {
				$('#tcbvm-single-search').trigger('input');
			});
		},

		/**
		 * ادغام نتایج جستجو در لیست انتخاب.
		 * آیتم‌های کامل‌شده (صفحهٔ اول) بلافاصله اضافه می‌شوند و برای باقی شناسه‌ها
		 * یک سطر جایگزین (Stub) ثبت می‌شود تا هیچ‌کدام از محصولات یافت‌شده — حتی
		 * بیش از ۱۰۰ عدد — از لیست انتخاب جا نمانند؛ جزئیات آن‌ها سپس در پس‌زمینه
		 * بسته‌به‌بسته از سرور دریافت و جایگزینی می‌شود.
		 */
		addSearchResults: function(data, $counter, emptyMessage) {
			const self = this;
			const items = (data && data.items) || [];
			const ids = (data && data.ids) || [];
			const baseMessage = (data && data.message) || (self.toPersianDigits(ids.length || items.length) + ' محصول یافت شد.');

			if (!items.length && !ids.length) {
				if ($counter && $counter.length) {
					$counter.text(baseMessage || emptyMessage);
				}
				self.renderProductTable();
				return;
			}

			items.forEach(function(item) {
				// حفظ وضعیت تیک اگر محصول از قبل در لیست بوده و کاربر آن را غیرفعال کرده است
				if (self.selectedProducts[item.id] && self.selectedProducts[item.id].selected === false) {
					item.selected = false;
				}
				self.selectedProducts[item.id] = item;
			});

			ids.forEach(function(id) {
				if (!self.selectedProducts[id]) {
					self.selectedProducts[id] = self.makeStub(id);
				}
			});

			self.renderProductTable();

			if (self.countStubs() > 0) {
				self.hydrateStubs($counter, baseMessage);
			} else if ($counter && $counter.length) {
				$counter.text(baseMessage);
			}
		},

		/**
		 * ساخت سطر جایگزین (Stub) برای شناسه‌ای که جزئیاتش هنوز از سرور نیامده است.
		 */
		makeStub: function(id) {
			return {
				id: id,
				stub: true,
				name: 'محصول #' + id,
				sku: '…',
				type: 'pending',
				cats: '…',
				variation_count: null,
				image_url: '',
				edit_url: '#',
				selected: true
			};
		},

		/**
		 * شمارش سطرهای جایگزین فعلی در لیست.
		 */
		countStubs: function() {
			const self = this;
			let n = 0;
			Object.keys(self.selectedProducts).forEach(function(pid) {
				if (self.selectedProducts[pid] && self.selectedProducts[pid].stub) {
					n++;
				}
			});
			return n;
		},

		hydrationSeq: 0,

		/**
		 * بارگذاری تدریجی جزئیات سطرهای جایگزین، بسته‌های ۱۰۰تایی، در پس‌زمینه.
		 * اگر کاربر جستجوی جدیدی انجام دهد، زنجیرهٔ قبلی با توکن لغو می‌شود و
		 * وضعیت تیک/حذف کاربر در حین جایگزینی حفظ می‌گردد.
		 */
		hydrateStubs: function($counter, baseMessage) {
			const self = this;
			const token = ++self.hydrationSeq;
			const pageSize = 100;

			const stubIds = Object.keys(self.selectedProducts)
				.filter(function(pid) { return self.selectedProducts[pid].stub; })
				.map(function(pid) { return parseInt(pid, 10); });

			const totalPending = stubIds.length;
			let loaded = 0;
			let offset = 0;

			const loadNext = function() {
				if (token !== self.hydrationSeq) return; // زنجیرهٔ قدیمی لغو شد

				const slice = stubIds.slice(offset, offset + pageSize);
				if (!slice.length) {
					if ($counter && $counter.length) {
						$counter.text(baseMessage);
					}
					return;
				}

				if ($counter && $counter.length) {
					$counter.text(baseMessage + ' — در حال تکمیل جزئیات لیست (' + self.toPersianDigits(loaded) + ' از ' + self.toPersianDigits(totalPending) + ')…');
				}

				$.ajax({
					url: tcbvmData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tcbvm_products_summary_page',
						nonce: tcbvmData.nonce,
						ids: slice
					},
					success: function(resp) {
						if (token !== self.hydrationSeq) return;
						if (resp.success && resp.data && resp.data.items) {
							resp.data.items.forEach(function(item) {
								if (!self.selectedProducts[item.id]) return; // کاربر سطر را از لیست حذف کرده است
								if (self.selectedProducts[item.id].selected === false) {
									item.selected = false; // حفظ وضعیت تیک کاربر
								}
								self.selectedProducts[item.id] = item;
							});
							self.renderProductTable();
						}
						loaded += slice.length;
						offset += pageSize;
						loadNext();
					},
					error: function() {
						if (token !== self.hydrationSeq) return;
						// در صورت خطا صفحهٔ بعدی امتحان می‌شود تا زنجیره قطع نشود؛
						// سطرهای جایگزین باقی‌مانده همچنان در انتخاب و اجرای نهایی لحاظ می‌شوند.
						loaded += slice.length;
						offset += pageSize;
						loadNext();
					}
				});
			};

			loadNext();
		},

		/**
		 * رندر و به‌روزرسانی جدول محصولات انتخاب‌شده
		 */
		renderProductTable: function() {
			const self = this;
			const pids = Object.keys(self.selectedProducts);
			$('#tcbvm-preview-output').addClass('tcbvm-hidden');
			const $tbody = $('#tcbvm-products-tbody');
			const $box = $('#tcbvm-products-box');

			if (pids.length === 0) {
				$box.addClass('tcbvm-hidden');
				$('#tcbvm-selected-badge').text('۰ محصول انتخاب‌شده');
				return;
			}

			$box.removeClass('tcbvm-hidden');
			$('#tcbvm-selected-badge').text(self.toPersianDigits(self.formatNumber(pids.length)) + ' محصول انتخاب‌شده');
			$tbody.empty();

			pids.forEach(function(pid) {
				const item = self.selectedProducts[pid];
				const isChecked = item.selected !== false;
				const isStub = !!item.stub;

				const thumbCell = isStub || !item.image_url
					? '<span class="tcbvm-thumb tcbvm-thumb--empty" aria-hidden="true"></span>'
					: '<img src="' + item.image_url + '" class="tcbvm-thumb" alt="">';

				const nameCell = isStub
					? '<strong>' + item.name + '</strong> <small class="tcbvm-muted">(در حال دریافت جزئیات…)</small>'
					: '<a href="' + (item.edit_url || '#') + '" target="_blank"><strong>' + item.name + '</strong></a>';

				const typeBadge = isStub
					? '<span class="tcbvm-badge tcbvm-badge--muted tcbvm-badge--loading">…</span>'
					: '<span class="tcbvm-badge">' + (item.type === 'variable' ? 'متغیر' : 'ساده') + '</span>';

				const varCell = isStub
					? '<span class="tcbvm-muted">…</span>'
					: '<strong>' + self.toPersianDigits(item.variation_count || 0) + '</strong> متغیر';

				const row = $(
					'<tr data-pid="' + item.id + '"' + (isStub ? ' class="tcbvm-row-stub"' : '') + '>' +
						'<td class="tcbvm-col-w38 tcbvm-center"><input type="checkbox" class="tcbvm-product-checkbox" ' + (isChecked ? 'checked' : '') + '></td>' +
						'<td class="tcbvm-col-w48">' + thumbCell + '</td>' +
						'<td>' + nameCell + '</td>' +
						'<td><span class="tisa-code">' + (item.sku || '—') + '</span> <small class="tcbvm-muted">(#' + item.id + ')</small></td>' +
						'<td><small class="tcbvm-muted">' + (item.cats || '—') + '</small></td>' +
						'<td>' + typeBadge + '</td>' +
						'<td>' + varCell + '</td>' +
						'<td class="tcbvm-col-w70 tcbvm-center"><button type="button" class="tcbvm-btn-remove-row" title="حذف از لیست">&times;</button></td>' +
					'</tr>'
				);
				$tbody.append(row);
			});
		},

		/**
		 * مدیریت رخدادهای جدول محصولات (چک‌باکس، حذف، پاک کردن همه)
		 */
		bindProductTable: function() {
			const self = this;

			// تغییر وضعیت تک محصول
			$(document).on('change', '.tcbvm-product-checkbox', function() {
				const pid = $(this).closest('tr').data('pid');
				if (self.selectedProducts[pid]) {
					self.selectedProducts[pid].selected = $(this).is(':checked');
				}
				self.updateSelectedBadge();
			});

			// انتخاب همه / لغو همه
			$('#tcbvm-select-all').on('change', function() {
				const checked = $(this).is(':checked');
				$('.tcbvm-product-checkbox').prop('checked', checked);
				Object.keys(self.selectedProducts).forEach(function(pid) {
					self.selectedProducts[pid].selected = checked;
				});
				self.updateSelectedBadge();
			});

			// دکمه حذف تک‌سطر
			$(document).on('click', '.tcbvm-btn-remove-row', function() {
				const pid = $(this).closest('tr').data('pid');
				delete self.selectedProducts[pid];
				self.renderProductTable();
			});

			// دکمه پاک کردن کل لیست
			$('#tcbvm-btn-clear-selection').on('click', function() {
				if (confirm('آیا مایل به پاک کردن تمام محصولات انتخاب‌شده از لیست هستید؟')) {
					self.selectedProducts = {};
					self.renderProductTable();
				}
			});
		},

		updateSelectedBadge: function() {
			const checkedCount = this.getActiveProductIds().length;
			$('#tcbvm-selected-badge').text(this.toPersianDigits(this.formatNumber(checkedCount)) + ' محصول فعال');
		},

		/**
		 * استخراج شناسه‌های محصولاتی که تیک خورده‌اند
		 */
		getActiveProductIds: function() {
			const self = this;
			const ids = [];
			Object.keys(self.selectedProducts).forEach(function(pid) {
				if (self.selectedProducts[pid].selected !== false) {
					ids.push(parseInt(pid, 10));
				}
			});
			return ids;
		},

		/**
		 * تنظیم فرم، قیمت و توضیحات بر اساس رفتار انتخاب‌شده.
		 */
		bindOperationMode: function() {
			const updateMode = function() {
				const mode = $('#tcbvm-operation-mode').val() || 'replace_all';
				const descriptions = {
					replace_all: {
						mode: 'حالت جایگزینی کامل همان رفتار قبلی است؛ همهٔ variationهای موجود حذف می‌شوند و فهرست تازه ساخته می‌شود.',
						label: 'مقادیر جدید مدل/ویژگی (هر خط یک مقدار، یا با خط عمودی | جدا کنید):',
						help: 'برای حفظ کاما داخل نام مقدار، فهرست را با خط جدید یا | جدا کنید؛ در این حالت variationهای فعلی جایگزین می‌شوند.'
					},
					add_missing: {
						mode: 'مقادیر و قیمت variationهای فعلی حفظ می‌شوند؛ فقط ترکیب‌هایی ساخته می‌شوند که دقیقاً وجود ندارند. ورودی تکراری دوباره ساخته یا قیمت‌گذاری نمی‌شود.',
						label: 'مقادیر مدل/ویژگی برای افزودن (هر خط یک مقدار، یا با خط عمودی | جدا کنید):',
						help: 'برای حفظ کاما داخل نام مقدار، فهرست را با خط جدید یا | جدا کنید. اگر سایر ویژگی‌های متغیر وجود دارد، گزینهٔ ترکیب را روشن کنید.'
					},
					remove_values: {
						mode: 'variationهایی که مقدار ویژگی هدفشان با فهرست منطبق باشد حذف می‌شوند و همان گزینه‌ها از ویژگی هدف برداشته می‌شود؛ سایر variationها و ویژگی‌ها حفظ می‌شوند. قیمت نادیده گرفته می‌شود.',
						label: 'مقادیر ویژگی برای حذف (هر خط یک مقدار، یا با خط عمودی | جدا کنید):',
						help: 'هر variation دارای یکی از این مقادیر در ویژگی هدف حذف می‌شود؛ چیزی ساخته نمی‌شود و قیمت واردشده اثری ندارد.'
					}
				};
				const copy = descriptions[mode] || descriptions.replace_all;
				$('#tcbvm-operation-mode-help').text(copy.mode);
				$('#tcbvm-models-label').text(copy.label);
				$('#tcbvm-models-help').text(copy.help);
				$('#tcbvm-pricing-card').toggleClass('tcbvm-hidden', mode === 'remove_values');
				$('#tcbvm-combine-field').toggleClass('tcbvm-hidden', mode === 'remove_values');
				$('#tcbvm-preview-output').addClass('tcbvm-hidden');
			};

			$('#tcbvm-operation-mode').on('change', updateMode);
			$('#tcbvm-attr-name, #tcbvm-regular-price, #tcbvm-sale-price, #tcbvm-stock-status, #tcbvm-combine-other').on('input change', function() {
				$('#tcbvm-preview-output').addClass('tcbvm-hidden');
			});
			updateMode();
		},

		/**
		 * مدیریت ورودی مدل‌ها و الگوهای آماده
		 */
		bindModelsInput: function() {
			const self = this;

			const updateCount = function() {
				const raw = $('#tcbvm-models-input').val();
				const list = self.parseModelsList(raw);
				$('#tcbvm-models-count').text(self.toPersianDigits(list.length) + ' مقدار تعریف شد');
				$('#tcbvm-preview-output').addClass('tcbvm-hidden');
			};

			$('#tcbvm-models-input').on('input keyup change', updateCount);

			// کلیک روی چیپ الگوهای آماده جهت درج سریع
			$('.tcbvm-chip-btn').on('click', function(e) {
				e.preventDefault();
				const pId = $(this).data('preset-id');
				if (tcbvmData.presets && tcbvmData.presets[pId] && tcbvmData.presets[pId].models) {
					const models = tcbvmData.presets[pId].models;
					$('#tcbvm-models-input').val(models.join('\n')).trigger('input');
				}
			});
		},

		/**
		 * تفکیک مقادیر مدل‌ها از روی متن
		 */
		parseModelsList: function(raw) {
			if (!raw || !raw.trim()) return [];
			let lines = [];
			if (raw.indexOf('\n') !== -1 || raw.indexOf('\r') !== -1 || raw.indexOf('|') !== -1) {
				lines = raw.split(/[\r\n|]+/);
			} else {
				lines = raw.split(/[,،]+/);
			}

			const unique = [];
			lines.forEach(function(line) {
				const trimmed = line.trim().replace(/^["'`•\-\s]+|["'`•\-\s]+$/g, '');
				if (trimmed && unique.indexOf(trimmed) === -1) {
					unique.push(trimmed);
				}
			});
			return unique;
		},

		/**
		 * فرمت زنده قیمت‌ها به حروف و تومان
		 */
		bindPriceFormat: function() {
			const self = this;

			const handlePrice = function($input, $display) {
				$input.on('input keyup', function() {
					const digits = $(this).val().replace(/[^\d]/g, '');
					if (!digits) {
						$display.text('');
						return;
					}
					const num = parseInt(digits, 10);
					$display.text(self.toPersianDigits(self.formatNumber(num)) + ' ' + (tcbvmData.currency || 'تومان'));
				});
			};

			handlePrice($('#tcbvm-regular-price'), $('#tcbvm-price-preview'));
			handlePrice($('#tcbvm-sale-price'), $('#tcbvm-sale-preview'));
		},

		/**
		 * دکمه‌های پیش‌نمایش و شروع اجرای قطعی
		 */
		bindActions: function() {
			const self = this;

			// پیش‌نمایش قبل از اجرا
			$('#tcbvm-btn-preview').on('click', function(e) {
				e.preventDefault();
				const pids = self.getActiveProductIds();
				if (!pids.length) {
					alert(tcbvmData.i18n.selectProductsPrompt);
					return;
				}

				const attrName = $('#tcbvm-attr-name').val().trim() || 'مدل گوشی';
				const models = self.parseModelsList($('#tcbvm-models-input').val());
				if (!models.length) {
					alert(tcbvmData.i18n.enterModelsPrompt);
					return;
				}

				const operationMode = $('#tcbvm-operation-mode').val() || 'replace_all';
				const price = $('#tcbvm-regular-price').val().replace(/[^\d]/g, '');
				if (operationMode !== 'remove_values' && !price) {
					alert(tcbvmData.i18n.enterPricePrompt);
					return;
				}

				const salePrice = $('#tcbvm-sale-price').val().replace(/[^\d]/g, '');
				const combineOther = operationMode !== 'remove_values' && $('#tcbvm-combine-other').is(':checked') ? 1 : 0;

				const $btn = $(this);
				self.runPreview($btn, {
					product_ids: pids,
					attr_name: attrName,
					new_values: models,
					price: price,
					sale_price: salePrice,
					combine_other: combineOther,
					operation_mode: operationMode
				});
			});

			// اجرای قطعی عملیات
			$('#tcbvm-btn-run').on('click', function(e) {
				e.preventDefault();
				if (self.isExecuting) return;

				const pids = self.getActiveProductIds();
				if (!pids.length) {
					alert(tcbvmData.i18n.selectProductsPrompt);
					return;
				}

				const attrName = $('#tcbvm-attr-name').val().trim() || 'مدل گوشی';
				const models = self.parseModelsList($('#tcbvm-models-input').val());
				if (!models.length) {
					alert(tcbvmData.i18n.enterModelsPrompt);
					return;
				}

				const operationMode = $('#tcbvm-operation-mode').val() || 'replace_all';
				const price = $('#tcbvm-regular-price').val().replace(/[^\d]/g, '');
				if (operationMode !== 'remove_values' && !price) {
					alert(tcbvmData.i18n.enterPricePrompt);
					return;
				}

				const modeConfirmation = operationMode === 'add_missing'
					? 'افزودن: متغیرهای فعلی حفظ می‌شوند؛ ترکیب تکراری ساخته یا قیمت‌گذاری نمی‌شود.'
					: (operationMode === 'remove_values'
						? 'حذف: variationهای دارای مقادیر فهرست‌شده در ویژگی هدف و همان گزینه‌ها حذف می‌شوند؛ قیمت اثری ندارد.'
						: 'جایگزینی: همهٔ variationهای فعلی حذف و مقادیر فهرست‌شده از نو ساخته می‌شوند.');
				const promptMsg = tcbvmData.i18n.confirmStart.replace('{n}', self.toPersianDigits(pids.length)) + '\n\n' + modeConfirmation;
				if (!confirm(promptMsg)) {
					return;
				}

			self.startBatchExecution({
				product_ids: pids,
				attr_name: attrName,
				new_values: models,
					price: price,
					sale_price: $('#tcbvm-sale-price').val().replace(/[^\d]/g, ''),
					stock_status: $('#tcbvm-stock-status').val(),
					combine_other: operationMode !== 'remove_values' && $('#tcbvm-combine-other').is(':checked') ? 1 : 0,
					operation_mode: operationMode
				});
		});

		// دکمه لغو عملیات (توقف پس از پایان بستهٔ جاری)
		$('#tcbvm-btn-cancel-run').on('click', function(e) {
			e.preventDefault();
			if (!self.isExecuting || self.cancelRequested) return;
			if (!confirm(tcbvmData.i18n.cancelRunConfirm || 'لغو کنید؟')) return;
			self.cancelRequested = true;
			$(this).prop('disabled', true).text('در حال توقف پس از بستهٔ جاری…');
			self.log('⚠ درخواست لغو توسط کاربر ثبت شد؛ پس از اتمام بستهٔ جاری عملیات متوقف می‌شود.', 'error');
		});
	},

		/**
		 * اجرای پیش‌نمایش: تکی برای انتخاب‌های کوچک، صفحه‌بندی‌شده با پیشرفت برای بزرگ‌ها.
		 */
		runPreview: function($btn, params) {
			const self = this;
			const PREVIEW_PAGE_SIZE = 50;
			const pids = params.product_ids || [];

			$btn.prop('disabled', true).addClass('is-busy');

			if (pids.length <= PREVIEW_PAGE_SIZE) {
				$.ajax({
					url: tcbvmData.ajaxUrl,
					type: 'POST',
					data: $.extend({ action: 'tcbvm_preview', nonce: tcbvmData.nonce }, params),
					success: function(resp) {
						$btn.prop('disabled', false).removeClass('is-busy');
						if (resp.success && resp.data) {
							self.renderPreview(resp.data);
						} else {
							alert(resp.data && resp.data.message ? resp.data.message : 'خطا در محاسبه پیش‌نمایش.');
						}
					},
					error: function() {
						$btn.prop('disabled', false).removeClass('is-busy');
						alert('خطا در ارتباط با سرور.');
					}
				});
				return;
			}

			self.runPagedPreview($btn, params, PREVIEW_PAGE_SIZE);
		},

		/**
		 * پیش‌نمایش صفحه‌به‌صفحه با نمایش پیشرفت؛ جمع‌بندی نهایی عین خروجی تکی است.
		 */
		runPagedPreview: function($btn, params, pageSize) {
			const self = this;
			const pids = params.product_ids || [];
			const total = pids.length;
			const $label = $btn.find('span').first();
			const originalLabel = $label.length ? $label.text() : '';

			const merged = {
				total_products: total,
				operation_mode: params.operation_mode || 'replace_all',
				operation_mode_label: '',
				attr_name: params.attr_name,
				new_values: [],
				new_values_count: 0,
				price: 0,
				sale_price: null,
				total_old_vars: 0,
				total_new_vars: 0,
				total_remove_vars: 0,
				total_new_vars_capped: false,
				preflight_error_count: 0,
				over_limit_count: 0,
				preview_issues: [],
				preview_issues_truncated: false,
				samples: [],
				combine_other: !!params.combine_other
			};

			let offset = 0;
			let failed = false;

			const finish = function() {
				$btn.prop('disabled', false).removeClass('is-busy');
				if ($label.length) $label.text(originalLabel);
				if (!failed) {
					merged.samples = merged.samples.slice(0, 25);
					merged.preview_issues = merged.preview_issues.slice(0, 100);
					self.renderPreview(merged);
				}
			};

			const fail = function(message) {
				failed = true;
				$btn.prop('disabled', false).removeClass('is-busy');
				if ($label.length) $label.text(originalLabel);
				alert(message || 'خطا در ارتباط با سرور.');
			};

			const fetchPage = function() {
				if ($label.length) {
					$label.text('در حال محاسبه پیش‌نمایش… ' + self.toPersianDigits(Math.min(offset, total)) + ' از ' + self.toPersianDigits(total));
				}
				$.ajax({
					url: tcbvmData.ajaxUrl,
					type: 'POST',
					data: $.extend({ action: 'tcbvm_preview_page', nonce: tcbvmData.nonce, offset: offset, limit: pageSize }, params),
					success: function(resp) {
						if (!resp.success || !resp.data) {
							fail(resp.data && resp.data.message ? resp.data.message : 'خطا در محاسبه پیش‌نمایش.');
							return;
						}
						const d = resp.data;
						if (offset === 0) {
							merged.operation_mode_label = d.operation_mode_label || merged.operation_mode_label;
							merged.new_values = d.new_values || [];
							merged.new_values_count = Number(d.new_values_count || 0);
							merged.price = Number(d.price || 0);
							merged.sale_price = (d.sale_price === null || typeof d.sale_price === 'undefined') ? null : Number(d.sale_price);
						}
						merged.total_old_vars += Number(d.total_old_vars || 0);
						merged.total_new_vars += Number(d.total_new_vars || 0);
						merged.total_remove_vars += Number(d.total_remove_vars || 0);
						if (d.total_new_vars_capped) merged.total_new_vars_capped = true;
						merged.preflight_error_count += Number(d.preflight_error_count || 0);
						merged.over_limit_count += Number(d.over_limit_count || 0);
						if (Array.isArray(d.preview_issues)) merged.preview_issues = merged.preview_issues.concat(d.preview_issues);
						if (d.preview_issues_truncated) merged.preview_issues_truncated = true;
						if (Array.isArray(d.samples)) merged.samples = merged.samples.concat(d.samples);

						offset += Number(d.page_count || 0) > 0 ? Number(d.page_count) : pageSize;
						if (offset < total) {
							fetchPage();
						} else {
							if (merged.preview_issues.length > 100) merged.preview_issues_truncated = true;
							finish();
						}
					},
					error: function() {
						fail('خطا در ارتباط با سرور.');
					}
				});
			};

			fetchPage();
		},

		/**
		 * رندر کارت پیش‌نمایش
		 */
		renderPreview: function(data) {
			const self = this;
			const $box = $('#tcbvm-preview-output');
			const $content = $('#tcbvm-preview-content');
			$content.empty();

			let html = '<div class="tcbvm-preview-grid">';
			const operationMode = data.operation_mode || 'replace_all';
			const isRemoveMode = operationMode === 'remove_values';
			const operationLabel = data.operation_mode_label || 'جایگزینی کامل';
			html += '<div class="tcbvm-stat-box"><span class="tcbvm-stat-num">' + self.toPersianDigits(data.total_products) + '</span><span class="tcbvm-stat-lbl">محصول انتخابی</span></div>';
			html += '<div class="tcbvm-stat-box"><span class="tcbvm-stat-num">' + self.toPersianDigits(data.new_values_count) + '</span><span class="tcbvm-stat-lbl">مقدار در فهرست (' + self.escapeHtml(data.attr_name) + ')</span></div>';
			if (!isRemoveMode) {
				html += '<div class="tcbvm-stat-box"><span class="tcbvm-stat-num">' + self.toPersianDigits(self.formatNumber(data.price)) + '</span><span class="tcbvm-stat-lbl">قیمت متغیرهای تازه (تومان)</span></div>';
			}
			html += '<p class="tcbvm-preview-mode" style="grid-column:1/-1;margin:0;color:#475569;"><strong>رفتار:</strong> ' + self.escapeHtml(operationLabel) + '</p>';
			const preflightErrors = Number(data.preflight_error_count || 0);
			const overLimitCount = Number(data.over_limit_count || 0);
			const previewIssues = Array.isArray(data.preview_issues) ? data.preview_issues : [];
			const previewTotal = isRemoveMode ? Number(data.total_remove_vars || 0) : Number(data.total_new_vars || 0);
			const totalLabel = !isRemoveMode && (data.total_new_vars_capped || preflightErrors) ? 'حداقل ' : '';
			const totalValue = totalLabel + self.toPersianDigits(self.formatNumber(previewTotal));
			const totalNotes = [];
			if (preflightErrors) totalNotes.push('برآورد ناقص؛ محاسبهٔ ' + self.toPersianDigits(preflightErrors) + ' محصول ناموفق بود');
			if (overLimitCount) totalNotes.push(self.toPersianDigits(overLimitCount) + ' محصول بیش از سقف ۳۰۰۰ ترکیب است و در اجرا رد می‌شود');
			else if (data.total_new_vars_capped && !isRemoveMode) totalNotes.push('برآورد محدودشده');
			const totalNote = totalNotes.length ? ' (' + totalNotes.join('؛ ') + ')' : '';
			const totalDescription = isRemoveMode ? 'مجموع variationهای منطبق برای حذف' : (operationMode === 'add_missing' ? 'ترکیب‌های جدید برای افزودن' : 'مجموع ترکیب‌های تولیدی');
			html += '<div class="tcbvm-stat-box"><span class="tcbvm-stat-num">' + totalValue + '</span><span class="tcbvm-stat-lbl">' + totalDescription + totalNote + '</span></div>';
			if (preflightErrors) {
				html += '<p class="tcbvm-preview-warning" style="grid-column:1/-1;color:#8a2c0d;background:#fff3e0;border-radius:6px;padding:10px 12px;">⚠️ محاسبهٔ برخی محصولات کامل نشد؛ اجرای همان محصول در سرور نیز برای جلوگیری از شمارش نادرست متوقف می‌شود.</p>';
			}
			if (overLimitCount) {
				html += '<p class="tcbvm-preview-warning" style="grid-column:1/-1;color:#8a2c0d;background:#fff3e0;border-radius:6px;padding:10px 12px;">⚠️ ' + self.toPersianDigits(overLimitCount) + ' محصول از سقف ایمنی ۳۰۰۰ ترکیب عبور کرده است؛ اجرای همان محصول بدون تغییر رد می‌شود.</p>';
			}
			if (previewIssues.length) {
				html += '<div class="tcbvm-preview-failures" style="grid-column:1/-1;"><strong>مواردی که در اجرا رد می‌شوند یا متوقف‌شونده‌اند:</strong><ul>';
				previewIssues.forEach(function(issue) {
					const productId = self.toPersianDigits(self.formatNumber(Number(issue.id) || 0));
					const reason = issue.reason === 'missing_product'
						? 'محصول در دسترس نیست.'
						: (issue.reason === 'over_limit' ? 'بیش از سقف ۳۰۰۰ ترکیب؛ محصول بدون تغییر رد می‌شود.' : 'خواندن یا شمارش گزینه‌های ویژگی ناموفق بود.');
					html += '<li>محصول #' + productId + ': ' + reason + '</li>';
				});
				if (data.preview_issues_truncated) {
					html += '<li>فهرست شناسه‌ها به ۱۰۰ مورد محدود شده است؛ تعداد کامل موارد در هشدارهای بالا آمده است.</li>';
				}
				html += '</ul></div>';
			}
			html += '</div>';

			if (data.samples && data.samples.length) {
				const sampleTitle = isRemoveMode ? 'نمونه محصولات و متغیرهای منطبق برای حذف:' : (operationMode === 'add_missing' ? 'نمونه ترکیب‌های تازه برای افزودن:' : 'نمونه محصولات جهت بازسازی متغیرها:');
				html += '<h4 style="margin: 16px 0 8px; font-size: 14px;">' + sampleTitle + '</h4>';
				html += '<div class="tcbvm-table-scroll"><table class="tisa-table tcbvm-table">';
				html += '<thead><tr><th>شناسه</th><th>نام محصول</th><th>وضعیت فعلی</th><th>' + (isRemoveMode ? 'حذف پیش‌بینی‌شده' : (operationMode === 'add_missing' ? 'افزودن پیش‌بینی‌شده' : 'تغییرات')) + '</th><th>ترکیب ویژگی‌ها</th></tr></thead><tbody>';

				data.samples.forEach(function(s) {
					html += '<tr>';
					html += '<td><span class="tisa-code">#' + s.id + '</span></td>';
					html += '<td><strong>' + self.escapeHtml(s.name) + '</strong></td>';
					html += '<td>' + self.toPersianDigits(s.old_vars) + ' متغیر فعلی</td>';
					let sampleCount;
					if (s.preflight_error) {
						sampleCount = 'اجرای این محصول متوقف می‌شود: ' + s.preflight_error;
					} else if (isRemoveMode) {
						sampleCount = self.toPersianDigits(s.remove_vars || 0) + ' متغیر حذف می‌شود';
					} else if (s.over_limit) {
						sampleCount = 'بیش از ۳۰۰۰؛ اجرای این محصول رد می‌شود';
					} else {
						sampleCount = self.toPersianDigits(s.new_vars) + (operationMode === 'add_missing' ? ' ترکیب تازه افزوده می‌شود' : ' متغیر جدید');
					}
					const sampleDanger = !!s.preflight_error || !!s.over_limit;
					html += '<td><span class="tcbvm-badge ' + (sampleDanger ? 'tcbvm-badge--danger' : 'tcbvm-badge--success') + '">' + self.escapeHtml(sampleCount) + '</span></td>';
					html += '<td><small class="tcbvm-muted">' + self.escapeHtml(s.other_attrs) + '</small></td>';
					html += '</tr>';
				});

				html += '</tbody></table></div>';
			}

			$content.html(html);
			$box.removeClass('tcbvm-hidden');
			$('html, body').animate({ scrollTop: $box.offset().top - 40 }, 400);
		},

		/**
		 * اجرای پله‌ای و ایجکس دسته‌ها (Batch Execution Engine)
		 */
		startBatchExecution: function(params) {
			const self = this;
			const operationMode = params.operation_mode || 'replace_all';
			const operationLabel = operationMode === 'add_missing' ? 'افزودن ترکیب‌های جدید' : (operationMode === 'remove_values' ? 'حذف مقادیر واردشده' : 'جایگزینی کامل');
			self.isExecuting = true;
			self.cancelRequested = false;
			$('#tcbvm-btn-cancel-run').prop('disabled', false).text('لغو عملیات');

			const $progressWrap = $('#tcbvm-progress-wrap');
			const $bar = $('#tcbvm-bar-fill');
			const $pText = $('#tcbvm-progress-text');
			const $pPercent = $('#tcbvm-progress-percent');
			const $console = $('#tcbvm-log-console');

			$progressWrap.removeClass('tcbvm-hidden');
			$console.empty();
			$bar.css('width', '0%');
			$pPercent.text('0%');
			$pText.text('در حال آغاز نشست و ایجاد اسنپ‌شات بازگردانی…');

			$('#tcbvm-btn-run, #tcbvm-btn-preview').prop('disabled', true);
			$('html, body').animate({ scrollTop: $progressWrap.offset().top - 30 }, 400);

			self.log('آغاز عملیات «' + operationLabel + '» برای ' + params.product_ids.length + ' محصول…', 'info');

			// ۱) ایجاد نشست
			$.ajax({
				url: tcbvmData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'tcbvm_start_run',
					nonce: tcbvmData.nonce,
					product_ids: params.product_ids,
					attr_name: params.attr_name,
					new_values: params.new_values,
					price: params.price,
					sale_price: params.sale_price,
					stock_status: params.stock_status,
					combine_other: params.combine_other,
					operation_mode: params.operation_mode
				},
				success: function(resp) {
					if (!resp.success || !resp.data || !resp.data.batches) {
						alert(resp.data && resp.data.message ? resp.data.message : 'خطا در ایجاد نشست.');
						self.isExecuting = false;
						$('#tcbvm-btn-run, #tcbvm-btn-preview').prop('disabled', false);
						return;
					}

					const runId = resp.data.run_id;
					const batches = resp.data.batches;
					const totalProducts = resp.data.total_items;

					$('#tcbvm-stat-total').text(self.toPersianDigits(totalProducts));
					$('#tcbvm-stat-processed').text('۰');
					$('#tcbvm-stat-success').text('۰');
					$('#tcbvm-stat-failed').text('۰');

					self.log('نشست با موفقیت ثبت شد (Run ID: ' + runId + '). پردازش در ' + batches.length + ' بسته آغاز می‌شود.', 'info');

					let currentBatchIndex = 0;
					let processedCount = 0;
					let successCount = 0;
					let failedCount = 0;
					let totalCreated = 0;
					let totalDeleted = 0;
					const allItems = [];

				const runNextBatch = function() {
					// لغو توسط کاربر: پس از پایان بستهٔ جاری، زنجیره ادامه نمی‌یابد
					if (self.cancelRequested) {
						self.finishRun(runId, totalCreated, totalDeleted, allItems, function() {
							$pText.text('عملیات لغو شد (' + self.toPersianDigits(processedCount) + ' از ' + self.toPersianDigits(totalProducts) + ' محصول پردازش شده بود). برای برگرداندن تغییرات، از تب تاریخچه Rollback کنید.');
							self.log('⚠ عملیات لغو شد. ' + self.toPersianDigits(processedCount) + ' محصول پردازش شده بود؛ تغییرات آن‌ها باقی مانده و از تب تاریخچه قابل بازگردانی است.', 'error');
							self.isExecuting = false;
							self.cancelRequested = false;
							$('#tcbvm-btn-run, #tcbvm-btn-preview').prop('disabled', false);
							$('#tcbvm-btn-cancel-run').prop('disabled', true).text('لغو شد');
							alert(tcbvmData.i18n.cancelledText || 'عملیات لغو شد.');
						}, 'cancelled');
						return;
					}

					if (currentBatchIndex >= batches.length) {
						// پایان تمام بسته‌ها
						self.finishRun(runId, totalCreated, totalDeleted, allItems, function() {
							$bar.css('width', '100%');
							$pPercent.text('۱۰۰٪');
							if (operationMode === 'remove_values') {
								$pText.text('حذف مقادیر منطبق با موفقیت تکمیل و در تاریخچه ثبت شد.');
								self.log('پایان تمام بسته‌ها! ' + self.toPersianDigits(self.formatNumber(totalDeleted)) + ' متغیر مطابق فهرست حذف شد.', 'success');
							} else if (operationMode === 'add_missing') {
								$pText.text('افزودن ترکیب‌های جدید تکمیل شد؛ متغیرهای قبلی و ترکیب‌های تکراری حفظ شدند.');
								self.log('پایان تمام بسته‌ها! ' + self.toPersianDigits(self.formatNumber(totalCreated)) + ' ترکیب تازه افزوده شد؛ متغیرهای قبلی حفظ شدند.', 'success');
							} else {
								$pText.text('تولید و بازسازی تمام متغیرها با موفقیت تکمیل و ثبت شد.');
								self.log('پایان تمام بسته‌ها! ' + self.toPersianDigits(self.formatNumber(totalCreated)) + ' متغیر تازه ساخته و ' + self.toPersianDigits(self.formatNumber(totalDeleted)) + ' متغیر قدیمی پاکسازی شد.', 'success');
							}
							self.isExecuting = false;
							$('#tcbvm-btn-run, #tcbvm-btn-preview').prop('disabled', false);
							$('#tcbvm-btn-cancel-run').prop('disabled', true);
							alert(tcbvmData.i18n.completedText);
						});
						return;
					}

						const batchIds = batches[currentBatchIndex];
						const batchNum = currentBatchIndex + 1;
						const batchDesc = batchIds.map(function(id) { return '#' + id; }).join('، ');
						$pText.text('در حال پردازش بسته ' + self.toPersianDigits(batchNum) + ' از ' + self.toPersianDigits(batches.length) + ' (' + batchDesc + ')…');
						self.log('⏳ شروع بسته ' + self.toPersianDigits(batchNum) + ' شامل ' + self.toPersianDigits(batchIds.length) + ' محصول (' + batchDesc + ')…', 'info');

						$.ajax({
							url: tcbvmData.ajaxUrl,
							type: 'POST',
							data: {
								action: 'tcbvm_execute_batch',
								nonce: tcbvmData.nonce,
								run_id: runId,
								batch_ids: batchIds,
								attr_name: params.attr_name,
								new_values: params.new_values,
								price: params.price,
								sale_price: params.sale_price,
								stock_status: params.stock_status,
								combine_other: params.combine_other
							},
							success: function(bResp) {
								const stopForSafety = !bResp.success || !!(bResp.data && (bResp.data.stop || bResp.data.fatal));
								if (bResp.success && bResp.data && bResp.data.items) {
									bResp.data.items.forEach(function(it) {
										processedCount++;
										allItems.push(it);
										if (it.status === 'success') {
											successCount++;
											totalCreated += (it.created || 0);
											totalDeleted += (it.deleted || 0);
											self.log('[#' + it.id + '] ' + it.title + ' ➔ ' + it.message, 'success');
										} else {
											failedCount++;
											self.log('[#' + it.id + '] ' + it.title + ' ➔ خطا: ' + it.message, 'error');
										}
									});
								} else {
									batchIds.forEach(function(pid) {
										processedCount++;
										failedCount++;
										const errItem = { id: pid, status: 'error', title: 'محصول #' + pid, message: (bResp.data && bResp.data.message ? bResp.data.message : 'خطا در پردازش بسته') };
										allItems.push(errItem);
										self.log('محصول #' + pid + ': خطا در بسته ' + (bResp.data && bResp.data.message ? bResp.data.message : ''), 'error');
									});
								}

								// به‌روزرسانی نوار پیشرفت و آمار
								if (stopForSafety && bResp.success && bResp.data && bResp.data.items) {
									const returnedIds = Object.create(null);
									bResp.data.items.forEach(function(it) { returnedIds[String(it.id)] = true; });
									batchIds.forEach(function(pid) {
										if (!returnedIds[String(pid)]) {
											processedCount++;
											failedCount++;
											const skipped = { id: pid, status: 'error', title: 'محصول #' + pid, message: 'به‌دلیل توقف ایمنی، این محصول پردازش نشد.' };
											allItems.push(skipped);
										}
									});
								}

								const pct = Math.round((processedCount / totalProducts) * 100);
								$bar.css('width', pct + '%');
								$pPercent.text(self.toPersianDigits(pct) + '٪');
								$pText.text('پردازش‌شده: ' + self.toPersianDigits(processedCount) + ' از ' + self.toPersianDigits(totalProducts) + ' محصول (' + self.toPersianDigits(pct) + '٪)');
								$('#tcbvm-stat-processed').text(self.toPersianDigits(processedCount));
								$('#tcbvm-stat-success').text(self.toPersianDigits(successCount));
								$('#tcbvm-stat-failed').text(self.toPersianDigits(failedCount));

								if (stopForSafety) {
									const stopMessage = bResp.data && bResp.data.message ? bResp.data.message : 'پاسخ امن و کامل از سرور دریافت نشد.';
									self.finishRun(runId, totalCreated, totalDeleted, allItems, function() {
										$pText.text('عملیات برای ایمنی متوقف شد؛ ادامهٔ بسته‌ها اجرا نشد. وضعیت محصولات پردازش‌شده را در تاریخچه بررسی کنید.');
										self.log('⚠ ادامهٔ عملیات متوقف شد: ' + stopMessage + '؛ برای بازگردانی، از تاریخچه استفاده کنید.', 'error');
										self.isExecuting = false;
										$('#tcbvm-btn-run, #tcbvm-btn-preview').prop('disabled', false);
										$('#tcbvm-btn-cancel-run').prop('disabled', true);
									}, 'failed');
									return;
								}

								currentBatchIndex++;
								setTimeout(runNextBatch, 50);
							},
							error: function(xhr, status, err) {
								self.log('خطای شبکه در بسته ' + batchNum + ': ' + err + '؛ ادامهٔ عملیات متوقف شد تا از تداخل درخواست‌ها جلوگیری شود.', 'error');
								batchIds.forEach(function(pid) {
									processedCount++;
									failedCount++;
									allItems.push({ id: pid, status: 'error', title: 'محصول #' + pid, message: 'پاسخ شبکه نامشخص است؛ وضعیت را از تاریخچه بررسی کنید.' });
								});
								self.finishRun(runId, totalCreated, totalDeleted, allItems, function() {
									$pText.text('به‌علت خطای شبکه، عملیات متوقف شد. وضعیت محصولات را در تاریخچه بررسی کنید.');
									self.isExecuting = false;
									$('#tcbvm-btn-run, #tcbvm-btn-preview').prop('disabled', false);
									$('#tcbvm-btn-cancel-run').prop('disabled', true);
								}, 'failed');
							}
						});
					};

					runNextBatch();
				},
				error: function() {
					alert('خطا در شروع نشست با سرور.');
					self.isExecuting = false;
					$('#tcbvm-btn-run, #tcbvm-btn-preview').prop('disabled', false);
				}
			});
		},

	/**
	 * اتمام نشست
	 */
	finishRun: function(runId, createdCount, deletedCount, items, callback, status) {
		$.ajax({
			url: tcbvmData.ajaxUrl,
			type: 'POST',
			data: {
				action: 'tcbvm_finish_run',
				nonce: tcbvmData.nonce,
				run_id: runId,
				status: status || 'completed',
				created_count: createdCount,
				deleted_count: deletedCount,
				items: items
			},
			complete: function() {
				if (typeof callback === 'function') callback();
			}
		});
	},

		/**
		 * مدیریت ذخیره و حذف الگوهای سفارشی
		 */
		bindPresets: function() {
			$('#tcbvm-form-new-preset').on('submit', function(e) {
				e.preventDefault();
				const name = $('#preset_name').val().trim();
				const desc = $('#preset_desc').val().trim();
				const models = $('#preset_models').val();

				if (!name || !models.trim()) {
					alert('نام الگو و حداقل یک مدل الزامی است.');
					return;
				}

				$.ajax({
					url: tcbvmData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tcbvm_save_preset',
						nonce: tcbvmData.nonce,
						name: name,
						description: desc,
						models: models
					},
					success: function(resp) {
						if (resp.success) {
							alert('الگو با موفقیت ذخیره شد.');
							window.location.reload();
						} else {
							alert(resp.data && resp.data.message ? resp.data.message : 'خطا در ذخیره الگو.');
						}
					}
				});
			});

			$('.tc-btn-delete-preset').on('click', function(e) {
				e.preventDefault();
				if (!confirm(tcbvmData.i18n.confirmDeletePreset)) return;

				const pId = $(this).data('preset-id');
				$.ajax({
					url: tcbvmData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tcbvm_delete_preset',
						nonce: tcbvmData.nonce,
						id: pId
					},
					success: function(resp) {
						if (resp.success) {
							window.location.reload();
						} else {
							alert(resp.data && resp.data.message ? resp.data.message : 'خطا در حذف الگو.');
						}
					}
				});
			});
		},

		/**
		 * بازگردانی (Rollback) و مشاهده جزئیات لاگ
		 */
		bindRollback: function() {
			// باز کردن / بستن کشوی جزئیات گزارش
			$(document).on('click', '.tc-btn-toggle-run-details', function(e) {
				e.preventDefault();
				const runId = $(this).data('run-id');
				$('#run-details-' + runId).toggleClass('tcbvm-hidden');
			});

			$('.tc-btn-rollback').on('click', function(e) {
				e.preventDefault();
				const $btn = $(this);
				const runId = $btn.data('run-id');
				const runStatus = String($btn.data('run-status') || '');
				const confirmation = 'in_progress' === runStatus
					? 'این اجرا ناتمام است. اگر قفل آزاد باشد، تغییرهای انجام‌شده بازگردانده و بسته‌های بعدی متوقف می‌شوند؛ اگر بسته‌ای هنوز قفل دارد، درخواست بازگردانی رد می‌شود. ادامه می‌دهید؟'
					: tcbvmData.i18n.confirmRollback;
				if (!confirm(confirmation)) return;
				$btn.prop('disabled', true).text('در حال بازگردانی…');

				$.ajax({
					url: tcbvmData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tcbvm_rollback',
						nonce: tcbvmData.nonce,
						run_id: runId
					},
					success: function(resp) {
						if (resp.success) {
							alert('عملیات با موفقیت بازگردانده شد و وضعیت متغیرها به حالت قبل برگشت.');
							window.location.reload();
						} else {
							alert(resp.data && resp.data.message ? resp.data.message : 'خطا در بازگردانی.');
							$btn.prop('disabled', false).text('بازگردانی (Rollback)');
						}
					},
					error: function() {
						alert('خطا در ارتباط با سرور.');
						$btn.prop('disabled', false).text('بازگردانی (Rollback)');
					}
				});
			});
		},

		/* ------------------------------------------------------------------
		 * ابزار پاکسازی ویژگی از محصولات (تب «پاکسازی ویژگی»)
		 * ------------------------------------------------------------------ */

		purgeItems: {},
		purgeMatches: null,
		isPurging: false,
		purgeCancelRequested: false,

		/**
		 * اتصال رخدادهای ابزار پاکسازی ویژگی: جستجو، جدول، اجرای بسته‌ای و حذف سراسری.
		 */
		bindPurgeAttr: function() {
			const self = this;
			if (!$('#tcbvm-purge-attr-name').length) return;

			// جستجوی محصولات دارای ویژگی
			$('#tcbvm-btn-purge-search').on('click', function(e) {
				e.preventDefault();
				const $btn = $(this);
				const attrName = $('#tcbvm-purge-attr-name').val().trim();

				if (!attrName) {
					alert('لطفاً عنوان ویژگی را وارد کنید (مثلاً: مدل گوشی).');
					return;
				}

				$btn.prop('disabled', true).addClass('is-busy');
				$('#tcbvm-purge-search-counter').text('در حال جستجو در کل فروشگاه…');

				$.ajax({
					url: tcbvmData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tcbvm_purge_attr_search',
						nonce: tcbvmData.nonce,
						attr_name: attrName
					},
					success: function(resp) {
						$btn.prop('disabled', false).removeClass('is-busy');
						if (!resp.success || !resp.data) {
							$('#tcbvm-purge-search-counter').text(resp.data && resp.data.message ? resp.data.message : 'خطا در جستجو.');
							return;
						}
						const d = resp.data;
						$('#tcbvm-purge-search-counter').text(d.message || '');
						self.purgeMatches = d.matches || null;
						self.purgeItems = {};
						(d.items || []).forEach(function(item) {
							item.selected = true;
							self.purgeItems[item.id] = item;
						});
						self.renderPurgeResults(d);
						self.renderGlobalAttrBox();
					},
					error: function() {
						$btn.prop('disabled', false).removeClass('is-busy');
						$('#tcbvm-purge-search-counter').text('خطا در برقراری ارتباط با سرور.');
					}
				});
			});

			// تغییر وضعیت تک‌سطر
			$(document).on('change', '.tcbvm-purge-checkbox', function() {
				const pid = $(this).closest('tr').data('pid');
				if (self.purgeItems[pid]) {
					self.purgeItems[pid].selected = $(this).is(':checked');
				}
				self.updatePurgeSummary();
			});

			// انتخاب همه / لغو همه
			$('#tcbvm-purge-select-all').on('change', function() {
				const checked = $(this).is(':checked');
				$('.tcbvm-purge-checkbox').prop('checked', checked);
				Object.keys(self.purgeItems).forEach(function(pid) {
					self.purgeItems[pid].selected = checked;
				});
				self.updatePurgeSummary();
			});

			// حذف مستقیم تعریف سراسری ویژگی (از کادر گام ۱)
			$('#tcbvm-btn-purge-global').on('click', function(e) {
				e.preventDefault();
				if (self.isPurging) return;
				const attrName = $('#tcbvm-purge-attr-name').val().trim();
				if (!attrName) return;
				self.isPurging = true;
				self.runGlobalAttributeDelete(attrName, '#tcbvm-purge-log-console', function() {
					self.isPurging = false;
					// تازه‌سازی نتایج پس از حذف سراسری
					$('#tcbvm-btn-purge-search').trigger('click');
				});
			});

			// اجرای پاکسازی
			$('#tcbvm-btn-purge-run').on('click', function(e) {
				e.preventDefault();
				if (self.isPurging) return;

				const attrName = $('#tcbvm-purge-attr-name').val().trim();
				const ids = self.getPurgeSelectedIds();
				if (!ids.length) {
					alert('حداقل یک محصول را تیک بزنید.');
					return;
				}

				const msg = (tcbvmData.i18n.purgeConfirmStart || 'ادامه می‌دهید؟')
					.replace('{attr}', attrName)
					.replace('{n}', self.toPersianDigits(ids.length));
				if (!confirm(msg)) return;

				self.startPurgeExecution(attrName, ids, $('#tcbvm-purge-global-delete').is(':checked'));
			});

			// دکمه لغو پاکسازی (توقف پس از پایان بستهٔ جاری)
			$('#tcbvm-btn-cancel-purge-run').on('click', function(e) {
				e.preventDefault();
				if (!self.isPurging || self.purgeCancelRequested) return;
				if (!confirm(tcbvmData.i18n.cancelRunConfirm || 'لغو کنید؟')) return;
				self.purgeCancelRequested = true;
				$(this).prop('disabled', true).text('در حال توقف پس از بستهٔ جاری…');
				self.log('⚠ درخواست لغو توسط کاربر ثبت شد؛ پس از اتمام بستهٔ جاری پاکسازی متوقف می‌شود.', 'error', '#tcbvm-purge-log-console');
			});
		},

		/**
		 * نمایش/مخفی‌سازی کادر حذف سراسری ویژگی بر اساس نتیجهٔ جستجو.
		 */
		renderGlobalAttrBox: function() {
			const self = this;
			const $box = $('#tcbvm-global-attr-box');
			const taxes = (self.purgeMatches && self.purgeMatches.taxonomies) || [];
			if (!$box.length) return;

			if (!taxes.length) {
				$box.addClass('tcbvm-hidden');
				return;
			}

			let chips = '';
			taxes.forEach(function(t) {
				chips += '<span class="tcbvm-badge tcbvm-badge--info" dir="ltr">' + t.key + '</span> ';
			});
			$('#tcbvm-global-attr-chips').html(chips);
			$box.removeClass('tcbvm-hidden');
		},

		/**
		 * شناسه‌های محصولات تیک‌خورده در ابزار پاکسازی.
		 */
		getPurgeSelectedIds: function() {
			const self = this;
			const ids = [];
			Object.keys(self.purgeItems).forEach(function(pid) {
				if (self.purgeItems[pid].selected !== false) {
					ids.push(parseInt(pid, 10));
				}
			});
			return ids;
		},

		/**
		 * رندر خلاصه و جدول نتایج جستجوی ویژگی.
		 */
		renderPurgeResults: function(data) {
			const self = this;
			const $box = $('#tcbvm-purge-results');
			const $tbody = $('#tcbvm-purge-tbody');
			$tbody.empty();

			const items = data.items || [];
			if (!items.length) {
				$box.addClass('tcbvm-hidden');
				return;
			}
			$box.removeClass('tcbvm-hidden');
			$('#tcbvm-purge-progress-wrap').addClass('tcbvm-hidden');

			items.forEach(function(item) {
				const row = $(
					'<tr data-pid="' + item.id + '">' +
						'<td class="tcbvm-col-w38 tcbvm-center"><input type="checkbox" class="tcbvm-purge-checkbox" ' + (item.selected !== false ? 'checked' : '') + '></td>' +
						'<td><a href="' + (item.edit_url || '#') + '" target="_blank"><strong>' + item.name + '</strong></a></td>' +
						'<td><span class="tisa-code">#' + item.id + '</span></td>' +
						'<td class="tcbvm-center">' + (item.linked_vars > 0
							? '<span class="tcbvm-badge tcbvm-badge--danger">' + self.toPersianDigits(self.formatNumber(item.linked_vars)) + ' متغیر</span>'
							: '<span class="tcbvm-muted">۰</span>') + '</td>' +
						'<td class="tcbvm-center">' + (item.terms > 0
							? '<span class="tcbvm-badge tcbvm-badge--info">' + self.toPersianDigits(item.terms) + ' ترم</span>'
							: '<span class="tcbvm-muted">۰</span>') + '</td>' +
					'</tr>'
				);
				$tbody.append(row);
			});

			self.updatePurgeSummary();
			$('html, body').animate({ scrollTop: $box.offset().top - 40 }, 400);
		},

		/**
		 * به‌روزرسانی شمارندههای خلاصهٔ پاکسازی بر اساس موارد تیک‌خورده.
		 */
		updatePurgeSummary: function() {
			const self = this;
			let selCount = 0;
			let selVars = 0;
			Object.keys(self.purgeItems).forEach(function(pid) {
				const it = self.purgeItems[pid];
				if (it.selected !== false) {
					selCount++;
					selVars += (it.linked_vars || 0);
				}
			});

			const totals = (self.purgeMatches ? Object.keys(self.purgeItems).length : 0);
			let html = '';
			html += '<span class="tcbvm-badge tcbvm-badge--success">' + self.toPersianDigits(self.formatNumber(selCount)) + ' محصول تیک‌خورده</span> ';
			html += '<span class="tcbvm-badge tcbvm-badge--danger">' + self.toPersianDigits(self.formatNumber(selVars)) + ' متغیر وابسته در محصولات تیک‌خورده</span> ';
			if (self.purgeMatches && self.purgeMatches.taxonomies && self.purgeMatches.taxonomies.length) {
				self.purgeMatches.taxonomies.forEach(function(t) {
					html += '<span class="tcbvm-badge tcbvm-badge--info" dir="ltr">' + t.key + '</span> ';
				});
			} else {
				html += '<span class="tcbvm-badge tcbvm-badge--muted">ویژگی محلی (بدون تاکسونومی سراسری)</span>';
			}
			$('#tcbvm-purge-summary').html(html);

			// حذف سراسری فقط وقتی تاکسونومی سراسری منطبق وجود دارد معنا دارد
			const hasGlobalTax = !!(self.purgeMatches && self.purgeMatches.taxonomies && self.purgeMatches.taxonomies.length);
			$('#tcbvm-purge-global-delete').prop('disabled', !hasGlobalTax);
		},

		/**
		 * اجرای بسته‌ای پاکسازی ویژگی با نوار پیشرفت و لاگ زنده مخصوص.
		 */
		startPurgeExecution: function(attrName, ids, deleteGlobalAfter) {
			const self = this;
			self.isPurging = true;
			self.purgeCancelRequested = false;
			$('#tcbvm-btn-cancel-purge-run').prop('disabled', false).text('لغو عملیات');

			const $wrap = $('#tcbvm-purge-progress-wrap');
			const $bar = $('#tcbvm-purge-bar-fill');
			const $pText = $('#tcbvm-purge-progress-text');
			const $pPercent = $('#tcbvm-purge-progress-percent');
			const consoleId = '#tcbvm-purge-log-console';

			$wrap.removeClass('tcbvm-hidden');
			$(consoleId).empty();
			$bar.css('width', '0%');
			$pPercent.text('0%');
			$pText.text('در حال ایجاد نشست پاکسازی و اسنپ‌شات…');
			$('#tcbvm-btn-purge-run, #tcbvm-btn-purge-search').prop('disabled', true);
			$('html, body').animate({ scrollTop: $wrap.offset().top - 30 }, 400);

			self.log('آغاز پاکسازی ویژگی «' + attrName + '» از ' + self.toPersianDigits(ids.length) + ' محصول…', 'info', consoleId);

			$.ajax({
				url: tcbvmData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'tcbvm_purge_attr_start',
					nonce: tcbvmData.nonce,
					product_ids: ids,
					attr_name: attrName
				},
				success: function(resp) {
					if (!resp.success || !resp.data || !resp.data.batches) {
						alert(resp.data && resp.data.message ? resp.data.message : 'خطا در ایجاد نشست پاکسازی.');
						self.isPurging = false;
						$('#tcbvm-btn-purge-run, #tcbvm-btn-purge-search').prop('disabled', false);
						return;
					}

					const runId = resp.data.run_id;
					const batches = resp.data.batches;
					const totalProducts = resp.data.total_items;

					$('#tcbvm-purge-stat-total').text(self.toPersianDigits(totalProducts));
					$('#tcbvm-purge-stat-processed').text('۰');
					$('#tcbvm-purge-stat-success').text('۰');
					$('#tcbvm-purge-stat-failed').text('۰');

					self.log('نشست پاکسازی ثبت شد (Run ID: ' + runId + '). پردازش در ' + self.toPersianDigits(batches.length) + ' بسته آغاز می‌شود.', 'info', consoleId);

					let currentBatchIndex = 0;
					let processedCount = 0;
					let successCount = 0;
					let failedCount = 0;
					let totalDeleted = 0;
					const allItems = [];

				const runNextBatch = function() {
					// لغو توسط کاربر: پس از پایان بستهٔ جاری، زنجیره ادامه نمی‌یابد
					if (self.purgeCancelRequested) {
						self.finishRun(runId, 0, totalDeleted, allItems, function() {
							$pText.text('پاکسازی لغو شد (' + self.toPersianDigits(processedCount) + ' از ' + self.toPersianDigits(totalProducts) + ' محصول پردازش شده بود). برای برگرداندن تغییرات، از تب تاریخچه Rollback کنید.');
							self.log('⚠ پاکسازی لغو شد. ' + self.toPersianDigits(processedCount) + ' محصول پردازش شده بود؛ تغییرات آن‌ها باقی مانده و از تب تاریخچه قابل بازگردانی است.', 'error', consoleId);
							self.isPurging = false;
							self.purgeCancelRequested = false;
							$('#tcbvm-btn-purge-run, #tcbvm-btn-purge-search').prop('disabled', false);
							$('#tcbvm-btn-cancel-purge-run').prop('disabled', true).text('لغو شد');
							alert(tcbvmData.i18n.cancelledText || 'عملیات لغو شد.');
						}, 'cancelled');
						return;
					}

					if (currentBatchIndex >= batches.length) {
						// پایان بسته‌ها — ثبت در تاریخچه
						self.finishRun(runId, 0, totalDeleted, allItems, function() {
							$bar.css('width', '100%');
							$pPercent.text('۱۰۰٪');
							$pText.text('پاکسازی ویژگی تمام شد و در تاریخچه ثبت گردید.');
							self.log('پایان پاکسازی! مجموعاً ' + self.toPersianDigits(self.formatNumber(totalDeleted)) + ' متغیر وابسته حذف و ویژگی از محصولات برداشته شد.', 'success', consoleId);

							const finalize = function() {
								self.isPurging = false;
								$('#tcbvm-btn-purge-run, #tcbvm-btn-purge-search').prop('disabled', false);
								$('#tcbvm-btn-cancel-purge-run').prop('disabled', true);
								alert(tcbvmData.i18n.purgeDoneText || 'پاکسازی پایان یافت.');
							};

							if (deleteGlobalAfter) {
								self.runGlobalAttributeDelete(attrName, consoleId, finalize);
							} else {
								finalize();
							}
						});
						return;
					}

					const batchIds = batches[currentBatchIndex];
					const batchNum = currentBatchIndex + 1;
					$pText.text('در حال پاکسازی بسته ' + self.toPersianDigits(batchNum) + ' از ' + self.toPersianDigits(batches.length) + '…');
					self.log('⏳ بسته ' + self.toPersianDigits(batchNum) + ' شامل ' + self.toPersianDigits(batchIds.length) + ' محصول…', 'info', consoleId);

						$.ajax({
							url: tcbvmData.ajaxUrl,
							type: 'POST',
							data: {
								action: 'tcbvm_purge_attr_batch',
								nonce: tcbvmData.nonce,
								run_id: runId,
								batch_ids: batchIds,
								attr_name: attrName
							},
							success: function(bResp) {
								const stopForSafety = !bResp.success || !!(bResp.data && (bResp.data.stop || bResp.data.fatal));
								if (bResp.success && bResp.data && bResp.data.items) {
									bResp.data.items.forEach(function(it) {
										processedCount++;
										allItems.push(it);
										if (it.status === 'success') {
											successCount++;
											totalDeleted += (it.deleted || 0);
											self.log('[#' + it.id + '] ' + it.title + ' ➔ ' + it.message, 'success', consoleId);
										} else {
											failedCount++;
											self.log('[#' + it.id + '] ' + it.title + ' ➔ خطا: ' + it.message, 'error', consoleId);
										}
									});
								} else {
									batchIds.forEach(function(pid) {
										processedCount++;
										failedCount++;
										const errItem = { id: pid, status: 'error', title: 'محصول #' + pid, message: (bResp.data && bResp.data.message ? bResp.data.message : 'خطا در پردازش بسته') };
										allItems.push(errItem);
										self.log('محصول #' + pid + ': خطا در بسته پاکسازی', 'error', consoleId);
									});
								}

								if (stopForSafety && bResp.success && bResp.data && bResp.data.items) {
									const returnedIds = Object.create(null);
									bResp.data.items.forEach(function(it) { returnedIds[String(it.id)] = true; });
									batchIds.forEach(function(pid) {
										if (!returnedIds[String(pid)]) {
											processedCount++;
											failedCount++;
											const skipped = { id: pid, status: 'error', title: 'محصول #' + pid, message: 'به‌دلیل توقف ایمنی، این محصول پردازش نشد.' };
											allItems.push(skipped);
										}
									});
								}

								const pct = Math.round((processedCount / totalProducts) * 100);
								$bar.css('width', pct + '%');
								$pPercent.text(self.toPersianDigits(pct) + '٪');
								$('#tcbvm-purge-stat-processed').text(self.toPersianDigits(processedCount));
								$('#tcbvm-purge-stat-success').text(self.toPersianDigits(successCount));
								$('#tcbvm-purge-stat-failed').text(self.toPersianDigits(failedCount));

								if (stopForSafety) {
									const stopMessage = bResp.data && bResp.data.message ? bResp.data.message : 'پاسخ امن و کامل از سرور دریافت نشد.';
									self.finishRun(runId, 0, totalDeleted, allItems, function() {
										$pText.text('پاکسازی برای ایمنی متوقف شد؛ ادامهٔ بسته‌ها و حذف سراسری اجرا نشد. وضعیت را در تاریخچه بررسی کنید.');
										self.log('⚠ ادامهٔ پاکسازی متوقف شد: ' + stopMessage + '؛ حذف سراسری انجام نشد.', 'error', consoleId);
										self.isPurging = false;
										self.purgeCancelRequested = false;
										$('#tcbvm-btn-purge-run, #tcbvm-btn-purge-search').prop('disabled', false);
										$('#tcbvm-btn-cancel-purge-run').prop('disabled', true);
									}, 'failed');
									return;
								}

								currentBatchIndex++;
								setTimeout(runNextBatch, 50);
							},
							error: function(xhr, status, err) {
								self.log('خطای شبکه در بسته ' + batchNum + ': ' + err + '؛ ادامهٔ پاکسازی متوقف شد.', 'error', consoleId);
								batchIds.forEach(function(pid) {
									processedCount++;
									failedCount++;
									allItems.push({ id: pid, status: 'error', title: 'محصول #' + pid, message: 'پاسخ شبکه نامشخص است؛ وضعیت را از تاریخچه بررسی کنید.' });
								});
								self.finishRun(runId, 0, totalDeleted, allItems, function() {
									$pText.text('به‌علت خطای شبکه، پاکسازی متوقف شد؛ حذف سراسری انجام نشد. وضعیت را در تاریخچه بررسی کنید.');
									self.isPurging = false;
									self.purgeCancelRequested = false;
									$('#tcbvm-btn-purge-run, #tcbvm-btn-purge-search').prop('disabled', false);
									$('#tcbvm-btn-cancel-purge-run').prop('disabled', true);
								}, 'failed');
							}
						});
					};

					runNextBatch();
				},
				error: function() {
					alert('خطا در شروع نشست پاکسازی با سرور.');
					self.isPurging = false;
					$('#tcbvm-btn-purge-run, #tcbvm-btn-purge-search').prop('disabled', false);
				}
			});
		},

		/**
		 * حذف سراسری تعریف ویژگی و ترم‌هایش (در صورت فعال بودن گزینهٔ کاربر).
		 */
		runGlobalAttributeDelete: function(attrName, consoleId, callback) {
			const self = this;

			const msg = (tcbvmData.i18n.purgeConfirmGlobal || 'ادامه می‌دهید؟').replace('{attr}', attrName);
			if (!confirm(msg)) {
				self.log('حذف سراسری تعریف ویژگی توسط کاربر لغو شد.', 'info', consoleId);
				if (typeof callback === 'function') callback(true);
				return;
			}

			self.log('در حال حذف سراسری تعریف ویژگی «' + attrName + '» و ترم‌هایش…', 'info', consoleId);

			$.ajax({
				url: tcbvmData.ajaxUrl,
				type: 'POST',
				data: {
					action: 'tcbvm_purge_attr_global',
					nonce: tcbvmData.nonce,
					attr_name: attrName
				},
				success: function(resp) {
					let text = '';
					if (resp.success && resp.data) {
						const terms = resp.data.terms || 0;
						text = (resp.data.message || 'حذف سراسری انجام شد.') + ' (' + self.toPersianDigits(terms) + ' ترم)';
						self.log('حذف سراسری کامل شد: ' + text, 'success', consoleId);
					} else {
						text = resp.data && resp.data.message ? resp.data.message : 'خطای نامشخص در حذف سراسری.';
						self.log('حذف سراسری: ' + text, 'error', consoleId);
					}
					alert(text);
					if (typeof callback === 'function') callback(true);
				},
				error: function() {
					self.log('خطای شبکه هنگام حذف سراسری ویژگی.', 'error', consoleId);
					alert('خطای شبکه هنگام حذف سراسری ویژگی.');
					if (typeof callback === 'function') callback(false);
				}
			});
		},

		/**
		 * پاکسازی ترنزینت‌ها و کش قیمت ووکامرس
		 */
		bindCacheFlush: function() {
			$('#tcbvm-btn-flush-cache').on('click', function(e) {
				e.preventDefault();
				const $btn = $(this);
				$btn.prop('disabled', true).text('در حال نوسازی…');

				$.ajax({
					url: tcbvmData.ajaxUrl,
					type: 'POST',
					data: {
						action: 'tcbvm_flush_cache',
						nonce: tcbvmData.nonce
					},
					success: function(resp) {
						$btn.prop('disabled', false).text('نوسازی کش قیمت‌های متغیر ووکامرس');
						alert(resp.data && resp.data.message ? resp.data.message : 'کش نوسازی شد.');
					},
					error: function() {
						$btn.prop('disabled', false).text('نوسازی کش قیمت‌های متغیر ووکامرس');
						alert('خطا در نوسازی کش.');
					}
				});
			});
		}
	};

	$(document).ready(function() {
		TCBVM.init();
	});

})(jQuery);
