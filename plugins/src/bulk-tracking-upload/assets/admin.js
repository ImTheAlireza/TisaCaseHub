/* کد رهگیری پستی — رفتار پیشخوان (v3.0)
   سه بخش: پردازش پاک‌سازی دسته‌ای، شناسایی/ذخیرهٔ منبع کد، و همگام‌سازی دسته‌ای. */
(function () {
    'use strict';

    function create(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (typeof text !== 'undefined') node.textContent = String(text);
        return node;
    }

    function digits(value) {
        try {
            return new Intl.NumberFormat('fa-IR').format(Number(value) || 0);
        } catch (error) {
            return String(Number(value) || 0);
        }
    }

    function config() {
        return window.BWTAdmin || {};
    }

    function post(action, fields) {
        var conf = config();
        var body = new URLSearchParams();
        body.set('action', action);
        Object.keys(fields || {}).forEach(function (key) {
            var value = fields[key];
            if (Array.isArray(value)) {
                value.forEach(function (item) { body.append(key + '[]', item); });
            } else {
                body.set(key, value);
            }
        });
        return fetch(conf.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (response) {
            return response.text().then(function (text) {
                var json = null;
                try {
                    json = JSON.parse(text);
                } catch (error) {
                    throw new Error(conf.genericError || 'پاسخ سرور قابل خواندن نبود.');
                }
                if (!response.ok || !json || !json.success) {
                    throw new Error(json && json.data && json.data.message ? json.data.message : (conf.genericError || 'ارتباط با سرور ناموفق بود.'));
                }
                return json.data || {};
            });
        });
    }

    function delay(ms) {
        return new Promise(function (resolve) { window.setTimeout(resolve, ms); });
    }

    function initCleanupProcessor() {
        var root = document.getElementById('bwt-cleanup-progress');
        var conf = config();
        if (!root || !conf.ajaxUrl || !conf.cleanupNonce) return;

        var jobId = root.getAttribute('data-job-id') || '';
        var statusNode = root.querySelector('[data-bwt-cleanup-status]');
        var progressNode = root.querySelector('[data-bwt-cleanup-progress]');
        var countersNode = root.querySelector('[data-bwt-cleanup-counters]');
        var resumeButton = root.querySelector('[data-bwt-cleanup-resume]');
        var resultNode = root.querySelector('[data-bwt-cleanup-result]');
        var running = false;
        var finished = false;

        try {
            var url = new URL(window.location.href);
            url.searchParams.set('bwt_cleanup_job', jobId);
            window.history.replaceState({}, '', url.toString());
        } catch (error) {
            // Progress UI remains usable without history.
        }

        function call(action) {
            return post(action, { nonce: conf.cleanupNonce, job_id: jobId });
        }

        function showResult(data) {
            if (!resultNode) return;
            resultNode.replaceChildren();
            var summary = data.file_error
                ? data.file_error
                : 'پاک‌سازی کامل شد؛ ' + digits(data.deleted) + ' ردیف metadata حذف شد.';
            if (data.mode === 'selected') {
                summary += ' پردازش: ' + digits(data.processed) + ' از ' + digits(data.total)
                    + ' · بدون کد: ' + digits(data.not_found) + ' · سفارش یافت‌نشده: ' + digits(data.invalid) + '.';
            }
            resultNode.appendChild(create('div', 'tisa-notice tisa-notice--' + (data.file_error ? 'danger' : 'success') + ' bwt-notice', summary));
            if (data.details && data.details.length) {
                resultNode.appendChild(create('p', 'bwt-hint', 'جزئیات (حداکثر ۲۰ مورد): ' + data.details.join('، ')));
            }
            if (Number(data.details_truncated) > 0) {
                resultNode.appendChild(create('p', 'bwt-hint', 'جزئیات ' + digits(data.details_truncated) + ' مورد دیگر برای محدودماندن حافظه نگهداری نشد.'));
            }
        }

        function update(data) {
            var percent = Math.max(0, Math.min(100, Number(data.progress) || 0));
            if (progressNode) progressNode.value = percent;
            if (statusNode) {
                statusNode.textContent = data.done
                    ? (data.file_error ? 'پاک‌سازی متوقف شد؛ امکان ادامه یا تلاش دوباره وجود دارد.' : 'پاک‌سازی کامل شد.')
                    : 'در حال حذف دسته‌ای… ' + digits(percent) + '٪';
            }
            if (countersNode) {
                countersNode.textContent = data.mode === 'selected'
                    ? 'پردازش‌شده: ' + digits(data.processed) + ' از ' + digits(data.total) + ' · حذف‌شده: ' + digits(data.deleted)
                    : 'حذف‌شده: ' + digits(data.deleted) + ' · جداول بررسی‌شده: ' + digits(Math.min(2, Math.floor(percent / 50)) + 1) + ' از ۲';
            }
        }

        function showRetry(message) {
            if (statusNode) statusNode.textContent = message || 'ارتباط موقتاً قطع شد؛ می‌توانید پاک‌سازی را ادامه دهید.';
            if (resumeButton) {
                resumeButton.hidden = false;
                resumeButton.textContent = conf.retryLabel || 'ادامهٔ پاک‌سازی';
            }
        }

        async function processBatches() {
            while (!finished) {
                var data = await call('bwt_cleanup_batch');
                update(data);
                if (data.done) {
                    finished = true;
                    showResult(data);
                    return;
                }
                await delay(30);
            }
        }

        async function startOrResume() {
            if (running || finished) return;
            running = true;
            if (resumeButton) resumeButton.hidden = true;
            try {
                var state = await call('bwt_cleanup_status');
                update(state);
                if (state.done) {
                    finished = true;
                    showResult(state);
                    return;
                }
                if (state.file_error) {
                    showRetry(state.file_error);
                    return;
                }
                await processBatches();
            } catch (error) {
                showRetry(error && error.message ? error.message : 'پاک‌سازی متوقف شد.');
            } finally {
                running = false;
            }
        }

        if (resumeButton) resumeButton.addEventListener('click', startOrResume);
        startOrResume();
    }

    function initSourceScanner() {
        var scanButton = document.querySelector('[data-bwt-scan]');
        var resultNode = document.querySelector('[data-bwt-scan-result]');
        var statusNode = document.querySelector('[data-bwt-scan-status]');
        var chipsNode = document.querySelector('[data-bwt-source-chips]');
        var form = document.querySelector('[data-bwt-source-form]');
        var conf = config();
        if (!scanButton || !resultNode || !conf.ajaxUrl) return;

        var selected = [];
        if (chipsNode) {
            Array.prototype.forEach.call(chipsNode.querySelectorAll('.bwt-chip'), function (chip) {
                var key = chip.getAttribute('data-key');
                if (key && selected.indexOf(key) === -1) selected.push(key);
            });
        }

        function setStatus(text, kind) {
            if (!statusNode) return;
            statusNode.replaceChildren();
            statusNode.appendChild(create('p', 'bwt-hint' + (kind ? ' bwt-hint--' + kind : ''), text));
        }

        function renderChips() {
            if (!chipsNode) return;
            chipsNode.replaceChildren();
            if (!selected.length) {
                chipsNode.appendChild(create('span', 'bwt-hint', 'هیچ منبع دستی انتخاب نشده؛ فقط کلیدهای شناخته‌شده بررسی می‌شوند.'));
                return;
            }
            selected.forEach(function (key) {
                var chip = create('span', 'bwt-chip', key);
                chip.setAttribute('data-key', key);
                chip.setAttribute('dir', 'ltr');
                var remove = create('button', 'bwt-chip-x', '×');
                remove.type = 'button';
                remove.setAttribute('aria-label', 'حذف ' + key);
                remove.addEventListener('click', function () {
                    selected = selected.filter(function (item) { return item !== key; });
                    renderChips();
                    save();
                });
                chip.appendChild(remove);
                chipsNode.appendChild(chip);
            });
        }

        function save() {
            return post('bwt_save_sources', { nonce: conf.scanNonce, keys: selected }).then(function (data) {
                selected = data.source_keys || selected;
                renderChips();
                setStatus(data.message || 'منبع ذخیره شد.');
                return data;
            }).catch(function (error) {
                setStatus(error.message || 'ذخیرهٔ منبع ناموفق بود.', 'bad');
            });
        }

        scanButton.addEventListener('click', function () {
            scanButton.disabled = true;
            setStatus('در حال خواندن سفارش‌های اخیر…');
            resultNode.replaceChildren();
            post('bwt_scan_sources', { nonce: conf.scanNonce }).then(function (data) {
                scanButton.disabled = false;
                if (!data.sampled) {
                    setStatus('سفارشی برای نمونه‌گیری پیدا نشد.', 'bad');
                    return;
                }
                setStatus('نمونه: ' + digits(data.sampled) + ' سفارش اخیر. کلیدی که کد را نگه می‌دارد را تیک بزنید.', 'ok');
                renderTable(data);
            }).catch(function (error) {
                scanButton.disabled = false;
                setStatus(error.message || 'شناسایی منابع ناموفق بود.', 'bad');
            });
        });

        function renderTable(data) {
            var section = create('div');
            var scroll = create('div', 'tisa-table-scroll bwt-scroll');
            var table = create('table', 'tisa-table bwt-table bwt-source-table');
            var thead = create('thead');
            var headRow = create('tr');
            ['انتخاب', 'کلید متا', 'سفارش‌های دارای مقدار', 'مقدار شبیه کد رهگیری', 'نمونه'].forEach(function (label) {
                headRow.appendChild(create('th', '', label));
            });
            thead.appendChild(headRow);
            table.appendChild(thead);

            var tbody = create('tbody');
            (data.keys || []).forEach(function (row) {
                var tr = create('tr');
                if (row.suggested) tr.className = 'bwt-row--suggested';

                var selectCell = create('td');
                var checkbox = create('input');
                checkbox.type = 'checkbox';
                checkbox.value = row.key;
                var shouldCheck = row.selected || (row.suggested && selected.indexOf(row.key) === -1);
                if (shouldCheck && selected.indexOf(row.key) === -1) {
                    selected.push(row.key);
                }
                checkbox.checked = shouldCheck;
                checkbox.addEventListener('change', function () {
                    if (checkbox.checked) {
                        if (selected.indexOf(row.key) === -1) selected.push(row.key);
                    } else {
                        selected = selected.filter(function (item) { return item !== row.key; });
                    }
                    renderChips();
                });
                selectCell.appendChild(checkbox);
                tr.appendChild(selectCell);

                var keyCell = create('td', 'bwt-ltr');
                keyCell.appendChild(create('span', 'tisa-code', row.key));
                if (row.builtin) keyCell.appendChild(create('span', 'bwt-tag', 'شناخته‌شده'));
                if (row.suggested) keyCell.appendChild(create('span', 'bwt-tag bwt-tag--ok', 'پیشنهاد'));
                tr.appendChild(keyCell);

                tr.appendChild(create('td', '', digits(row.orders)));
                tr.appendChild(create('td', '', digits(row.tracking_like)));

                var sampleCell = create('td', 'bwt-ltr bwt-sample-cell');
                sampleCell.textContent = (row.samples || []).join(' · ');
                tr.appendChild(sampleCell);
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
            scroll.appendChild(table);
            section.appendChild(scroll);

            if (data.notes && data.notes.orders > 0) {
                section.appendChild(create(
                    'p',
                    'bwt-hint',
                    'در ' + digits(data.notes.orders) + ' سفارش، کد رهگیری داخل یادداشت سفارش دیده شد'
                    + (data.notes.samples && data.notes.samples.length ? ' (نمونه: ' + data.notes.samples.join('، ') + ')' : '')
                    + '. برای استفاده، کلید order_note را اضافه کنید یا گزینهٔ «خواندن از یادداشت‌ها» را روشن بگذارید.'
                ));
            }

            var actions = create('div', 'bwt-actions');
            var saveButton = create('button', 'tisa-btn', 'ذخیرهٔ منابع انتخاب‌شده');
            saveButton.type = 'button';
            saveButton.addEventListener('click', function () {
                saveButton.disabled = true;
                save().then(function () {
                    saveButton.disabled = false;
                });
            });
            actions.appendChild(saveButton);
            section.appendChild(actions);
            resultNode.replaceChildren(section);
            renderChips();
        }

        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var input = form.querySelector('[data-bwt-source-key]');
                var key = input ? String(input.value || '').trim() : '';
                if (!/^[A-Za-z0-9_\-:.]{1,191}$/.test(key)) {
                    setStatus('کلید معتبر نیست. نمونه: _sorna_tracking_code یا order_note', 'bad');
                    return;
                }
                if (selected.indexOf(key) === -1) selected.push(key);
                if (input) input.value = '';
                renderChips();
                save();
            });
        }

        renderChips();
    }

    function initKeyFinder() {
        var form = document.querySelector('[data-bwt-find-key-form]');
        var resultNode = document.querySelector('[data-bwt-find-key-result]');
        var statusNode = document.querySelector('[data-bwt-find-key-status]');
        var conf = config();
        if (!form || !resultNode || !conf.ajaxUrl || !conf.scanNonce) return;

        function setStatus(text, kind) {
            if (!statusNode) return;
            statusNode.replaceChildren();
            if (text) statusNode.appendChild(create('p', 'bwt-hint' + (kind ? ' bwt-hint--' + kind : ''), text));
        }

        function addSourceKey(key) {
            var chipsNode = document.querySelector('[data-bwt-source-chips]');
            if (!chipsNode) return Promise.resolve();
            var existing = [];
            Array.prototype.forEach.call(chipsNode.querySelectorAll('.bwt-chip'), function (chip) {
                var value = chip.getAttribute('data-key');
                if (value) existing.push(value);
            });
            if (existing.indexOf(key) !== -1) return Promise.resolve();
            existing.push(key);
            return post('bwt_save_sources', { nonce: conf.scanNonce, keys: existing }).then(function (data) {
                chipsNode.replaceChildren();
                (data.source_keys || existing).forEach(function (value) {
                    var chip = create('span', 'bwt-chip', value);
                    chip.setAttribute('data-key', value);
                    chip.setAttribute('dir', 'ltr');
                    chipsNode.appendChild(chip);
                });
                setStatus('کلید ' + key + ' به منابع اضافه شد.', 'ok');
            });
        }

        function render(data) {
            resultNode.replaceChildren();
            if (!data.found) {
                setStatus('این کد در هیچ متای سفارشی پیدا نشد. اگر سورناپست کد را در جدول خودش نگه می‌دارد، از بخش «منبع جدول اختصاصی» در کارت تنظیمات استفاده کنید.', 'bad');
                renderTables(data.tables || []);
                return;
            }
            setStatus('این کد در ' + digits(data.matches.length) + ' کلید پیدا شد. کلیدی که نامش هم مرتبط است انتخاب کنید.', 'ok');

            var scroll = create('div', 'tisa-table-scroll bwt-scroll');
            var table = create('table', 'tisa-table bwt-table');
            var thead = create('thead');
            var headRow = create('tr');
            ['کلید متا', 'تعداد رکورد', 'جدول', 'ارتباط با کد رهگیری', 'افزودن'].forEach(function (label) {
                headRow.appendChild(create('th', '', label));
            });
            thead.appendChild(headRow);
            table.appendChild(thead);
            var tbody = create('tbody');
            data.matches.forEach(function (row) {
                var tr = create('tr');
                if (row.suggested) tr.className = 'bwt-row--suggested';
                if (row.tracking_like) tr.classList.add('bwt-row--match');

                var keyCell = create('td', 'bwt-ltr');
                keyCell.appendChild(create('span', 'tisa-code', row.key));
                if (row.suggested) keyCell.appendChild(create('span', 'bwt-tag bwt-tag--ok', 'پیشنهاد'));
                tr.appendChild(keyCell);

                tr.appendChild(create('td', '', digits(row.count)));
                tr.appendChild(create('td', 'bwt-ltr', (row.tables || []).join(' · ')));
                tr.appendChild(create('td', '', row.tracking_like ? 'بله' : 'نامرتبط'));

                var actionCell = create('td');
                var button = create('button', 'tisa-btn tisa-btn--ghost tisa-btn--sm', 'استفاده');
                button.type = 'button';
                button.addEventListener('click', function () {
                    button.disabled = true;
                    addSourceKey(row.key).then(function () {
                        button.textContent = 'اضافه شد';
                    }).catch(function () {
                        button.disabled = false;
                    });
                });
                actionCell.appendChild(button);
                tr.appendChild(actionCell);
                tbody.appendChild(tr);
            });
            table.appendChild(tbody);
            scroll.appendChild(table);
            resultNode.appendChild(scroll);
            renderTables(data.tables || []);
        }

        function renderTables(tables) {
            if (!tables.length) return;
            var box = create('details', 'bwt-tables');
            box.appendChild(create('summary', '', 'جدول‌های مشکوک به سورناپست/پست روی این سایت (' + digits(tables.length) + ')'));
            tables.forEach(function (item) {
                var row = create('p', 'bwt-hint');
                row.appendChild(create('code', 'bwt-ltr', item.table));
                row.appendChild(document.createTextNode(' → ' + (item.columns || []).join(', ')));
                box.appendChild(row);
            });
            box.appendChild(create('p', 'bwt-hint', 'اگر کد در یکی از این جدول‌ها بود، نام جدول + ستون شمارهٔ سفارش + ستون کد را در «منبع جدول اختصاصی» وارد کنید.'));
            resultNode.appendChild(box);
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var input = form.querySelector('[data-bwt-find-key-input]');
            var code = input ? String(input.value || '').trim() : '';
            if (!code) {
                setStatus('کد رهگیری را وارد کنید.', 'bad');
                return;
            }
            var button = form.querySelector('button[type="submit"]');
            if (button) button.disabled = true;
            setStatus('در حال جست‌وجو در متاهای سفارش‌ها…');
            resultNode.replaceChildren();
            post('bwt_find_key', { nonce: conf.scanNonce, code: code }).then(function (data) {
                if (button) button.disabled = false;
                render(data);
            }).catch(function (error) {
                if (button) button.disabled = false;
                setStatus(error && error.message ? error.message : 'جست‌وجو ناموفق بود.', 'bad');
            });
        });
    }

    function initSyncRunner() {
        var buttons = document.querySelectorAll('[data-bwt-sync-mode]');
        var root = document.getElementById('bwt-sync-progress');
        var conf = config();
        if (!buttons.length || !root || !conf.ajaxUrl || !conf.syncNonce) return;

        var progressNode = root.querySelector('[data-bwt-progress]');
        var countersNode = root.querySelector('[data-bwt-sync-counters]');
        var resumeButton = root.querySelector('[data-bwt-resume]');
        var resultNode = root.querySelector('[data-bwt-sync-result]');
        var running = false;
        var finished = false;
        var jobId = '';

        function setCounters(text) {
            if (countersNode) countersNode.textContent = text;
        }

        function update(data) {
            var percent = Math.max(0, Math.min(100, Number(data.progress) || 0));
            if (progressNode) progressNode.value = percent;
            setCounters(
                'بررسی‌شده: ' + digits(data.scanned) + ' از ' + digits(data.total)
                + ' · ثبت‌شده: ' + digits(data.created)
                + ' · به‌روزشده: ' + digits(data.updated)
                + ' · بدون منبع: ' + digits(data.no_source)
            );
        }

        function showResult(data) {
            if (!resultNode) return;
            resultNode.replaceChildren();
            var updated = Number(data.created) + Number(data.updated);
            var kind = updated > 0 ? 'success' : 'warning';
            resultNode.appendChild(create(
                'div',
                'tisa-notice tisa-notice--' + kind + ' bwt-notice',
                updated > 0
                    ? 'همگام‌سازی کامل شد؛ ' + digits(updated) + ' سفارش کد رهگیری گرفت.'
                    : 'همگام‌سازی تمام شد؛ کد جدیدی پیدا نشد. اگر سورناپست برای این سفارش‌ها کد صادر نکرده باشد طبیعی است.'
            ));
            if (Number(data.disabled) > 0) {
                resultNode.appendChild(create('p', 'bwt-hint', digits(data.disabled) + ' سفارش با «همگام‌سازی خاموش» رد شد.'));
            }
            if (Number(data.invalid) > 0) {
                resultNode.appendChild(create('p', 'bwt-hint', digits(data.invalid) + ' شناسهٔ سفارش قابل خواندن نبود.'));
            }
        }

        function showRetry(message) {
            setCounters(message || 'ارتباط قطع شد؛ می‌توانید ادامه دهید.');
            if (resumeButton) {
                resumeButton.hidden = false;
                resumeButton.textContent = conf.retryLabel || 'ادامهٔ همگام‌سازی';
            }
        }

        function runBatch() {
            return post('bwt_sync_batch', { nonce: conf.syncNonce, job_id: jobId });
        }

        async function processBatches() {
            while (!finished) {
                var data = await runBatch();
                update(data);
                if (data.done) {
                    finished = true;
                    showResult(data);
                    return;
                }
                await delay(30);
            }
        }

        async function start(mode) {
            if (running) return;
            running = true;
            finished = false;
            if (resumeButton) resumeButton.hidden = true;
            if (resultNode) resultNode.replaceChildren();
            if (progressNode) progressNode.value = 0;
            setCounters('در حال ساخت فهرست سفارش‌ها…');
            try {
                var created = await post('bwt_sync_create', { nonce: conf.syncNonce, mode: mode });
                jobId = created.job_id || '';
                root.setAttribute('data-job-id', jobId);
                update(created);
                await processBatches();
            } catch (error) {
                showRetry(error && error.message ? error.message : 'همگام‌سازی متوقف شد.');
            } finally {
                running = false;
            }
        }

        async function resume() {
            if (!jobId) return;
            if (running) return;
            running = true;
            if (resumeButton) resumeButton.hidden = true;
            try {
                var data = await runBatch();
                update(data);
                if (data.done) {
                    finished = true;
                    showResult(data);
                    return;
                }
                await processBatches();
            } catch (error) {
                showRetry(error && error.message ? error.message : 'همگام‌سازی متوقف شد.');
            } finally {
                running = false;
            }
        }

        Array.prototype.forEach.call(buttons, function (button) {
            button.addEventListener('click', function () {
                start(button.getAttribute('data-bwt-sync-mode') || 'missing');
            });
        });
        if (resumeButton) resumeButton.addEventListener('click', resume);
    }

    function init() {
        initCleanupProcessor();
        initSourceScanner();
        initKeyFinder();
        initSyncRunner();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}());
