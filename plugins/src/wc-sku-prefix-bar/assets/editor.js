(function ($) {
    'use strict';

    var sku;
    var status;
    var timer = null;
    var last = '';

    function setState(state, html) {
        if (!status || !status.length) return;
        status.removeClass('is-ok is-dupe is-busy').addClass(state ? 'is-' + state : '').html(html || '');
    }

    function checkSku() {
        var value = $.trim(sku.val() || '');
        if (!value) {
            setState('', '');
            last = '';
            return;
        }
        if (value === last) return;
        last = value;
        setState('busy', WCSPB_ED.i18n.checking);
        $.post(WCSPB_ED.ajax, {
            action: 'wcspb_check',
            nonce: WCSPB_ED.nonce,
            sku: value,
            post_id: WCSPB_ED.postId
        }, function (response) {
            if (!response || !response.success) {
                setState('', '');
                return;
            }
            if (response.data.dupe) {
                var link = response.data.link
                    ? ' <a href="' + response.data.link + '" target="_blank" rel="noopener">(' + WCSPB_ED.i18n.edit + ')</a>'
                    : '';
                setState('dupe', '⚠ ' + WCSPB_ED.i18n.dupe + ' <strong>' + $('<div>').text(response.data.title).html() + '</strong>' + link);
            } else {
                setState('ok', '✓ ' + WCSPB_ED.i18n.free);
            }
        }).fail(function () {
            setState('', '');
        });
    }

    function requestNext(prefix, fallback, attempt) {
        $.post(WCSPB_ED.ajax, {
            action: 'wcspb_next',
            nonce: WCSPB_ED.nonce,
            prefix: prefix
        }).done(function (response) {
            if (response && response.success && response.data && response.data.pending) {
                setState('busy', WCSPB_ED.i18n.building || WCSPB_ED.i18n.checking);
                window.setTimeout(function () { requestNext(prefix, fallback, attempt + 1); }, 180);
                return;
            }
            var next = response && response.success && response.data && response.data.next
                ? response.data.next
                : fallback;
            sku.val(next).trigger('change').focus();
            last = '';
            checkSku();
        }).fail(function () {
            if (attempt < 12) {
                window.setTimeout(function () { requestNext(prefix, fallback, attempt + 1); }, 250);
                return;
            }
            setState('', WCSPB_ED.i18n.buildFailed || 'ساخت فهرست SKU ناموفق بود؛ دوباره تلاش کنید.');
        });
    }

    function insertSku() {
        var value = $('#wcspb-gen').val();
        if (!value) return;
        var prefix = (value.match(/^[^0-9]*/) || [''])[0];
        setState('busy', WCSPB_ED.i18n.checking);
        requestNext(prefix, value, 0);
    }

    function refreshSeries() {
        $.post(WCSPB_ED.ajax, { action: 'wcspb_summary', nonce: WCSPB_ED.nonce, surface: 'editor' }).done(function (response) {
            if (!response || !response.success || !response.data) {
                window.setTimeout(refreshSeries, 1200);
                return;
            }
            var data = response.data;
            if (data.error) {
                setState('', WCSPB_ED.i18n.buildFailed || 'ساخت فهرست SKU ناموفق بود؛ دوباره تلاش کنید.');
                window.setTimeout(refreshSeries, 1500);
                return;
            }
            if (!data.done) {
                setState('busy', (WCSPB_ED.i18n.building || WCSPB_ED.i18n.checking) + ' ' + data.processed);
                window.setTimeout(refreshSeries, 180);
                return;
            }
            var select = $('#wcspb-gen');
            if (!select.length) return;
            var oldValue = select.val() || '';
            var oldPrefix = (oldValue.match(/^[^0-9]*/) || [''])[0];
            select.empty().append($('<option>').val('').text(WCSPB_ED.i18n.chooseSeries || '— انتخاب سری —'));
            (data.items || []).forEach(function (item) {
                select.append($('<option>').val(item.next).text(item.label + '  →  ' + item.next));
            });
            var preserved = (data.items || []).filter(function (item) { return item.prefix === oldPrefix; })[0];
            if (preserved) select.val(preserved.next);
            if (data.truncated) {
                setState('', WCSPB_ED.i18n.truncated || 'فهرست برای محدودماندن حافظه به ۱۰۰۰ سری محدود شده است.');
            }
        }).fail(function () {
            window.setTimeout(refreshSeries, 1200);
        });
    }

    $(function () {
        sku = $('#_sku');
        if (!sku.length) return;
        status = $('#wcspb-gen-status');
        if (!status.length) {
            status = $('<span class="wcspb-gen-status" id="wcspb-gen-status"></span>');
            sku.closest('.form-field').append(status);
        }
        sku.on('input keyup change', function () {
            window.clearTimeout(timer);
            timer = window.setTimeout(checkSku, 450);
        });
        $(document).on('click', '#wcspb-gen-btn', insertSku).on('dblclick', '#wcspb-gen', insertSku);
        if ($.trim(sku.val() || '')) checkSku();
        if ($('#wcspb-gen').length) refreshSeries();
    });
}(jQuery));
