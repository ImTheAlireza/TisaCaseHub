/**
 * TisaCase Pricing — تب قوانین داینامیک.
 *
 * هر «فهرست» یک lane است: کلید = نوع-نوعِ‌فهرست، مثلاً product-rule, product-exc, category-rule, category-exc.
 * هر محصول/دسته فقط در یکی از دو فهرست همان نوع می‌تواند باشد (قانون یا استثنا)؛ جابه‌جایی خودکار انجام می‌شود.
 * استثناهای شناسه (پیشوند SKU) هم در تب سوم همین بخش مدیریت می‌شوند.
 */
/* global TCP_RULES, jQuery */
(function ($) {
    'use strict';

    // پرچم سلامت برای آشکارساز asset کش‌شده داخل views/rules.php — باید اول باشد.
    window.__tcpRulesV3 = true;

    const cfg = window.TCP_RULES || {};
    const modes = cfg.modes || { none: 'بدون رند', round: 'رند به ۸', jitter: 'تخفیف متغیر (رند به ۸)' };
    const typeLabels = cfg.productTypes || {};
    const defaults = cfg.defaults || { increase: 10, sale: 10, mode: 'round' };
    const minChars = parseInt(cfg.minChars || 2, 10);

    const ACTIONS = { product: cfg.productAct, category: cfg.catAct };
    const LANE_KEYS = ['product-rule', 'product-exc', 'category-rule', 'category-exc'];
    const ROW_SEL = 'tr[data-rule-id]';

    // انتخاب‌های داخل نتایج هر lane: id -> آیتم.
    const selection = {};
    // آخرین نتایج هر lane برای رندر مجدد.
    const lastResults = {};
    // وضعیت صفحه‌بندی جستجو هر lane.
    const searchState = {};
    // شمارهٔ آخرین درخواست؛ پاسخ درخواست قدیمی نادیده گرفته می‌شود.
    const searchGen = {};
    const searchXhr = {};
    const debounceTimers = {};
    LANE_KEYS.forEach(function (key) {
        selection[key] = new Map();
        lastResults[key] = [];
        searchState[key] = blankSearch();
        searchGen[key] = 0;
        searchXhr[key] = null;
    });

    function blankSearch() {
        return { term: '', page: 0, pages: 0, total: 0, perPage: 0 };
    }

    /* ---------------- ابزار ---------------- */

    function esc(value) {
        return $('<div>').text(value == null ? '' : String(value)).html();
    }

    /** برای مقادیر داخل اتریبیوت: کوتیشن هم خنثی می‌شود. */
    function escAttr(value) {
        return esc(value).replace(/"/g, '&quot;');
    }

    function faNum(value) {
        const n = Number(value);
        if (!isFinite(n)) return String(value == null ? '' : value);
        try {
            return n.toLocaleString('fa-IR');
        } catch (e) {
            return String(n);
        }
    }

    function pct(value) {
        const n = parseFloat(value);
        return String(isFinite(n) ? n : 0);
    }

    function modeLabel(mode) {
        return modes[mode] || mode || '';
    }

    function typeLabel(type) {
        return typeLabels[type] || type || '';
    }

    function laneEl(key) {
        return $('.tcp-lane[data-lane="' + key + '"]');
    }

    function laneKeyOf($el) {
        return String($el.closest('.tcp-lane').attr('data-lane') || '');
    }

    function siblingKey(key) {
        const parts = key.split('-');
        return parts[0] + '-' + (parts[1] === 'rule' ? 'exc' : 'rule');
    }

    function isExcKey(key) {
        return key.split('-')[1] === 'exc';
    }

    function typeOfKey(key) {
        return key.split('-')[0];
    }

    /** خواندن همهٔ فیلدهای یک سطر از inputهای مخفی. */
    function readRule($tr) {
        const get = (f) => ($tr.find('input[data-f="' + f + '"]').val() || '');
        return {
            increase: get('increase'),
            sale: get('sale'),
            mode: get('mode') || 'round',
            from: get('from'),
            to: get('to'),
            min: get('min'),
            max: get('max'),
            enabled: get('enabled') === '1',
            exclude: get('exclude') === '1'
        };
    }

    /** خلاصهٔ نمایشی قانون — آینهٔ همان منطق PHP در views/rules.php. */
    function summarize(rule) {
        if (rule.exclude) {
            return { text: 'از همهٔ قوانین (حتی سراسری) خارج است', sub: '' };
        }
        const parts = ['↑ ' + pct(rule.increase) + '٪'];
        parts.push(parseFloat(rule.sale) > 0 ? 'فروش ویژه ' + pct(rule.sale) + '٪' : 'بدون فروش ویژه');
        parts.push(modeLabel(rule.mode));
        const subs = [];
        if (rule.from || rule.to) {
            subs.push('بازه: ' + (rule.from || '…') + ' تا ' + (rule.to || '…'));
        }
        const hasMin = rule.min !== '' && Number(rule.min) > 0;
        const hasMax = rule.max !== '' && Number(rule.max) > 0;
        if (hasMin || hasMax) {
            subs.push('کف/سقف: ' + (hasMin ? faNum(rule.min) : '…') + ' تا ' + (hasMax ? faNum(rule.max) : '…'));
        }
        return { text: parts.join(' · '), sub: subs.join(' · ') };
    }

    function flash($el) {
        $el.addClass('tcp-flash');
        setTimeout(function () { $el.removeClass('tcp-flash'); }, 950);
    }

    const noteTimers = {};
    /** پیام کوتاه بالای فهرست (مثلاً «منتقل شد»)؛ بعد از چند ثانیه پاک می‌شود. */
    function note(key, text) {
        const $n = laneEl(key).find('.tcp-lane-note');
        $n.text(text || '');
        clearTimeout(noteTimers[key]);
        if (text) {
            noteTimers[key] = setTimeout(function () { $n.text(''); }, 6000);
        }
    }

    /* ---------------- شمارنده و حالت خالی ---------------- */

    function refreshLane(key) {
        const $lane = laneEl(key);
        const $rows = $lane.find('.tcp-lane-body').children(ROW_SEL);
        const n = $rows.length;
        $lane.find('.tcp-lane-count').text(faNum(n) + ' مورد');
        $lane.find('.tcp-lane-empty').toggle(!n);
        $lane.find('.tcp-rule-table-scroll').toggle(!!n);
        refreshTabs();
    }

    /** شمارندهٔ کنار تب‌های بخش استثناها. */
    function refreshTabs() {
        const count = function (key) { return laneEl(key).find('.tcp-lane-body').children(ROW_SEL).length; };
        $('[data-ex-count="product"]').text(faNum(count('product-exc')));
        $('[data-ex-count="category"]').text(faNum(count('category-exc')));
        $('[data-ex-count="prefix"]').text(faNum($('#tcp-prefix-list .tcp-prefix-chip').length));
        $('#tcp-prefix-empty').toggle(!$('#tcp-prefix-list .tcp-prefix-chip').length);
    }

    function refreshAll() {
        LANE_KEYS.forEach(refreshLane);
    }

    /** به‌روزرسانی بج‌ها و خلاصهٔ یک سطر از روی مقادیر مخفی (فقط سطر قانون). */
    function refreshRow($tr) {
        const rule = readRule($tr);
        const sum = summarize(rule);
        const key = laneKeyOf($tr);
        if (isExcKey(key)) {
            $tr.find('.tcp-rule-sum').text(sum.text);
            return;
        }
        $tr.find('.tcp-status')
            .text(rule.enabled ? 'فعال' : 'غیرفعال')
            .toggleClass('tcp-st-done', rule.enabled)
            .toggleClass('tcp-st-cancelled', !rule.enabled);
        $tr.find('.tcp-rule-sum').text(sum.text);
        const $sub = $tr.find('.tcp-rule-sub');
        $sub.text(sum.sub).toggleClass('tcp-hidden', sum.sub === '');
        $tr.toggleClass('is-off', !rule.enabled);
        $tr.find('.tcp-quick-enabled').prop('checked', rule.enabled);
    }

    /* ---------------- ساخت سطر ---------------- */

    function thumbHtml(type, item) {
        if (type === 'product') {
            if (item.image_url) {
                return '<img class="tcp-rule-thumb" src="' + escAttr(item.image_url) + '" alt="" loading="lazy">';
            }
            return '<span class="tcp-rule-thumb tcp-rule-thumb--empty" aria-hidden="true">□</span>';
        }
        return '<span class="tcp-rule-thumb tcp-rule-thumb--icon" aria-hidden="true"><span class="dashicons dashicons-category"></span></span>';
    }

    function chipsHtml(type, item) {
        if (type === 'product') {
            let out = '';
            if (item.sku) {
                out += '<code class="tcp-sku" dir="ltr" title="SKU">' + esc(item.sku) + '</code>';
            } else {
                out += '<code class="tcp-sku tcp-sku--empty" title="SKU ثبت نشده">بدون SKU</code>';
            }
            out += '<span class="tcp-ex-id tisa-code">#' + esc(item.id) + '</span>';
            if (item.type) out += '<span>' + esc(typeLabel(item.type)) + '</span>';
            return out;
        }
        let out = '<span class="tcp-ex-id tisa-code">#' + esc(item.id) + '</span>';
        if (typeof item.count !== 'undefined') {
            out += '<span>' + esc(faNum(item.count) + ' محصول') + '</span>';
        }
        if (item.path && item.path !== item.name) {
            out += '<span class="tcp-ex-path">' + esc(item.path) + '</span>';
        }
        return out;
    }

    function searchKey(type, item) {
        const raw = type === 'product'
            ? (item.name + ' ' + (item.sku || '') + ' ' + item.id)
            : (item.name + ' ' + (item.path || '') + ' ' + item.id);
        return String(raw).toLowerCase();
    }

    /** سطر جدید برای یک lane. */
    function buildRow(key, item, rule) {
        const type = typeOfKey(key);
        const exc = isExcKey(key);
        const id = parseInt(item.id, 10);
        const group = type === 'product' ? 'products' : 'categories';
        const n = group + '[' + id + ']';
        const sum = summarize(rule);
        const editUrl = item.edit_url || item.editUrl || '';
        const title = editUrl
            ? '<a class="tcp-rule-name" href="' + escAttr(editUrl) + '" target="_blank" rel="noopener" title="باز کردن صفحهٔ ویرایش در تب جدید">' + esc(item.name) + '</a>'
            : '<span class="tcp-rule-name">' + esc(item.name) + '</span>';
        const enabled = exc ? true : !!rule.enabled;

        const hidden = (f, v) => '<input type="hidden" data-f="' + f + '" name="' + n + '[' + f + ']" value="' + escAttr(v) + '">';
        const identity =
            '<td class="tcp-cell-identity"><div class="tcp-rule-identity">' + thumbHtml(type, item) +
                '<div class="tcp-rule-idmain"><div class="tcp-rule-title">' + title +
                    (exc ? '' : '<span class="tcp-badge tcp-status ' + (enabled ? 'tcp-st-done' : 'tcp-st-cancelled') + '">' + (enabled ? 'فعال' : 'غیرفعال') + '</span>') +
                '</div><div class="tcp-rule-chips">' + chipsHtml(type, item) + '</div></div>' +
            '</div></td>';
        const ruleCell =
            '<td class="tcp-cell-rule"><div class="tcp-rule-sum">' + esc(sum.text) + '</div>' +
                '<div class="tcp-rule-sub' + (sum.sub && !exc ? '' : ' tcp-hidden') + '">' + esc(exc ? '' : sum.sub) + '</div></td>';
        const flagsCell = exc ? '' :
            '<td class="tcp-cell-flags"><label class="tisa-switch tcp-toggle tcp-toggle--sm"><input type="checkbox" class="tcp-quick-enabled"' + (enabled ? ' checked' : '') + '><span class="tisa-switch__track" aria-hidden="true"></span><span>فعال</span></label></td>';
        const actions = exc
            ? '<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm tcp-move-rule" data-to="rule" title="حذف از استثناها و افزودن به قوانین">به قوانین</button>'
            : '<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm tcp-edit-rule">ویرایش</button>' +
              '<button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm tcp-move-rule" data-to="exc" title="خارج کردن از همهٔ قوانین و افزودن به استثناها">به استثنا</button>';
        const label = exc ? 'حذف استثنای ' : 'حذف قانون ';
        const actionCell =
            '<td class="tcp-cell-actions">' + actions +
                '<button type="button" class="tisa-btn tisa-btn--danger-ghost tisa-btn--sm tcp-remove-rule" aria-label="' + escAttr(label + item.name) + '">حذف</button>' +
                hidden('exists', '1') +
                hidden('increase', rule.increase) + hidden('sale', rule.sale) + hidden('mode', rule.mode) +
                hidden('from', rule.from) + hidden('to', rule.to) +
                hidden('min', rule.min) + hidden('max', rule.max) +
                hidden('enabled', enabled ? '1' : '0') + hidden('exclude', exc ? '1' : '0') +
            '</td>';

        const $tr = $('<tr>', {
            'class': 'tcp-rule-row' + (exc ? ' is-excluded' : '') + (enabled ? '' : ' is-off'),
            'data-rule-id': id
        });
        $tr.attr('data-search', searchKey(type, item));
        $tr.attr('data-item', JSON.stringify(item));
        $tr.html(identity + ruleCell + flagsCell + actionCell);
        return $tr;
    }

    function defaultRule(asExclude) {
        return {
            increase: defaults.increase,
            sale: defaults.sale,
            mode: defaults.mode || 'round',
            from: '',
            to: '',
            min: '',
            max: '',
            enabled: true,
            exclude: !!asExclude
        };
    }

    function rowIn(key, id) {
        return laneEl(key).find('.tcp-lane-body').children(ROW_SEL + '[data-rule-id="' + id + '"]');
    }

    /**
     * آیتم را در یک lane قرار می‌دهد. اگر همان محصول/دسته در lane خواهرِ خودش (قانون ↔ استثنا) باشد، منتقل می‌شود.
     * @returns {'added'|'moved'|'dup'}
     */
    function placeItem(key, item, baseRule) {
        const id = parseInt(item.id, 10);
        const $existing = rowIn(key, id);
        if ($existing.length) {
            flash($existing);
            return 'dup';
        }
        const exc = isExcKey(key);
        let rule = baseRule ? $.extend({}, baseRule) : defaultRule(exc);
        let moved = false;
        const sib = siblingKey(key);
        const $sib = rowIn(sib, id);
        if ($sib.length) {
            if (!baseRule) rule = readRule($sib);
            $sib.remove();
            refreshLane(sib);
            moved = true;
        }
        rule.exclude = exc;
        if (exc) {
            rule.enabled = true;
            rule.from = '';
            rule.to = '';
        }
        const $tr = buildRow(key, item, rule);
        laneEl(key).find('.tcp-lane-body').append($tr);
        refreshLane(key);
        applyFilter(key);
        flash($tr);
        return moved ? 'moved' : 'added';
    }

    /* ---------------- جستجوی چندانتخابی ---------------- */

    function resultMeta(type, item) {
        if (type === 'product') {
            const bits = ['#' + item.id];
            if (item.sku) bits.push('SKU: ' + item.sku);
            if (item.type) bits.push(typeLabel(item.type));
            return bits.join(' · ');
        }
        const bits = ['#' + item.id];
        if (typeof item.count !== 'undefined') bits.push(faNum(item.count) + ' محصول');
        if (item.path && item.path !== item.name) bits.push(item.path);
        return bits.join(' · ');
    }

    /** پاسخ جستجو را به شکل یکسان درمی‌آورد (نسخهٔ قدیمی فقط آرایهٔ آیتم‌ها بود). */
    function normalizeResults(raw) {
        if (Array.isArray(raw)) {
            return { items: raw, total: raw.length, page: 1, pages: raw.length ? 1 : 0, perPage: 0 };
        }
        const d = raw || {};
        const items = Array.isArray(d.items) ? d.items : [];
        const total = Number(d.total || 0);
        const pages = Number(d.pages || 0);
        return {
            items: items,
            total: total || items.length,
            page: Math.max(1, Number(d.page || 1)),
            pages: pages || (items.length ? 1 : 0),
            perPage: Number(d.per_page || 0)
        };
    }

    /** افزودن صفحهٔ بعدی به نتایج قبلی بدون آیتم تکراری. */
    function mergeItems(existing, incoming) {
        const seen = {};
        const out = [];
        existing.concat(incoming).forEach(function (item) {
            const id = parseInt(item.id, 10);
            if (!id || seen[id]) return;
            seen[id] = true;
            out.push(item);
        });
        return out;
    }

    function renderResults(key) {
        const $box = laneEl(key).find('.tcp-lane-results');
        const items = lastResults[key];
        const st = searchState[key];
        const type = typeOfKey(key);
        const sib = siblingKey(key);
        const sibLabel = isExcKey(key) ? 'در قوانین' : 'در استثناها';
        $box.empty();

        if (!items.length) {
            $box.append($('<div class="tcp-search-empty">').text('موردی پیدا نشد.')).show();
            return;
        }

        // سرصفحهٔ چسبان: چند مورد در کل سایت پیدا شد و چند مورد تا الآن بارگذاری شده است.
        let headText = faNum(st.total) + ' مورد پیدا شد';
        if (st.total > items.length) {
            headText += ' — ' + faNum(items.length) + ' مورد بارگذاری شده';
        }
        $box.append($('<div class="tcp-search-head">').text(headText));

        items.forEach(function (item) {
            const id = parseInt(item.id, 10);
            const inHere = rowIn(key, id).length > 0;
            const inSib = !inHere && rowIn(sib, id).length > 0;
            const checked = selection[key].has(id);

            const $label = $('<label class="tcp-search-check">').toggleClass('is-added', inHere);
            const $cb = $('<input type="checkbox">')
                .attr('data-id', id)
                .prop('checked', checked)
                .prop('disabled', inHere);
            $label.append($cb);
            $label.toggleClass('is-checked', checked && !inHere);
            if (type === 'product') {
                if (item.image_url) {
                    $label.append($('<img class="tcp-rule-thumb tcp-rule-thumb--xs" alt="">').attr('src', item.image_url).attr('loading', 'lazy'));
                } else {
                    $label.append($('<span class="tcp-rule-thumb tcp-rule-thumb--xs tcp-rule-thumb--empty" aria-hidden="true">').text('□'));
                }
            } else {
                $label.append($('<span class="tcp-rule-thumb tcp-rule-thumb--xs tcp-rule-thumb--icon" aria-hidden="true">').append($('<span class="dashicons dashicons-category">')));
            }
            const $txt = $('<span class="tcp-search-text">');
            $txt.append($('<strong>').text(item.name));
            $txt.append($('<small>').text(resultMeta(type, item)));
            $label.append($txt);
            if (inHere) {
                $label.append($('<span class="tcp-added-tag">').text('در این فهرست'));
            } else if (inSib) {
                $label.append($('<span class="tcp-added-tag tcp-added-tag--move">').text(sibLabel + ' · با افزودن منتقل می‌شود'));
            }
            $box.append($label);
        });

        // نوار ثابت پایین: شمارش + دکمه‌ها.
        const $foot = $('<div class="tcp-search-foot">');
        $foot.append($('<span class="tcp-search-count">').text('۰ انتخاب شده'));
        $foot.append($('<button type="button" class="tisa-btn tisa-btn--sm tisa-btn--secondary" data-tcp-all>')
            .text('انتخاب همه')
            .attr('title', 'همهٔ موارد بارگذاری‌شده در این فهرست انتخاب می‌شوند'));
        $foot.append($('<button type="button" class="tisa-btn tisa-btn--sm tisa-btn--ghost" data-tcp-clear>').text('پاک کردن'));
        $foot.append($('<button type="button" class="tisa-btn tisa-btn--sm tisa-btn--primary" data-tcp-add>')
            .text(isExcKey(key) ? 'افزودن به استثناها' : 'افزودن به قوانین'));
        // بقیهٔ نتایج: فهرست دیگر به ۳۰ مورد ختم نمی‌شود.
        if (st.pages > st.page) {
            const rest = Math.max(0, st.total - items.length);
            const next = st.perPage ? Math.min(st.perPage, rest) : rest;
            $foot.append($('<button type="button" class="tisa-btn tisa-btn--sm tisa-btn--secondary" data-tcp-more>')
                .text('نمایش ' + faNum(next) + ' مورد بعدی'));
        }
        $box.append($foot);

        updateFoot(key);
        $box.show();
    }

    function updateFoot(key) {
        const $box = laneEl(key).find('.tcp-lane-results');
        const n = selection[key].size;
        $box.find('.tcp-search-count').text(faNum(n) + ' انتخاب شده');
        $box.find('[data-tcp-add]').text(
            (n ? 'افزودن ' + faNum(n) + ' مورد' : (isExcKey(key) ? 'افزودن به استثناها' : 'افزودن به قوانین'))
        ).prop('disabled', !n);
    }

    /**
     * جستجو در کل کاتالوگ؛ page>1 نتایج صفحهٔ بعدی را به فهرست اضافه می‌کند.
     */
    function doSearch(key, page) {
        const $input = laneEl(key).find('.tcp-lane-search');
        const $results = laneEl(key).find('.tcp-lane-results');
        const want = Math.max(1, parseInt(page, 10) || 1);
        const term = want > 1 ? searchState[key].term : ($input.val() || '').trim();

        if (want > 1 && !term) return;

        if (want === 1) {
            searchState[key] = blankSearch();
            if (term.length < minChars) {
                searchGen[key]++;
                if (searchXhr[key]) { searchXhr[key].abort(); searchXhr[key] = null; }
                lastResults[key] = [];
                if (!term.length) {
                    $results.empty().hide();
                } else {
                    $results.html('<div class="tcp-search-empty">' + esc('حداقل ' + minChars + ' حرف بنویس…') + '</div>').show();
                }
                return;
            }
        }

        const requestId = ++searchGen[key];
        if (searchXhr[key]) { searchXhr[key].abort(); searchXhr[key] = null; }

        if (want === 1) {
            $results.html('<div class="tcp-search-loading">در حال جستجو...</div>').show();
        } else {
            $results.find('[data-tcp-more]').prop('disabled', true).text('در حال بارگذاری…');
        }

        searchXhr[key] = $.post(cfg.ajaxUrl, {
            action: ACTIONS[typeOfKey(key)],
            nonce: cfg.nonce,
            term: term,
            page: want
        }).done(function (response) {
            if (requestId !== searchGen[key]) return;
            if (!response || !response.success) {
                $results.html('<div class="tcp-search-empty">خطا در جستجو.</div>').show();
                return;
            }
            const data = normalizeResults(response.data);
            searchState[key] = {
                term: term,
                page: data.page,
                pages: data.pages,
                total: data.total,
                perPage: data.perPage
            };
            lastResults[key] = want > 1 ? mergeItems(lastResults[key], data.items) : data.items;
            renderResults(key);
        }).fail(function (xhr, status) {
            // پاسخ درخواستی که با جستجوی تازه‌تر باطل شده نادیده گرفته می‌شود.
            if ('abort' === status || requestId !== searchGen[key]) return;
            $results.html('<div class="tcp-search-empty">ارتباط با سرور برقرار نشد.</div>').show();
        }).always(function () {
            if (requestId === searchGen[key]) { searchXhr[key] = null; }
        });
    }

    function bindSearch(key) {
        const $input = laneEl(key).find('.tcp-lane-search');

        $input.on('input', function () {
            clearTimeout(debounceTimers[key]);
            debounceTimers[key] = setTimeout(function () { doSearch(key, 1); }, 300);
        });

        $input.on('focus', function () {
            if (($input.val() || '').trim().length >= minChars) {
                if (lastResults[key].length) renderResults(key);
                else doSearch(key, 1);
            }
        });

        // اینتر داخل جستجو نباید فرم ذخیره را سابمیت کند.
        $input.on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(debounceTimers[key]);
                doSearch(key, 1);
            }
        });
    }

    /** افزودن همهٔ انتخاب‌شده‌ها به lane. */
    function addSelected(key) {
        let added = 0, dup = 0, moved = 0;
        selection[key].forEach(function (item) {
            const res = placeItem(key, item, null);
            if (res === 'added') added++;
            else if (res === 'moved') { added++; moved++; }
            else dup++;
        });
        if (added) {
            const $scroll = laneEl(key).find('.tcp-rule-table-scroll');
            $scroll.scrollTop($scroll.prop('scrollHeight'));
        }
        selection[key].clear();
        if (moved) {
            note(key, faNum(moved) + ' مورد از ' + (isExcKey(key) ? 'قوانین' : 'استثناها') + ' به این فهرست منتقل شد.');
        }
        // نتایج را تازه کن تا «در این فهرست»ها به‌روز شوند.
        const $box = laneEl(key).find('.tcp-lane-results');
        if (lastResults[key].length && $box.css('display') !== 'none') {
            renderResults(key);
        } else {
            laneEl(key).find('.tcp-lane-results').empty().hide();
        }
        return { added: added, dup: dup };
    }

    /* ---------------- فیلتر داخل لیست ---------------- */

    function applyFilter(key) {
        const q = (laneEl(key).find('.tcp-lane-filter').val() || '').trim().toLowerCase();
        laneEl(key).find('.tcp-lane-body').children(ROW_SEL).each(function () {
            const k = ($(this).attr('data-search') || '');
            $(this).toggle(!q || k.indexOf(q) !== -1);
        });
    }

    /* ---------------- مودال ویرایش (فقط قانون) ---------------- */

    function $modal() {
        return $('#tcp-rule-modal');
    }
    let modalTarget = null; // { key, id }

    function modalRule() {
        return {
            increase: $('#tcp-m-increase').val() || '0',
            sale: $('#tcp-m-sale').val() || '0',
            mode: $('#tcp-m-mode').val() || 'round',
            from: $('#tcp-m-from').val() || '',
            to: $('#tcp-m-to').val() || '',
            min: $('#tcp-m-min').val() || '',
            max: $('#tcp-m-max').val() || '',
            enabled: $('#tcp-m-enabled').is(':checked'),
            exclude: false
        };
    }

    function updateModalHint() {
        const sum = summarize(modalRule());
        $('#tcp-modal-hint').text(sum.text + (sum.sub ? ' — ' + sum.sub : ''));
    }

    function openModal(key, id) {
        const $tr = rowIn(key, id);
        if (!$tr.length) return;
        const rule = readRule($tr);

        modalTarget = { key: key, id: id };
        $('#tcp-m-increase').val(rule.increase);
        $('#tcp-m-sale').val(rule.sale);
        $('#tcp-m-mode').val(rule.mode);
        $('#tcp-m-from').val(rule.from);
        $('#tcp-m-to').val(rule.to);
        $('#tcp-m-min').val(rule.min);
        $('#tcp-m-max').val(rule.max);
        $('#tcp-m-enabled').prop('checked', rule.enabled);

        const name = $tr.find('.tcp-rule-name').first().text();
        $('#tcp-modal-title').text('ویرایش قانون ' + (typeOfKey(key) === 'product' ? 'محصول' : 'دسته‌بندی'));
        $('#tcp-modal-sub').text(name + ' — #' + id);
        updateModalHint();

        $modal().prop('hidden', false);
        document.body.classList.add('tcp-modal-open');
        setTimeout(function () { $('#tcp-m-increase').trigger('focus').trigger('select'); }, 30);
    }

    function closeModal() {
        $modal().prop('hidden', true);
        document.body.classList.remove('tcp-modal-open');
        modalTarget = null;
    }

    function modalOpen() {
        return !$modal().prop('hidden');
    }

    function saveModal() {
        if (!modalTarget) return;
        const $tr = rowIn(modalTarget.key, modalTarget.id);
        if (!$tr.length) {
            closeModal();
            return;
        }
        let rule = modalRule();

        // اعتبارسنجی سبک در همان مودال (سرور هم normalize می‌کند).
        let inc = Math.max(0, Math.min(500, parseFloat(rule.increase) || 0));
        let sale = Math.max(0, Math.min(99.9, parseFloat(rule.sale) || 0));
        rule.increase = String(inc);
        rule.sale = String(sale);
        if (!modes[rule.mode]) rule.mode = 'round';
        if (rule.min !== '' && !(Number(rule.min) > 0)) rule.min = '';
        if (rule.max !== '' && !(Number(rule.max) > 0)) rule.max = '';
        if (rule.min !== '' && rule.max !== '' && Number(rule.min) > Number(rule.max)) rule.max = '';

        $tr.find('input[data-f="increase"]').val(rule.increase);
        $tr.find('input[data-f="sale"]').val(rule.sale);
        $tr.find('input[data-f="mode"]').val(rule.mode);
        $tr.find('input[data-f="from"]').val(rule.from);
        $tr.find('input[data-f="to"]').val(rule.to);
        $tr.find('input[data-f="min"]').val(rule.min);
        $tr.find('input[data-f="max"]').val(rule.max);
        $tr.find('input[data-f="enabled"]').val(rule.enabled ? '1' : '0');

        refreshRow($tr);
        refreshLane(modalTarget.key);
        closeModal();
        flash($tr);
    }

    /* ---------------- استثنای شناسه (پیشوند SKU) ---------------- */

    const PREFIX_RE = /^[\p{L}\p{N}_-]{1,30}$/u;

    function prefixMsg(text, isError) {
        $('#tcp-prefix-msg').text(text || '').toggleClass('is-error', !!isError);
        if (text) {
            clearTimeout(prefixMsg.timer);
            prefixMsg.timer = setTimeout(function () { $('#tcp-prefix-msg').text(''); }, 6000);
        }
    }

    function prefixExists(p) {
        let found = false;
        $('#tcp-prefix-list .tcp-prefix-chip').each(function () {
            if (String($(this).attr('data-prefix')) === p) found = true;
        });
        return found;
    }

    function appendPrefixChip(p) {
        const label = 'حذف شناسهٔ ' + p;
        const $chip = $(
            '<span class="tcp-prefix-chip" data-prefix="' + escAttr(p) + '">' +
                '<code class="tcp-prefix-code" dir="ltr">' + esc(p) + '</code>' +
                '<span class="tcp-prefix-count">پس از ذخیره</span>' +
                '<button type="button" class="tcp-prefix-remove" aria-label="' + escAttr(label) + '">×</button>' +
                '<input type="hidden" name="prefixes[]" value="' + escAttr(p) + '">' +
            '</span>'
        );
        $('#tcp-prefix-list').append($chip);
        flash($chip);
        return $chip;
    }

    function addPrefixes(raw) {
        const parts = String(raw || '').split(/[\s,،;]+/).map(function (s) { return s.trim(); }).filter(Boolean);
        if (!parts.length) {
            prefixMsg('اول یک شناسه بنویس.', true);
            return;
        }
        let added = 0, dup = 0;
        const bad = [];
        parts.forEach(function (part) {
            const p = part.toUpperCase();
            if (!PREFIX_RE.test(p)) {
                bad.push(part);
                return;
            }
            if (prefixExists(p)) {
                dup++;
                return;
            }
            appendPrefixChip(p);
            added++;
        });
        if (added) {
            $('#tcp-prefix-input').val('');
        }
        const msgs = [];
        if (added) msgs.push(faNum(added) + ' شناسه اضافه شد. برای اعمال روی قیمت‌ها ذخیره کن.');
        if (dup) msgs.push(faNum(dup) + ' شناسه از قبل در فهرست بود.');
        if (bad.length) msgs.push('نامعتبر (فقط حروف، عدد، - و _ ، تا ۳۰ نویسه): ' + bad.join('، '));
        prefixMsg(msgs.join(' '), !added);
        refreshTabs();
    }

    /* ---------------- اتصال رویدادها ---------------- */

    function bindAll() {
        LANE_KEYS.forEach(bindSearch);
        refreshAll();
        applyFilterAll();
    }

    function applyFilterAll() {
        LANE_KEYS.forEach(applyFilter);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindAll);
    } else {
        bindAll();
    }

    // کلیک روی سطر نتیجه: تاگل قطعی چک‌باکس، مستقل از رفتار پیش‌فرض لیبل.
    $(document).on('click', '.tcp-search-check', function (e) {
        if ($(e.target).is('input')) {
            return; // کلیک مستقیم روی چک‌باکس: رفتار بومی مرورگر کافی است.
        }
        e.preventDefault();
        const $cbx = $(this).find('input');
        if (!$cbx.length || $cbx.prop('disabled')) {
            return;
        }
        $cbx.prop('checked', !$cbx.prop('checked')).trigger('change');
    });

    // تیک‌زدن داخل نتایج.
    $(document).on('change', '.tcp-search-check input', function () {
        const $cbx = $(this);
        const key = laneKeyOf($cbx);
        $cbx.closest('.tcp-search-check').toggleClass('is-checked', $cbx.is(':checked'));
        const id = parseInt($cbx.attr('data-id'), 10);
        if (this.checked) {
            const found = lastResults[key].find(function (it) { return parseInt(it.id, 10) === id; });
            if (found) selection[key].set(id, found);
        } else {
            selection[key].delete(id);
        }
        updateFoot(key);
    });

    // دکمهٔ افزودن نتایج.
    $(document).on('click', '[data-tcp-add]', function () {
        addSelected(laneKeyOf($(this)));
    });

    $(document).on('click', '[data-tcp-all]', function () {
        const key = laneKeyOf($(this));
        lastResults[key].forEach(function (item) {
            const id = parseInt(item.id, 10);
            if (!rowIn(key, id).length) selection[key].set(id, item);
        });
        renderResults(key);
    });

    $(document).on('click', '[data-tcp-clear]', function () {
        const key = laneKeyOf($(this));
        selection[key].clear();
        renderResults(key);
    });

    // صفحهٔ بعدی نتایج جستجو.
    $(document).on('click', '[data-tcp-more]', function () {
        const key = laneKeyOf($(this));
        const st = searchState[key];
        if (st.pages > st.page) {
            doSearch(key, st.page + 1);
        }
    });

    // ویرایش / حذف / جابه‌جایی سطر.
    $(document).on('click', '.tcp-edit-rule', function () {
        const $tr = $(this).closest(ROW_SEL);
        openModal(laneKeyOf($(this)), parseInt($tr.attr('data-rule-id'), 10));
    });

    $(document).on('click', '.tcp-remove-rule', function () {
        const $btn = $(this);
        const key = laneKeyOf($btn);
        $btn.closest(ROW_SEL).remove();
        refreshLane(key);
    });

    // «به استثنا» / «به قوانین»: سطر به lane مقابل منتقل می‌شود (تنظیمات قانون حفظ می‌شود).
    $(document).on('click', '.tcp-move-rule', function () {
        const $tr = $(this).closest(ROW_SEL);
        const from = laneKeyOf($(this));
        const type = typeOfKey(from);
        const to = type + '-' + $(this).attr('data-to');
        let item = {};
        try {
            item = JSON.parse($tr.attr('data-item') || '{}');
        } catch (e) {
            item = {};
        }
        item.id = parseInt($tr.attr('data-rule-id'), 10);
        item.name = item.name || $tr.find('.tcp-rule-name').first().text();
        const rule = readRule($tr);
        $tr.remove();
        refreshLane(from);
        const res = placeItem(to, item, rule);
        if (res === 'added' || res === 'moved') {
            note(to, 'به ' + (isExcKey(to) ? 'استثناها' : 'قوانین') + ' منتقل شد.');
        }
    });

    // سوییچ «فعال» روی سطر قانون.
    $(document).on('change', '.tcp-quick-enabled', function () {
        // موقعیت اسکرول صفحه و جدول قبل از تغییر DOM؛ هر جابه‌جایی ناخواسته برگردانده می‌شود.
        const winY = window.pageYOffset || document.documentElement.scrollTop || 0;
        const $scrolls = $('.tcp-rule-table-scroll');
        const tops = $scrolls.map(function () { return $(this).scrollTop(); }).get();
        const restoreScroll = function () {
            try {
                if ((window.pageYOffset || document.documentElement.scrollTop || 0) !== winY) {
                    window.scrollTo(0, winY);
                }
                $scrolls.each(function (i) {
                    if ($(this).scrollTop() !== tops[i]) {
                        $(this).scrollTop(tops[i]);
                    }
                });
            } catch (e) { /* ignore */ }
        };
        const $tr = $(this).closest(ROW_SEL);
        $tr.find('input[data-f="enabled"]').val($tr.find('.tcp-quick-enabled').is(':checked') ? '1' : '0');
        refreshRow($tr);
        restoreScroll();
        if (window.requestAnimationFrame) {
            window.requestAnimationFrame(restoreScroll);
        } else {
            setTimeout(restoreScroll, 0);
        }
    });

    // فیلتر داخل فهرست.
    $(document).on('input', '.tcp-lane-filter', function () { applyFilter(laneKeyOf($(this))); });
    $(document).on('keydown', '.tcp-lane-filter', function (e) {
        if (e.key === 'Enter') e.preventDefault();
    });

    // تب‌های بخش استثناها.
    $(document).on('click', '[data-ex-tab]', function () {
        const tab = String($(this).attr('data-ex-tab'));
        $('[data-ex-tab]').each(function () {
            const on = String($(this).attr('data-ex-tab')) === tab;
            $(this).toggleClass('is-active', on).attr('aria-selected', on ? 'true' : 'false');
        });
        $('[data-ex-panel]').each(function () {
            $(this).prop('hidden', String($(this).attr('data-ex-panel')) !== tab);
        });
    });

    // استثنای شناسه: افزودن با دکمه یا Enter (چند مورد با ویرگول/فاصله).
    $(document).on('click', '#tcp-prefix-add', function () {
        addPrefixes($('#tcp-prefix-input').val());
    });
    $(document).on('keydown', '#tcp-prefix-input', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            addPrefixes($(this).val());
        }
    });
    $(document).on('click', '.tcp-prefix-remove', function () {
        $(this).closest('.tcp-prefix-chip').remove();
        refreshTabs();
    });

    // مودال.
    $(document).on('click', '#tcp-modal-save', saveModal);
    $(document).on('click', '[data-tcp-close]', closeModal);
    $(document).on('input change', '#tcp-m-increase, #tcp-m-sale, #tcp-m-mode, #tcp-m-from, #tcp-m-to, #tcp-m-min, #tcp-m-max, #tcp-m-enabled', updateModalHint);
    $(document).on('keydown', function (e) {
        if (e.key === 'Escape' && modalOpen()) closeModal();
        // اینتر داخل فیلدهای متنی مودال = ذخیره؛ روی دکمه/سلکت/چک‌باکس رفتار پیش‌فرض مرورگر.
        if (e.key === 'Enter' && modalOpen() && $(e.target).is('input[type="text"], input[type="number"], input[type="date"], input[type="search"]')) {
            e.preventDefault();
            saveModal();
        }
    });

    // بستن دراپ‌داون با کلیک بیرون.
    $(document).on('click', function (event) {
        if (!$(event.target).closest('.tcp-search-box').length) {
            $('.tcp-search-results').hide();
        }
    });

    // جلوگیری از سابمیت تصادفی فرم با اینتر در جستجوها.
    $(document).on('keydown', '#tcp-rules-form input[type="search"]', function (e) {
        if (e.key === 'Enter') e.preventDefault();
    });
})(jQuery);
