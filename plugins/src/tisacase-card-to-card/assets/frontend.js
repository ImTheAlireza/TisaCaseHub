(function () {
    'use strict';
    document.querySelectorAll('.tcc-payment').forEach(panel => {
        panel.querySelectorAll('[data-tcc-copy]').forEach(button => button.addEventListener('click', async () => {
            const message = panel.querySelector('.tcc-copy-feedback');
            try {
                if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(button.dataset.tccCopy);
                else {
                    const field = document.createElement('textarea'); field.value = button.dataset.tccCopy;
                    field.style.position = 'fixed'; field.style.opacity = '0'; document.body.append(field); field.select();
                    const ok = document.execCommand('copy'); field.remove(); if (!ok) throw new Error('copy');
                }
                message.textContent = 'کپی شد؛ واحد مبلغ را در اپ بانکی بررسی کنید.';
            } catch (_) { message.textContent = 'کپی خودکار ممکن نیست؛ شماره یا مبلغ را انتخاب و دستی کپی کنید.'; }
        }));
        const form = panel.querySelector('.tcc-upload-form'); if (!form) return;
        const file = form.querySelector('[type=file]'), preview = form.querySelector('.tcc-preview'), feedback = form.querySelector('.tcc-feedback'), progress = form.querySelector('progress');
        let objectUrl;
        file.addEventListener('change', () => {
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            preview.hidden = true; feedback.textContent = '';
            if (!file.files[0]) return;
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.files[0].type)) { feedback.textContent = 'فقط JPG، PNG و WebP پشتیبانی می‌شوند؛ نوع واقعی در سرور هم بررسی می‌شود.'; return; }
            objectUrl = URL.createObjectURL(file.files[0]); preview.src = objectUrl; preview.hidden = false;
        });
        form.addEventListener('submit', event => {
            if (!window.FormData || !window.XMLHttpRequest) return;
            event.preventDefault(); const button = form.querySelector('[type=submit]'); if (button.disabled) return;
            const xhr = new XMLHttpRequest(); xhr.open('POST', form.dataset.ajax); xhr.responseType = 'json'; xhr.timeout = 120000;
            button.disabled = true; progress.hidden = false; feedback.textContent = 'در حال ارسال رسید…';
            xhr.upload.onprogress = e => { if (e.lengthComputable) { progress.value = Math.round(e.loaded / e.total * 100); feedback.textContent = progress.value < 100 ? 'در حال ارسال: ' + progress.value + '٪' : 'فایل ارسال شد؛ منتظر ثبت نهایی سرور…'; } };
            const failed = text => { feedback.textContent = text; progress.hidden = true; button.disabled = false; };
            xhr.onerror = () => failed('ارتباط قطع شد؛ می‌توانید با همین اطلاعات دوباره تلاش کنید. سفارش تازه ایجاد نمی‌شود.');
            xhr.ontimeout = () => failed('پاسخ نرسید؛ وضعیت را بررسی کنید یا با همین فرم دوباره تلاش کنید.');
            xhr.onload = () => {
                const result = xhr.response;
                if (xhr.status >= 200 && xhr.status < 300 && result && result.success) { feedback.textContent = result.data.message; window.location.reload(); }
                else failed(result && result.data && result.data.message ? result.data.message : 'پاسخ سرور معتبر نیست؛ صفحه را دوباره باز کنید یا تلاش کنید.');
            };
            xhr.send(new FormData(form));
        });
    });
}());
