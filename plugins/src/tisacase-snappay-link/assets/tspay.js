(function () {
    'use strict';

    // Templates put indentation/newlines inside the receipt paragraph, so CSS
    // :empty alone does not match it. Keep actual receipt/review messages intact.
    function removeEmptyReceipt() {
        if (!document.querySelector('.tspay-panel')) return;
        document.querySelectorAll('.woocommerce-thankyou-order-received').forEach(function (notice) {
            if (!notice.textContent.trim()) notice.remove();
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', removeEmptyReceipt);
    } else {
        removeEmptyReceipt();
    }

    document.querySelectorAll('.tspay-panel [data-tspay-copy]').forEach(function (button) {
        var panel = button.closest('.tspay-panel');
        var label = button.querySelector('[data-copy-label]');
        var original = label ? label.textContent : '';
        var feedback = panel ? panel.querySelector('.tspay-copy-feedback') : null;
        var timer;

        button.addEventListener('click', function () {
            var value = button.getAttribute('data-tspay-copy') || '';
            var success = button.getAttribute('data-copy-success') || 'مبلغ کپی شد.';
            var copied = function () {
                if (feedback) feedback.textContent = success;
                if (label) label.textContent = 'کپی شد';
                button.classList.add('is-copied');
                window.clearTimeout(timer);
                timer = window.setTimeout(function () {
                    if (label) label.textContent = original;
                    button.classList.remove('is-copied');
                }, 1800);
            };
            var fallback = function () {
                var field = document.createElement('textarea');
                field.value = value;
                field.setAttribute('readonly', 'readonly');
                field.style.position = 'fixed';
                field.style.opacity = '0';
                document.body.appendChild(field);
                field.select();
                var ok = false;
                try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
                document.body.removeChild(field);
                if (ok) copied();
                else if (feedback) feedback.textContent = 'کپی خودکار ممکن نیست؛ مبلغ را انتخاب و دستی کپی کنید.';
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(value).then(copied).catch(fallback);
            } else {
                fallback();
            }
        });
    });
}());
