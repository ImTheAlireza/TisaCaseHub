(function () {
    'use strict';
    let dirty = false;
    const orderForm = document.querySelector('form#post, form#order, form#woocommerce-order');
    const orderValues = () => orderForm ? JSON.stringify(Array.from(orderForm.querySelectorAll('input,select,textarea')).filter(field => field.name && !field.closest('.tcc-panel') && !['hidden', 'submit', 'button'].includes(field.type)).map(field => [field.name, field.value, field.checked, field.multiple ? Array.from(field.selectedOptions).map(option => option.value) : null])) : '';
    const initialOrderValues = orderValues();
    const markDirty = event => {
        if (event.target.closest('.tcc-panel')) return;
        if (event.target.closest('form#post, form#order, form#woocommerce-order')) dirty = true;
    };
    document.addEventListener('input', markDirty); document.addEventListener('change', markDirty);
    document.querySelectorAll('.tcc-reason-preset').forEach(select => select.addEventListener('change', () => { if (select.value) select.closest('.tcc-panel').querySelector('[name=reason]').value = select.value; }));
    document.querySelectorAll('.tcc-rotate').forEach(button => button.addEventListener('click', () => { const image = button.closest('.tcc-admin-image').querySelector('img'); const rotation = (Number(image.dataset.rotation || 0) + 90) % 360; image.dataset.rotation = rotation; image.style.transform = 'rotate(' + rotation + 'deg)'; }));
    document.querySelectorAll('.tcc-review-form').forEach(form => form.addEventListener('submit', async event => {
        if (!window.fetch || !window.FormData) return;
        event.preventDefault();
        const panel = document.querySelector('[data-tcc-order="' + form.elements.order_id.value + '"]');
        const feedback = panel.querySelector('.tcc-feedback');
        if (dirty || orderValues() !== initialOrderValues) { feedback.textContent = 'تغییرات ذخیره‌نشده در سفارش دارید. ابتدا سفارش را به‌روزرسانی و سپس پرداخت را بررسی کنید.'; return; }
        const decision = event.submitter && event.submitter.value;
        if (!decision) { feedback.textContent = 'یک اقدام مشخص انتخاب کنید.'; return; }
        const data = new FormData(form); data.set('decision', decision);
        if (decision === 'approve' && data.get('confirmed') !== 'yes' && panel.querySelector('[name=confirmed]')) { feedback.textContent = 'ابتدا بررسی ورود وجه به حساب را تأیید کنید.'; return; }
        if (['reject', 'correct', 'reconcile'].includes(decision) && !String(data.get('reason') || '').trim()) { feedback.textContent = 'علت قابل‌نمایش به مشتری الزامی است.'; return; }
        const buttons = document.querySelectorAll('button[form="' + form.id + '"]');
        buttons.forEach(button => { button.disabled = true; }); feedback.textContent = 'در حال ثبت تصمیم؛ صفحه را نبندید…';
        const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 90000);
        try {
            const response = await fetch(form.dataset.ajax, {method: 'POST', body: data, credentials: 'same-origin', signal: controller.signal});
            const result = await response.json();
            if (!response.ok || !result.success) throw new Error(result.data && result.data.message ? result.data.message : 'ثبت تصمیم ناموفق بود.');
            feedback.textContent = result.data.message; window.location.reload();
        } catch (error) { feedback.textContent = error.name === 'AbortError' || error instanceof TypeError ? 'پاسخ مشخص نیست؛ قبل از تکرار تأیید، سفارش را دوباره باز کنید و وضعیت/سابقه را بررسی کنید.' : error.message; }
        finally { clearTimeout(timer); buttons.forEach(button => { button.disabled = false; }); }
    }));
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
        Array.from(cards.children).forEach((row, index) => { row.dataset.optionIndex = String(index + 1); });
        ['input', 'change'].forEach(type => cards.addEventListener(type, event => {
            const row = event.target.closest('.tcc-card-row'); if (row) updateCard(row);
        }));
        add.addEventListener('click', () => {
            if (cards.children.length >= 20) return;
            const template = settings.querySelector('#tcc-card-template');
            const wrapper = document.createElement('div');
            wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(cards.children.length));
            const row = wrapper.firstElementChild;
            cards.append(row); updateCount(); markSettingsDirty();
            row.querySelector('[data-card-field=number]').focus();
        });
        updateCount();
    }
}());
