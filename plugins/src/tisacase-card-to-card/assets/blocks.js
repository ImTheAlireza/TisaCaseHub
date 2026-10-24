/* global wc, wp */
(function () {
    'use strict';
    const data = wc.wcSettings.getSetting('tisacase_card_to_card_data', {});
    const e = wp.element.createElement;
    const title = wp.htmlEntities.decodeEntities(data.title || 'کارت به کارت تیساکیس');
    const Content = () => e('p', null, wp.htmlEntities.decodeEntities(data.description || ''));
    wc.wcBlocksRegistry.registerPaymentMethod({name: 'tisacase_card_to_card', label: title, ariaLabel: title, content: e(Content), edit: e(Content), canMakePayment: () => true, supports: {features: data.supports || ['products']}});
}());
