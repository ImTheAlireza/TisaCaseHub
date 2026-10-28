/**
 * تست jsdom برای رفتار پیشخوان (پاک‌سازی/کلید منبع/همگام‌سازی) و فرم پیگیری مشتری.
 * اجرا: node tests/admin-progress-tests.mjs
 */
import assert from 'node:assert/strict';
import fs from 'node:fs';
import { JSDOM } from 'jsdom';

// شمارش واقعی سنجه‌ها تا پیام پایانی با واقعیت بخواند
let assertionCount = 0;
for (const key of Object.keys(assert)) {
    if (typeof assert[key] !== 'function') continue;
    const original = assert[key];
    assert[key] = (...args) => {
        assertionCount += 1;
        return original(...args);
    };
}

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

/* ================= ۲) کلید متای کد رهگیری ================= */
{
    const markup = `<!doctype html><html><body>
        <form data-bwt-source-form>
            <input data-bwt-source-key />
            <button type="submit">ذخیره</button>
        </form>
        <div data-bwt-source-status></div>
        <div data-bwt-source-chips><span class="bwt-chip" data-key="_old_key">_old_key</span></div>
    </body></html>`;
    const dom = new JSDOM(markup, {
        url: 'https://example.test/wp-admin/admin.php?page=bulk-tracking-upload',
        runScripts: 'outside-only',
    });
    const saveCalls = [];
    let unexpected = [];
    dom.window.BWTAdmin = { ajaxUrl: '/ajax', sourceNonce: 'src-nonce' };
    dom.window.fetch = async (_url, options) => {
        const body = readBody(options);
        const action = body.get('action');
        if (action !== 'bwt_save_sources') {
            unexpected.push(action);
            throw new Error('unexpected action: ' + action);
        }
        const keys = body.getAll('keys[]');
        saveCalls.push({ keys, nonce: body.get('nonce') });
        return jsonResponse({ source_keys: keys, message: 'کلید ذخیره شد.' });
    };
    await boot(dom, adminSource);
    const doc = dom.window.document;
    const input = doc.querySelector('[data-bwt-source-key]');
    const form = doc.querySelector('[data-bwt-source-form]');
    const status = doc.querySelector('[data-bwt-source-status]');
    const submit = () => form.dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));

    // افزودن کلید واقعی
    input.value = ' _surena_barcode ';
    submit();
    await waitFor(() => saveCalls.length === 1);
    assert.deepEqual(saveCalls[0].keys, ['_old_key', '_surena_barcode']);
    assert.equal(saveCalls[0].nonce, 'src-nonce');
    await waitFor(() => input.value === '');
    assert.equal(input.value, '', 'پس از ذخیره، فیلد خالی می‌شود');
    assert.equal(doc.querySelectorAll('[data-bwt-source-chips] .bwt-chip').length, 2);
    assert.match(status.textContent, /ذخیره/);
    assert.match(status.className, /bwt-status-line--ok/);

    // کلید نامعتبر سمت مرورگر رد می‌شود (بدون درخواست)
    input.value = 'bad key!';
    submit();
    await waitFor(() => /معتبر نیست/.test(status.textContent));
    assert.match(status.textContent, /معتبر نیست/);
    assert.equal(saveCalls.length, 1, 'درخواستی ارسال نمی‌شود');

    // کلید تکراری
    input.value = '_old_key';
    submit();
    await waitFor(() => /از قبل/.test(status.textContent));
    assert.match(status.textContent, /از قبل ثبت شده/);
    assert.equal(saveCalls.length, 1);

    // ورودی خالی
    input.value = '   ';
    submit();
    await waitFor(() => /نام کلید متا/.test(status.textContent));
    assert.match(status.textContent, /نام کلید متا/);
    assert.equal(saveCalls.length, 1);

    // حذف چیپ، سرور را هم به‌روز می‌کند
    doc.querySelector('[data-bwt-source-chips] .bwt-chip[data-key="_surena_barcode"] .bwt-chip-x').click();
    await waitFor(() => saveCalls.length === 2);
    assert.deepEqual(saveCalls[1].keys, ['_old_key']);
    assert.equal(doc.querySelectorAll('[data-bwt-source-chips] .bwt-chip').length, 1);

    assert.deepEqual(unexpected, [], 'هیچ اکشن حذف‌شده‌ای فراخوانی نمی‌شود');
    dom.window.close();
}

