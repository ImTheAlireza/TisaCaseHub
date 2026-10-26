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

    function initBatchUploader() {
        var root = document.getElementById('bwt-upload-progress');
        var config = window.BWTAdmin || {};
        if (!root || !config.ajaxUrl || !config.nonce) return;

        var jobId = root.getAttribute('data-job-id') || '';
        var statusNode = root.querySelector('[data-bwt-status]');
        var progressNode = root.querySelector('[data-bwt-progress]');
        var countersNode = root.querySelector('[data-bwt-counters]');
        var resumeButton = root.querySelector('[data-bwt-resume]');
        var resultNode = document.querySelector('[data-bwt-result]');
        var allDetails = [];
        var running = false;
        var finished = false;

        try {
            var url = new URL(window.location.href);
            url.searchParams.set('bwt_job', jobId);
            window.history.replaceState({}, '', url.toString());
        } catch (error) {
            // The progress UI remains usable if history is unavailable.
        }

        function post(action) {
            var body = new URLSearchParams();
            body.set('action', action);
            body.set('nonce', config.nonce);
            body.set('job_id', jobId);
            return fetch(config.ajaxUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            }).then(function (response) {
                return response.text().then(function (text) {
                    var json;
                    try {
                        json = JSON.parse(text);
                    } catch (error) {
                        throw new Error('پاسخ سرور قابل خواندن نبود.');
                    }
                    if (!response.ok || !json || !json.success) {
                        var message = json && json.data && json.data.message ? json.data.message : 'ارتباط با سرور ناموفق بود.';
                        throw new Error(message);
                    }
                    return json.data || {};
                });
            });
        }

        function updateProgress(data) {
            var percent = Math.max(0, Math.min(100, Number(data.progress) || 0));
            if (progressNode) progressNode.value = percent;
            if (statusNode) {
                statusNode.textContent = data.done
                    ? (data.file_error ? 'پردازش با خطا متوقف شد.' : 'پردازش فایل کامل شد.')
                    : 'در حال پردازش دسته‌ای فایل CSV… ' + digits(percent) + '٪';
            }
            if (countersNode) {
                countersNode.textContent = 'ردیف پردازش‌شده: ' + digits(data.processed) + ' · موفق: ' + digits(data.updated) + ' · خطا: ' + digits(data.errors);
            }
        }

        function rememberDetails(details, replace) {
            if (replace) allDetails = [];
            var seen = Object.create(null);
            allDetails.forEach(function (detail) { seen[String(detail.row)] = true; });
            (details || []).forEach(function (detail) {
                var key = String(detail.row);
                if (!seen[key]) {
                    seen[key] = true;
                    allDetails.push(detail);
                }
            });
        }

        function appendNotice(parent, kind, message) {
            parent.appendChild(create('div', 'tisa-notice tisa-notice--' + kind + ' bwt-notice', message));
        }

        function makeKpi(parent, label, value, extraClass) {
            var card = create('div', 'bwt-kpi' + (extraClass ? ' ' + extraClass : ''));
            card.appendChild(create('div', 't', label));
            card.appendChild(create('div', 'v', digits(value)));
            parent.appendChild(card);
        }

        function renderResult(data) {
            if (!resultNode) return;
            resultNode.replaceChildren();
            resultNode.hidden = false;

            if (data.file_error) {
                appendNotice(resultNode, 'danger', data.file_error);
                return;
            }

            var updated = Number(data.updated) || 0;
            var errors = Number(data.errors) || 0;
            var processed = Number(data.processed) || (updated + errors);
            var kpis = create('div', 'bwt-kpis');
            makeKpi(kpis, 'ردیف‌های پردازش‌شده', processed, '');
            makeKpi(kpis, 'بروزرسانی موفق', updated, 'bwt-kpi--ok');
            makeKpi(kpis, 'ناموفق', errors, errors > 0 ? 'bwt-kpi--bad' : '');
            resultNode.appendChild(kpis);

            if (errors === 0) {
                appendNotice(resultNode, 'success', 'همهٔ ردیف‌ها با موفقیت پردازش شدند.');
            } else {
                appendNotice(resultNode, 'warning', 'پردازش تمام شد؛ ' + digits(errors) + ' ردیف بروزرسانی نشد. جزئیات در جدول زیر است.');
            }

            if (data.details_warning) {
                appendNotice(resultNode, 'warning', 'ثبت فایل جزئیات خطاها کامل نشد؛ تعداد خطاها در آمار بالا محفوظ است.');
            }

            var details = data.details || allDetails;
            if (!details.length) return;

            var section = create('section', 'bwt-card bwt-card--error');
            var head = create('div', 'bwt-card-head');
            head.appendChild(create('span', 'bwt-dot bwt-dot--bad'));
            var title = create('div');
            title.appendChild(create('h2', '', 'ردیف‌های خطا خورده (' + digits(details.length) + ' مورد)'));
            title.appendChild(create('p', '', 'این ردیف‌ها بروزرسانی نشدند. فایل را اصلاح و دوباره آپلود کنید.'));
            head.appendChild(title);
            section.appendChild(head);

            var cardBody = create('div', 'bwt-card-body');
            var scroll = create('div', 'tisa-table-scroll bwt-scroll');
            var table = create('table', 'tisa-table bwt-table');
            var thead = create('thead');
            var headerRow = create('tr');
            ['ردیف فایل', 'order_id', 'tracking_code', 'دلیل خطا'].forEach(function (label) {
                headerRow.appendChild(create('th', '', label));
            });
            thead.appendChild(headerRow);
            table.appendChild(thead);
            var tbody = create('tbody');
            details.forEach(function (detail) {
                var row = create('tr');
                row.appendChild(create('td', '', digits(detail.row)));
                row.appendChild(create('td', 'bwt-ltr', detail.order_id_raw || '—'));
                var codeCell = create('td', 'bwt-ltr');
                codeCell.appendChild(create('span', 'tisa-code', detail.tracking_code_raw || '—'));
                row.appendChild(codeCell);
                row.appendChild(create('td', 'bwt-reason', detail.reason || 'خطای نامشخص'));
                tbody.appendChild(row);
            });
            table.appendChild(tbody);
            scroll.appendChild(table);
            cardBody.appendChild(scroll);

            var omitted = Number(data.details_truncated) || 0;
            if (omitted > 0) {
                cardBody.appendChild(create('p', 'bwt-hint', 'برای محدود ماندن مصرف حافظه، جزئیات ' + digits(omitted) + ' خطای دیگر ذخیره نشد؛ شمار کل خطاها در آمار بالاست.'));
            }
            section.appendChild(cardBody);
            resultNode.appendChild(section);
        }

        function showRetry(message) {
            if (statusNode) statusNode.textContent = message || 'ارتباط موقتاً قطع شد؛ می‌توانید پردازش را ادامه دهید.';
            if (resumeButton) {
                resumeButton.hidden = false;
                resumeButton.textContent = config.retryLabel || 'تلاش دوباره / ادامه پردازش';
            }
        }

        function delay(ms) {
            return new Promise(function (resolve) { window.setTimeout(resolve, ms); });
        }

        async function processBatches() {
            while (!finished) {
                var attempt = 0;
                var data;
                while (attempt < 3) {
                    try {
                        data = await post('bwt_upload_batch');
                        break;
                    } catch (error) {
                        attempt++;
                        if (attempt >= 3) throw error;
                        await delay(500 * attempt);
                    }
                }
                updateProgress(data);
                rememberDetails(data.details || [], !!data.done);
                if (data.done) {
                    finished = true;
                    renderResult(data);
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
                var state = await post('bwt_upload_status');
                updateProgress(state);
                rememberDetails(state.details || [], true);
                if (state.done) {
                    finished = true;
                    renderResult(state);
                    return;
                }
                await processBatches();
            } catch (error) {
                showRetry(error && error.message ? error.message : 'پردازش متوقف شد.');
            } finally {
                running = false;
            }
        }

        if (resumeButton) resumeButton.addEventListener('click', startOrResume);
        startOrResume();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initBatchUploader, { once: true });
    } else {
        initBatchUploader();
    }
}());
