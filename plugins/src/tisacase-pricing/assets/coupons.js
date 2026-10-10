/**
 * TisaCase Pricing — تب کد تخفیف: کپی کد، کد تصادفی، حذف/تغییر وضعیت با فرم‌های مخفی.
 */
(function ($) {
	'use strict';

	var ALPHA = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

	function randomCode(len) {
		var out = '';
		for (var i = 0; i < len; i++) { out += ALPHA[Math.floor(Math.random() * ALPHA.length)]; }
		return out;
	}

	$('#tcp-cp-random').on('click', function () {
		$('#tcp-cp-code').val(randomCode(8)).trigger('focus');
	});

	$('.tcp-cp-type').on('change', function () {
		var pct = $(this).val() === 'percent';
		$(this).closest('.tcp-grid-3').find('.tcp-cp-unit').text(pct ? '(٪)' : '(' + ((window.TCP_COUPONS && window.TCP_COUPONS.currency) || '') + ')');
	});

	$(document).on('click', '.tcp-cp-code', function () {
		var code = $(this).data('code');
		var $b = $(this);
		var done = function () { $b.addClass('is-copied'); setTimeout(function () { $b.removeClass('is-copied'); }, 900); };
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(code).then(done);
		} else {
			var ta = $('<textarea>').val(code).appendTo('body').select();
			try { document.execCommand('copy'); } catch (e) { /* noop */ }
			ta.remove(); done();
		}
	});

	$('#tcp-cp-all').on('change', function () {
		$('input[name="coupon_ids[]"]').prop('checked', this.checked).trigger('change');
	});
	$(document).on('change', 'input[name="coupon_ids[]"]', function () {
		$('#tcp-cp-delete-selected').prop('disabled', !$('input[name="coupon_ids[]"]:checked').length);
	});

	$('#tcp-cp-list-form').on('submit', function () {
		var n = $('input[name="coupon_ids[]"]:checked').length;
		return window.confirm(n + ' کد برای همیشه حذف شود؟');
	});

	$('#tcp-cp-delete-batch').on('click', function () {
		var b = $(this).data('batch');
		if (!window.confirm('همهٔ کدهای گروه «' + b + '» حذف شوند؟')) { return; }
		var $f = $('#tcp-cp-list-form');
		if (!$f.length) { return; }
		$f.off('submit');
		$('input[name="coupon_ids[]"]').prop('checked', false);
		$('#tcp-cp-delete-batch-input').val(b);
		$f.trigger('submit');
	});

	$(document).on('click', '.tcp-cp-toggle', function () {
		$('#tcp-cp-toggle-id').val($(this).data('id'));
		$('#tcp-cp-toggle-form').trigger('submit');
	});

	function phoneMode() {
		var enabled = $('input[name="phone_enabled"]').prop('checked');
		$('.tcp-phone-options').toggle(!!enabled);
		$('input[name="phone_limit"]').prop('required', !!enabled);
		$('input[name="phone_enabled"]').attr({'aria-controls': 'tcp-phone-options', 'aria-expanded': String(!!enabled)});
		if ($('input[name="phone_enabled"]').length) {
			$('input[name="usage_limit"], input[name="usage_limit_per_user"]').prop('disabled', !!enabled);
		}
	}
	$('input[name="phone_enabled"]').on('change', phoneMode);
	phoneMode();
	function phoneAudience() {
		var selected = $('input[name="phone_selected"]').prop('checked');
		$('.tcp-phone-list').toggle(!!selected);
		$('.tcp-phone-all').toggle(!selected);
		$('input[name="phone_selected"]').attr({'aria-controls': 'tcp-phone-list', 'aria-expanded': String(!!selected)});
	}
	$('input[name="phone_selected"]').on('change', phoneAudience);
	phoneAudience();
	$('.tcp-phone-import').on('click', function () {
		if (window.confirm('فهرست فعلی فرم با شماره‌های نسخه قدیمی جایگزین شود؟ برای اعمال، ذخیره کنید.')) {
			$('textarea[name="phone_list"]').val($(this).attr('data-phones'));
			$('input[name="phone_selected"]').prop('checked', true).trigger('change');
		}
	});

	$('.tcp-phone-sync').on('submit', function (event) {
		if (!window.fetch) { return; } // Standard POST remains a one-batch no-JS fallback.
		event.preventDefault();
		var form = this, $form = $(form);
		if ($form.data('running')) { return; }
		$form.data('running', true);
		var stopped = false;
		var $start = $form.find('button:not([type="button"])').prop('disabled', true);
		var $stop = $form.find('.tcp-phone-sync-stop').prop('hidden', false);
		var $status = $form.find('.tcp-phone-sync-status');
		$stop.off('click').on('click', function () { stopped = true; $status.text('توقف پس از ذخیره دسته جاری…'); });
		function end(message) {
			$form.data('running', false); $start.prop('disabled', false); $stop.prop('hidden', true); $status.text(message);
		}
		function batch() {
			$status.text('در حال بررسی یک دسته از سفارش‌ها…');
			fetch($form.attr('data-ajax-url'), {method: 'POST', credentials: 'same-origin', body: new FormData(form)})
				.then(function (response) { if (!response.ok) { throw new Error('خطای سرور؛ ادامه را دوباره بزنید.'); } return response.json(); })
				.then(function (result) {
					if (!result.success) { throw new Error((result.data && result.data.message) || 'همگام‌سازی انجام نشد.'); }
					if (result.data.done) { end('همگام‌سازی کامل شد.'); window.location.reload(); return; }
					if (stopped) { end('متوقف شد. دسته بعدی: ' + result.data.page); return; }
					$status.text('دسته ذخیره شد؛ دسته بعدی: ' + result.data.page);
					setTimeout(function () { if (stopped) { end('متوقف شد؛ برای ادامه دوباره شروع را بزنید.'); } else { batch(); } }, 300);
				})
				.catch(function (error) { end(error.message || 'اتصال قطع شد؛ دوباره ادامه دهید.'); });
		}
		batch();
	});
})(jQuery);

