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
    for (const shared of [false, true]) {
        for (const width of [1440, 1024, 768, 390, 320]) {
            await page.setViewportSize({width, height: 1000});
            for (const route of ['/', '/order', '/payment', '/correction', '/first-run']) {
                const label = `${route} ${width}px ${shared ? 'Hub tokens' : 'standalone'}`;
                await goto(route + (shared ? '?shared=1' : ''));
                await check('No page/control overflow: ' + label, async () => {
                    const result = await geometry(); assert.ok(result.scroll <= result.width + 1, JSON.stringify(result)); assert.deepEqual(result.offenders, []);
                });
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
    await page.setViewportSize({width: 1024, height: 900}); await goto('/order');
    let reviews = [];
    page.on('request', request => { if (request.method() === 'POST' && request.url().endsWith('/preview-action')) reviews.push(request.postData()); });
    await page.locator('[value=approve]').click();
    await check('Actual approve button blocks unconfirmed bank credit', async () => { assert.match(await page.locator('.tcc-feedback').innerText(), /ورود وجه/); assert.equal(reviews.length, 0); });
    await page.locator('[value=reject]').click();
    await check('Actual reject button requires customer reason', async () => { assert.match(await page.locator('.tcc-feedback').innerText(), /علت/); assert.equal(reviews.length, 0); });
    await page.locator('[name=confirmed]').check();
    await page.locator('[value=approve]').click();
    await page.locator('.tcc-feedback').filter({hasText: 'هیچ تغییری ثبت نشد'}).waitFor();
    await check('Browser external form posts revision, nonce and bank confirmation', async () => {
        assert.equal(reviews.length, 1); assert.match(reviews[0], /name="confirmed"\r\n\r\nyes/); assert.match(reviews[0], /name="revision"\r\n\r\n2/); assert.match(reviews[0], /name="_wpnonce"/);
    });
    await page.locator('.preview-order-header summary').click(); await page.locator('[name=order_total]').fill('900'); await page.locator('[value=approve]').click();
    await check('Native Woo-like form edits block another review request', async () => { assert.equal(reviews.length, 1); assert.match(await page.locator('.tcc-feedback').innerText(), /ذخیره/); });
    await page.locator('.tcc-rotate').click();
    await check('Real receipt rotation preserves image source', async () => { const image = page.locator('.tcc-admin-image img'); assert.match(await image.getAttribute('style'), /rotate\(90deg\)/); assert.equal(await image.getAttribute('src'), '/receipt.svg'); });
    await goto('/payment');
    const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a7N8AAAAASUVORK5CYII=', 'base64');
    await page.locator('input[type=file]').setInputFiles({name: 'test.png', mimeType: 'image/png', buffer: png});
    await check('Real browser previews selected PNG locally', async () => { const image = page.locator('.tcc-preview'); assert.equal(await image.isVisible(), true); assert.match(await image.getAttribute('src'), /^blob:/); });
    await page.locator('.tcc-upload-form [type=submit]').click();
    await check('Preview upload is non-operational: no HTTP receipt submission', async () => { assert.equal(reviews.length, 1); assert.match(await page.locator('.tcc-upload-form .tcc-feedback').innerText(), /پیش‌نمایش/); });
    const noJS = await browser.newContext({javaScriptEnabled: false, viewport: {width: 390, height: 844}});
    const fallback = await noJS.newPage(); await fallback.goto(base + '/');
    await check('No-JS browser exposes all settings panels, with one valid form', async () => { assert.equal(await fallback.locator('[data-tcc-panel]:visible').count(), 4); assert.equal(await fallback.locator('form').count(), 1); });
    await noJS.close();
    await goto('/checklist');
    await check('Checklist has every Markdown case with a unique ID', async () => {
        const ids = await page.locator('[data-case]').evaluateAll(nodes => nodes.map(n => n.dataset.case));
        const markdown = fs.readFileSync('CARD_TO_CARD_TEST_CHECKLIST.md', 'utf8');
        assert.equal(ids.length, (markdown.match(/^- \[ \]/gm) || []).length); assert.equal(new Set(ids).size, 75); assert.equal(ids.length, 75);
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
    await check('Checklist exports all statuses and notes as JSON', async () => { const data = JSON.parse(fs.readFileSync(exportPath, 'utf8')); assert.equal(data.version, '1.2.0'); assert.equal(Object.keys(data.cases).length, 75); assert.equal(data.cases.A01.status, 'fail'); assert.equal(data.cases.R11.status, 'na'); assert.equal(data.tester, 'TEST ONLY'); });
    for (const width of [390, 320]) {
        await page.setViewportSize({width, height: 844});
        await check('Checklist mobile layout ' + width, async () => { const result = await geometry(); assert.ok(result.scroll <= result.width + 1, JSON.stringify(result)); });
    }
    await page.setViewportSize({width: 1024, height: 900});
    await page.evaluate(() => document.querySelectorAll('details').forEach(d => { d.open = true; })); await page.addScriptTag({content: axe});
    await check('Expanded checklist automated accessibility audit', async () => { const result = await page.evaluate(async () => window.axe.run('main', {runOnly: {type: 'tag', values: ['wcag2a','wcag2aa','wcag21aa']}})); assert.deepEqual(result.violations.map(v => ({id: v.id, targets: v.nodes.map(n => n.target)})), []); });
    page.once('dialog', dialog => dialog.accept()); await page.locator('#qa-reset').click();
    await check('Confirmed checklist reset clears only checklist state', async () => { assert.equal(await page.locator('[data-status=pass]').count(), 0); assert.equal(await page.locator('[data-status=fail]').count(), 0); assert.equal(await page.locator('[data-case=A01] textarea').inputValue(), ''); assert.equal(await page.locator('[data-case]').count(), 75); });
    await check('No uncaught browser errors', async () => assert.deepEqual(errors, []));
    console.log(`\nResult: ${count} browser checks passed (Chromium ${browser.version()}, PHP fixtures; NOT WordPress/Woo integration)`);
} finally { await browser.close(); }
