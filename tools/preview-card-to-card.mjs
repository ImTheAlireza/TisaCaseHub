/** Preview real PHP-rendered fixtures. No WordPress, data writes or financial actions. */
import http from 'node:http';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {JSDOM} from 'jsdom';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const fixtureDir = path.resolve(root, '.cache/tcc-tests');
const assets = path.join(root, 'plugins/src/tisacase-card-to-card/assets');
const port = Number(process.env.PORT || 4173);
const routes = {'/': 'settings.html', '/order': 'admin.html', '/payment': 'payment-new.html', '/correction': 'customer.html', '/first-run': 'settings-empty.html'};
const escape = value => value.replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;');
const previewCSS = `
@font-face{font-family:Vazirmatn;src:url('/font/400.woff2') format('woff2');font-weight:400;font-display:swap}
@font-face{font-family:Vazirmatn;src:url('/font/600.woff2') format('woff2');font-weight:600;font-display:swap}
@font-face{font-family:Vazirmatn;src:url('/font/700.woff2') format('woff2');font-weight:700;font-display:swap}
html,body{margin:0;min-height:100%;background:#f4f2ec;color:#1f2a2e;font-family:Vazirmatn,Tahoma,sans-serif;font-size:14px}
.preview-chrome{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;padding:14px max(24px,calc((100vw - 1080px)/2));border-bottom:1px solid #e3e1da;background:#fff}
.preview-chrome a{display:inline-flex;min-height:32px;align-items:center;padding:0 10px;color:#65746f;text-decoration:none;font-size:12px;border-radius:7px}
.preview-chrome a[aria-current=page]{color:#064e46;background:#e8f0ee;font-weight:600}
.preview-chrome nav{display:flex;gap:4px;flex-wrap:wrap}
.preview-brand{font:700 13px/1.5 Vazirmatn,Tahoma,sans-serif;color:#064e46;letter-spacing:.05em}
.preview-label{font-size:10px;color:#65746f}.preview-label b{display:inline-block;width:5px;height:5px;background:#b08228;border-radius:50%;margin-inline-end:5px}
.preview-content{max-width:1120px;margin:0 auto;padding:0 20px 40px;box-sizing:border-box}
.preview-order-header{display:flex;align-items:center;justify-content:space-between;gap:16px;margin:24px 0 16px}.preview-order-header h1{margin:0;font-size:20px}
.preview-order-header details{font-size:11px;color:#65746f}.preview-order-header input{max-width:110px;padding:6px;border:1px solid #ddd;border-radius:6px}
.preview-order-host{padding:20px;border:1px solid #e3e1da;border-radius:16px;background:#fff}
.preview-payment-host{max-width:900px;margin:auto}
@media(max-width:600px){.preview-chrome{padding:12px 16px;gap:8px}.preview-label{width:100%}.preview-content{padding-inline:12px}.preview-order-host{padding:12px}.preview-order-header{flex-wrap:wrap}.preview-chrome a{padding-inline:8px}}
`;
const receipt = `<svg xmlns="http://www.w3.org/2000/svg" width="320" height="480" viewBox="0 0 320 480"><rect x="1" y="1" width="318" height="478" rx="12" fill="white" stroke="#e3e1da"/><circle cx="160" cy="67" r="24" fill="#e8f0ee"/><path d="m148 66 8 8 16-18" fill="none" stroke="#0e7c6b" stroke-width="3" stroke-linecap="round"/><g font-family="Tahoma,Arial" text-anchor="middle"><text x="160" y="125" font-size="20" fill="#064e46">TEST RECEIPT</text><text x="160" y="153" font-size="12" fill="#65746f">DEMO ONLY / NOT A BANK DOCUMENT</text><text x="160" y="220" font-size="30" fill="#1f2a2e">485,000</text><text x="160" y="246" font-size="13" fill="#65746f">IRT</text><text x="160" y="310" font-size="14" fill="#1f2a2e">Reference: 000123</text><text x="160" y="343" font-size="14" fill="#65746f">Order #101</text><text x="160" y="424" font-size="11" fill="#65746f">TISACASE / INTERFACE PREVIEW</text></g><path d="M28 274h264M28 382h264" stroke="#e3e1da" stroke-dasharray="4 5"/></svg>`;
function render(route, shared) {
    const file = path.join(fixtureDir, routes[route]);
    if (!fs.existsSync(file)) throw new Error('Run bash tools/test-card-to-card.sh first.');
    const doc = new JSDOM(fs.readFileSync(file, 'utf8')).window.document;
    // Cosmetic fixture cleanup only. Runtime templates are used unchanged.
    const clean = text => text.replaceAll('<script>owner</script>', 'فروشگاه آزمایشی تیساکیس').replaceAll('<script>hello</script>', 'رسید پرداخت سفارش ارسال شد.').replaceAll('<script>reason</script>', 'تصویر رسید خوانا نیست؛ لطفاً عکس واضح‌تری بفرستید.').replaceAll('TEST BANK', 'بانک آزمایشی').replaceAll('TEST ONLY', 'فروشگاه آزمایشی').replaceAll('INTERNAL SECRET', 'یادداشت آزمایشی مسئول مالی');
    const walker = doc.createTreeWalker(doc.body, doc.defaultView.NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) walker.currentNode.textContent = clean(walker.currentNode.textContent);
    doc.querySelectorAll('input[value]').forEach(input => input.setAttribute('value', clean(input.value)));
    doc.querySelectorAll('img').forEach(image => { if (image.src.includes('tcc_receipt')) image.setAttribute('src', '/receipt.svg'); });
    doc.querySelectorAll('a').forEach(link => {
        if (link.href.includes('tcc_receipt')) link.setAttribute('href', '/receipt.svg');
        else if (link.href.includes('tcc_method=')) link.setAttribute('href', '/order');
        else if (link.href.includes('page=tisacase-card-to-card')) link.setAttribute('href', '/');
    });
    doc.querySelectorAll('form').forEach(form => { form.action = '/preview-action'; if (form.dataset.ajax) form.dataset.ajax = '/preview-action'; });
    const input = doc.querySelector('[name=order_total]');
    if (input) {
        const wrapper = doc.createElement('header'); wrapper.className = 'preview-order-header';
        wrapper.innerHTML = '<h1>بررسی پرداخت سفارش #۱۰۱</h1><details><summary>نمونه فرم سفارش</summary><label>مبلغ سفارش </label></details>';
        input.replaceWith(wrapper); wrapper.querySelector('label').append(input);
        const box = doc.querySelector('.tcc-order-box'); const host = doc.createElement('div'); host.className = 'preview-order-host'; box.replaceWith(host); host.append(box);
    }
    const customer = ['/payment', '/correction'].includes(route);
    const links = [['/', 'تنظیمات'], ['/order', 'داخل سفارش'], ['/payment', 'پرداخت مشتری']].map(([href, label]) => `<a href="${href}${shared ? '?shared=1' : ''}"${route === href ? ' aria-current="page"' : ''}>${label}</a>`).join('');
    return `<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>پیش‌نمایش کارت به کارت تیساکیس</title><style>${previewCSS}</style>${shared ? '<link rel="stylesheet" href="/shared.css">' : ''}<link rel="stylesheet" href="/assets/tcc.css">${customer ? '' : '<link rel="stylesheet" href="/assets/admin.css">'}<script src="/assets/${customer ? 'frontend.js' : 'admin.js'}" defer></script><script src="/preview.js" defer></script></head><body class="${customer ? '' : 'woocommerce_page_tisacase-card-to-card'}${shared ? ' tisa-scope' : ''}"><header class="preview-chrome"><span class="preview-brand" lang="en">TISACASE <span style="font-weight:400;color:#8c9994">/ PAYMENTS</span></span><nav aria-label="صفحه‌های پیش‌نمایش">${links}</nav><span class="preview-label"><b></b>داده آزمایشی؛ بدون ثبت یا پرداخت واقعی</span></header><${doc.querySelector('main') ? 'div' : 'main'} class="preview-content${customer ? ' preview-payment-host' : ''}">${doc.body.innerHTML}</${doc.querySelector('main') ? 'div' : 'main'}></body></html>`;
}
export const server = http.createServer((req, res) => {
    const url = new URL(req.url, 'http://preview.invalid');
    res.setHeader('Cache-Control', 'no-store');
    res.setHeader('X-Content-Type-Options', 'nosniff');
    const send = (type, body, code = 200) => { res.writeHead(code, {'Content-Type': type}); res.end(body); };
    if (url.pathname === '/favicon.ico') { res.writeHead(204); res.end(); return; }
    if (url.pathname === '/preview-action') { send('application/json; charset=utf-8', JSON.stringify({success: false, data: {message: 'این پیش‌نمایش است؛ هیچ تغییری ثبت نشد.'}}), 400); return; }
    if (url.pathname === '/preview.js') { send('text/javascript', `document.querySelector('#tcc-settings-form')?.addEventListener('submit',e=>{e.preventDefault();document.querySelector('.tcc-settings-feedback').textContent='پیش‌نمایش؛ تغییری ذخیره نشد.'});document.querySelectorAll('.tcc-upload-form').forEach(f=>f.addEventListener('submit',e=>{e.preventDefault();e.stopImmediatePropagation();f.querySelector('.tcc-feedback').textContent='پیش‌نمایش؛ هیچ رسیدی ارسال نشد.'},true));`); return; }
    if (url.pathname === '/receipt.svg') { send('image/svg+xml', receipt); return; }
    const staticFiles = {'/shared.css': path.join(root, 'design/tisacase-ui.css')};
    for (const name of ['tcc.css', 'admin.css', 'admin.js', 'frontend.js']) staticFiles['/assets/' + name] = path.join(assets, name);
    for (const weight of [400, 600, 700]) staticFiles['/font/' + weight + '.woff2'] = path.join(root, `node_modules/@fontsource/vazirmatn/files/vazirmatn-arabic-${weight}-normal.woff2`);
    if (staticFiles[url.pathname] && fs.existsSync(staticFiles[url.pathname])) {
        const ext = path.extname(staticFiles[url.pathname]); send(ext === '.css' ? 'text/css' : ext === '.woff2' ? 'font/woff2' : 'text/javascript', fs.readFileSync(staticFiles[url.pathname])); return;
    }
    if (routes[url.pathname]) { try { send('text/html; charset=utf-8', render(url.pathname, url.searchParams.get('shared') === '1')); } catch (error) { send('text/plain', escape(error.message), 503); } return; }
    send('text/plain', 'Not found', 404);
});
server.listen(port, '0.0.0.0', () => console.log('TisaCase interface preview on port ' + port + ' (fixtures only; no real writes)'));
