/**
 * تست jsdom برای رفتار پیشخوان (پاک‌سازی/شناسایی منبع/همگام‌سازی) و فرم پیگیری مشتری.
 * اجرا: node tests/admin-progress-tests.mjs
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { JSDOM } from 'jsdom';

const adminSource = fs.readFileSync(new URL('../assets/admin.js', import.meta.url), 'utf8');
const trackingSource = fs.readFileSync(new URL('../assets/tracking.js', import.meta.url), 'utf8');

function readBody(options) {
    return new URLSearchParams(options.body);
}

function jsonResponse(data) {
    return { ok: true, text: async () => JSON.stringify({ success: true, data }) };
}

function errorResponse(message, status = 400) {
    return {
        ok: false,
        status,
        text: async () => JSON.stringify({ success: false, data: { message } }),
    };
}

async function boot(dom, source) {
    dom.window.eval(source);
    await waitFor(() => dom.window.document.readyState !== 'loading');
}

async function waitFor(predicate, timeout = 1500) {
    const started = Date.now();
    while (Date.now() - started < timeout) {
        if (predicate()) return true;
        await new Promise((resolve) => setTimeout(resolve, 15));
    }
    return false;
}

/* ================= ۱) پاک‌سازی دسته‌ای ================= */
{
    const jobId = 'abcdef0123456789abcdef0123456789';
    const markup = `<!doctype html><html><body>
        <section id="bwt-cleanup-progress" data-job-id="${jobId}">
            <p data-bwt-cleanup-status></p>
            <progress data-bwt-cleanup-progress max="100" value="0"></progress>
            <div data-bwt-cleanup-counters></div>
            <button data-bwt-cleanup-resume hidden></button>
            <div data-bwt-cleanup-result></div>
        </section>
    </body></html>`;
    const dom = new JSDOM(markup, {
        url: 'https://example.test/wp-admin/admin.php?page=bwt-cleanup',
        runScripts: 'outside-only',
    });
    const requests = [];
    const payloads = {
        bwt_cleanup_status: {
            mode: 'selected', done: false, progress: 40, processed: 4, total: 10,
            deleted: 3, not_found: 1, invalid: 0, details: [], details_truncated: 0,
        },
        bwt_cleanup_batch: {
            mode: 'selected', done: true, progress: 100, processed: 10, total: 10,
            deleted: 7, not_found: 2, invalid: 1,
            details: ['#12 (کد نداشت)', '<script>bad()</script>'], details_truncated: 3,
        },
    };
    dom.window.BWTAdmin = { ajaxUrl: '/ajax', cleanupNonce: 'c-nonce', retryLabel: 'ادامه' };
    dom.window.fetch = async (_url, options) => {
        const body = readBody(options);
        const action = body.get('action');
        requests.push({ action, nonce: body.get('nonce'), jobId: body.get('job_id') });
        return jsonResponse(payloads[action]);
    };
    await boot(dom, adminSource);
    await waitFor(() => requests.length >= 2);
    const doc = dom.window.document;

    assert.deepEqual(requests.map((entry) => entry.action), ['bwt_cleanup_status', 'bwt_cleanup_batch']);
    assert.ok(requests.every((entry) => entry.nonce === 'c-nonce' && entry.jobId === jobId));
    assert.equal(doc.querySelector('[data-bwt-cleanup-progress]').value, 100);
    assert.match(doc.querySelector('[data-bwt-cleanup-result]').textContent, /پاک‌سازی کامل شد/);
    assert.match(doc.querySelector('[data-bwt-cleanup-result]').textContent, /۳ مورد دیگر/);
    assert.equal(doc.querySelectorAll('[data-bwt-cleanup-result] script').length, 0);
    assert.equal(new URL(dom.window.location.href).searchParams.get('bwt_cleanup_job'), jobId);
    dom.window.close();
}

