/* پیگیری سفارش — رفتار فرانت (v3.0)
   بدون وابستگی: فرم را با AJAX می‌فرستد، نتیجه را با DOM می‌سازد و
   تا آمدن کد، هر ۳۰ ثانیه (حداکثر ۲۰ بار) دوباره بررسی می‌کند. */
(function () {
    'use strict';

    function config() {
        return window.BWT_Tracking || {};
    }

    function strings() {
        var custom = config().strings || {};
        var defaults = {
            sending: 'در حال بررسی سفارش…',
            generic: 'ارتباط با سرور ناموفق بود؛ دوباره تلاش کنید.',
            copy: 'کپی کد',
            copied: 'کپی شد',
            retry: 'بررسی مجدد',
            autoCheck: 'بررسی خودکار بعدی تا',
            seconds: 'ثانیه',
            codeLabel: 'کد رهگیری پستی',
            linkLabel: 'پیگیری مرسوله در سایت پست',
            order: 'شماره سفارش',
            date: 'تاریخ ثبت سفارش',
            name: 'نام و نام خانوادگی',
            address: 'آدرس',
            status: 'وضعیت سفارش',
            stopPoll: 'بررسی خودکار متوقف شد؛ دکمهٔ «بررسی مجدد» را بزنید.'
        };
        Object.keys(defaults).forEach(function (key) {
            if (typeof custom[key] !== 'string' || custom[key] === '') {
                custom[key] = defaults[key];
            }
        });
        return custom;
    }

    function make(tag, className, text) {
        var node = document.createElement(tag);
        if (className) node.className = className;
        if (typeof text !== 'undefined') node.textContent = String(text);
        return node;
    }

    function fa(value) {
        try {
            return new Intl.NumberFormat('fa-IR').format(Number(value) || 0);
        } catch (error) {
            return String(Number(value) || 0);
        }
    }

    function post(payload) {
        var conf = config();
        var body = new URLSearchParams();
        body.set('action', 'bwt_track_lookup');
        body.set('nonce', conf.nonce || '');
        body.set('order_id', payload.orderId);
        body.set('billing_phone', payload.phone);
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
                    throw new Error(strings().generic);
                }
                if (!json || !json.success) {
                    var message = json && json.data && json.data.message ? json.data.message : strings().generic;
                    throw new Error(message);
                }
                return json.data || {};
            });
        });
    }

    function copyText(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        return Promise.reject(new Error('clipboard'));
    }

    function init(root) {
        var form = root.querySelector('[data-bwt-form]');
        var result = root.querySelector('[data-bwt-result]');
        if (!form || !result) return;

        var orderInput = form.querySelector('[name="order_id"]');
        var phoneInput = form.querySelector('[name="billing_phone"]');
        var submit = form.querySelector('button[type="submit"]');
        var poll = { active: false, timer: null, attempts: 0 };

        function stopPoll() {
            poll.active = false;
            if (poll.timer) {
                window.clearInterval(poll.timer);
                poll.timer = null;
            }
        }

        function showError(message) {
            stopPoll();
            result.hidden = false;
            result.className = 'bwt-track__result bwt-track__result--error';
            result.replaceChildren();

            var title = make('p', 'bwt-result__title');
            title.appendChild(make('span', 'bwt-badge', '!'));
            title.appendChild(document.createTextNode('پیگیری انجام نشد'));
            result.appendChild(title);
            result.appendChild(make('p', '', message));
        }

        function infoRow(label, value) {
            var row = make('div', 'bwt-row');
            row.appendChild(make('p', 'bwt-row__label', label));
            row.appendChild(make('p', 'bwt-row__value', value || '—'));
            return row;
        }

        function renderReady(data) {
            var s = strings();
            stopPoll();
            result.hidden = false;
            result.className = 'bwt-track__result bwt-track__result--ready';
            result.replaceChildren();

            var title = make('p', 'bwt-result__title');
            title.appendChild(make('span', 'bwt-badge', '✓'));
            title.appendChild(document.createTextNode('اطلاعات سفارش شما'));
            result.appendChild(title);

            var info = make('div', 'bwt-rows');
            if (data.customer && data.customer.name) info.appendChild(infoRow(s.name, data.customer.name));
            if (data.customer && data.customer.address) info.appendChild(infoRow(s.address, data.customer.address));
            if (data.order && data.order.date) info.appendChild(infoRow(s.date, data.order.date));
            if (data.order && data.order.status_label) info.appendChild(infoRow(s.status, data.order.status_label));
            if (data.order && data.order.number) info.appendChild(infoRow(s.order, data.order.number));
            result.appendChild(info);

            var list = make('div', 'bwt-codes');
            (data.codes || []).forEach(function (item) {
                var wrap = make('div', 'bwt-code');
                wrap.appendChild(make('p', 'bwt-code__label', '📮 ' + s.codeLabel));
                var codeNode = make('span', 'bwt-code__value', item.code);
                wrap.appendChild(codeNode);
                var actions = make('div', 'bwt-code__actions');

                var copyBtn = make('button', 'bwt-btn bwt-btn--soft bwt-copy-btn', s.copy);
                copyBtn.type = 'button';
                copyBtn.addEventListener('click', function () {
                    copyText(item.code).then(function () {
                        copyBtn.textContent = s.copied;
                        copyBtn.classList.add('is-done');
                        window.setTimeout(function () {
                            copyBtn.textContent = s.copy;
                            copyBtn.classList.remove('is-done');
                        }, 1800);
                    }).catch(function () {
                        var range = document.createRange();
                        range.selectNodeContents(codeNode);
                        var selection = window.getSelection();
                        if (selection) {
                            selection.removeAllRanges();
                            selection.addRange(range);
                        }
                        copyBtn.textContent = s.copied;
                    });
                });
                actions.appendChild(copyBtn);

                if (item.url) {
                    var link = make('a', 'bwt-btn bwt-btn--ghost bwt-post-tracking-btn', '🚚 ' + s.linkLabel);
                    link.href = item.url;
                    link.target = '_blank';
                    link.rel = 'noopener noreferrer';
                    actions.appendChild(link);
                }
                wrap.appendChild(actions);
                list.appendChild(wrap);
            });
            result.appendChild(list);
        }

        function schedulePoll(credentials, note, attempts) {
            var s = strings();
            var conf = config();
            var interval = Math.max(10, Number(conf.pollSeconds) || 30);
            var maxAttempts = Math.max(1, Number(conf.pollMax) || 20);
            var remaining = interval;
            stopPoll();
            poll = { active: true, timer: null, attempts: attempts || 0 };

            function tick() {
                if (!poll.active) return;
                if (remaining > 0) {
                    note.textContent = s.autoCheck + ' ' + fa(remaining) + ' ' + s.seconds;
                    remaining--;
                    return;
                }
                stopPoll();
                if (poll.attempts >= maxAttempts) {
                    note.textContent = s.stopPoll;
                    return;
                }
                poll.attempts++;
                var attemptNo = poll.attempts;
                lookup(credentials, true).then(function (data) {
                    if (data && data.state === 'pending') {
                        schedulePoll(credentials, note, attemptNo);
                    }
                });
            }

            poll.timer = window.setInterval(tick, 1000);
            tick();
        }

        function renderPending(data, credentials) {
            var s = strings();
            result.hidden = false;
            result.className = 'bwt-track__result';
            result.replaceChildren();

            var title = make('p', 'bwt-result__title');
            title.appendChild(make('span', 'bwt-badge', '⏳'));
            title.appendChild(document.createTextNode('مرسوله هنوز تحویل پست نشده است'));
            result.appendChild(title);

            var box = make('div', 'bwt-pending');
            (data.pending_lines || []).forEach(function (line) {
                box.appendChild(make('p', '', line));
            });
            result.appendChild(box);

            var actions = make('div', 'bwt-result__actions');
            var retry = make('button', 'bwt-btn bwt-btn--ghost bwt-retry-btn', s.retry);
            retry.type = 'button';
            retry.addEventListener('click', function () {
                stopPoll();
                note.textContent = '';
                lookup(credentials, true);
            });
            actions.appendChild(retry);
            result.appendChild(actions);

            var note = make('p', 'bwt-poll-note');
            result.appendChild(note);
            schedulePoll(credentials, note, 0);
        }

        function lookup(credentials, silent) {
            var s = strings();
            if (!silent) {
                result.hidden = false;
                result.className = 'bwt-track__result';
                result.replaceChildren(make('p', 'bwt-spinner', s.sending));
                if (submit) submit.disabled = true;
            }
            return post(credentials).then(function (data) {
                if (submit) submit.disabled = false;
                if (data.state === 'ready') {
                    renderReady(data);
                    return data;
                }
                if (silent) {
                    return data;
                }
                renderPending(data, credentials);
                return data;
            }).catch(function (error) {
                if (submit) submit.disabled = false;
                stopPoll();
                if (!silent) {
                    showError(error && error.message ? error.message : s.generic);
                }
                return null;
            });
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            var credentials = {
                orderId: orderInput ? String(orderInput.value || '').trim() : '',
                phone: phoneInput ? String(phoneInput.value || '').trim() : ''
            };
            if (!credentials.orderId || !credentials.phone) {
                showError('وارد کردن شماره سفارش و شماره موبایلی که سفارش با آن ثبت شده، الزامی است.');
                return;
            }
            stopPoll();
            lookup(credentials, false);
        });

        window.addEventListener('pagehide', stopPoll);
    }

    function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('[data-bwt-tracking]'), init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, { once: true });
    } else {
        boot();
    }
}());
