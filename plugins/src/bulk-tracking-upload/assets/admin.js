/* کد رهگیری پستی — رفتار پیشخوان (v3.3)
   سه بخش: ذخیرهٔ کلید متای کد، همگام‌سازی دسته‌ای، پاک‌سازی دسته‌ای. */
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
            } else if (value !== null && typeof value !== 'undefined') {
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

    function notice(kind, text) {
        var box = create('div', 'bwt-notice tisa-notice tisa-notice--' + kind);
        box.appendChild(create('p', '', text));
        return box;
    }

    function setStatus(node, text, kind) {
        if (!node) return;
        node.replaceChildren();
        if (!text) return;
        node.appendChild(document.createTextNode(text));
        node.classList.remove('bwt-status-line--ok', 'bwt-status-line--bad');
        if (kind) node.classList.add('bwt-status-line--' + kind);
    }

    function table(columns, rows, className) {
        var wrap = create('div', 'bwt-table-wrap');
        var node = create('table', 'bwt-table' + (className ? ' ' + className : ''));
        var thead = create('thead');
        var headRow = create('tr');
        columns.forEach(function (column) {
            headRow.appendChild(create('th', column.className || '', column.label));
        });
        thead.appendChild(headRow);
        node.appendChild(thead);
        node.appendChild(rows);
        wrap.appendChild(node);
        return wrap;
    }

    /* ---------- ۱) پاک‌سازی دسته‌ای ---------- */
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
                : 'پاک‌سازی کامل شد؛ ' + digits(data.deleted) + ' ردیف حذف شد.';
            if (data.mode === 'selected') {
                summary += ' بررسی‌شده: ' + digits(data.processed) + ' از ' + digits(data.total)
                    + ' · بدون کد: ' + digits(data.not_found) + ' · سفارش پیدا نشد: ' + digits(data.invalid) + '.';
            }
            resultNode.appendChild(notice(data.file_error ? 'danger' : 'success', summary));
            if (data.details && data.details.length) {
                resultNode.appendChild(create('p', 'bwt-hint', 'جزئیات (حداکثر ۲۰ مورد): ' + data.details.join('، ')));
            }
            if (Number(data.details_truncated) > 0) {
                resultNode.appendChild(create('p', 'bwt-hint', digits(data.details_truncated) + ' مورد دیگر برای محدودماندن حافظه نگه‌داری نشد.'));
            }
        }

        function update(data) {
            var percent = Math.max(0, Math.min(100, Number(data.progress) || 0));
            if (progressNode) progressNode.value = percent;
            if (statusNode) {
                statusNode.textContent = data.done
                    ? (data.file_error ? 'پاک‌سازی متوقف شد؛ می‌توانید ادامه دهید.' : 'پاک‌سازی تمام شد.')
                    : 'در حال حذف دسته‌ای… ' + digits(percent) + '٪';
            }
            if (countersNode) {
                countersNode.textContent = data.mode === 'selected'
                    ? 'بررسی‌شده: ' + digits(data.processed) + ' از ' + digits(data.total) + ' · حذف‌شده: ' + digits(data.deleted)
                    : 'حذف‌شده: ' + digits(data.deleted);
            }
        }

        function showRetry(message) {
            if (statusNode) statusNode.textContent = message || 'ارتباط موقتاً قطع شد؛ می‌توانید ادامه دهید.';
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

    /* ---------- ۲) کلید متای کد رهگیری ---------- */
    function initSourceKeys() {
        var chipsNode = document.querySelector('[data-bwt-source-chips]');
        var form = document.querySelector('[data-bwt-source-form]');
        var statusNode = document.querySelector('[data-bwt-source-status]');
        var conf = config();
        if (!chipsNode || !form || !conf.ajaxUrl || !conf.sourceNonce) return;

        var input = form.querySelector('[data-bwt-source-key]');
        var selected = [];

        Array.prototype.forEach.call(chipsNode.querySelectorAll('.bwt-chip'), function (chip) {
            var key = chip.getAttribute('data-key');
            if (key && selected.indexOf(key) === -1) selected.push(key);
        });

        function renderChips() {
            chipsNode.replaceChildren();
            if (!selected.length) {
                chipsNode.appendChild(create('span', 'bwt-hint', 'هیچ کلیدی ثبت نشده؛ فقط کلیدهای شناخته‌شده و یادداشت سفارش بررسی می‌شوند.'));
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

        renderChips();

        function save() {
            return post('bwt_save_sources', { nonce: conf.sourceNonce, keys: selected }).then(function (data) {
                selected = data.source_keys || selected;
                renderChips();
                setStatus(statusNode, data.message || 'کلید ذخیره شد.', 'ok');
                return data;
            }).catch(function (error) {
                setStatus(statusNode, error.message || 'ذخیرهٔ کلید ناموفق بود.', 'bad');
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var key = input ? String(input.value || '').trim() : '';
            if (key === '') {
                setStatus(statusNode, 'نام کلید متا را وارد کنید.', 'bad');
                return;
            }
            if (!/^[A-Za-z0-9_\-:.]{1,191}$/.test(key)) {
                setStatus(statusNode, 'نام کلید معتبر نیست؛ فقط حروف لاتین، رقم، خط تیره و آندرلاین مجاز است.', 'bad');
                return;
            }
            if (selected.indexOf(key) !== -1) {
                setStatus(statusNode, 'این کلید از قبل ثبت شده است.', 'bad');
                return;
            }
            selected = selected.concat([key]);
            renderChips();
            save().then(function () {
                if (input) input.value = '';
            });
        });
    }

    /* ---------- ۳) همگام‌سازی دسته‌ای ---------- */
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
            resultNode.appendChild(notice(
                updated > 0 ? 'success' : 'warning',
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
            Array.prototype.forEach.call(buttons, function (button) { button.disabled = true; });
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
                Array.prototype.forEach.call(buttons, function (button) { button.disabled = false; });
            }
        }

        async function resume() {
            if (!jobId || running) return;
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
        initSourceKeys();
        initSyncRunner();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, { once: true });
    } else {
        init();
    }
}());