/* ================= ۲) شناسایی و ذخیرهٔ منبع ================= */
{
    const markup = `<!doctype html><html><body>
        <button data-bwt-scan>scan</button>
        <div data-bwt-scan-status></div>
        <div data-bwt-scan-result></div>
        <form data-bwt-source-form><input data-bwt-source-key /><button type="submit">add</button></form>
        <div data-bwt-source-chips><span class="bwt-chip" data-key="_old_key">_old_key</span></div>
    </body></html>`;
    const dom = new JSDOM(markup, {
        url: 'https://example.test/wp-admin/admin.php?page=bulk-tracking-upload',
        runScripts: 'outside-only',
    });
    const savedKeys = [];
    const scan = {
        sampled: 100,
        keys: [
            { key: '_sorna_barcode', orders: 80, tracking_like: 80, samples: ['RR123456789IR'], selected: false, builtin: false, suggested: true },
            { key: '_billing_phone', orders: 100, tracking_like: 100, samples: ['09121234567'], selected: false, builtin: false, suggested: false },
            { key: '_tracking_code', orders: 20, tracking_like: 20, samples: ['RR999999999IR'], selected: false, builtin: true, suggested: false },
        ],
        notes: { orders: 3, samples: ['RR777777777IR'], enabled: true },
        source_keys: ['_old_key'],
    };
    dom.window.BWTAdmin = { ajaxUrl: '/ajax', scanNonce: 's-nonce' };
    dom.window.fetch = async (_url, options) => {
        const body = readBody(options);
        const action = body.get('action');
        if (action === 'bwt_scan_sources') {
            return jsonResponse(scan);
        }
        if (action === 'bwt_save_sources') {
            const keys = body.getAll('keys[]');
            savedKeys.push(keys);
            return jsonResponse({ source_keys: keys, message: 'منبع ذخیره شد.' });
        }
        throw new Error('unexpected action: ' + action);
    };
    await boot(dom, adminSource);
    const doc = dom.window.document;
    doc.querySelector('[data-bwt-scan]').click();
    await waitFor(() => doc.querySelectorAll('.bwt-source-table tbody tr').length === 3);

    const rows = doc.querySelectorAll('.bwt-source-table tbody tr');
    assert.equal(rows.length, 3);
    assert.equal(rows[0].querySelector('input[type="checkbox"]').value, '_sorna_barcode');
    assert.equal(rows[0].querySelector('input[type="checkbox"]').checked, true, 'پیشنهاد باید تیک بخورد');
    assert.equal(rows[1].querySelector('input[type="checkbox"]').checked, false);
    assert.match(doc.querySelector('[data-bwt-scan-status]').textContent, /۱۰۰ سفارش/);
    assert.match(doc.querySelector('[data-bwt-scan-result]').textContent, /یادداشت سفارش/);

    // ذخیره: کلید پیشنهادی + کلید قبلی
    const saveButton = doc.querySelector('[data-bwt-scan-result] .bwt-actions button');
    saveButton.click();
    await waitFor(() => savedKeys.length === 1);
    assert.deepEqual(savedKeys[0].sort(), ['_old_key', '_sorna_barcode'].sort());
    assert.equal(doc.querySelectorAll('[data-bwt-source-chips] .bwt-chip').length, 2);

    // افزودن دستی کلید
    const input = doc.querySelector('[data-bwt-source-key]');
    input.value = '_sorna_tracking_code';
    doc.querySelector('[data-bwt-source-form]').dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
    await waitFor(() => savedKeys.length === 2);
    assert.ok(savedKeys[1].includes('_sorna_tracking_code'));
    assert.equal(doc.querySelectorAll('[data-bwt-source-chips] .bwt-chip').length, 3);

    // کلید نامعتبر
    input.value = 'bad key!';
    doc.querySelector('[data-bwt-source-form]').dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
    await waitFor(() => /معتبر نیست/.test(doc.querySelector('[data-bwt-scan-status]').textContent));
    assert.match(doc.querySelector('[data-bwt-scan-status]').textContent, /معتبر نیست/);
    dom.window.close();
}

