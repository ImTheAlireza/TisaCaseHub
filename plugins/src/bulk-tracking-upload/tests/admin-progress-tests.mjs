import assert from 'node:assert/strict';
import fs from 'node:fs';
import { JSDOM } from 'jsdom';

const source = fs.readFileSync(new URL('../assets/admin.js', import.meta.url), 'utf8');
const markup = `<!doctype html><html><body>
    <section id="bwt-upload-progress" data-job-id="1234567812344abc8def1234567890ab" aria-live="polite">
        <p data-bwt-status></p><progress data-bwt-progress max="100" value="0"></progress>
        <div data-bwt-counters></div><button data-bwt-resume hidden></button>
    </section>
    <div data-bwt-result hidden></div>
</body></html>`;
const jobId = '1234567812344abc8def1234567890ab';

const dom = new JSDOM(markup, {
    url: 'https://example.test/wp-admin/admin.php?page=bulk-tracking-upload',
    runScripts: 'outside-only',
});
const requests = [];
const detail = {
    row: 9,
    order_id_raw: '999',
    tracking_code_raw: '<script>bad()</script>',
    reason: 'سفارش نامعتبر است',
};
const payloads = {
    bwt_upload_status: { done: false, progress: 0, processed: 0, updated: 0, errors: 0, details: [], details_truncated: 0 },
    bwt_upload_batch: { done: true, progress: 100, processed: 1, updated: 0, errors: 1, details: [detail], details_truncated: 3 },
};

dom.window.BWTAdmin = { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'test', retryLabel: 'ادامه' };
dom.window.fetch = async function (_url, options) {
    const body = new URLSearchParams(options.body);
    const action = body.get('action');
    requests.push(action);
    return {
        ok: true,
        text: async () => JSON.stringify({ success: true, data: payloads[action] }),
    };
};
dom.window.eval(source);
await new Promise((resolve) => setTimeout(resolve, 100));

assert.deepEqual(requests, ['bwt_upload_status', 'bwt_upload_batch']);
assert.equal(dom.window.document.querySelector('[data-bwt-progress]').value, 100);
assert.equal(dom.window.document.querySelector('[data-bwt-result]').hidden, false);
assert.equal(dom.window.document.querySelectorAll('[data-bwt-result] tbody tr').length, 1);
assert.equal(dom.window.document.querySelector('[data-bwt-result] tbody tr td:nth-child(3)').textContent, '<script>bad()</script>');
assert.equal(dom.window.document.querySelectorAll('[data-bwt-result] script').length, 0);
assert.match(dom.window.document.querySelector('[data-bwt-result]').textContent, /۳ خطای دیگر/);
assert.equal(new URL(dom.window.location.href).searchParams.get('bwt_job'), jobId);
dom.window.close();

const retryDom = new JSDOM(markup, { url: 'https://example.test/wp-admin/admin.php?page=bulk-tracking-upload', runScripts: 'outside-only' });
let retryCount = 0;
retryDom.window.BWTAdmin = { ajaxUrl: '/wp-admin/admin-ajax.php', nonce: 'test', retryLabel: 'ادامه' };
retryDom.window.fetch = async function (_url, options) {
    const action = new URLSearchParams(options.body).get('action');
    retryCount++;
    if (retryCount === 1) throw new Error('offline');
    const data = action === 'bwt_upload_status'
        ? { done: true, progress: 100, processed: 0, updated: 0, errors: 0, details: [], details_truncated: 0 }
        : {};
    return { ok: true, text: async () => JSON.stringify({ success: true, data }) };
};
retryDom.window.eval(source);
await new Promise((resolve) => setTimeout(resolve, 80));
const resumeButton = retryDom.window.document.querySelector('[data-bwt-resume]');
assert.equal(resumeButton.hidden, false);
assert.match(retryDom.window.document.querySelector('[data-bwt-status]').textContent, /offline/);
resumeButton.click();
await new Promise((resolve) => setTimeout(resolve, 80));
assert.equal(retryDom.window.document.querySelector('[data-bwt-result]').hidden, false);
assert.equal(retryCount, 2);
retryDom.window.close();

console.log('✓ bulk-tracking-upload AJAX progress: 12 assertions passed');