/* Independent per-coupon report. One read request at a time; no overlapping polling. */
(function ($) {
	'use strict';
	var $root = $('#tcp-coupon-report');
	if (!$root.length || !window.fetch) { return; }
	var $content = $('#tcp-report-content'), $status = $('#tcp-report-status');
	var page = Number($content.find('.tcp-report-meta').attr('data-page')) || 1;
	var reading = false, syncing = false, stop = false;
	function request(values) {
		var body = new FormData();
		body.append('action', 'tcp_coupon_report');
		body.append('nonce', $root.attr('data-nonce'));
		body.append('coupon_id', $root.attr('data-id'));
		Object.keys(values).forEach(function (key) { body.append(key, values[key]); });
		return fetch($root.attr('data-url'), {method: 'POST', credentials: 'same-origin', body: body})
			.then(function (response) { return response.json(); })
			.then(function (result) { if (!result.success) { throw new Error((result.data && result.data.message) || 'دسترسی یا درخواست معتبر نیست؛ صفحه را دوباره باز کنید.'); } return result.data; });
	}
	function refresh(target) {
		if (reading) { return; }
		reading = true;
		$('#tcp-report-refresh, #tcp-report-filter button').prop('disabled', true);
		request({operation: 'read', report_page: target || page, order_id: $('#tcp-report-filter [name="order_id"]').val() || 0})
			.then(function (data) {
				$content.html(data.html);
				page = Number($content.find('.tcp-report-meta').attr('data-page')) || 1;
				if (!syncing) { $status.text('اطلاعات فعلی سفارش‌ها دریافت شد.'); }
			})
			.catch(function (error) { $status.text('تازه‌سازی ناموفق؛ اطلاعات قبلی روی صفحه باقی مانده است. ' + error.message); })
			.finally(function () { reading = false; $('#tcp-report-refresh, #tcp-report-filter button').prop('disabled', false); });
	}
	$('#tcp-report-refresh').on('click', function () { refresh(); });
	$('#tcp-report-filter').on('submit', function (event) { event.preventDefault(); refresh(1); });
	$content.on('click', '.tcp-report-page', function (event) { event.preventDefault(); refresh(Number($(this).attr('data-page'))); });
	setInterval(function () {
		// Do not replace expanded details or disrupt keyboard focus while someone reads/interacts.
		if (!document.hidden && !syncing && $('#tcp-report-auto').prop('checked') && !$content.find('details[open]').length && !$content[0].contains(document.activeElement)) { refresh(); }
	}, 30000);
	$('#tcp-report-stop').on('click', function () { stop = true; $status.text('توقف پس از ذخیره دسته جاری…'); });
	$('#tcp-report-sync').on('click', function () {
		if (syncing) { return; }
		var $button = $(this), restart = $button.attr('data-restart') === '1';
		syncing = true; stop = false; $button.prop('disabled', true); $('#tcp-report-stop').prop('hidden', false);
		function end(message) {
			syncing = false; $button.prop('disabled', false); $('#tcp-report-stop').prop('hidden', true); $status.text(message);
		}
		function batch() {
			request({operation: 'sync', restart: restart ? 1 : 0})
				.then(function (data) {
					restart = false; $button.attr('data-restart', data.done ? '1' : '0');
					if (data.done) { $('#tcp-report-sync-state').text('همگام‌سازی سوابق گزارش کامل شد.'); end('همگام‌سازی گزارش کامل شد؛ ' + data.checked + ' سفارش بررسی شد.'); refresh(); return; }
					$status.text(data.checked + ' سفارش بررسی شد؛ ادامه…');
					if (stop) { end('متوقف شد؛ با همان دکمه می‌توانید ادامه دهید.'); refresh(); return; }
					setTimeout(function () { if (stop) { end('متوقف شد.'); } else { batch(); } }, 300);
				})
				.catch(function (error) { $button.attr('data-restart', '0'); end('همگام‌سازی متوقف شد. ' + error.message); });
		}
		$status.text('در حال خواندن سوابق سفارش‌ها…'); batch();
	});
})(jQuery);