/* ================= ۲.b) پیدا کردن کلید با خود کد ================= */
{
    const realCode = '799400522201761978143147';
    const markup = `<!doctype html><html><body>
        <form data-bwt-find-key-form>
            <input data-bwt-find-key-input value="${realCode}" />
            <button type="submit">پیدا کن</button>
        </form>
        <div data-bwt-find-key-status></div>
        <div data-bwt-find-key-result></div>
        <div data-bwt-source-chips></div>
    </body></html>`;
    const dom = new JSDOM(markup, { url: 'https://example.test/wp-admin/admin.php?page=bulk-tracking-upload', runScripts: 'outside-only' });
    const calls = [];
    dom.window.BWTAdmin = { ajaxUrl: '/ajax', scanNonce: 's-nonce' };
    dom.window.fetch = async (_url, options) => {
        const body = readBody(options);
        const action = body.get('action');
        calls.push({ action, code: body.get('code'), keys: body.getAll('keys[]') });
        if (action === 'bwt_find_key') {
            return jsonResponse({
                code: realCode,
                variants: [realCode],
                found: true,
                matches: [
                    { key: '_sorna_tracking_code', count: 17, tables: ['wp_postmeta', 'wp_wc_orders_meta'], tracking_like: true, suggested: true },
                    { key: '_billing_phone', count: 3, tables: ['wp_postmeta'], tracking_like: false, suggested: false },
                ],
                tables: [{ table: 'wp_sorna_orders', columns: ['id', 'order_id', 'tracking_code'] }],
                source_keys: [],
            });
        }
        if (action === 'bwt_save_sources') {
            return jsonResponse({ source_keys: body.getAll('keys[]'), message: 'ذخیره شد' });
        }
        throw new Error('unexpected action ' + action);
    };
    await boot(dom, adminSource);
    const doc = dom.window.document;
    doc.querySelector('[data-bwt-find-key-form]').dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
    await waitFor(() => doc.querySelectorAll('[data-bwt-find-key-result] tbody tr').length === 2);

    assert.equal(calls[0].action, 'bwt_find_key');
    assert.equal(calls[0].code, realCode);
    const rows = doc.querySelectorAll('[data-bwt-find-key-result] tbody tr');
    assert.equal(rows.length, 2);
    assert.match(rows[0].textContent, /_sorna_tracking_code/);
    assert.match(rows[0].textContent, /۱۷/);
    assert.match(rows[0].textContent, /پیشنهاد/);
    assert.match(doc.querySelector('[data-bwt-find-key-result]').textContent, /wp_sorna_orders/);
    assert.match(doc.querySelector('[data-bwt-find-key-status]').textContent, /کلید/);

    // «استفاده» کلید را به منابع اضافه می‌کند
    rows[0].querySelector('button').click();
    await waitFor(() => calls.some((entry) => entry.action === 'bwt_save_sources'));
    const saveCall = calls.find((entry) => entry.action === 'bwt_save_sources');
    assert.deepEqual(saveCall.keys, ['_sorna_tracking_code']);
    await waitFor(() => doc.querySelectorAll('[data-bwt-source-chips] .bwt-chip').length === 1);
    assert.equal(doc.querySelector('[data-bwt-source-chips] .bwt-chip').getAttribute('data-key'), '_sorna_tracking_code');
    dom.window.close();
}

{
    const markup = `<!doctype html><html><body>
        <form data-bwt-find-key-form><input data-bwt-find-key-input value="1234567890123456" /><button type="submit">پیدا کن</button></form>
        <div data-bwt-find-key-status></div>
        <div data-bwt-find-key-result></div>
    </body></html>`;
    const dom = new JSDOM(markup, { url: 'https://example.test/wp-admin/admin.php?page=bulk-tracking-upload', runScripts: 'outside-only' });
    dom.window.BWTAdmin = { ajaxUrl: '/ajax', scanNonce: 's-nonce' };
    dom.window.fetch = async (_url, options) => {
        const action = readBody(options).get('action');
        if (action === 'bwt_find_key') {
            return jsonResponse({ code: '123', variants: ['123'], found: false, matches: [], tables: [], source_keys: [] });
        }
        throw new Error('unexpected');
    };
    await boot(dom, adminSource);
    const doc = dom.window.document;
    doc.querySelector('[data-bwt-find-key-form]').dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
    await waitFor(() => /پیدا نشد/.test(doc.querySelector('[data-bwt-find-key-status]').textContent));
    assert.match(doc.querySelector('[data-bwt-find-key-status]').textContent, /پیدا نشد/);
    assert.match(doc.querySelector('[data-bwt-find-key-status]').textContent, /جدول اختصاصی/);
    dom.window.close();
}

