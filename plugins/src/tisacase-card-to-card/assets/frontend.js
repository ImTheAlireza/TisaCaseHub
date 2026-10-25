(function () {
    'use strict';
    document.querySelectorAll('.tcc-payment').forEach(panel => {
        panel.querySelectorAll('[data-tcc-refresh]').forEach(button => button.addEventListener('click', () => window.location.reload()));
        panel.querySelectorAll('[data-tcc-copy]').forEach(button => {
            const label = button.querySelector('[data-copy-label]'), original = label ? label.textContent : '';
            let timer;
            button.addEventListener('click', async () => {
                const message = panel.querySelector('.tcc-copy-feedback');
                try {
                    if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(button.dataset.tccCopy);
                    else {
                        const field = document.createElement('textarea'); field.value = button.dataset.tccCopy;
                        field.style.position = 'fixed'; field.style.opacity = '0'; document.body.append(field); field.select();
                        const ok = document.execCommand('copy'); field.remove(); if (!ok) throw new Error('copy');
                    }
                    message.textContent = button.dataset.tccKind === 'amount' ? 'مبلغ ریالی کپی شد؛ واحد اپ بانکی را بررسی کنید.' : 'شماره کارت کپی شد.';
                    if (label) label.textContent = 'کپی شد'; button.classList.add('is-copied');
                    clearTimeout(timer); timer = setTimeout(() => { if (label) label.textContent = original; button.classList.remove('is-copied'); }, 1800);
                } catch (_) { message.textContent = 'کپی خودکار ممکن نیست؛ شماره یا مبلغ را انتخاب و دستی کپی کنید.'; }
            });
        });
        const form = panel.querySelector('.tcc-upload-form'); if (!form) return;
        const file = form.querySelector('[type=file]'), preview = form.querySelector('.tcc-preview'), feedback = form.querySelector('.tcc-feedback'), progress = form.querySelector('progress');
        const filename = form.querySelector('[data-tcc-file-name]'), picker = form.querySelector('.tcc-file-picker');
        const updateFilename = () => {
            const name = file.files && file.files[0] ? file.files[0].name : '';
            if (filename) { filename.textContent = name || 'تصویری انتخاب نشده'; filename.title = name; }
            if (picker) picker.classList.toggle('has-file', Boolean(name));
        };
        updateFilename(); let objectUrl;
        file.addEventListener('change', () => {
            updateFilename();
            if (objectUrl) { URL.revokeObjectURL(objectUrl); objectUrl = null; }
            preview.hidden = true; preview.removeAttribute('src'); feedback.textContent = '';
            if (!file.files[0]) return;
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.files[0].type)) { feedback.textContent = 'فقط JPG، PNG و WebP پشتیبانی می‌شوند؛ نوع واقعی در سرور هم بررسی می‌شود.'; return; }
            objectUrl = URL.createObjectURL(file.files[0]); preview.src = objectUrl; preview.hidden = false;
        });
        form.addEventListener('submit', event => {
            if (!window.FormData || !window.XMLHttpRequest) return;
            event.preventDefault(); const button = form.querySelector('[type=submit]'); if (button.disabled) return;
            const xhr = new XMLHttpRequest(); xhr.open('POST', form.dataset.ajax); xhr.responseType = 'json'; xhr.timeout = 120000;
            button.disabled = true; form.setAttribute('aria-busy', 'true'); progress.hidden = false; progress.value = 0; feedback.textContent = 'در حال ارسال رسید…';
            xhr.upload.onprogress = e => { if (e.lengthComputable) { progress.value = Math.round(e.loaded / e.total * 100); feedback.textContent = progress.value < 100 ? 'در حال ارسال: ' + progress.value + '٪' : 'فایل ارسال شد؛ منتظر ثبت نهایی سرور…'; } };
            const failed = text => { feedback.textContent = text; progress.hidden = true; button.disabled = false; form.removeAttribute('aria-busy'); };
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