{
    // بدون کلید ذخیره‌شده: راهنما نشان داده می‌شود و حذف آخرین کلید، فهرست را خالی نگه می‌دارد
    const markup = `<!doctype html><html><body>
        <form data-bwt-source-form><input data-bwt-source-key value="_surena_barcode" /><button type="submit">ذخیره</button></form>
        <div data-bwt-source-status></div>
        <div data-bwt-source-chips></div>
    </body></html>`;
    const dom = new JSDOM(markup, { url: 'https://example.test/wp-admin/admin.php?page=bulk-tracking-upload', runScripts: 'outside-only' });
    const calls = [];
    dom.window.BWTAdmin = { ajaxUrl: '/ajax', sourceNonce: 'src-nonce' };
    dom.window.fetch = async (_url, options) => {
        const body = readBody(options);
        const keys = body.getAll('keys[]');
        calls.push(keys);
        return jsonResponse({ source_keys: keys, message: 'کلید ذخیره شد.' });
    };
    await boot(dom, adminSource);
    const doc = dom.window.document;
    assert.match(doc.querySelector('[data-bwt-source-chips]').textContent, /هیچ کلیدی ثبت نشده/);

    doc.querySelector('[data-bwt-source-form]').dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
    await waitFor(() => calls.length === 1);
    assert.deepEqual(calls[0], ['_surena_barcode']);
    assert.equal(doc.querySelectorAll('[data-bwt-source-chips] .bwt-chip').length, 1);

    doc.querySelector('[data-bwt-source-chips] .bwt-chip-x').click();
    await waitFor(() => calls.length === 2);
    assert.deepEqual(calls[1], []);
    assert.match(doc.querySelector('[data-bwt-source-chips]').textContent, /هیچ کلیدی ثبت نشده/);
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
        <div class="bwt-track__fallback" data-bwt-fallback hidden>
            <a class="bwt-btn bwt-btn--primary" href="https://example.test/tracking.pdf" download>دانلود فایل کدهای رهگیری</a>
        </div>
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
    assert.equal(doc.querySelector('[data-bwt-fallback]').hidden, true, 'جعبهٔ PDF در حالت موفق مخفی است');
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
    assert.equal(doc.querySelector('[data-bwt-fallback]').hidden, true, 'جعبهٔ PDF در حالت انتظار مخفی است');
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
    const fallback = doc.querySelector('[data-bwt-fallback]');
    assert.equal(fallback.hidden, false, 'جعبهٔ PDF زیر پیام خطا ظاهر می‌شود');
    assert.equal(fallback.getAttribute('href'), null);
    assert.equal(fallback.querySelector('a').getAttribute('href'), 'https://example.test/tracking.pdf');
    assert.ok(doc.querySelector('[data-bwt-result]').compareDocumentPosition(fallback) & dom.window.Node.DOCUMENT_POSITION_FOLLOWING, 'جعبهٔ PDF بعد از پیام خطا قرار دارد');
    dom.window.close();
}