/* ================= ۳) همگام‌سازی دسته‌ای ================= */
{
    const markup = `<!doctype html><html><body>
        <button data-bwt-sync-mode="missing">missing</button>
        <button data-bwt-sync-mode="recent">recent</button>
        <div id="bwt-sync-progress" data-job-id="">
            <progress data-bwt-progress max="100" value="0"></progress>
            <div data-bwt-sync-counters></div>
            <button data-bwt-resume hidden></button>
            <div data-bwt-sync-result></div>
        </div>
    </body></html>`;
    const dom = new JSDOM(markup, { url: 'https://example.test/wp-admin/admin.php?page=bulk-tracking-upload', runScripts: 'outside-only' });
    const calls = [];
    let batchCalls = 0;
    dom.window.BWTAdmin = { ajaxUrl: '/ajax', syncNonce: 'y-nonce' };
    dom.window.fetch = async (_url, options) => {
        const body = readBody(options);
        const action = body.get('action');
        calls.push({ action, mode: body.get('mode'), jobId: body.get('job_id') });
        if (action === 'bwt_sync_create') {
            return jsonResponse({ job_id: 'abc12345', mode: 'missing', done: false, progress: 0, total: 60, offset: 0, scanned: 0, created: 0, updated: 0, unchanged: 0, no_source: 0, disabled: 0, invalid: 0 });
        }
        if (action === 'bwt_sync_batch') {
            batchCalls++;
            return jsonResponse({
                job_id: 'abc12345', mode: 'missing', done: batchCalls >= 2,
                progress: batchCalls >= 2 ? 100 : 50,
                total: 60, offset: batchCalls * 50, scanned: batchCalls * 50,
                created: batchCalls * 45, updated: 0, unchanged: 0, no_source: batchCalls * 5,
                disabled: 1, invalid: 0,
            });
        }
        throw new Error('unexpected action');
    };
    await boot(dom, adminSource);
    const doc = dom.window.document;
    doc.querySelector('[data-bwt-sync-mode="missing"]').click();
    await waitFor(() => /همگام‌سازی کامل شد/.test(doc.querySelector('[data-bwt-sync-result]').textContent), 3000);

    assert.deepEqual(calls.map((entry) => entry.action), ['bwt_sync_create', 'bwt_sync_batch', 'bwt_sync_batch']);
    assert.ok(calls.every((entry) => entry.jobId === 'abc12345' || entry.action === 'bwt_sync_create'));
    assert.equal(doc.querySelector('[data-bwt-progress]').value, 100);
    assert.match(doc.querySelector('[data-bwt-sync-result]').textContent, /۹۰ سفارش کد رهگیری گرفت/);
    assert.match(doc.querySelector('[data-bwt-sync-result]').textContent, /همگام‌سازی خاموش/);
    assert.equal(doc.querySelector('[data-bwt-resume]').hidden, true);
    dom.window.close();
}

/* ================= ۴) خطای همگام‌سازی و دکمهٔ ادامه ================= */
{
    const markup = `<!doctype html><html><body>
        <button data-bwt-sync-mode="missing">missing</button>
        <div id="bwt-sync-progress">
            <progress data-bwt-progress max="100" value="0"></progress>
            <div data-bwt-sync-counters></div>
            <button data-bwt-resume hidden></button>
            <div data-bwt-sync-result></div>
        </div>
    </body></html>`;
    const dom = new JSDOM(markup, { url: 'https://example.test/wp-admin/admin.php?page=bulk-tracking-upload', runScripts: 'outside-only' });
    dom.window.BWTAdmin = { ajaxUrl: '/ajax', syncNonce: 'y-nonce', retryLabel: 'ادامه بده' };
    dom.window.fetch = async () => errorResponse('سفارشی برای بررسی پیدا نشد.');
    await boot(dom, adminSource);
    const doc = dom.window.document;
    doc.querySelector('[data-bwt-sync-mode="missing"]').click();
    await waitFor(() => doc.querySelector('[data-bwt-resume]').hidden === false);
    assert.equal(doc.querySelector('[data-bwt-resume]').hidden, false);
    assert.equal(doc.querySelector('[data-bwt-resume]').textContent, 'ادامه بده');
    assert.match(doc.querySelector('[data-bwt-sync-counters]').textContent, /پیدا نشد/);
    dom.window.close();
}

/* ================= ۵) فرم پیگیری مشتری ================= */
const trackingMarkup = `<!doctype html><html><body>
    <div data-bwt-tracking>
        <form data-bwt-form>
            <input name="order_id" value="3001" />
            <input name="billing_phone" value="09121234567" />
            <button type="submit">پیگیری</button>
        </form>
        <div data-bwt-result hidden></div>
    </div>
</body></html>`;

