/* پیش‌نمایش تعاملی پیشخوان — داده‌ها ساختگی‌اند تا چیدمان و رفتار admin.js دیده شود. */
(function () {
    'use strict';

    var scanRows = [
        { key: '_surena_barcode', orders: 27, tracking_like: 27, samples: ['799400522201761978143147'], sorna_value: 'surena-post', post_service: true, selected: false, builtin: false, suggested: true },
        { key: '_tracking_code', orders: 118, tracking_like: 118, samples: ['799400521200000012345678'], sorna_value: '', post_service: false, selected: true, builtin: true, suggested: false },
        { key: '_post_barcode', orders: 64, tracking_like: 64, samples: ['799400521200000098765432'], sorna_value: '', post_service: false, selected: false, builtin: true, suggested: false },
        { key: '_wc_shipment_tracking_items', orders: 22, tracking_like: 22, samples: ['{"tracking_number":"RR123456789IR"}'], sorna_value: '', post_service: false, selected: false, builtin: true, suggested: false },
        { key: '_billing_phone', orders: 300, tracking_like: 300, samples: ['09121234567'], sorna_value: '', post_service: false, selected: false, builtin: false, suggested: false },
        { key: '_payment_method_title', orders: 300, tracking_like: 0, samples: ['پرداخت آنلاین'], sorna_value: '', post_service: false, selected: false, builtin: false, suggested: false },
        { key: '_surena_status', orders: 31, tracking_like: 0, samples: ['confirmed'], sorna_value: 'surena-post', post_service: true, selected: false, builtin: false, suggested: false }
    ];

    var payloads = {
        bwt_scan_sources: {
            sampled: 300,
            limit: 300,
            with_code: 8,
            detected: 3,
            sorna_orders: 3,
            sorna_keys: [{ key: '_surena_barcode' }, { key: '_surena_status' }],
            storage: { hpos: 'hpos', label: 'جدول سفارش HPOS (wp_wc_orders)' },
            keys: scanRows,
            notes: { orders: 'کد پستی در یادداشت خصوصی سفارش هم دیده شد.', samples: ['کد رهگیری: 799400522201761978143147 — سورناپست'], enabled: true },
            tables: [
                { table: 'wp_surena_post_orders', columns: ['id', 'order_id', 'tracking_code', 'status', 'created_at'] },
                { table: 'wp_surena_post_barcodes', columns: ['id', 'order_id', 'barcode', 'pdf'] }
            ],
            source_keys: ['_tracking_code']
        },
        bwt_save_sources: null,
        bwt_find_key: {
            code: '799400522201761978143147',
            found: true,
            matches: [
                { key: '_surena_barcode', count: 27, tables: ['wp_wc_orders_meta'], tracking_like: true, suggested: true },
                { key: '_bwt_debug_raw', count: 2, tables: ['wp_postmeta'], tracking_like: false, suggested: false }
            ],
            tables: [{ table: 'wp_surena_post_orders', columns: ['id', 'order_id', 'tracking_code', 'status'] }],
            source_keys: ['_tracking_code']
        }
    };

    var batchCalls = 0;

    function reply(data) {
        return Promise.resolve({ ok: true, text: function () { return Promise.resolve(JSON.stringify({ success: true, data: data })); } });
    }

    window.BWTAdmin = {
        ajaxUrl: '/demo-ajax',
        cleanupNonce: 'demo',
        scanNonce: 'demo',
        syncNonce: 'demo',
        retryLabel: 'ادامهٔ پردازش',
        genericError: 'پیش‌نمایش: ارتباط با سرور شبیه‌سازی نشد.'
    };

    window.fetch = function (url, options) {
        var body = new URLSearchParams((options && options.body) || '');
        var action = body.get('action');

        if (action === 'bwt_save_sources') {
            return reply({ source_keys: body.getAll('keys[]'), message: 'منبع ذخیره شد.' });
        }
        if (action === 'bwt_sync_create') {
            batchCalls = 0;
            return reply({ job_id: 'demo', mode: body.get('mode'), done: false, progress: 0, total: 120, scanned: 0, created: 0, updated: 0, no_source: 0, disabled: 0, invalid: 0 });
        }
        if (action === 'bwt_sync_batch') {
            batchCalls++;
            var scanned = Math.min(120, batchCalls * 40);
            return reply({
                job_id: 'demo', mode: 'missing', done: scanned >= 120, progress: Math.round((scanned / 120) * 100),
                total: 120, scanned: scanned, created: Math.round(scanned * 0.7), updated: 2,
                no_source: scanned - Math.round(scanned * 0.7) - 2, disabled: 1, invalid: 0
            });
        }
        if (payloads[action]) {
            return reply(payloads[action]);
        }
        return Promise.reject(new Error('اکشن ناشناخته در پیش‌نمایش: ' + action));
    };

    var script = document.createElement('script');
    script.src = '../../../plugins/src/bulk-tracking-upload/assets/admin.js';
    document.body.appendChild(script);
}());
