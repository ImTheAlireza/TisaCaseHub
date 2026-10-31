(function () {
    'use strict';

    document.querySelectorAll('.tspay-admin-review').forEach(function (box) {
        var feedback = box.querySelector('.tspay-admin-feedback');
        var buttons = box.querySelectorAll('[data-decision]');

        buttons.forEach(function (button) {
            button.addEventListener('click', function () {
                var decision = button.getAttribute('data-decision');
                var confirmed = box.querySelector('[data-tspay-confirm]');
                var reason = box.querySelector('[data-tspay-reason]');
                if (decision === 'approve' && confirmed && !confirmed.checked) {
                    if (feedback) feedback.textContent = 'قبل از تأیید، دریافت وجه و مبلغ را بررسی و تیک تأیید را بزنید.';
                    return;
                }
                if (decision === 'reject' && (!reason || !reason.value.trim())) {
                    if (feedback) feedback.textContent = 'برای رد اعلام پرداخت، علت را وارد کنید.';
                    if (reason) reason.focus();
                    return;
                }
                var prompt = decision === 'approve'
                    ? 'فقط پس از تطبیق واقعی وجه در اسنپ‌پی تأیید کنید. سفارش به وضعیت پرداخت‌شده می‌رود. ادامه می‌دهید؟'
                    : 'اعلام پرداخت رد و سفارش ناموفق می‌شود. این کار وجه را بازپرداخت نمی‌کند. ادامه می‌دهید؟';
                if (!window.confirm(prompt)) return;

                var payload = new URLSearchParams();
                payload.set('action', 'tspay_admin_review');
                payload.set('order_id', box.getAttribute('data-order') || '');
                payload.set('nonce', box.getAttribute('data-nonce') || '');
                payload.set('decision', decision);
                payload.set('confirmed', decision === 'approve' && confirmed && confirmed.checked ? '1' : '0');
                payload.set('reason', decision === 'reject' && reason ? reason.value.trim() : '');
                buttons.forEach(function (item) { item.disabled = true; });
                if (feedback) feedback.textContent = 'در حال ثبت تصمیم…';

                window.fetch(box.getAttribute('data-ajax'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: payload.toString()
                }).then(function (response) {
                    return response.json().then(function (data) {
                        if (!response.ok || !data || !data.success) {
                            throw new Error(data && data.data && data.data.message ? data.data.message : 'ثبت تصمیم ناموفق بود.');
                        }
                        return data;
                    });
                }).then(function (data) {
                    if (feedback) feedback.textContent = data.data.message || 'تصمیم ثبت شد.';
                    window.setTimeout(function () { window.location.reload(); }, 900);
                }).catch(function (error) {
                    if (feedback) feedback.textContent = error.message || 'خطا در ارتباط؛ وضعیت سفارش را دوباره بررسی کنید.';
                    buttons.forEach(function (item) { item.disabled = false; });
                });
            });
        });
    });
}());
