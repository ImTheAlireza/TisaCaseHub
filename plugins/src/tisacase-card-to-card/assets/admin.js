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
    const add = document.querySelector('#tcc-add-card');
    if (add) add.addEventListener('click', () => {
        const cards = document.querySelector('#tcc-cards');
        if (cards.children.length >= 20) return;
        const template = document.querySelector('#tcc-card-template');
        const wrapper = document.createElement('div'); wrapper.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(cards.children.length));
        cards.append(wrapper.firstElementChild);
    });
}());
