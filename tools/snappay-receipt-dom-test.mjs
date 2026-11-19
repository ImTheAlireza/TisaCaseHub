import { JSDOM } from 'jsdom';
import fs from 'node:fs';
import assert from 'node:assert/strict';
const script = fs.readFileSync('plugins/src/tisacase-snappay-link/assets/tspay.js', 'utf8');
for (const [panel, text, expected] of [[true, ' \n\t ', false], [true, '', false], [true, 'کد رهگیری دریافت شد', true], [false, ' \n ', true]]) {
 const dom = new JSDOM(`<p class="woocommerce-thankyou-order-received">${text}</p>${panel ? '<section class="tspay-panel"></section>' : ''}`, { runScripts: 'outside-only' });
 dom.window.eval(script);
 dom.window.document.dispatchEvent(new dom.window.Event('DOMContentLoaded'));
 assert.equal(!!dom.window.document.querySelector('.woocommerce-thankyou-order-received'), expected);
 dom.window.close();
}
console.log('PASS: empty/whitespace receipts removed; real messages and other gateways preserved');