async function trackingDom(payload) {
    const dom = new JSDOM(trackingMarkup, { url: 'https://example.test/track', runScripts: 'outside-only' });
    dom.window.BWT_Tracking = {
        ajaxUrl: '/ajax',
        nonce: 't-nonce',
        pollSeconds: 30,
        pollMax: 20,
        strings: { copy: 'کپی', copied: 'کپی شد' },
    };
    const sent = [];
    dom.window.fetch = async (_url, options) => {
        const body = readBody(options);
        sent.push({ action: body.get('action'), nonce: body.get('nonce'), orderId: body.get('order_id'), phone: body.get('billing_phone') });
        return typeof payload === 'function' ? payload() : jsonResponse(payload);
    };
    await boot(dom, trackingSource);
    return { dom, sent };
}

{
    const { dom, sent } = await trackingDom({
        state: 'ready',
        order: { id: 3001, number: '3001', date: '۱۴۰۴/۰۶/۰۱', status: 'processing', status_label: 'در حال انجام' },
        customer: { name: '<script>bad()</script>', address: 'تهران، خیابان ولیعصر' },
        codes: [{ code: 'RR123456789IR', url: 'https://tracking.post.ir/?id=RR123456789IR' }],
        pending_lines: [],
    });
    const doc = dom.window.document;
    doc.querySelector('[data-bwt-form]').dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
    await waitFor(() => doc.querySelector('[data-bwt-result]').className.includes('bwt-track__result--ready'));

    assert.equal(sent.length, 1);
    assert.deepEqual(sent[0], { action: 'bwt_track_lookup', nonce: 't-nonce', orderId: '3001', phone: '09121234567' });
    const result = doc.querySelector('[data-bwt-result]');
    assert.equal(result.hidden, false);
    assert.match(result.textContent, /RR123456789IR/);
    assert.match(doc.querySelector('.bwt-code__value').textContent, /RR123456789IR/);
    assert.equal(doc.querySelectorAll('[data-bwt-result] script').length, 0, 'داده‌ها باید با textContent رندر شوند');
    assert.equal(doc.querySelector('.bwt-post-tracking-btn').getAttribute('href'), 'https://tracking.post.ir/?id=RR123456789IR');
    assert.ok(doc.querySelector('.bwt-copy-btn'));
    dom.window.close();
}

{
    const { dom, sent } = await trackingDom({
        state: 'pending',
        order: { id: 3003, number: '3003' },
        customer: { name: '', address: '' },
        codes: [],
        pending_lines: ['سفارش در مرحلهٔ آماده‌سازی است.', 'پس از تحویل به پست، کد همین‌جا ثبت می‌شود.'],
    });
    const doc = dom.window.document;
    doc.querySelector('[data-bwt-form]').dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
    await waitFor(() => doc.querySelector('.bwt-retry-btn') !== null);
    const result = doc.querySelector('[data-bwt-result]');
    assert.match(result.textContent, /آماده‌سازی/);
    assert.ok(doc.querySelector('.bwt-poll-note').textContent.length > 0, 'شمارندهٔ بررسی خودکار نمایش داده می‌شود');
    assert.equal(sent.length, 1, 'تا ۳۰ ثانیه درخواست اضافه‌ای ارسال نمی‌شود');

    // دکمهٔ «بررسی مجدد» درخواست تازه می‌فرستد
    doc.querySelector('.bwt-retry-btn').click();
    await waitFor(() => sent.length === 2);
    assert.equal(sent.length, 2);
    dom.window.close();
}

{
    const { dom } = await trackingDom(() => errorResponse('شماره سفارش یا شماره موبایل واردشده معتبر نیست.', 403));
    const doc = dom.window.document;
    doc.querySelector('[data-bwt-form]').dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
    await waitFor(() => doc.querySelector('[data-bwt-result]').className.includes('bwt-track__result--error'));
    const result = doc.querySelector('[data-bwt-result]');
    assert.match(result.className, /bwt-track__result--error/);
    assert.match(result.textContent, /معتبر نیست/);
    dom.window.close();
}

{
    const { dom, sent } = await trackingDom({});
    const doc = dom.window.document;
    doc.querySelector('[name="billing_phone"]').value = '';
    doc.querySelector('[data-bwt-form]').dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
    await waitFor(() => /الزامی/.test(doc.querySelector('[data-bwt-result]').textContent));
    assert.equal(sent.length, 0, 'فرم ناقص به سرور فرستاده نمی‌شود');
    assert.match(doc.querySelector('[data-bwt-result]').className, /bwt-track__result--error/);
    dom.window.close();
}

console.log('✓ ۲۸ سنجهٔ رابط: پاک‌سازی، منابع، پیدا کردن کلید، همگام‌سازی و فرم پیگیری سالم است.');
