(function ($) {
    'use strict';

    function requestSummary() {
        $.post(WCSPB.ajax, { action: 'wcspb_summary', nonce: WCSPB.nonce, surface: 'bar' }).done(function (response) {
            if (!response || !response.success || !response.data) {
                window.setTimeout(requestSummary, 1500);
                return;
            }
            var data = response.data;
            var current = $('#wcspb-bar');
            if (current.length && data.html) current.replaceWith(data.html);
            if (data.error) window.setTimeout(requestSummary, 1500);
            else if (!data.done) window.setTimeout(requestSummary, 180);
        }).fail(function () {
            window.setTimeout(requestSummary, 1500);
        });
    }

    function copy(text) {
        if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
        var textarea = $('<textarea>').val(text).css({ position: 'fixed', top: '-1000px' }).appendTo('body');
        textarea[0].select();
        try { document.execCommand('copy'); } catch (error) { /* clipboard fallback is best effort */ }
        textarea.remove();
        return $.Deferred().resolve();
    }

    $(document).on('click', '.wcspb-chip', function (event) {
        event.preventDefault();
        var url = new URL(window.location.href);
        url.searchParams.set('post_type', 'product');
        url.searchParams.set('s', $(this).data('prefix'));
        url.searchParams.set('orderby', 'sku');
        url.searchParams.set('order', 'desc');
        url.searchParams.delete('paged');
        window.location.assign(url.toString());
    });

    $(document).on('click', '#wcspb-refresh', function (event) {
        event.preventDefault();
        var button = $('#wcspb-bar').addClass('loading');
        $.post(WCSPB.ajax, { action: 'wcspb_refresh', nonce: WCSPB.nonce }, function (response) {
            if (response && response.success && response.data.html) {
                button.replaceWith(response.data.html);
                if (response.data.building) requestSummary();
            } else {
                button.removeClass('loading');
            }
        }).fail(function () {
            button.removeClass('loading');
        });
    });

    $(function () {
        if ($('#wcspb-bar').attr('data-building') === '1') requestSummary();
    });
}(jQuery));