{
    // خالی‌بودن فیلدها هم خطاست و باید جعبهٔ PDF را نشان دهد (بدون درخواست به سرور)
    const { dom, sent } = await trackingDom({ state: 'ready', codes: [] });
    const doc = dom.window.document;
    doc.querySelector('[name="order_id"]').value = '';
    doc.querySelector('[data-bwt-form]').dispatchEvent(new dom.window.Event('submit', { bubbles: true, cancelable: true }));
    await waitFor(() => doc.querySelector('[data-bwt-result]').className.includes('bwt-track__result--error'));
    assert.equal(doc.querySelector('[data-bwt-result]').className.includes('--error'), true);
    assert.equal(doc.querySelector('[data-bwt-fallback]').hidden, false);
    assert.equal(sent.length, 0, 'اعتبارسنجی سمت مرورگر بدون درخواست به سرور');
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

/* ================= ۶) چیدمان A+ فرم پیگیری ================= */
{
    // فرم واقعی انتخاب‌شده: متن راهنمای کوتاه + دو فیلد + دکمه در یک ردیف
    const previewPath = new URL('../../../../design/preview/tracking-form/index.html', import.meta.url);
    const preview = new JSDOM(fs.readFileSync(previewPath, 'utf8')).window.document;
    const card = preview.querySelector('.bwt-track');
    assert.ok(card, 'کارت فرم در پیش‌نمایش هست');
    const lead = card.querySelector('.bwt-track__head');
    assert.ok(lead, 'سرصفحهٔ راهنما در فرم هست');
    const title = lead.querySelector('.bwt-track__head-title');
    const text = lead.querySelector('.bwt-track__head-text');
    assert.ok(title && text, 'تیتر و توضیح دو عنصر جدا هستند');
    assert.match(title.textContent, /کد رهگیری مرسوله/);
    assert.match(text.textContent, /شمارهٔ سفارش/);
    assert.equal(card.querySelectorAll('.bwt-track__head').length, 1, 'فقط یک سرصفحه');
    assert.equal(lead.querySelectorAll('input').length, 0, 'سرصفحه فقط متن است');
    assert.ok(lead.compareDocumentPosition(card.querySelector('.bwt-track__fields')) & lead.ownerDocument.defaultView.Node.DOCUMENT_POSITION_FOLLOWING, 'سرصفحه قبل از فیلدها می‌آید');
    assert.equal(card.querySelectorAll('.bwt-track__fields .bwt-field').length, 2, 'دو فیلد داخل ردیف');
    assert.equal(card.querySelectorAll('.bwt-btn--block').length, 0, 'دکمه دیگر بلوک تمام‌عرض نیست');
    const submit = card.querySelector('form[data-bwt-form] button[type="submit"]');
    assert.ok(submit.classList.contains('bwt-btn--primary'));
    assert.ok(lead.compareDocumentPosition(submit) & submit.ownerDocument.defaultView.Node.DOCUMENT_POSITION_FOLLOWING, 'دکمه بعد از بلوک راهنما و فیلدها می‌آید');
    const fallback = card.querySelector('[data-bwt-fallback]');
    assert.ok(submit.compareDocumentPosition(fallback) & submit.ownerDocument.defaultView.Node.DOCUMENT_POSITION_FOLLOWING, 'جعبهٔ PDF همچنان پایین‌تر از دکمه است');
}

{
    // قاعده‌های چیدمان در CSS سمت کاربر (نه پیشخوان)
    const css = fs.readFileSync(new URL('../assets/tracking.css', import.meta.url), 'utf8');
    assert.match(css, /\.bwt-track__form\s*\{[^}]*display:\s*grid/, 'ردیف فرم گرید است');
    assert.match(css, /grid-template-columns:\s*minmax\(0, 1fr\) minmax\(0, 1fr\) 210px/, 'دو فیلد کشسان کل عرض را می‌گیرند و دکمه ستون ثابت دارد');
    assert.match(css, /\.bwt-track__form\s*\{[^}]*align-items:\s*end/, 'تراز از پایین برای هم‌ارتفاعی فیلد و دکمه');
    assert.match(css, /\.bwt-track__fields\s*\{[^}]*display:\s*contents/, 'فیلدها فرزندِ همان گرید می‌شوند');
    assert.match(css, /\.bwt-track__fields > \.bwt-field\s*\{[^}]*max-width:\s*none/, 'فیلدها سقف عرض ندارند و فضای خالی نمی‌گذارند');
    assert.doesNotMatch(css, /max-width:\s*420px/, 'سقف ۴۲۰px برداشته شد تا کادر کامل پر شود');
    assert.match(css, /\.bwt-track\s*\{[^}]*container-type:\s*inline-size/, 'کارت ظرف کوئری است');
    assert.match(css, /\.bwt-track__head\s*\{[^}]*grid-column:\s*1 \/ -1/, 'تیتر و توضیح یک سطر کامل بالای فیلدها هستند');
    assert.match(css, /\.bwt-track__head\s*\{[^}]*text-align:\s*start/, 'سرصفحه راست‌چین می‌ماند حتی اگر قالب وسط‌چین کند');
    assert.match(css, /\.bwt-track__head-title\s*\{[^}]*display:\s*block/, 'تیتر در سطر جدا از توضیح است');
    assert.match(css, /\.bwt-track__head-text\s*\{[^}]*display:\s*block/, 'توضیح زیر تیتر می‌آید');
    assert.doesNotMatch(css, /@container bwt-track \(max-width:\s*900px\)/, 'سرصفحه مشروط به عرض کارت نیست و همیشه بالاست');
    assert.match(css, /@container bwt-track \(max-width:\s*860px\)/, 'شکست ۸۶۰px بر اساس عرض کارت');
    assert.match(css, /@container bwt-track \(max-width:\s*560px\)/, 'شکست ۵۶۰px بر اساس عرض کارت');
    assert.match(css, /@supports not \(display: contents\)/, 'برای مرورگرهای قدیمی جانشین display:contents هست');
    assert.match(css, /@supports not \(container-type: inline-size\)/, 'برای مرورگرهای قدیمی جانشین container query هست');
    assert.match(css, /\.bwt-track\s*\{[^}]*max-width:\s*100%/, 'کارت تمام‌عرض ستون محتواست');
    assert.doesNotMatch(css, /max-width:\s*640px;\s*\n\s*margin:\s*0 auto/, 'کارت دیگر وسط‌چین و باریک نیست');
    const adminCss = fs.readFileSync(new URL('../assets/admin.css', import.meta.url), 'utf8');
    assert.doesNotMatch(adminCss, /bwt-track__lead/, 'استایل فرم در admin.css نیست');
}

console.log(`✓ ${assertionCount} سنجهٔ رابط: پاک‌سازی، کلید منبع، همگام‌سازی، فرم پیگیری (چیدمان A+)، جعبهٔ PDF جایگزین و CSS سالم است.`);
