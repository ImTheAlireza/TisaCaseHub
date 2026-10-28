/* پیش‌نمایش تعاملی پیشخوان — پاسخ‌های سرور ساختگی‌اند تا چیدمان و رفتار admin.js دیده شود. */
(function () {
    'use strict';

    var batchCalls = 0;

    function reply(data) {
        return Promise.resolve({
            ok: true,
            text: function () {
                return Promise.resolve(JSON.stringify({ success: true, data: data }));
            }
        });
    }

    window.BWTAdmin = {
        ajaxUrl: '/demo-ajax',
        cleanupNonce: 'demo',
        sourceNonce: 'demo',
        syncNonce: 'demo',
        retryLabel: 'ادامهٔ پردازش',
        genericError: 'پیش‌نمایش: ارتباط با سرور شبیه‌سازی نشد.'
    };

    window.fetch = function (url, options) {
        var body = new URLSearchParams((options && options.body) || '');
        var action = body.get('action');

        if (action === 'bwt_save_sources') {
            return reply({ source_keys: body.getAll('keys[]'), message: 'کلید ذخیره شد.' });
        }
        if (action === 'bwt_sync_create') {
            batchCalls = 0;
            return reply({
                job_id: 'demo', mode: body.get('mode'), done: false, progress: 0, total: 120,
                scanned: 0, created: 0, updated: 0, no_source: 0, disabled: 0, invalid: 0
            });
        }
        if (action === 'bwt_sync_batch') {
            batchCalls++;
            var scanned = Math.min(120, batchCalls * 40);
            return reply({
                job_id: 'demo', mode: 'missing', done: scanned >= 120,
                progress: Math.round((scanned / 120) * 100), total: 120, scanned: scanned,
                created: Math.round(scanned * 0.7), updated: 2,
                no_source: scanned - Math.round(scanned * 0.7) - 2, disabled: 1, invalid: 0
            });
        }
        return Promise.reject(new Error('اکشن ناشناخته در پیش‌نمایش: ' + action));
    };

    var script = document.createElement('script');
    script.src = '../../../plugins/src/bulk-tracking-upload/assets/admin.js';
    document.body.appendChild(script);
}());
