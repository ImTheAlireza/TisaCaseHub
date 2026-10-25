(function () {
    'use strict';
    document.querySelectorAll('[data-tcc-order]').forEach(panel => {
        const form = document.querySelector('form#post, form#order, form#woocommerce-order');
        const feedback = panel.querySelector('.tcc-feedback');
        const choices = Array.from(panel.querySelectorAll('[data-tcc-decision]'));
        const reason = panel.querySelector('[data-tcc-reason]'), confirmed = panel.querySelector('[data-tcc-confirmed]');
        const clear = panel.querySelector('[data-tcc-clear]');
        if (!form) { feedback.textContent = 'برای ثبت تصمیم، صفحه ویرایش سفارش ووکامرس را باز کنید.'; choices.forEach(choice => { choice.disabled = true; }); return; }
        panel.querySelectorAll('[name^="tcc_review["]').forEach(control => { if (control.form !== form) control.setAttribute('form', form.id); });
        const selected = () => choices.find(choice => choice.checked);
        const validity = () => {
            const choice = selected(), decision = choice ? choice.value : '';
            const needsReason = ['reject', 'correct', 'reconcile'].includes(decision);
            if (reason) { reason.required = needsReason; reason.setCustomValidity(needsReason && !reason.value.trim() ? 'پیام قابل‌نمایش به مشتری را بنویسید.' : ''); }
            if (confirmed) { confirmed.required = decision === 'approve'; confirmed.setCustomValidity(decision === 'approve' && !confirmed.checked ? 'بررسی ورود وجه به حساب مقصد را تأیید کنید.' : ''); }
        };
        const update = () => {
            validity(); const choice = selected();
            feedback.textContent = choice ? '«' + choice.nextElementSibling.textContent.trim() + '» انتخاب شد؛ برای ثبت، «به‌روزرسانی سفارش» را بزنید.' : 'تصمیم را انتخاب کنید؛ با «به‌روزرسانی سفارش» ثبت می‌شود.';
            clear.hidden = !choice; panel.classList.toggle('has-pending-decision', Boolean(choice));
        };
        choices.forEach(choice => choice.addEventListener('change', update));
        [reason, confirmed].filter(Boolean).forEach(control => { control.addEventListener('input', validity); control.addEventListener('change', validity); });
        clear.addEventListener('click', () => { choices.forEach(choice => { choice.checked = false; }); update(); });
        panel.querySelectorAll('.tcc-reason-preset').forEach(select => select.addEventListener('change', () => { if (select.value && reason) { reason.value = select.value; validity(); } }));
        panel.querySelectorAll('.tcc-rotate').forEach(button => button.addEventListener('click', () => { const image = button.closest('.tcc-admin-image').querySelector('img'); const rotation = (Number(image.dataset.rotation || 0) + 90) % 360; image.dataset.rotation = rotation; image.style.transform = 'rotate(' + rotation + 'deg)'; }));
        form.addEventListener('invalid', event => {
            if (!panel.contains(event.target)) return;
            feedback.textContent = event.target.validationMessage;
            const details = event.target.closest('details'); if (details) details.open = true;
        }, true);
        form.addEventListener('submit', event => {
            if (!selected()) return;
            validity();
            const invalid = [reason, confirmed].filter(Boolean).find(control => !control.checkValidity());
            if (invalid) { event.preventDefault(); feedback.textContent = invalid.validationMessage; invalid.focus(); return; }
            feedback.textContent = 'سفارش و تصمیم انتخاب‌شده در حال ذخیره‌اند…';
            // No fetch, hidden auto-submit or browser fingerprint. The native Woo
            // Update request carries the selection; the server verifies saved data.
        });
        update();
    });
    const settings = document.querySelector('#tcc-settings-form');
    if (settings) {
        const tabs = Array.from(settings.querySelectorAll('[data-tcc-tab]'));
        const panels = Array.from(settings.querySelectorAll('[data-tcc-panel]'));
        const feedback = settings.querySelector('.tcc-settings-feedback');
        const activate = (key, focus = false, updateHash = true) => {
            if (!tabs.some(tab => tab.dataset.tccTab === key)) return;
            tabs.forEach(tab => {
                const selected = tab.dataset.tccTab === key;
                tab.setAttribute('aria-selected', String(selected));
                tab.tabIndex = selected ? 0 : -1;
                if (selected && focus) tab.focus();
            });
            panels.forEach(panel => { panel.hidden = panel.dataset.tccPanel !== key; });
            settings.elements.ui_tab.value = key;
            if (updateHash) history.replaceState(null, '', '#tcc-tab-' + key);
        };
        settings.querySelector('.tcc-tabs').setAttribute('role', 'tablist');
        tabs.forEach((tab, index) => {
            tab.setAttribute('role', 'tab'); tab.setAttribute('aria-controls', 'tcc-tab-' + tab.dataset.tccTab);
            tab.addEventListener('click', event => { event.preventDefault(); activate(tab.dataset.tccTab); });
            tab.addEventListener('keydown', event => {
                const rtl = settings.closest('[dir]').dir === 'rtl';
                let next;
                if (event.key === 'Home') next = 0;
                if (event.key === 'End') next = tabs.length - 1;
                if (event.key === 'ArrowRight') next = (index + (rtl ? -1 : 1) + tabs.length) % tabs.length;
                if (event.key === 'ArrowLeft') next = (index + (rtl ? 1 : -1) + tabs.length) % tabs.length;
                if (next === undefined) return;
                event.preventDefault(); activate(tabs[next].dataset.tccTab, true);
            });
        });
        panels.forEach(panel => panel.setAttribute('role', 'tabpanel'));
        const hash = window.location.hash.replace(/^#tcc-tab-/, '');
        activate(tabs.some(tab => tab.dataset.tccTab === hash) ? hash : settings.dataset.activeTab, false, false);
        // Native validation must reveal the invalid control, including closed card editors.
        settings.addEventListener('invalid', event => {
            const panel = event.target.closest('[data-tcc-panel]');
            if (panel) activate(panel.dataset.tccPanel);
            const details = event.target.closest('details');
            if (details) details.open = true;
        }, true);
        const markSettingsDirty = () => { feedback.textContent = 'تغییرات ذخیره‌نشده'; };
        settings.addEventListener('input', markSettingsDirty); settings.addEventListener('change', markSettingsDirty);
        const cards = settings.querySelector('#tcc-cards');
        const add = settings.querySelector('#tcc-add-card');
        const updateCount = () => {
            settings.querySelector('#tcc-card-count').textContent = String(cards.children.length);
            add.disabled = cards.children.length >= 20;
            add.title = add.disabled ? 'حداکثر ۲۰ کارت؛ ابتدا حذف‌ها را ذخیره کنید.' : 'افزودن کارت';
        };
        const updateCard = row => {
            const value = key => row.querySelector('[data-card-field="' + key + '"]');
            const bank = value('bank');
            const logo = row.querySelector('[data-tcc-bank-logo]');
            const fallback = row.querySelector('[data-tcc-bank-fallback]');
            const src = bank.selectedOptions?.[0]?.dataset.bankLogo || '';
            if (logo && fallback) {
                if (src && logo.getAttribute('src') !== src) logo.setAttribute('src', src);
                if (!src) logo.removeAttribute('src');
                logo.hidden = !src; fallback.hidden = Boolean(src);
                logo.onerror = () => { logo.hidden = true; fallback.hidden = false; };
            }
            const removed = value('remove').checked;
            const active = value('active').checked;
            row.classList.toggle('is-removing', removed);
            row.querySelector('.tcc-card-title').textContent = value('label').value.trim() || value('bank').value.trim() || 'کارت جدید';
            // Preserve Persian/Arabic digits in the preview; authoritative validation stays in PHP.
            const number = value('number').value.replace(/[\s-]/g, '');
            row.querySelector('.tcc-card-last4').textContent = number ? '•••• ' + number.slice(-4) : '—';
            const state = row.querySelector('.tcc-card-state');
            state.textContent = removed ? 'حذف پس از ذخیره' : active ? 'فعال' : 'غیرفعال';
            state.classList.toggle('tcc-approved', active && !removed);
            const option = settings.querySelector('[name="settings[default_card]"]').options[row.dataset.optionIndex];
            if (option) option.textContent = row.querySelector('.tcc-card-title').textContent + ' · ' + number.slice(-4);
        };
        Array.from(cards.children).forEach((row, index) => { row.dataset.optionIndex = String(index + 1); updateCard(row); });
        ['input', 'change'].forEach(type => cards.addEventListener(type, event => {
            const row = event.target.closest('.tcc-card-row'); if (row) updateCard(row);
        }));
        add.addEventListener('click', () => {
            if (cards.children.length >= 20) return;
            const template = settings.querySelector('#tcc-card-template');
            const wrapper = document.createElement('div');
            wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(cards.children.length));
            const row = wrapper.firstElementChild;
            cards.append(row); updateCard(row); updateCount(); markSettingsDirty();
            row.querySelector('[data-card-field=number]').focus();
        });
        updateCount();
    }
}());
