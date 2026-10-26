import assert from 'node:assert/strict';
import fs from 'node:fs';
import { JSDOM } from 'jsdom';
import jqueryFactory from 'jquery';

const source = fs.readFileSync(new URL('../assets/editor.js', import.meta.url), 'utf8');
const dom = new JSDOM(`<!doctype html><html><body>
    <div class="form-field"><input id="_sku" value=""></div>
    <select id="wcspb-gen"><option value="AB0002">AB0001 → AB0002</option></select>
    <button id="wcspb-gen-btn"></button><span id="wcspb-gen-status"></span>
</body></html>`, { url: 'https://store.example.test/wp-admin/post.php', runScripts: 'outside-only' });
const $ = jqueryFactory(dom.window);
dom.window.$ = $;
dom.window.jQuery = $;
dom.window.WCSPB_ED = {
    ajax: '/wp-admin/admin-ajax.php',
    nonce: 'test',
    postId: 12,
    i18n: { checking: 'checking', dupe: 'duplicate', free: 'free', edit: 'edit', building: 'building', chooseSeries: 'choose', truncated: 'truncated', buildFailed: 'failed' },
};

let summaryCalls = 0;
let nextCalls = 0;
$.post = function (_url, data) {
    const deferred = $.Deferred();
    setTimeout(function () {
        if (data.action === 'wcspb_summary') {
            summaryCalls++;
            deferred.resolve({ success: true, data: summaryCalls === 1
                ? { done: false, processed: 500 }
                : { done: true, processed: 1200, truncated: false, items: [
                    { prefix: 'AB', label: 'AB0005', next: 'AB0006' },
                    { prefix: 'ZX', label: 'ZX0011', next: 'ZX0012' },
                ] } });
        } else if (data.action === 'wcspb_next') {
            nextCalls++;
            deferred.resolve({ success: true, data: nextCalls === 1 ? { pending: true } : { next: 'AB0006' } });
        } else if (data.action === 'wcspb_check') {
            deferred.resolve({ success: true, data: { dupe: false } });
        } else {
            deferred.reject(new Error('unexpected action: ' + data.action));
        }
    }, 0);
    return deferred.promise();
};

dom.window.eval(source);
const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
await wait(300);
assert.equal(summaryCalls, 2, 'editor continues the summary build across bounded AJAX requests');
assert.equal($('#wcspb-gen option').length, 3, 'completed prefix series replace the partial list');
assert.equal($('#wcspb-gen option').eq(1).val(), 'AB0006');

$('#wcspb-gen').val('AB0006');
$('#wcspb-gen-btn').trigger('click');
await wait(300);
assert.equal(nextCalls, 2, 'next-SKU action waits for the summary instead of flushing/rebuilding it');
assert.equal($('#_sku').val(), 'AB0006', 'generator inserts the latest number after the summary is complete');

dom.window.close();
console.log('✓ SKU editor progressive summary and next-SKU tests passed');
