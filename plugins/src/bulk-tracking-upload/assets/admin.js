/* کد رهگیری پستی — رفتار پیشخوان (v3.2)
   چهار بخش: شناسایی/ذخیرهٔ منبع کد، پیدا کردن کلید با خود کد، همگام‌سازی دسته‌ای، پاک‌سازی دسته‌ای. */
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

    /* ---------- ۲) شناسایی منبع کد ---------- */
    function initSourceScanner() {
        var scanButton = document.querySelector('[data-bwt-scan]');
        var limitSelect = document.querySelector('[data-bwt-scan-limit]');
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

        function save(silent) {
            return post('bwt_save_sources', { nonce: conf.scanNonce, keys: selected }).then(function (data) {
                selected = data.source_keys || selected;
                renderChips();
                if (!silent) setStatus(statusNode, data.message || 'منبع ذخیره شد.', 'ok');
                return data;
            }).catch(function (error) {
                setStatus(statusNode, error.message || 'ذخیرهٔ منبع ناموفق بود.', 'bad');
            });
        }

        function addKey(key) {
            key = String(key || '').trim();
            if (!key) return Promise.resolve();
            if (selected.indexOf(key) === -1) selected.push(key);
            renderChips();
            return save();
        }

        if (form) {
            form.addEventListener('submit', function (event) {
                event.preventDefault();
                var input = form.querySelector('[data-bwt-source-key]');
                var key = input ? String(input.value || '').trim() : '';
                if (!key) {
                    setStatus(statusNode, 'نام کلید متا را وارد کنید.', 'bad');
                    return;
                }
                addKey(key).then(function () {
                    if (input) input.value = '';
                });
            });
        }

        scanButton.addEventListener('click', function () {
            var limit = limitSelect ? String(limitSelect.value || '300') : '300';
            scanButton.disabled = true;
            setStatus(statusNode, 'در حال خواندن ' + digits(limit) + ' سفارش اخیر…');
            resultNode.replaceChildren();
            post('bwt_scan_sources', { nonce: conf.scanNonce, limit: limit }).then(function (data) {
                scanButton.disabled = false;
                renderScan(data);
            }).catch(function (error) {
                scanButton.disabled = false;
                setStatus(statusNode, error.message || 'شناسایی منابع ناموفق بود.', 'bad');
            });
        });

        function renderScan(data) {
            if (!data.sampled) {
                setStatus(statusNode, 'سفارشی برای نمونه‌گیری پیدا نشد.', 'bad');
                return;
            }

            var storage = data.storage || {};
            var parts = ['نمونه: ' + digits(data.sampled) + ' سفارش اخیر'];
            parts.push('دارای کد: ' + digits(data.with_code));
            if (Number(data.sorna_orders) > 0) {
                parts.push('سفارش با نشانهٔ سورناپست: ' + digits(data.sorna_orders));
            }
            parts.push('جای ذخیره: ' + (storage.label || storage.hpos || '—'));
            resultNode.replaceChildren();

            // کلیدهایی که مقدارشان نشانهٔ سورناپست دارد، خودکار به منابع اضافه می‌شوند (بدون کار دستی).
            var autokeys = (data.keys || [])
                .filter(function (row) {
                    return row.post_service
                        && (Number(row.tracking_like) > 0 || row.key_match)
                        && selected.indexOf(row.key) === -1;
                })
                .map(function (row) { return row.key; })
                .slice(0, 3);
            if (autokeys.length) {
                selected = selected.concat(autokeys);
                renderChips();
                parts.push('منبع پیشنهادی ذخیره شد: ' + autokeys.join('، '));
                save(true).catch(function () { /* پیام خطا در save نمایش داده می‌شود */ });
            }

            setStatus(statusNode, parts.join(' · '), Number(data.with_code) > 0 || autokeys.length ? 'ok' : 'bad');

            var sornaKeys = data.sorna_keys || [];
            var sornaLabels = sornaKeys.map(function (item) {
                if (typeof item === 'string') return item;
                return item && item.key ? String(item.key) : '';
            }).filter(function (label) { return label !== ''; });
            if (sornaLabels.length) {
                resultNode.appendChild(notice(
                    'warning',
                    'کلیدهایی که مقدارشان نشانهٔ سورناپست دارد: ' + sornaLabels.join('، ')
                    + ' — اگر یکی از این‌ها کد رهگیری را نگه می‌دارد، همان را انتخاب کنید.'
                ));
            } else if (!Number(data.detected)) {
                resultNode.appendChild(notice(
                    'warning',
                    'در این ' + digits(data.sampled) + ' سفارش، نه کدی پیدا شد و نه نشانه‌ای از سورناپست. '
                    + 'یعنی احتمالاً سورناپست کد را در متای سفارش ذخیره نمی‌کند و در جدول اختصاصی خودش است. '
                    + 'از کارت ۲ (پیدا کردن کلید با خود کد) استفاده کنید یا جدول اختصاصی را در تنظیمات وارد کنید.'
                ));
            }

            var keys = data.keys || [];
            if (!keys.length) {
                resultNode.appendChild(create('p', 'bwt-hint', 'هیچ کلید متایی در سفارش‌های نمونه پیدا نشد.'));
            } else {
                var head = create('thead');
                var headRow = create('tr');
                [
                    { label: 'انتخاب', className: 'bwt-cell-pick' },
                    { label: 'کلید متا' },
                    { label: 'تعداد سفارش' },
                    { label: 'کد رهگیری', className: 'bwt-cell-num' },
                    { label: 'نمونهٔ مقدار', className: 'bwt-cell-sample' }
                ].forEach(function (column) {
                    headRow.appendChild(create('th', column.className || '', column.label));
                });
                head.appendChild(headRow);

                var body = create('tbody');
                keys.forEach(function (row) {
                    var tr = create('tr');
                    if (row.post_service) tr.classList.add('bwt-row--sorna');
                    else if (row.selected) tr.classList.add('bwt-row--suggested');

                    var pick = create('td', 'bwt-cell-pick');
                    var checkbox = create('input');
                    checkbox.type = 'checkbox';
                    checkbox.value = row.key;
                    checkbox.checked = selected.indexOf(row.key) !== -1;
                    checkbox.setAttribute('aria-label', 'انتخاب کلید ' + row.key);
                    checkbox.addEventListener('change', function () {
                        if (checkbox.checked) {
                            if (selected.indexOf(row.key) === -1) selected.push(row.key);
                        } else {
                            selected = selected.filter(function (item) { return item !== row.key; });
                        }
                        renderChips();
                        save();
                    });
                    pick.appendChild(checkbox);
                    tr.appendChild(pick);

                    var keyCell = create('td', 'bwt-cell-key');
                    keyCell.setAttribute('dir', 'ltr');
                    keyCell.appendChild(document.createTextNode(row.key));
                    if (row.builtin) keyCell.appendChild(create('span', 'bwt-tag', 'شناخته‌شده'));
                    if (row.post_service) keyCell.appendChild(create('span', 'bwt-tag bwt-tag--sorna', 'سورناپست'));
                    else if (row.suggested) keyCell.appendChild(create('span', 'bwt-tag bwt-tag--ok', 'پیشنهاد'));
                    tr.appendChild(keyCell);

                    tr.appendChild(create('td', 'bwt-cell-num', digits(row.orders)));
                    tr.appendChild(create('td', 'bwt-cell-num', row.tracking_like ? 'بله' : '—'));

                    var sample = create('td', 'bwt-cell-sample');
                    var firstSample = row.samples && row.samples.length ? row.samples[0] : '';
                    var sampleValue = firstSample || row.sorna_value || '';
                    if (sampleValue) {
                        sample.appendChild(create('span', '', sampleValue));
                        sample.setAttribute('dir', 'ltr');
                        if (row.sorna_value && row.sorna_value !== sampleValue) {
                            sample.setAttribute('title', 'مقدار نشانهٔ سورناپست: ' + row.sorna_value);
                        }
                    } else {
                        sample.appendChild(document.createTextNode('—'));
                    }
                    tr.appendChild(sample);

                    body.appendChild(tr);
                });

                var scroll = create('div', 'bwt-table-wrap');
                var node = create('table', 'bwt-table');
                node.appendChild(head);
                node.appendChild(body);
                scroll.appendChild(node);
                resultNode.appendChild(scroll);
                resultNode.appendChild(create('p', 'bwt-hint', 'تیک زدن هر کلید همان لحظه ذخیره می‌شود؛ همگام‌سازی و جاروی خودکار از همین فهرست می‌خوانند.'));
            }

            var notes = data.notes || {};
            if (notes.orders) {
                var details = create('details', 'bwt-details');
                details.appendChild(create('summary', '', 'یادداشت‌های سفارش‌های نمونه'));
                details.appendChild(create('p', 'bwt-hint', notes.orders));
                if (notes.samples && notes.samples.length) {
                    var list = create('p', 'bwt-hint');
                    list.setAttribute('dir', 'rtl');
                    list.textContent = 'نمونه‌ها: ' + notes.samples.slice(0, 5).join(' | ');
                    details.appendChild(list);
                }
                resultNode.appendChild(details);
            }

            renderTables(data.tables || []);
        }

        function renderTables(tables) {
            if (!tables.length) return;
            var box = create('details', 'bwt-details');
            box.appendChild(create('summary', '', 'جدول‌های مشکوک به سورناپست/پست روی این سایت (' + digits(tables.length) + ')'));
            tables.forEach(function (item) {
                var row = create('p', 'bwt-hint');
                row.appendChild(create('code', '', item.table));
                row.setAttribute('dir', 'ltr');
                row.appendChild(document.createTextNode(' → ' + (item.columns || []).join(', ')));
                box.appendChild(row);
            });
            box.appendChild(create('p', 'bwt-hint', 'اگر کد در یکی از این جدول‌ها بود، در تنظیمات → «منبع جدول اختصاصی سورناپست» نام جدول و ستون‌ها را وارد کنید.'));
            resultNode.appendChild(box);
        }
    }

    /* ---------- ۳) پیدا کردن کلید با خود کد رهگیری ---------- */
    function initKeyFinder() {
        var form = document.querySelector('[data-bwt-find-key-form]');
        var resultNode = document.querySelector('[data-bwt-find-key-result]');
        var statusNode = document.querySelector('[data-bwt-find-key-status]');
        var conf = config();
        if (!form || !resultNode || !conf.ajaxUrl || !conf.scanNonce) return;

        var input = form.querySelector('[data-bwt-find-key-input]');
        var chipsNode = document.querySelector('[data-bwt-source-chips]');

        function addSourceKey(key) {
            var keys = [];
            if (chipsNode) {
                Array.prototype.forEach.call(chipsNode.querySelectorAll('.bwt-chip'), function (chip) {
                    var value = chip.getAttribute('data-key');
                    if (value) keys.push(value);
                });
            }
            if (keys.indexOf(key) === -1) keys.push(key);
            return post('bwt_save_sources', { nonce: conf.scanNonce, keys: keys }).then(function (data) {
                if (!chipsNode) return data;
                chipsNode.replaceChildren();
                (data.source_keys || keys).forEach(function (value) {
                    var chip = create('span', 'bwt-chip', value);
                    chip.setAttribute('data-key', value);
                    chip.setAttribute('dir', 'ltr');
                    chipsNode.appendChild(chip);
                });
                setStatus(statusNode, 'کلید ' + key + ' به منابع اضافه شد.', 'ok');
                return data;
            });
        }

        function render(data) {
            resultNode.replaceChildren();
            if (!data.found) {
                setStatus(statusNode, 'این کد در هیچ متای سفارشی پیدا نشد. اگر سورناپست کد را در جدول خودش نگه می‌دارد، از «منبع جدول اختصاصی سورناپست» در تنظیمات استفاده کنید.', 'bad');
                renderTables(data.tables || []);
                return;
            }
            setStatus(statusNode, 'این کد در ' + digits(data.matches.length) + ' کلید پیدا شد. کلیدی که نامش هم مرتبط است انتخاب کنید.', 'ok');

            var head = create('thead');
            var headRow = create('tr');
            ['کلید متا', 'تعداد رکورد', 'جدول', 'ارتباط با کد رهگیری', 'افزودن'].forEach(function (label) {
                headRow.appendChild(create('th', '', label));
            });
            head.appendChild(headRow);

            var body = create('tbody');
            data.matches.forEach(function (row) {
                var tr = create('tr');
                if (row.suggested) tr.classList.add('bwt-row--suggested');

                var keyCell = create('td', 'bwt-cell-key');
                keyCell.setAttribute('dir', 'ltr');
                keyCell.appendChild(document.createTextNode(row.key));
                if (row.suggested) keyCell.appendChild(create('span', 'bwt-tag bwt-tag--ok', 'پیشنهاد'));
                tr.appendChild(keyCell);

                tr.appendChild(create('td', 'bwt-cell-num', digits(row.count)));
                var tablesCell = create('td', '');
                tablesCell.setAttribute('dir', 'ltr');
                tablesCell.textContent = (row.tables || []).join(' · ') || '—';
                tr.appendChild(tablesCell);
                tr.appendChild(create('td', '', row.tracking_like ? 'بله' : 'نامرتبط'));

                var actionCell = create('td', 'bwt-cell-action');
                var button = create('button', 'bwt-btn bwt-btn--ghost bwt-btn--sm', 'استفاده');
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

                body.appendChild(tr);
            });

            var scroll = create('div', 'bwt-table-wrap');
            var node = create('table', 'bwt-table');
            node.appendChild(head);
            node.appendChild(body);
            scroll.appendChild(node);
            resultNode.appendChild(scroll);
            renderTables(data.tables || []);
        }

        function renderTables(tables) {
            if (!tables.length) return;
            var box = create('details', 'bwt-details');
            box.appendChild(create('summary', '', 'جدول‌های مشکوک به سورناپست/پست روی این سایت (' + digits(tables.length) + ')'));
            tables.forEach(function (item) {
                var row = create('p', 'bwt-hint');
                row.setAttribute('dir', 'ltr');
                row.appendChild(create('code', '', item.table));
                row.appendChild(document.createTextNode(' → ' + (item.columns || []).join(', ')));
                box.appendChild(row);
            });
            box.appendChild(create('p', 'bwt-hint', 'اگر کد در یکی از این جدول‌ها بود، در تنظیمات → «منبع جدول اختصاصی سورناپست» نام جدول و ستون‌ها را وارد کنید.'));
            resultNode.appendChild(box);
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var code = input ? String(input.value || '').trim() : '';
            if (!code) {
                setStatus(statusNode, 'کد رهگیری را وارد کنید.', 'bad');
                return;
            }
            var button = form.querySelector('button[type="submit"]');
            if (button) button.disabled = true;
            setStatus(statusNode, 'در حال جست‌وجو در متاهای سفارش‌ها…');
            resultNode.replaceChildren();
            post('bwt_find_key', { nonce: conf.scanNonce, code: code }).then(function (data) {
                if (button) button.disabled = false;
                render(data);
            }).catch(function (error) {
                if (button) button.disabled = false;
                setStatus(statusNode, error && error.message ? error.message : 'جست‌وجو ناموفق بود.', 'bad');
            });
        });
    }

    /* ---------- ۴) همگام‌سازی دسته‌ای ---------- */
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
