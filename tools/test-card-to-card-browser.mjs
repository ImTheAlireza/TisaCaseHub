/** Real Chromium on PHP fixtures, NOT a WordPress/WooCommerce integration test.
 * Start tools/preview-card-to-card.mjs first. Browser dependencies are optional.
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import {fileURLToPath} from 'node:url';
import {spawnSync} from 'node:child_process';
import {brotliDecompressSync} from 'node:zlib';
import {chromium} from 'playwright-core';
import packedChromium from '@sparticuz/chromium';
const base = process.env.TCC_PREVIEW_URL || 'http://127.0.0.1:4173';
const out = '.cache/tcc-browser'; fs.mkdirSync(out, {recursive: true});
const executablePath = process.env.CHROMIUM_PATH || await packedChromium.executablePath();
// Minimal Linux sandboxes may lack NSPR/NSS. Use the libraries already shipped
// in the trusted Chromium npm package, never apt/download from another host.
const env = {...process.env};
if (!process.env.CHROMIUM_PATH && process.platform === 'linux' && process.arch === 'x64') {
    const missing = spawnSync('ldd', [executablePath], {encoding: 'utf8'}).stdout || '';
    if (/lib(?:nss|nspr).*not found/.test(missing)) {
        const bin = path.resolve(path.dirname(fileURLToPath(import.meta.resolve('@sparticuz/chromium'))), '../bin');
        const libDir = path.resolve('.cache/tcc-chromium-libs'); fs.mkdirSync(libDir, {recursive: true});
        const archive = path.join(libDir, 'libraries.tar');
        fs.writeFileSync(archive, brotliDecompressSync(fs.readFileSync(path.join(bin, 'al2023.tar.br'))));
        const extracted = spawnSync('tar', ['-xf', archive, '-C', libDir]);
        if (extracted.status !== 0) throw new Error('Cannot extract bundled Chromium libraries.');
        env.LD_LIBRARY_PATH = path.join(libDir, 'lib') + (env.LD_LIBRARY_PATH ? ':' + env.LD_LIBRARY_PATH : '');
    }
}
const browser = await chromium.launch({executablePath, env, args: packedChromium.args.filter(arg => !['--disable-web-security', '--allow-running-insecure-content', '--single-process'].includes(arg)), headless: true});
let count = 0;
const errors = [];
const page = await browser.newPage({viewport: {width: 1440, height: 1000}, locale: 'fa-IR'});
page.on('pageerror', error => errors.push(error.message));
const axe = fs.readFileSync('node_modules/axe-core/axe.min.js', 'utf8');
const check = async (name, fn) => { await fn(); count++; console.log('OK ' + name); };
async function goto(route) { await page.goto(base + route); await page.evaluate(() => document.fonts.ready); }
async function geometry() {
    return page.evaluate(() => {
        const offenders = [];
        document.querySelectorAll('.tcc-panel button, .tcc-panel input, .tcc-panel select, .tcc-panel textarea, .tcc-panel summary, .tcc-card-number').forEach(el => {
            const rect = el.getBoundingClientRect();
            if (rect.width && rect.height && (rect.left < -1 || rect.right > innerWidth + 1)) offenders.push(el.tagName + '.' + el.className);
        });
        return {width: innerWidth, scroll: document.documentElement.scrollWidth, offenders};
    });
}
try {
    // Reproduce storefronts that inherit left alignment or style Woo forms
    // explicitly, even when their stylesheet is loaded AFTER the gateway CSS.
    for (const host of ['inherited', 'explicit']) {
        for (const width of [1024, 390, 320]) {
            await page.setViewportSize({width, height: 1000});
            for (const route of ['/payment', '/correction']) {
                const label = `${route} ${width}px ${host} LTR theme`;
                await goto(route);
                await page.evaluate(() => {
                    document.documentElement.dir = 'ltr';
                    document.body.classList.add('woocommerce');
                    const outside = document.createElement('p'); outside.id = 'theme-copy'; outside.textContent = 'Unrelated theme content'; document.body.append(outside);
                });
                await page.addStyleTag({content: `
                    html, body { direction: ltr; text-align: left; }
                    ${host === 'explicit' ? `
                    .woocommerce form { text-align: left; }
                    .woocommerce :is(h1, h2, h3, p, small, details, summary, label) { direction: ltr; text-align: left; }
                    .woocommerce form :is(input, textarea) { direction: ltr; text-align: left; }
                    ` : ''}
                `});
                await check('Persian payment text resists theme alignment: ' + label, async () => {
                    const alignment = await page.locator('.tcc-payment, .tcc-payment h2, .tcc-payment p, .tcc-payment details, .tcc-payment summary, .tcc-upload-form, .tcc-field, .tcc-upload-form > small, .tcc-upload-form textarea').evaluateAll(nodes => nodes.map(el => ({
                        element: el.tagName + '.' + el.className, direction: getComputedStyle(el).direction, align: getComputedStyle(el).textAlign
                    })));
                    assert.ok(alignment.length >= 10);
                    assert.deepEqual(alignment.filter(el => el.direction !== 'rtl' || el.align !== 'right'), [], 'Theme must not left-align customer payment text');
                });
                await check('LTR values and centered bank card survive RTL fix: ' + label, async () => {
                    await page.locator('[name=reference]').fill('000123-AB'); await page.locator('[name=last4]').fill('0123');
                    await page.locator('[name=note]').fill('توضیح آزمایشی مشتری');
                    const values = await page.locator('.tcc-upload-form').evaluate(form => ({
                        values: ['reference', 'last4', 'note'].map(name => new FormData(form).get(name)),
                        inputs: ['reference', 'last4'].map(name => ({direction: getComputedStyle(form.elements[name]).direction, align: getComputedStyle(form.elements[name]).textAlign})),
                        fileDirection: getComputedStyle(form.elements.receipt).direction
                    }));
                    assert.deepEqual(values.values, ['000123-AB', '0123', 'توضیح آزمایشی مشتری']);
                    assert.deepEqual(values.inputs, [{direction: 'ltr', align: 'left'}, {direction: 'ltr', align: 'left'}]); assert.equal(values.fileDirection, 'rtl');
                    const card = await page.locator('.tcc-card-number').evaluate(el => ({direction: getComputedStyle(el).direction, align: getComputedStyle(el).textAlign}));
                    assert.deepEqual(card, {direction: 'ltr', align: 'center'});
                    assert.deepEqual(await page.locator('.tcc-money, .tcc-bank').evaluateAll(nodes => nodes.map(el => getComputedStyle(el).textAlign)), ['center', 'center']);
                });
                await check('RTL fix stays scoped and fits mobile: ' + label, async () => {
                    assert.deepEqual(await page.locator('#theme-copy').evaluate(el => ({direction: getComputedStyle(el).direction, align: getComputedStyle(el).textAlign})), {direction: 'ltr', align: 'left'});
                    const result = await geometry(); assert.ok(result.scroll <= result.width + 1, JSON.stringify(result)); assert.deepEqual(result.offenders, []);
                    const text = await page.locator('label:has([name=reference])').evaluate(el => {
                        const range = document.createRange(); range.selectNodeContents(el.firstChild);
                        return {right: range.getBoundingClientRect().right, fieldRight: el.getBoundingClientRect().right};
                    });
                    assert.ok(Math.abs(text.right - text.fieldRight) < 2, JSON.stringify(text));
                });
                if (host === 'explicit' && route === '/payment') await page.locator('.tcc-payment').screenshot({path: path.join(out, `payment-rtl-theme-${width}.png`)});
            }
        }
    }
    for (const shared of [false, true]) {
        for (const width of [1440, 1024, 768, 390, 320]) {
            await page.setViewportSize({width, height: 1000});
            for (const route of ['/', '/order', '/payment', '/correction', '/first-run', '/pending', '/saman', '/approved', '/order-hpos']) {
                const label = `${route} ${width}px ${shared ? 'Hub tokens' : 'standalone'}`;
                await goto(route + (shared ? '?shared=1' : ''));
                await check('No page/control overflow: ' + label, async () => {
                    const result = await geometry(); assert.ok(result.scroll <= result.width + 1, JSON.stringify(result)); assert.deepEqual(result.offenders, []);
                });
                await check('Blue customer palette / unchanged green admin: ' + label, async () => {
                    const customer = ['/payment', '/correction', '/pending', '/saman', '/approved'].includes(route);
                    const colors = await page.locator('.tcc-panel').first().evaluate(el => ({brand: getComputedStyle(el).getPropertyValue('--tcc-brand').trim().toLowerCase(), ink: getComputedStyle(el).color, sheets: Array.from(document.querySelectorAll('link[rel=stylesheet]'), el => new URL(el.href).pathname)}));
                    assert.equal(colors.brand, customer ? '#2874fc' : '#0e7c6b');
                    assert.equal(colors.sheets.includes('/assets/customer.css'), customer);
                    if (customer) assert.equal(colors.ink, 'rgb(22, 24, 28)');
                });
                if (['/payment', '/correction', '/pending', '/saman', '/approved'].includes(route)) {
                    await check('Three visible payment stages are centered inside their card: ' + label, async () => {
                        const positions = await page.locator('.tcc-steps').evaluate(el => {
                            const spans = Array.from(el.querySelectorAll('li > span'), span => span.getBoundingClientRect());
                            const card = el.closest('.tcc-payment').getBoundingClientRect();
                            const ticks = Array.from(el.querySelectorAll('.tcc-step-number'), span => { const r = span.getBoundingClientRect(); return r.top + r.height / 2; });
                            return {left: Math.min(...spans.map(r => r.left)), right: Math.max(...spans.map(r => r.right)), cardCenter: card.left + card.width / 2, ticks};
                        });
                        assert.ok(Math.abs((positions.left + positions.right) / 2 - positions.cardCenter) <= 1, JSON.stringify(positions));
                        assert.equal(positions.ticks.length, 3); assert.ok(Math.max(...positions.ticks) - Math.min(...positions.ticks) <= 1, JSON.stringify(positions));
                    });
                }
                if (['/payment', '/saman'].includes(route)) {
                    await check('Readable cardholder name and safe long-name wrapping: ' + label, async () => {
                        const holder = page.locator('.tcc-holder > span');
                        const metrics = await holder.evaluate(el => ({size: parseFloat(getComputedStyle(el).fontSize), weight: Number(getComputedStyle(el).fontWeight), labelSize: parseFloat(getComputedStyle(el.previousElementSibling).fontSize), direction: getComputedStyle(el).direction, align: getComputedStyle(el).textAlign}));
                        assert.equal(metrics.size, 18); assert.ok(metrics.weight >= 600); assert.equal(metrics.labelSize, 10); assert.equal(metrics.direction, 'rtl'); assert.equal(metrics.align, 'right');
                        const original = await holder.textContent();
                        try {
                            await holder.evaluate(el => { el.textContent = 'شرکت آزمایشی خدمات بازرگانی و توسعه فناوری نمونه'; });
                            const bounds = await holder.evaluate(el => { const r = el.getBoundingClientRect(), card = el.closest('.tcc-bank').getBoundingClientRect(); return {left: r.left, right: r.right, cardLeft: card.left, cardRight: card.right, scroll: el.scrollWidth, width: r.width}; });
                            assert.ok(bounds.left >= bounds.cardLeft && bounds.right <= bounds.cardRight && bounds.scroll <= bounds.width + 1, JSON.stringify(bounds));
                            const result = await geometry(); assert.ok(result.scroll <= result.width + 1, JSON.stringify(result)); assert.deepEqual(result.offenders, []);
                        } finally { await holder.evaluate((el, text) => { el.textContent = text; }, original); }
                    });
                }
                await page.addScriptTag({content: axe});
                await check('WCAG A/AA automated audit: ' + label, async () => {
                    const result = await page.evaluate(async () => window.axe.run('.tcc-panel', {runOnly: {type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa']}}));
                    assert.deepEqual(result.violations.map(v => ({id: v.id, targets: v.nodes.map(n => n.target)})), []);
                });
                if (!shared && [1440, 390].includes(width) && ['/', '/order', '/payment'].includes(route)) {
                    await page.screenshot({path: path.join(out, (route === '/' ? 'settings' : route.slice(1)) + '-' + width + '.png'), fullPage: true});
                }
            }
            await goto('/' + (shared ? '?shared=1' : ''));
            for (const tab of ['payment', 'notifications', 'access']) {
                await page.locator('[data-tcc-tab=' + tab + ']').click();
                await check(`Tab layout ${tab} ${width}px ${shared ? 'Hub' : 'standalone'}`, async () => {
                    const result = await geometry(); assert.ok(result.scroll <= result.width + 1, JSON.stringify(result)); assert.deepEqual(result.offenders, []);
                    assert.equal(await page.locator('[role=tabpanel]:visible').count(), 1);
                    assert.equal(await page.locator('[data-tcc-panel=' + tab + ']').isVisible(), true);
                });
                await page.addScriptTag({content: axe});
                await check(`Tab automated accessibility audit ${tab} ${width}px ${shared ? 'Hub' : 'standalone'}`, async () => {
                    const result = await page.evaluate(async () => window.axe.run('.tcc-panel', {runOnly: {type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa']}}));
                    assert.deepEqual(result.violations.map(v => ({id: v.id, targets: v.nodes.map(n => n.target)})), []);
                });
            }
        }
    }
    await goto('/?shared=1');
    await page.addStyleTag({path: 'plugins/src/tisacase-card-to-card/assets/customer.css'});
    await check('Customer CSS cannot turn admin blue even if accidentally co-loaded', async () => {
        assert.equal(await page.locator('.tcc-settings').evaluate(el => getComputedStyle(el).getPropertyValue('--tcc-brand').trim().toLowerCase()), '#0e7c6b');
        const result = await page.evaluate(() => {
            const admin = document.querySelector('.tcc-settings'); const before = getComputedStyle(admin).getPropertyValue('--tcc-brand');
            const probe = document.createElement('section'); probe.className = 'tcc-panel tcc-payment'; document.body.append(probe);
            const blue = getComputedStyle(probe).getPropertyValue('--tcc-brand').trim(); probe.remove();
            return {before: before.trim().toLowerCase(), after: getComputedStyle(admin).getPropertyValue('--tcc-brand').trim().toLowerCase(), blue};
        });
        assert.deepEqual(result, {before:'#0e7c6b',after:'#0e7c6b',blue:'#2874fc'});
    });
    for (const shared of [false,true]) for (const width of [320,390]) {
        await page.setViewportSize({width,height:1000}); await goto('/' + (shared ? '?shared=1' : '')); await page.locator('.tcc-card-row').first().locator('summary').click();
        await check(`All 37 selected bank names fit the mobile dropdown ${width}px ${shared?'Hub':'standalone'}`, async()=> {
            const dimensions = await page.locator('[data-card-field=bank]').first().evaluate(el=>{const s=getComputedStyle(el), c=document.createElement('canvas').getContext('2d');c.font=s.font;return {available:el.clientWidth-parseFloat(s.paddingLeft)-parseFloat(s.paddingRight),longest:Math.max(...Array.from(el.querySelectorAll('option[data-bank-logo]'),o=>c.measureText(o.textContent).width))};});
            assert.ok(dimensions.longest+4<=dimensions.available,JSON.stringify(dimensions));
        });
    }
    const banks = Object.entries(JSON.parse(fs.readFileSync('.cache/tcc-tests/banks.json', 'utf8')));
    await page.setViewportSize({width: 1024, height: 1000}); await goto('/');
    const bankRow = page.locator('#tcc-cards > .tcc-card-row').first(); await bankRow.locator('summary').click();
    const bankSelect = bankRow.locator('[data-card-field=bank]');
    await check('Bank picker is native, has all 37 choices and preserves unknown existing labels', async () => {
        assert.equal(await bankSelect.evaluate(el => el.tagName), 'SELECT'); assert.equal(await bankSelect.locator('option[data-bank-logo]').count(), 37);
        assert.equal(await bankSelect.inputValue(), 'بانک آزمایشی'); assert.match(await bankSelect.locator('option:checked').textContent(), /نام فعلی/);
    });
    const bankRequests = []; const trackBankRequest = request => { if (request.method() !== 'GET') bankRequests.push(request.url()); }; page.on('request', trackBankRequest);
    for (const [key, bank] of banks) {
        await bankSelect.selectOption({label: bank.name});
        await check('Native bank selection previews the correct bundled logo: ' + key, async () => {
            await page.waitForFunction(key => { const img = document.querySelector('#tcc-cards [data-tcc-bank-logo]'); return img.complete && img.naturalWidth > 0 && img.getAttribute('src') === '/banks/' + key + '.svg'; }, key);
            assert.equal(await bankRow.locator('[data-tcc-bank-logo]').isVisible(), true);
            assert.equal(await bankSelect.evaluate(el => new FormData(el.form).get(el.name)), bank.name);
        });
    }
    page.off('request', trackBankRequest);
    await check('Changing banks neither saves settings nor sends financial requests', async () => assert.deepEqual(bankRequests, []));
    await page.locator('#tcc-add-card').click();
    await check('Dynamically added cards use the same catalog without free text', async () => { const select = page.locator('#tcc-cards > .tcc-card-row').last().locator('[data-card-field=bank]'); assert.equal(await select.evaluate(el => el.tagName), 'SELECT'); assert.equal(await select.locator('option[data-bank-logo]').count(), 37); assert.equal(await select.inputValue(), ''); });
    await page.addScriptTag({content: axe});
    await check('Expanded native bank picker automated accessibility audit', async () => { const result = await page.evaluate(async () => window.axe.run('.tcc-panel', {runOnly:{type:'tag', values:['wcag2a','wcag2aa','wcag21aa']}})); assert.deepEqual(result.violations.map(v => ({id:v.id,targets:v.nodes.map(n=>n.target)})), []); });
    for (const [key, bank] of banks) {
        await page.setViewportSize({width: 320, height: 1000}); await goto('/bank/' + key);
        await check('Real PHP customer template selects a visible local logo safely at 320px: ' + key, async () => {
            const img = page.locator('.tcc-bank-logo img'); await img.waitFor();
            await img.evaluate(el => el.decode()); assert.equal(await img.getAttribute('src'), '/banks/' + key + '.svg');
            assert.equal(await img.evaluate(el => el.complete && el.naturalWidth > 0), true);
            assert.equal(await page.locator('.tcc-bank-brand > span:last-child').textContent(), bank.name);
            assert.equal(await page.locator('.tcc-payment').evaluate(el=>getComputedStyle(el).getPropertyValue('--tcc-brand').trim()), '#2874fc');
            const result = await geometry(); assert.ok(result.scroll <= result.width + 1, JSON.stringify(result)); assert.deepEqual(result.offenders, []);
        });
    }
    await goto('/settings-bank-alias');
    await check('JS enhancement preserves the exact saved bank alias in submitted data', async () => { const select = page.locator('#tcc-cards [data-card-field=bank]').first(); assert.equal(await select.inputValue(), 'بانك ملي ايران'); assert.equal(await select.evaluate(el => new FormData(el.form).get(el.name)), 'بانك ملي ايران'); assert.equal(await page.locator('#tcc-cards [data-tcc-bank-logo]').first().getAttribute('src'), '/banks/melli.svg'); });
    const bankNoJS = await browser.newContext({javaScriptEnabled:false, viewport:{width:390,height:900}}); const bankNoJSPage = await bankNoJS.newPage();
    try { await bankNoJSPage.goto(base+'/first-run'); const select = bankNoJSPage.locator('[data-card-field=bank]'); await select.selectOption({label:'کارآفرین'}); await check('Bank dropdown is usable and submits the choice without JavaScript', async()=> { assert.equal(await select.inputValue(), 'کارآفرین'); assert.equal(await select.evaluate(el=>new FormData(el.form).get(el.name)), 'کارآفرین'); assert.equal(await select.locator('option[data-bank-logo]').count(),37); }); } finally { await bankNoJS.close(); }
    await page.setViewportSize({width: 1024, height: 900}); await goto('/');
    await page.locator('[data-tcc-tab=access]').click();
    await page.screenshot({path: path.join(out, 'access-roles-1024.png'), fullPage: true});
    await check('Protected administrator and native role checkbox labels work', async () => {
        const admin = page.locator('[name="reviewer_roles[]"][value=administrator]'); assert.equal(await admin.isChecked(), true); assert.equal(await admin.isDisabled(), true);
        await page.getByText('مدیر فروشگاه', {exact: true}).click(); assert.equal(await page.locator('[name="reviewer_roles[]"][value=shop_manager]').isChecked(), true);
    });
    await page.locator('[data-tcc-tab=cards]').click();
    await check('Browser form preserves role selection while access tab is hidden', async () => {
        const values = await page.locator('#tcc-settings-form').evaluate(form => ({roles: new FormData(form).getAll('reviewer_roles[]'), marker: new FormData(form).get('reviewer_roles_present'), users: new FormData(form).get('reviewers')}));
        assert.deepEqual(values.roles, ['shop_manager']); assert.equal(values.marker, '1'); assert.equal(values.users, '2,7');
    });
    await page.setViewportSize({width: 1440, height: 1000}); await goto('/');
    await page.locator('[data-tcc-tab=payment]').click();
    await page.screenshot({path: path.join(out, 'settings-payment-1440.png'), fullPage: true});
    await check('Native FormData submits hidden-tab values and CSRF', async () => {
        const values = await page.locator('#tcc-settings-form').evaluate(form => Object.fromEntries(new FormData(form)));
        assert.equal(values['settings[hours]'], '12'); assert.equal(values['settings[max_mb]'], '3');
        assert.equal(values['settings[cards][0][number]'], '6037990000000001'); assert.equal(values.reviewers, '2,7'); assert.ok(values._wpnonce);
    });
    await page.locator('[data-tcc-tab=access]').click();
    await page.locator('[name="settings[hours]"]').evaluate(input => { input.value = '0'; });
    await page.locator('button[type=submit]').click();
    await check('Native browser validation opens hidden tab and focuses invalid field', async () => {
        assert.equal(await page.locator('[data-tcc-panel=payment]').isVisible(), true);
        assert.equal(await page.evaluate(() => document.activeElement.name), 'settings[hours]');
    });
    await page.locator('[name="settings[hours]"]').fill('12');
    await page.locator('[data-tcc-tab=cards]').focus(); await page.keyboard.press('ArrowLeft');
    await check('Real keyboard RTL navigation and focus', async () => assert.equal(await page.evaluate(() => document.activeElement.dataset.tccTab), 'payment'));
    await page.keyboard.press('End');
    await check('Real keyboard End selects access panel', async () => assert.equal(await page.locator('[name=ui_tab]').inputValue(), 'access'));
    await page.keyboard.press('Home'); await page.locator('#tcc-add-card').click();
    await check('New card opens and focuses number field in browser', async () => assert.equal(await page.evaluate(() => document.activeElement.name), 'settings[cards][2][number]'));
    await page.getByText('فعال‌سازی درگاه', {exact: true}).click();
    await check('Switch label toggles native checkbox', async () => assert.equal(await page.locator('[name="settings[enabled]"]').isChecked(), false));
    await page.locator('#tcc-cards > details').first().locator('summary').click();
    await page.locator('[name="settings[cards][0][label]"]').fill('کارت فروشگاه با عنوان طولانی برای بررسی چیدمان موبایل '.repeat(4));
    await page.setViewportSize({width: 320, height: 844});
    await check('Long destination label does not break mobile layout', async () => { const result = await geometry(); assert.ok(result.scroll <= result.width + 1); assert.deepEqual(result.offenders, []); });
    await page.setViewportSize({width: 1024, height: 1000}); await goto('/order');
    const financeRequests = [];
    page.on('request', request => { if (request.method() === 'POST' && request.url().endsWith('/preview-action')) financeRequests.push(request); });
    await page.evaluate(() => {
        const form = document.querySelector('#post');
        form.querySelector('[name=order_total]').dispatchEvent(new Event('change', {bubbles: true}));
        const widget = document.createElement('input'); widget.name = 'woo_widget'; widget.value = 'automatic initialization'; form.append(widget); widget.dispatchEvent(new Event('change', {bubbles: true}));
    });
    for (const decision of ['approve', 'reject', 'correct', 'reconcile']) {
        await page.locator('[data-tcc-decision][value=' + decision + ']').check();
        await check('Native decision selection does not auto-save/pay: ' + decision, async () => {
            assert.equal(await page.locator('[data-tcc-decision]:checked').inputValue(), decision);
            assert.match(await page.locator('.tcc-order-box .tcc-feedback').innerText(), /به‌روزرسانی سفارش/);
            assert.doesNotMatch(await page.locator('.tcc-order-box .tcc-feedback').innerText(), /تغییرات ذخیره‌نشده/);
            assert.equal(financeRequests.length, 0);
        });
    }
    await page.locator('[data-tcc-decision][value=approve]').check(); await page.locator('#publish').click();
    await check('Native Update requires explicit confirmation before approval', async () => { assert.match(await page.locator('.tcc-order-box .tcc-feedback').innerText(), /ورود وجه/); assert.equal(financeRequests.length, 0); });
    await page.locator('[data-tcc-confirmed]').check(); await page.locator('#publish').click();
    await check('Only native Update carries the financial intent and original Woo action', async () => {
        const data = await page.evaluate(() => window.tccPreviewSave);
        assert.equal(data['tcc_review[decision]'], 'approve'); assert.equal(data['tcc_review[confirmed]'], 'yes'); assert.equal(data.action, 'editpost');
        for (const key of ['order_id', 'revision', 'fingerprint', 'nonce']) assert.ok(data['tcc_review[' + key + ']']);
        assert.match(await page.locator('.tcc-order-box .tcc-feedback').innerText(), /پیش‌نمایش/); assert.equal(financeRequests.length, 0);
    });
    for (const decision of ['reject', 'correct', 'reconcile']) {
        await page.locator('[data-tcc-reason]').fill(''); await page.locator('[data-tcc-decision][value=' + decision + ']').check(); await page.locator('#publish').click();
        await check('Native Update validates the public reason: ' + decision, async () => assert.match(await page.locator('.tcc-order-box .tcc-feedback').innerText(), /پیام/));
        await page.locator('[data-tcc-reason]').fill('تصویر رسید خوانا نیست'); await page.locator('#publish').click();
        await check('Native Update submits the selected receipt decision: ' + decision, async () => {
            const data = await page.evaluate(() => window.tccPreviewSave); assert.equal(data['tcc_review[decision]'], decision); assert.equal(data['tcc_review[reason]'], 'تصویر رسید خوانا نیست'); assert.equal(financeRequests.length, 0);
        });
    }
    await page.locator('.preview-order-header summary').click(); await page.locator('[name=order_total]').fill('900'); await page.locator('#publish').click();
    await check('Edited Woo fields travel with the intent for authoritative server validation', async () => { const data = await page.evaluate(() => window.tccPreviewSave); assert.equal(data.order_total, '900'); assert.equal(data['tcc_review[decision]'], 'reconcile'); assert.ok(data['tcc_review[fingerprint]']); });
    await page.locator('[data-tcc-clear]').click(); await page.locator('#publish').click();
    await check('Cancelling selection leaves ordinary Woo Update without a financial decision', async () => { const data = await page.evaluate(() => window.tccPreviewSave); assert.equal(data['tcc_review[decision]'], undefined); assert.equal(await page.locator('[data-tcc-decision]:checked').count(), 0); assert.equal(financeRequests.length, 0); });
    await goto('/order-hpos');
    await page.locator('[data-tcc-decision][value=reject]').check();
    await check('HPOS fixture stages without request or native action replacement', async () => { assert.equal(financeRequests.length, 0); assert.match(await page.locator('.tcc-order-box .tcc-feedback').innerText(), /به‌روزرسانی سفارش/); assert.equal(await page.locator('form#order').count(), 1); });
    await page.locator('[data-tcc-reason]').fill('رسید نیازمند اصلاح است'); await page.locator('#publish').click();
    await check('HPOS fixture submits decision inside its own native Update', async () => { const data = await page.evaluate(() => window.tccPreviewSave); assert.equal(data.action, 'edit_order'); assert.equal(data['tcc_review[decision]'], 'reject'); assert.equal(data['tcc_review[reason]'], 'رسید نیازمند اصلاح است'); assert.equal(financeRequests.length, 0); });
    await page.locator('.tcc-rotate').click();
    await check('Receipt rotation remains presentation-only', async () => { const image = page.locator('.tcc-admin-image img'); assert.match(await image.getAttribute('style'), /rotate\(90deg\)/); assert.equal(await image.getAttribute('src'), '/receipt.svg'); });
    await goto('/payment');
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a7N8AAAAASUVORK5CYII=', 'base64');
    const fileEvent = page.waitForEvent('filechooser'); await page.getByLabel('انتخاب تصویر رسید', {exact: true}).click(); const chooser = await fileEvent;
    await chooser.setFiles({name: 'رسید آزمایشی.png', mimeType: 'image/png', buffer: png});
    await check('Persian file picker opens the native chooser and shows a Persian filename', async () => { assert.equal(await page.locator('[data-tcc-file-name]').innerText(), 'رسید آزمایشی.png'); assert.match(await page.locator('.tcc-file-action').innerText(), /انتخاب تصویر/); assert.doesNotMatch(await page.locator('.tcc-file-picker').innerText(), /Choose File|No file chosen/i); });
    await check('Selected PNG previews locally without uploading', async () => { const image = page.locator('.tcc-preview'); assert.equal(await image.isVisible(), true); assert.match(await image.getAttribute('src'), /^blob:/); assert.equal(financeRequests.length, 0); });
    await page.locator('.tcc-upload-form [type=submit]').click();
    await check('Preview upload is explicitly non-operational', async () => { assert.equal(financeRequests.length, 0); assert.match(await page.locator('.tcc-upload-form .tcc-feedback').innerText(), /پیش‌نمایش/); });
    await page.locator('input[type=file]').setInputFiles([]);
    await check('Cancelling the file resets its Persian status and stale preview', async () => { assert.equal(await page.locator('[data-tcc-file-name]').innerText(), 'تصویری انتخاب نشده'); assert.equal(await page.locator('.tcc-preview').isVisible(), false); assert.equal(await page.locator('.tcc-preview').getAttribute('src'), null); });
    await page.context().grantPermissions(['clipboard-read', 'clipboard-write']);
    await page.locator('[data-tcc-kind=amount]').click();
    await check('Browser clipboard contains exact rials with no unit or separators', async () => { assert.equal(await page.evaluate(() => navigator.clipboard.readText()), '4850000'); assert.match(await page.locator('.tcc-copy-feedback').innerText(), /مبلغ ریالی کپی شد/); assert.match(await page.locator('[data-tcc-kind=amount]').innerText(), /کپی شد/); });
    await goto('/correction');
    await check('Correction keeps its public reason and neutral resubmission guidance', async () => {
        const status = page.locator('.tcc-status-card');
        assert.equal(await status.locator('small').innerText(), 'لطفاً موارد اعلام‌شده را اصلاح کنید و مجدداً برای بررسی ثبت کنید.');
        assert.ok((await status.locator('p').innerText()).trim()); assert.doesNotMatch(await status.innerText(), /دوباره واریز نکنید/);
    });
    await goto('/pending');
    await check('Pending page has one clear status, not repeated review paragraphs', async () => { assert.equal(await page.locator('.tcc-status-card').count(), 1); assert.match(await page.locator('.tcc-status-copy h3').innerText(), /رسید ثبت شد/); assert.match(await page.locator('.tcc-status-copy p').innerText(), /پرداخت هنوز تأیید نشده/); assert.equal(await page.locator('.tcc-payment-meta').count(), 0); });
    await check('Pending receipt has a direct protected image action and optional corrections', async () => { assert.equal(await page.getByRole('link', {name: 'مشاهده تصویر رسید'}).isVisible(), true); assert.equal(await page.locator('.tcc-upload').getAttribute('open'), null); assert.equal(await page.locator('.tcc-transfer-details').getAttribute('open'), null); });
    await page.locator('.tcc-upload > summary').click(); await page.locator('[name=reference]').fill('000456');
    await check('Pending correction is available only when explicitly expanded', async () => { assert.equal(await page.locator('.tcc-upload-form').isVisible(), true); assert.equal(await page.locator('[name=reference]').inputValue(), '000456'); });
    await goto('/saman');
    await check('Recognized bank logo is served locally with no third-party page requests', async () => { const img = page.locator('.tcc-bank-logo img'); assert.equal(await img.getAttribute('src'), '/banks/saman.svg'); assert.equal(await img.evaluate(el => el.complete && el.naturalWidth > 0), true); });
    await page.emulateMedia({reducedMotion: 'reduce'});
    await check('Reduced motion disables all decorative customer animations', async () => { assert.deepEqual(await page.locator('.tcc-payment, .tcc-bank').evaluateAll(nodes => nodes.map(el => getComputedStyle(el).animationName)), ['none', 'none']); });
    await page.emulateMedia({reducedMotion: 'no-preference'});
    await goto('/approved');
    await check('Paid page never offers another transfer or receipt upload', async () => { assert.equal(await page.locator('.tcc-upload-form').count(), 0); assert.equal(await page.locator('[data-tcc-copy]').count(), 0); assert.match(await page.locator('.tcc-status-copy h3').innerText(), /پرداخت سفارش ثبت شد/); });
    const noJS = await browser.newContext({javaScriptEnabled: false, viewport: {width: 390, height: 844}});
    const fallback = await noJS.newPage(); await fallback.goto(base + '/');
    await check('No-JS browser exposes all settings panels, with one valid form', async () => { assert.equal(await fallback.locator('[data-tcc-panel]:visible').count(), 4); assert.equal(await fallback.locator('form').count(), 1); });
    for (const [route, id, action] of [['/order', 'post', 'editpost'], ['/order-hpos', 'order', 'edit_order']]) {
        await fallback.goto(base + route); await fallback.locator('[data-tcc-decision][value=reject]').check(); await fallback.locator('[data-tcc-reason]').fill('تصویر خوانا نیست');
        await check('No-JS staged choice uses the native order form: ' + id, async () => { const data = await fallback.locator('form').evaluate(form => ({id: form.id, values: Object.fromEntries(new FormData(form))})); assert.equal(await fallback.locator('form').count(), 1); assert.equal(data.id, id); assert.equal(data.values.action, action); assert.equal(data.values['tcc_review[decision]'], 'reject'); assert.ok(data.values['tcc_review[nonce]']); });
        await fallback.locator('input[type=radio][name="tcc_review[decision]"][value=""]').check();
        await check('No-JS user can cancel only the financial choice: ' + id, async () => { const data = await fallback.locator('form').evaluate(form => Object.fromEntries(new FormData(form))); assert.equal(data['tcc_review[decision]'], ''); assert.equal(data['tcc_review[reason]'], 'تصویر خوانا نیست'); assert.equal(data.action, action); });
    }
    await fallback.goto(base + '/payment');
    const noJSFile = fallback.waitForEvent('filechooser'); await fallback.getByLabel('انتخاب تصویر رسید', {exact: true}).click(); await (await noJSFile).setFiles({name: 'رسید.png', mimeType: 'image/png', buffer: png});
    await check('No-JS Persian picker retains the real multipart file input', async () => { assert.equal(await fallback.locator('form').getAttribute('enctype'), 'multipart/form-data'); assert.equal(await fallback.locator('input[type=file]').evaluate(input => input.files[0].name), 'رسید.png'); assert.match(await fallback.locator('.tcc-file-action').innerText(), /انتخاب تصویر/); });
    await noJS.close();
    await goto('/checklist');
    await check('Checklist has every Markdown case with a unique ID', async () => {
        const ids = await page.locator('[data-case]').evaluateAll(nodes => nodes.map(n => n.dataset.case));
        const markdown = fs.readFileSync('CARD_TO_CARD_TEST_CHECKLIST.md', 'utf8');
        assert.equal(ids.length, (markdown.match(/^- \[ \]/gm) || []).length); assert.equal(new Set(ids).size, 80); assert.equal(ids.length, 80);
    });
    await check('Manual checklist starts with no claimed test passes', async () => { assert.equal(await page.locator('[data-status=pass]').count(), 0); assert.equal(await page.locator('.qa-progress').getAttribute('value'), '0'); });
    await page.locator('[data-case=A01] .qa-pass').check();
    await page.locator('[data-case=A01] details summary').click();
    await page.locator('[data-case=A01] textarea').fill('<img src=x onerror=alert(1)> یادداشت تست');
    await page.locator('#qa-tester').fill('TEST ONLY'); await page.reload();
    await check('Checklist pass, notes and tester persist after reload', async () => {
        assert.equal(await page.locator('[data-case=A01] .qa-pass').isChecked(), true);
        assert.match(await page.locator('[data-case=A01] textarea').inputValue(), /یادداشت/);
        assert.equal(await page.locator('#qa-tester').inputValue(), 'TEST ONLY'); assert.equal(await page.locator('img').count(), 0);
    });
    await page.locator('[data-case=A01] .qa-fail').click();
    await check('Marking defect clears pass and updates status', async () => { assert.equal(await page.locator('[data-case=A01]').getAttribute('data-status'), 'fail'); assert.equal(await page.locator('[data-case=A01] .qa-pass').isChecked(), false); });
    await page.locator('[data-filter=fail]').click();
    await check('Defects filter shows only the failed case', async () => assert.equal(await page.locator('[data-case]:visible').count(), 1));
    await page.locator('[data-filter=pending]').click(); await page.locator('[data-filter=all]').click();
    await page.locator('[data-case=R11] .qa-na').click();
    await check('Only optional tests offer N/A without marking them passed', async () => { assert.equal(await page.locator('[data-case=R11]').getAttribute('data-status'), 'na'); assert.equal(await page.locator('[data-case=A01] .qa-na').count(), 0); });
    const downloadEvent = page.waitForEvent('download'); await page.locator('#qa-export').click(); const download = await downloadEvent;
    const exportPath = path.join(out, 'checklist-export.json'); await download.saveAs(exportPath);
    await check('Checklist exports all statuses and notes as JSON', async () => { const data = JSON.parse(fs.readFileSync(exportPath, 'utf8')); assert.equal(data.version, '1.4.0'); assert.equal(Object.keys(data.cases).length, 80); assert.equal(data.cases.A01.status, 'fail'); assert.equal(data.cases.R11.status, 'na'); assert.equal(data.tester, 'TEST ONLY'); });
    for (const width of [390, 320]) {
        await page.setViewportSize({width, height: 844});
        await check('Checklist mobile layout ' + width, async () => { const result = await geometry(); assert.ok(result.scroll <= result.width + 1, JSON.stringify(result)); });
    }
    await page.setViewportSize({width: 1024, height: 900});
    await page.evaluate(() => document.querySelectorAll('details').forEach(d => { d.open = true; })); await page.addScriptTag({content: axe});
    await check('Expanded checklist automated accessibility audit', async () => { const result = await page.evaluate(async () => window.axe.run('main', {runOnly: {type: 'tag', values: ['wcag2a','wcag2aa','wcag21aa']}})); assert.deepEqual(result.violations.map(v => ({id: v.id, targets: v.nodes.map(n => n.target)})), []); });
    page.once('dialog', dialog => dialog.accept()); await page.locator('#qa-reset').click();
    await check('Confirmed checklist reset clears only checklist state', async () => { assert.equal(await page.locator('[data-status=pass]').count(), 0); assert.equal(await page.locator('[data-status=fail]').count(), 0); assert.equal(await page.locator('[data-case=A01] textarea').inputValue(), ''); assert.equal(await page.locator('[data-case]').count(), 80); });
    await check('No uncaught browser errors', async () => assert.deepEqual(errors, []));
    console.log(`\nResult: ${count} browser checks passed (Chromium ${browser.version()}, PHP fixtures; NOT WordPress/Woo integration)`);
} finally { await browser.close(); }
