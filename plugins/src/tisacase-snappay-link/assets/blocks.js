/* global wc, wp */
(function () {
    'use strict';
    const data = wc.wcSettings.getSetting('tisacase_snappay_link_data', {});
    const e = wp.element.createElement;
    const title = wp.htmlEntities.decodeEntities(data.title || 'پرداخت اقساطی با اسنپ‌پی');
    const Content = () => e('div', {className: 'tspay-block-content'},
        data.description ? e('p', null, wp.htmlEntities.decodeEntities(data.description)) : null,
        data.fee_percent > 0 ? e('p', {className: 'tspay-fee-hint'}, (data.fee_hint || 'کارمزد اسنپ‌پی (%s٪) به مبلغ کل سفارش افزوده می‌شود.').replace('%s', String(data.fee_percent))) : null,
        data.warning ? e('div', {className: 'tspay-warning', role: 'alert'}, e('strong', null, 'هشدار پیش از پرداخت'), e('p', null, wp.htmlEntities.decodeEntities(data.warning))) : null
    );
    wc.wcBlocksRegistry.registerPaymentMethod({name: 'tisacase_snappay_link', label: title, ariaLabel: title, content: e(Content), edit: e(Content), canMakePayment: () => true, supports: {features: data.supports || ['products']}});
}());
