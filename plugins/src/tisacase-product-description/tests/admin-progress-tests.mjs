import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { JSDOM } from 'jsdom';
import { createRequire } from 'node:module';
const require = createRequire(import.meta.url);
const jquery = require('jquery');

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const source = fs.readFileSync(path.join(root, 'assets/admin.js'), 'utf8');
const markup = `<!doctype html><html><body>
<button id="tc-run"></button><input id="tc-dry" type="checkbox"><div id="tc-progress-wrap" hidden><div id="tc-progress-fill"></div><span id="tc-progress-text"></span></div><span id="tc-total"></span><span id="tc-processed"></span><span id="tc-changed"></span>
<button id="tc-scan"></button><div id="tc-scan-progress-wrap" hidden><div id="tc-scan-progress-fill"></div><span id="tc-scan-progress-text"></span></div>
<div id="tc-scan-result" hidden><div id="tc-scan-list"><div class="tc-scan-row" data-id="101"><input class="tc-scan-select" type="checkbox" checked></div><div class="tc-scan-row" data-id="102"><input class="tc-scan-select" type="checkbox" checked></div></div><div id="tc-scan-note"></div><div id="tc-scan-summary"></div><div id="tc-scan-filters" hidden></div><div id="tc-scan-selectall-wrap" hidden></div><input id="tc-scan-selectall" type="checkbox"><button id="tc-apply" disabled></button><span id="tc-apply-hint"></span></div>
<input id="tc-preview-id"><button id="tc-preview-run"></button><div id="tc-preview-result"></div>
</body></html>`;
const i18n = { error: 'error', sessionExpired: 'expired', confirm: 'confirm', running: 'running', done: 'done', scanRunning: 'scanning', scanDone: 'scan done', noMismatch: 'no mismatches', currentLabel: 'current', emptyDesc: 'empty', newLabel: 'new', clearDesc: 'clear', mismatchSummary: '{n}', selected: 'selected', applyConfirm: 'Apply {n}', applied: '{changed} changed / {unchanged} unchanged', allDone: 'all done' };
async function makeDom(action, replies) {
  const dom = new JSDOM(markup, { url: 'https://example.test/wp-admin/admin.php?page=tisacase-desc', runScripts: 'outside-only' });
  const $ = jquery(dom.window);
  const requests = [];
  dom.window.jQuery = $;
  dom.window.tisa = { ajax: '/wp-admin/admin-ajax.php', nonce: 'test', i18n };
  dom.window.confirm = () => true;
  $.post = (_url, data) => {
    requests.push({ ...data });
    const deferred = $.Deferred();
    const response = replies.shift();
    setTimeout(() => {
      if (response && response.reject) deferred.reject({ responseText: 'offline' });
      else deferred.resolve({ success: true, data: response });
    }, 0);
    return deferred.promise();
  };
  dom.window.eval(source);
  await new Promise((resolve) => setTimeout(resolve, 20));
  if (action === 'tc-apply') dom.window.document.getElementById(action).disabled = false;
  dom.window.document.getElementById(action).click();
  return { dom, requests };
}
async function waitFor(predicate) {
  const start = Date.now();
  while (!predicate()) {
    if (Date.now() - start > 1500) throw new Error('Timed out waiting for batched AJAX completion');
    await new Promise((resolve) => setTimeout(resolve, 10));
  }
}

const repair = await makeDom('tc-run', [
  { processed: 1, changed: 0, total: 2, done: false, next_page: 0, next_offset: 1, snapshot: 'snap-a' },
  { processed: 1, changed: 1, total: 2, done: false, next_page: 1, next_offset: 0, snapshot: 'snap-a' },
  { processed: 0, changed: 0, total: 2, done: true, next_page: 2, next_offset: 0, snapshot: '' },
]);
await waitFor(() => repair.dom.window.document.querySelector('#tc-progress-text').textContent.startsWith('done'));
assert.deepEqual(repair.requests.map((request) => [request.page, request.offset]), [[0, 0], [0, 1], [1, 0]]);
assert.equal(repair.requests[1].snapshot, 'snap-a');
repair.dom.window.close();

const scan = await makeDom('tc-scan', [
  { processed: 1, mismatches: [], total: 2, done: false, next_page: 0, next_offset: 1 },
  { processed: 1, mismatches: [], total: 2, done: false, next_page: 1, next_offset: 0 },
  { processed: 0, mismatches: [], total: 2, done: true, next_page: 2, next_offset: 0 },
]);
await waitFor(() => scan.dom.window.document.querySelector('#tc-scan-progress-text').textContent.includes('scan done'));
assert.deepEqual(scan.requests.map((request) => [request.page, request.offset]), [[0, 0], [0, 1], [1, 0]]);
scan.dom.window.close();

const resumable = await makeDom('tc-run', [
  { processed: 1, changed: 1, total: 2, done: false, next_page: 0, next_offset: 1, snapshot: 'snap-resume' },
  { reject: true },
  { processed: 1, changed: 0, total: 2, done: true, next_page: 1, next_offset: 0, snapshot: 'snap-resume' },
]);
await waitFor(() => !resumable.dom.window.document.querySelector('#tc-run').disabled && resumable.dom.window.document.querySelector('#tc-progress-text').textContent.includes('offline'));
resumable.dom.window.document.querySelector('#tc-run').click();
await waitFor(() => resumable.dom.window.document.querySelector('#tc-progress-text').textContent.startsWith('done'));
assert.deepEqual(resumable.requests.map((request) => [request.page, request.offset]), [[0, 0], [0, 1], [0, 1]]);
assert.equal(resumable.requests[2].snapshot, 'snap-resume');
resumable.dom.window.close();

const selective = await makeDom('tc-apply', [
  { changed: 1, unchanged: 0, processed: 1, total: 2, done: false, next_offset: 1, snapshot: 'snap-selective' },
  { changed: 0, unchanged: 1, processed: 1, total: 2, done: true, next_offset: 2, snapshot: 'snap-selective' },
]);
await waitFor(() => selective.requests.length === 2 && selective.dom.window.document.querySelector('#tc-scan-note').textContent.includes('unchanged'));
assert.deepEqual(selective.requests.map((request) => request.offset), [0, 1]);
assert.deepEqual(Array.from(selective.requests[0].ids), [101, 102]);
assert.equal(selective.requests[1].snapshot, 'snap-selective');
selective.dom.window.close();

console.log('PASS repair resumes a partially processed page by offset');
console.log('PASS scan resumes a partially processed page by offset');
console.log('PASS repair resumes its saved cursor and backup after a request failure');
console.log('PASS selective apply advances in bounded AJAX batches with one backup');
