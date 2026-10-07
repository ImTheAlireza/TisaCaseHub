/**
 * TisaCase Pricing — تب قوانین داینامیک.
 * جستجوی چندانتخابی محصول/دسته، جدول قوانین و ویرایش با مودال.
 */
/* global TCP_RULES, jQuery */
(function ($) {
    'use strict';

    // پرچم سلامت برای آشکارساز asset کش‌شده داخل views/rules.php — باید اول باشد.
    window.__tcpRulesV2 = true;

    const cfg = window.TCP_RULES || {};
    const modes = cfg.modes || { none: 'بدون رند', round: 'رند به ۸', jitter: 'تخفیف متغیر (رند به ۸)' };
    const typeLabels = cfg.productTypes || {};
    const defaults = cfg.defaults || { increase: 10, sale: 10, mode: 'round' };
    const minChars = parseInt(cfg.minChars || 2, 10);

    const GROUPS = {
        product: {
            group: 'products',
            list: '#tcp-product-rules',
            empty: '#tcp-product-empty',
            count: '#tcp-product-count',
            filter: '#tcp-product-filter',
            search: '#tcp-product-search',
            results: '#tcp-product-results',
            action: cfg.productAct,
            modalKind: 'محصول'
        },
        category: {
            group: 'categories',
            list: '#tcp-category-rules',
            empty: '#tcp-category-empty',
            count: '#tcp-category-count',
            filter: '#tcp-category-filter',
            search: '#tcp-category-search',
            results: '#tcp-category-results',
            action: cfg.catAct,
            modalKind: 'دسته‌بندی'
        }
    };

    const ROW_SEL = 'tr[data-rule-id]';

    // انتخاب‌های داخل دراپ‌داون هر گروه: id -> آیتم.
    const selection = { product: new Map(), category: new Map() };
    // آخرین نتایج هر گروه برای رندر مجدد (بعد از افزودن، تیک «در لیست»).
    const lastResults = { product: [], category: [] };
    // وضعیت صفحه‌بندی جستجو: نتیجه‌ها صفحه‌به‌صفحه (۱۰۰ مورد) اضافه می‌شوند.
    const searchState = { product: blankSearch(), category: blankSearch() };
    // شمارهٔ آخرین درخواست هر گروه؛ پاسخ درخواست قدیمی نادیده گرفته می‌شود.
    const searchGen = { product: 0, category: 0 };
    const searchXhr = { product: null, category: null };
    const debounceTimers = {};

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

    /* ---------------- شمارنده و حالت خالی ---------------- */

    function refreshGroup(type) {
        const g = GROUPS[type];
        const $rows = $(g.list).children(ROW_SEL);
        const n = $rows.length;
        let e = 0;
        $rows.each(function () {
            if ($(this).find('input[data-f="exclude"]').val() === '1') e++;
        });
        let text = faNum(n) + ' مورد';
        if (e) text += ' · ' + faNum(e) + ' استثنا';
        $(g.count).text(text);
        $(g.empty).toggle(!n);
        $(g.list).closest('.tcp-rule-table-scroll').toggle(!!n);
    }

    function refreshAll() {
        refreshGroup('product');
        refreshGroup('category');
    }

    /** به‌روزرسانی بج‌ها و خلاصهٔ یک سطر از روی مقادیر مخفی. */
    function refreshRow($tr) {
        const rule = readRule($tr);
        const sum = summarize(rule);

        const $status = $tr.find('.tcp-status');
        $status
            .text(rule.enabled ? 'فعال' : 'غیرفعال')
            .toggleClass('tcp-st-done', rule.enabled)
            .toggleClass('tcp-st-cancelled', !rule.enabled);
        $tr.find('.tcp-exbadge').toggleClass('tcp-hidden', !rule.exclude);
        $tr.find('.tcp-rule-sum').text(sum.text);
        const $sub = $tr.find('.tcp-rule-sub');
        $sub.text(sum.sub).toggleClass('tcp-hidden', sum.sub === '');

        $tr.toggleClass('is-excluded', rule.exclude);
        $tr.toggleClass('is-off', !rule.enabled);
        $tr.find('.tcp-quick-enabled').prop('checked', rule.enabled);
        $tr.find('.tcp-quick-exclude').prop('checked', rule.exclude);
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

    function addRuleRow(type, item, rule) {
        const g = GROUPS[type];
        const $list = $(g.list);
        const id = parseInt(item.id, 10);

        const $dup = $list.children(ROW_SEL + '[data-rule-id="' + id + '"]');
        if ($dup.length) {
            flash($dup);
            return 'dup';
        }

        const n = g.group + '[' + id + ']';
        const sum = summarize(rule);
        const editUrl = item.edit_url || item.editUrl || '';
        const title = editUrl
            ? '<a class="tcp-rule-name" href="' + escAttr(editUrl) + '" target="_blank" rel="noopener" title="باز کردن صفحهٔ ویرایش در تب جدید">' + esc(item.name) + '</a>'
            : '<span class="tcp-rule-name">' + esc(item.name) + '</span>';

        const hidden = (f, v) => '<input type="hidden" data-f="' + f + '" name="' + n + '[' + f + ']" value="' + escAttr(v) + '">';
        const html =
            '<td class="tcp-cell-identity"><div class="tcp-rule-identity">' + thumbHtml(type, item) +
                '<div class="tcp-rule-idmain"><div class="tcp-rule-title">' + title +
                    '<span class="tcp-badge tcp-status ' + (rule.enabled ? 'tcp-st-done' : 'tcp-st-cancelled') + '">' + (rule.enabled ? 'فعال' : 'غیرفعال') + '</span>' +
                    '<span class="tcp-badge tcp-exbadge tcp-cp-expired' + (rule.exclude ? '' : ' tcp-hidden') + '">استثنا</span>' +
                '</div><div class="tcp-rule-chips">' + chipsHtml(type, item) + '</div></div>' +
            '</div></td>' +
            '<td class="tcp-cell-rule"><div class="tcp-rule-sum">' + esc(sum.text) + '</div>' +
                '<div class="tcp-rule-sub' + (sum.sub ? '' : ' tcp-hidden') + '">' + esc(sum.sub) + '</div></td>' +
            '<td class="tcp-cell-flags">' +
                '<label class="tisa-switch tcp-toggle tcp-toggle--sm"><input type="checkbox" class="tcp-quick-enabled"' + (rule.enabled ? ' checked' : '') + '><span class="tisa-switch__track" aria-hidden="true"></span><span>فعال</span></label>' +
                '<label class="tisa-switch tcp-toggle tcp-toggle--sm"><input type="checkbox" class="tcp-quick-exclude"' + (rule.exclude ? ' checked' : '') + '><span class="tisa-switch__track" aria-hidden="true"></span><span>استثنا</span></label>' +
            '</td>' +
            '<td class="tcp-cell-actions">' +
                '<button type="button" class="tisa-btn tisa-btn--secondary tisa-btn--sm tcp-edit-rule">ویرایش</button>' +
                '<button type="button" class="tisa-btn tisa-btn--danger-ghost tisa-btn--sm tcp-remove-rule" aria-label="حذف قانون ' + escAttr(item.name) + '">حذف</button>' +
                '<input type="hidden" name="' + n + '[exists]" value="1">' +
                hidden('increase', rule.increase) + hidden('sale', rule.sale) + hidden('mode', rule.mode) +
                hidden('from', rule.from) + hidden('to', rule.to) +
                hidden('min', rule.min) + hidden('max', rule.max) +
                hidden('enabled', rule.enabled ? '1' : '0') + hidden('exclude', rule.exclude ? '1' : '0') +
            '</td>';

        const $tr = $('<tr>', {
            'class': 'tcp-rule-row' + (rule.exclude ? ' is-excluded' : '') + (rule.enabled ? '' : ' is-off'),
            'data-rule-id': id
        });
        $tr.attr('data-search', searchKey(type, item));
        $tr.html(html);
        $list.append($tr);
        flash($tr);
        refreshGroup(type);
        applyFilter(type);
        return 'added';
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

    /* ---------------- جستجوی چندانتخابی ---------------- */

    function inList(type, id) {
        return $(GROUPS[type].list).children(ROW_SEL + '[data-rule-id="' + id + '"]').length > 0;
    }

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

    function renderResults(type) {
        const g = GROUPS[type];
        const $box = $(g.results);
        const items = lastResults[type];
        const st = searchState[type];
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
            const added = inList(type, id);
            const checked = selection[type].has(id);

            const $label = $('<label class="tcp-search-check">').toggleClass('is-added', added);
            const $cb = $('<input type="checkbox">')
                .attr('data-id', id)
                .prop('checked', checked)
                .prop('disabled', added);
            $label.append($cb);
            $label.toggleClass('is-checked', checked && !added);
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
            if (added) {
                $label.append($('<span class="tcp-added-tag">').text('در لیست'));
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
        $foot.append($('<button type="button" class="tisa-btn tisa-btn--sm tisa-btn--primary" data-tcp-add>').text('افزودن'));
        $foot.append($('<button type="button" class="tisa-btn tisa-btn--sm tisa-btn--secondary" data-tcp-add-ex>').text('افزودن به‌عنوان استثنا'));
        // بقیهٔ نتایج: فهرست دیگر به ۳۰ مورد ختم نمی‌شود.
        if (st.pages > st.page) {
            const rest = Math.max(0, st.total - items.length);
            const next = st.perPage ? Math.min(st.perPage, rest) : rest;
            $foot.append($('<button type="button" class="tisa-btn tisa-btn--sm tisa-btn--secondary" data-tcp-more>')
                .text('نمایش ' + faNum(next) + ' مورد بعدی'));
        }
        $box.append($foot);

        updateFoot(type);
        $box.show();
    }

    function updateFoot(type) {
        const g = GROUPS[type];
        const $box = $(g.results);
        const n = selection[type].size;
        $box.find('.tcp-search-count').text(faNum(n) + ' انتخاب شده');
        $box.find('[data-tcp-add]').text(n ? 'افزودن ' + faNum(n) + ' مورد' : 'افزودن').prop('disabled', !n);
        $box.find('[data-tcp-add-ex]').prop('disabled', !n);
    }

    /**
     * جستجو در کل کاتالوگ؛ page>1 نتایج صفحهٔ بعدی را به فهرست اضافه می‌کند.
     */
    function doSearch(type, page) {
        const g = GROUPS[type];
        const $input = $(g.search);
        const $results = $(g.results);
        const want = Math.max(1, parseInt(page, 10) || 1);
        const term = want > 1 ? searchState[type].term : ($input.val() || '').trim();

        if (want > 1 && !term) return;

        if (want === 1) {
            searchState[type] = blankSearch();
            if (term.length < minChars) {
                searchGen[type]++;
                if (searchXhr[type]) { searchXhr[type].abort(); searchXhr[type] = null; }
                lastResults[type] = [];
                if (!term.length) {
                    $results.empty().hide();
                } else {
                    $results.html('<div class="tcp-search-empty">' + esc('حداقل ' + minChars + ' حرف بنویس…') + '</div>').show();
                }
                return;
            }
        }

        const requestId = ++searchGen[type];
        if (searchXhr[type]) { searchXhr[type].abort(); searchXhr[type] = null; }

        if (want === 1) {
            $results.html('<div class="tcp-search-loading">در حال جستجو...</div>').show();
        } else {
            $results.find('[data-tcp-more]').prop('disabled', true).text('در حال بارگذاری…');
        }

        searchXhr[type] = $.post(cfg.ajaxUrl, {
            action: g.action,
            nonce: cfg.nonce,
            term: term,
            page: want
        }).done(function (response) {
            if (requestId !== searchGen[type]) return;
            if (!response || !response.success) {
                $results.html('<div class="tcp-search-empty">خطا در جستجو.</div>').show();
                return;
            }
            const data = normalizeResults(response.data);
            searchState[type] = {
                term: term,
                page: data.page,
                pages: data.pages,
                total: data.total,
                perPage: data.perPage
            };
            lastResults[type] = want > 1 ? mergeItems(lastResults[type], data.items) : data.items;
            renderResults(type);
        }).fail(function (xhr, status) {
            // پاسخ درخواستی که با جستجوی تازه‌تر باطل شده نادیده گرفته می‌شود.
            if ('abort' === status || requestId !== searchGen[type]) return;
            $results.html('<div class="tcp-search-empty">ارتباط با سرور برقرار نشد.</div>').show();
        }).always(function () {
            if (requestId === searchGen[type]) { searchXhr[type] = null; }
        });
    }

    function bindSearch(type) {
        const g = GROUPS[type];
        const $input = $(g.search);

        $input.on('input', function () {
            clearTimeout(debounceTimers[type]);
            debounceTimers[type] = setTimeout(function () { doSearch(type, 1); }, 300);
        });

        $input.on('focus', function () {
            if (($input.val() || '').trim().length >= minChars) {
                if (lastResults[type].length) renderResults(type);
                else doSearch(type, 1);
            }
        });

        // اینتر داخل جستجو نباید فرم ذخیره را سابمیت کند.
        $input.on('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                clearTimeout(debounceTimers[type]);
                doSearch(type, 1);
            }
        });
    }

    /** افزودن همهٔ انتخاب‌شده‌ها به جدول. */
    function addSelected(type, asExclude) {
        let added = 0, dup = 0;
        selection[type].forEach(function (item) {
            const res = addRuleRow(type, item, defaultRule(asExclude));
            if (res === 'added') added++;
            else dup++;
        });
        if (added) {
            const $scroll = $(GROUPS[type].list).closest('.tcp-rule-table-scroll');
            $scroll.scrollTop($scroll.prop('scrollHeight'));
        }
        selection[type].clear();
        // نتایج را تازه کن تا «در لیست»ها به‌روز شوند.
        if ($(GROUPS[type].results).is(':visible') && lastResults[type].length) {
            renderResults(type);
        } else {
            $(GROUPS[type].results).empty().hide();
        }
        return { added: added, dup: dup };
    }

    /* ---------------- فیلتر داخل لیست ---------------- */

    function applyFilter(type) {
        const g = GROUPS[type];
        const q = ($(g.filter).val() || '').trim().toLowerCase();
        $(g.list).children(ROW_SEL).each(function () {
            const key = ($(this).attr('data-search') || '');
            $(this).toggle(!q || key.indexOf(q) !== -1);
        });
    }

    /* ---------------- مودال ویرایش ---------------- */

    // تنبل: اگر بهینه‌سازی اسکریپت را زودتر اجرا کند، رفرنس خالی کش نمی‌شود.
    function $modal() {
        return $('#tcp-rule-modal');
    }
    let modalTarget = null; // { type, id }

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
            exclude: $('#tcp-m-exclude').is(':checked')
        };
    }

    function updateModalHint() {
        const sum = summarize(modalRule());
        $('#tcp-modal-hint').text(sum.text + (sum.sub ? ' — ' + sum.sub : ''));
    }

    function openModal(type, id) {
        const $tr = $(GROUPS[type].list).children(ROW_SEL + '[data-rule-id="' + id + '"]');
        if (!$tr.length) return;
        const rule = readRule($tr);

        modalTarget = { type: type, id: id };
        $('#tcp-m-increase').val(rule.increase);
        $('#tcp-m-sale').val(rule.sale);
        $('#tcp-m-mode').val(rule.mode);
        $('#tcp-m-from').val(rule.from);
        $('#tcp-m-to').val(rule.to);
        $('#tcp-m-min').val(rule.min);
        $('#tcp-m-max').val(rule.max);
        $('#tcp-m-enabled').prop('checked', rule.enabled);
        $('#tcp-m-exclude').prop('checked', rule.exclude);

        const name = $tr.find('.tcp-rule-name').first().text();
        $('#tcp-modal-title').text('ویرایش قانون ' + GROUPS[type].modalKind);
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
        const $tr = $(GROUPS[modalTarget.type].list).children(ROW_SEL + '[data-rule-id="' + modalTarget.id + '"]');
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
        $tr.find('input[data-f="exclude"]').val(rule.exclude ? '1' : '0');

        refreshRow($tr);
        refreshGroup(modalTarget.type);
        closeModal();
        flash($tr);
    }

    /* ---------------- اتصال رویدادها ---------------- */

    function bindAll() {
        bindSearch('product');
        bindSearch('category');
        refreshAll();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bindAll);
    } else {
        bindAll();
    }

    function groupOf($el) {
        const $box = $el.closest('.tcp-search-results, tbody');
        if ($box.is(GROUPS.product.results) || $box.is(GROUPS.product.list)) return 'product';
        return 'category';
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
        $cbx.closest('.tcp-search-check').toggleClass('is-checked', $cbx.is(':checked'));
        const type = groupOf($cbx);
        const id = parseInt($cbx.attr('data-id'), 10);
        if (this.checked) {
            const found = lastResults[type].find(function (it) { return parseInt(it.id, 10) === id; });
            if (found) selection[type].set(id, found);
        } else {
            selection[type].delete(id);
        }
        updateFoot(type);
    });

    // دکمه‌های نوار نتایج.
    $(document).on('click', '[data-tcp-add], [data-tcp-add-ex]', function () {
        const type = groupOf($(this));
        addSelected(type, $(this).is('[data-tcp-add-ex]'));
    });

    $(document).on('click', '[data-tcp-all]', function () {
        const type = groupOf($(this));
        lastResults[type].forEach(function (item) {
            const id = parseInt(item.id, 10);
            if (!inList(type, id)) selection[type].set(id, item);
        });
        renderResults(type);
    });

    $(document).on('click', '[data-tcp-clear]', function () {
        const type = groupOf($(this));
        selection[type].clear();
        renderResults(type);
    });

    // صفحهٔ بعدی نتایج جستجو.
    $(document).on('click', '[data-tcp-more]', function () {
        const type = groupOf($(this));
        const st = searchState[type];
        if (st.pages > st.page) {
            doSearch(type, st.page + 1);
        }
    });

    // ویرایش / حذف سطر.
    $(document).on('click', '.tcp-edit-rule', function () {
        const $tr = $(this).closest(ROW_SEL);
        openModal(groupOf($(this)), parseInt($tr.attr('data-rule-id'), 10));
    });

    $(document).on('click', '.tcp-remove-rule', function () {
        const $btn = $(this);
        const type = groupOf($btn);
        $btn.closest(ROW_SEL).remove();
        refreshGroup(type);
    });

    // سوییچ‌های سریع روی سطر.
    $(document).on('change', '.tcp-quick-enabled, .tcp-quick-exclude', function () {
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
        const type = groupOf($(this));
        $tr.find('input[data-f="enabled"]').val($tr.find('.tcp-quick-enabled').is(':checked') ? '1' : '0');
        $tr.find('input[data-f="exclude"]').val($tr.find('.tcp-quick-exclude').is(':checked') ? '1' : '0');
        refreshRow($tr);
        refreshGroup(type);
        restoreScroll();
        if (window.requestAnimationFrame) {
            window.requestAnimationFrame(restoreScroll);
        } else {
            setTimeout(restoreScroll, 0);
        }
    });

    // فیلتر داخل لیست.
    $(document).on('input', '#tcp-product-filter', function () { applyFilter('product'); });
    $(document).on('input', '#tcp-category-filter', function () { applyFilter('category'); });
    $(document).on('keydown', '#tcp-product-filter, #tcp-category-filter', function (e) {
        if (e.key === 'Enter') e.preventDefault();
    });

    // مودال.
    $(document).on('click', '#tcp-modal-save', saveModal);
    $(document).on('click', '[data-tcp-close]', closeModal);
    $(document).on('input change', '#tcp-m-increase, #tcp-m-sale, #tcp-m-mode, #tcp-m-from, #tcp-m-to, #tcp-m-min, #tcp-m-max, #tcp-m-enabled, #tcp-m-exclude', updateModalHint);
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
