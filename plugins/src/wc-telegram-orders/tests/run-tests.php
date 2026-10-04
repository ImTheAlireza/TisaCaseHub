<?php
/**
 * تست منطق «شرط ارسال سفارش» (فقط سفارش‌های پرداخت‌شده) بدون وردپرس.
 * اجرا:  php tests/run-tests.php
 *
 * @package WC_Telegram_Orders
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

require_once __DIR__ . '/stubs.php';
require_once dirname(__DIR__) . '/wc-telegram-orders.php';

/* ---------- زیرکلاس تست: تلگرام و لاگ واقعی جایگزین می‌شوند ---------- */

class WCTO_Testable extends WC_Telegram_Orders {
    public $sent_messages = [];
    public $logs          = [];

    public $passthrough = false;

    public function send_to_all_chats($message, $chat_ids_raw = null) {
        $this->sent_messages[] = $message;
        if ($this->passthrough) {
            // مسیر واقعی ارسال (تلگرام تقلبی در stubs) — برای تست هشدار سلامت
            return parent::send_to_all_chats($message, $chat_ids_raw);
        }
        return ['ok' => true, 'sent' => ['-100123' => 1], 'message' => ''];
    }

    public function build_message($order) {
        return 'پیام سفارش #' . $order->get_order_number();
    }

    public function log($level, $channel, $event, $message, $context = [], $object_id = 0) {
        $this->logs[] = [
            'level'   => $level,
            'channel' => $channel,
            'event'   => $event,
            'message' => $message,
            'context' => $context,
            'id'      => $object_id,
        ];
        return true;
    }

    public function has_event($event) {
        foreach ($this->logs as $row) {
            if ($row['event'] === $event) {
                return true;
            }
        }
        return false;
    }

    /** فراخوانی متدهای private */
    public function call($name, $args = []) {
        $rm = new ReflectionMethod(get_class($this), $name);
        $rm->setAccessible(true);
        return $rm->invokeArgs($this, $args);
    }
}

/* ---------- ابزار تست ---------- */

$pass = 0;
$fail = 0;

function t($label, $cond, $extra = '') {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "  OK   " . $label . "\n";
    } else {
        $fail++;
        echo "  FAIL " . $label . ($extra !== '' ? '  >> ' . $extra : '') . "\n";
    }
}

/** محیط تازه: تنظیمات دلخواه + یک افزونهٔ تستی */
function setup($settings = [], $orders = []) {
    wcto_reset_env();
    $GLOBALS['wcto_http'] = [];
    update_option(WC_TELEGRAM_ORDERS_OPTION, array_merge([
        'enabled'    => 'yes',
        'bot_token'  => 'test:token',
        'chat_ids'   => '-100123',
    ], $settings));
    foreach ($orders as $order) {
        $GLOBALS['wcto_orders'][$order->get_id()] = $order;
    }
    $plugin = new WCTO_Testable();
    $plugin->reset_settings_cache();
    return $plugin;
}

function send_events() {
    return wcto_cron_hooks('wc_telegram_send_order');
}

function status_events() {
    return wcto_cron_hooks('wc_telegram_send_status');
}

/* ---------- ۱) پیش‌فرض‌ها و تنظیمات ---------- */

echo "--- 1) پیش‌فرض‌ها و ذخیرهٔ تنظیمات ---\n";

$p = setup();
$defaults = $p->defaults();
t('پیش‌فرض: شرط پرداخت روشن است', isset($defaults['paid_gate']) && $defaults['paid_gate'] === 'yes', var_export(isset($defaults['paid_gate']) ? $defaults['paid_gate'] : null, true));
t('پیش‌فرض: وضعیت مجاز = processing', isset($defaults['send_statuses']) && $defaults['send_statuses'] === 'processing', var_export(isset($defaults['send_statuses']) ? $defaults['send_statuses'] : null, true));
t('فهرست وضعیت‌های مجاز (پیش‌فرض)', $p->call('send_allowed_statuses') === ['processing'], implode(',', $p->call('send_allowed_statuses')));

$out = $p->sanitize_settings([
    '_tab'          => 'orders',
    'paid_gate'     => 'yes',
    'send_statuses' => ' Processing , completed , WC-ON-HOLD ',
]);
t('ذخیره: اسلاگ‌ها نرمال و یکتا می‌شوند', $out['send_statuses'] === 'processing,completed,on-hold', $out['send_statuses']);
t('ذخیره: شرط پرداخت روشن می‌ماند', $out['paid_gate'] === 'yes', $out['paid_gate']);

$out = $p->sanitize_settings(['_tab' => 'orders', 'paid_gate' => 'yes', 'send_statuses' => '  ']);
t('ذخیره: فیلد خالی → پیش‌فرض processing', $out['send_statuses'] === 'processing', $out['send_statuses']);

$out = $p->sanitize_settings(['_tab' => 'orders']);
t('ذخیره: تیک نخورده → شرط پرداخت خاموش', $out['paid_gate'] === 'no', $out['paid_gate']);

/* ---------- ۲) قضاوت وضعیت ---------- */

echo "--- 2) مجاز بودن ارسال بر اساس وضعیت ---\n";

$p = setup();
$cases = [
    'pending'        => false,
    'checkout-draft' => false,
    'on-hold'        => false,
    'cancelled'      => false,
    'failed'         => false,
    'refunded'       => false,
    'trash'          => false,
    'processing'     => true,
    'completed'      => false,
];
foreach ($cases as $status => $expected) {
    $order = new WC_Order(101, $status);
    $got   = $p->call('order_send_is_allowed', [$order]);
    t('وضعیت «' . $status . '» → ' . ($expected ? 'مجاز' : 'غیرمجاز'), $got === $expected, var_export($got, true));
}

$p2 = setup(['send_statuses' => 'processing,completed']);
t('با فهرست سفارشی، completed هم مجاز است', $p2->call('order_send_is_allowed', [new WC_Order(102, 'completed')]) === true);

$p3 = setup(['paid_gate' => 'no']);
t('شرط خاموش: سفارش pending با آدرس و اقلام (رفتار قبلی) مجاز است', $p3->call('order_send_is_allowed', [new WC_Order(103, 'pending')]) === true);
t('شرط خاموش: on-hold مجاز است (رفتار قبلی)', $p3->call('order_send_is_allowed', [new WC_Order(104, 'on-hold')]) === true);

t('وضعیت مرده: cancelled', $p->call('order_status_is_dead', ['cancelled']) === true);
t('وضعیت مرده: auto-cancelled', $p->call('order_status_is_dead', ['auto-cancelled']) === true);
t('وضعیت زنده: processing', $p->call('order_status_is_dead', ['processing']) === false);

/* ---------- ۳) ساخت سفارش جدید ---------- */

echo "--- 3) ساخت سفارش جدید (on_new_order) ---\n";

$order = new WC_Order(201, 'pending');
$p     = setup([], [$order]);
$p->on_new_order(201, null);
t('سفارش در انتظار پرداخت به تلگرام نمی‌رود', count($p->sent_messages) === 0 && count(send_events()) === 0, 'رویداد=' . count(send_events()));
t('پرچم انتظار ثبت می‌شود', $order->get_meta('_wc_telegram_pending') === 'yes');
t('یادداشت «هنوز پرداخت نشده» در سفارش', strpos(implode("\n", $order->wcto_notes()), 'پرداخت نشده') !== false, implode(' | ', $order->wcto_notes()));
t('رویداد لاگ order_pending_payment', $p->has_event('order_pending_payment'));

$order = new WC_Order(202, 'processing');
$p     = setup([], [$order]);
$p->on_new_order(202, null);
t('سفارش پرداخت‌شده زمان‌بندی ارسال می‌گیرد', count(send_events()) === 1, 'رویداد=' . count(send_events()));
t('پرچم انتظار ثبت نمی‌شود', $order->get_meta('_wc_telegram_pending') === '');

$order = new WC_Order(203, 'cancelled');
$p     = setup([], [$order]);
$p->on_new_order(203, null);
t('سفارش لغوشده هیچ پیام/زمان‌بندی نمی‌گیرد', count($p->sent_messages) === 0 && count(send_events()) === 0);
t('سفارش لغوشده پرچم انتظار نمی‌گیرد', $order->get_meta('_wc_telegram_pending') === '');
t('رویداد لاگ order_skipped_dead', $p->has_event('order_skipped_dead'));

/* ---------- ۴) تغییر وضعیت به «در حال انجام» ---------- */

echo "--- 4) تغییر وضعیت: پرداخت شد ---\n";

$order = new WC_Order(301, 'processing', ['meta' => ['_wc_telegram_pending' => 'yes']]);
$p     = setup([], [$order]);
$returned = $p->on_status_changed(301, 'pending', 'processing', $order);
t('با پرداخت، پیام سفارش زمان‌بندی می‌شود', count(send_events()) === 1, 'رویداد=' . count(send_events()));
t('پیام کوتاه تغییر وضعیت نمی‌رود (پیام کامل کافی است)', count(status_events()) === 0, 'رویداد=' . count(status_events()));

// همان مسیر وقتی «ارسال پیام تغییر وضعیت» خاموش است
$order = new WC_Order(302, 'processing', ['meta' => ['_wc_telegram_pending' => 'yes']]);
$p     = setup(['status_enabled' => 'no'], [$order]);
$p->on_status_changed(302, 'pending', 'processing', $order);
t('با پیام وضعیت خاموش هم سفارش پرداخت‌شده معرفی می‌شود', count(send_events()) === 1, 'رویداد=' . count(send_events()));

// لغو قبل از پرداخت
$order = new WC_Order(303, 'cancelled', ['meta' => ['_wc_telegram_pending' => 'yes']]);
$p     = setup([], [$order]);
$p->on_status_changed(303, 'pending', 'cancelled', $order);
t('لغو قبل از پرداخت: پیامی نمی‌رود', count($p->sent_messages) === 0 && count(send_events()) === 0 && count(status_events()) === 0);
t('لغو قبل از پرداخت: پرچم انتظار پاک می‌شود', $order->get_meta('_wc_telegram_pending') === '');

// on-hold (هنوز پرداخت نشده)
$order = new WC_Order(304, 'on-hold', ['meta' => ['_wc_telegram_pending' => 'yes']]);
$p     = setup([], [$order]);
$p->on_status_changed(304, 'pending', 'on-hold', $order);
t('on-hold قبل از پرداخت: پیامی نمی‌رود', count(send_events()) === 0, 'رویداد=' . count(send_events()));
t('on-hold: پرچم انتظار می‌ماند', $order->get_meta('_wc_telegram_pending') === 'yes');

/* ---------- ۵) اجرای کرون ارسال ---------- */

echo "--- 5) پردازشگر کرون (process_order_send) ---\n";

$order = new WC_Order(401, 'pending');
$p     = setup([], [$order]);
$p->process_order_send(401);
t('کرون، سفارش پرداخت‌نشده را ارسال نمی‌کند', count($p->sent_messages) === 0);
t('کرون، پرچم انتظار را نگه می‌دارد', $order->get_meta('_wc_telegram_pending') === 'yes');

$order = new WC_Order(402, 'cancelled', ['meta' => ['_wc_telegram_attempts' => 2]]);
$p     = setup([], [$order]);
$p->process_order_send(402);
t('کرون، سفارش لغوشده را ارسال نمی‌کند', count($p->sent_messages) === 0);

$order = new WC_Order(403, 'processing');
$p     = setup([], [$order]);
$p->process_order_send(403);
t('کرون، سفارش پرداخت‌شده را ارسال می‌کند', count($p->sent_messages) === 1, var_export($p->sent_messages, true));
t('پرچم ارسال ثبت می‌شود', $order->get_meta('_wc_telegram_sent') === 'yes');

$order = new WC_Order(404, 'pending', ['meta' => ['_wc_telegram_pending' => 'yes']]);
$p     = setup([], [$order]);
$p->process_order_send(404);
t('مسیر معوق: سفارش پرداخت‌نشده ارسال نمی‌شود', count($p->sent_messages) === 0);
t('مسیر معوق: پرچم انتظار می‌ماند', $order->get_meta('_wc_telegram_pending') === 'yes');

$order = new WC_Order(405, 'processing', ['meta' => ['_wc_telegram_pending' => 'yes']]);
$p     = setup([], [$order]);
$p->process_order_send(405);
t('مسیر معوق: با پرداخت شدن ارسال می‌شود', count($p->sent_messages) === 1);
t('مسیر معوق: پرچم‌ها پاک می‌شوند', $order->get_meta('_wc_telegram_pending') === '' && $order->get_meta('_wc_telegram_sent') === 'yes');

/* ---------- ۶) جاروی دوره‌ای ---------- */

echo "--- 6) جاروی سفارش‌های معوق ---\n";

$waiting   = new WC_Order(501, 'pending', ['meta' => ['_wc_telegram_pending' => 'yes']]);
$paid      = new WC_Order(502, 'processing', ['meta' => ['_wc_telegram_pending' => 'yes']]);
$cancelled = new WC_Order(503, 'cancelled', ['meta' => ['_wc_telegram_pending' => 'yes']]);
$p         = setup([], [$waiting, $paid, $cancelled]);
$p->sweep_pending_orders();
t('جارو: سفارش پرداخت‌شده زمان‌بندی می‌شود', count(send_events()) === 1, 'رویداد=' . count(send_events()));
t('جارو: سفارش در انتظار پرداخت رها نمی‌شود و ارسال هم نمی‌شود', $waiting->get_meta('_wc_telegram_pending') === 'yes');
t('جارو: سفارش لغوشده از فهرست معوق‌ها خارج می‌شود', $cancelled->get_meta('_wc_telegram_pending') === '');

/* ---------- ۷) رندر تب تنظیمات ---------- */

echo "--- 7) رندر تب «ارسال سفارشات جدید» ---\n";

$p = setup(['send_statuses' => 'processing,completed']);
$rm = new ReflectionMethod($p, 'render_orders_tab');
$rm->setAccessible(true);
ob_start();
$rm->invoke($p, $p->get_settings(), 'wc_telegram_orders_settings');
$html = ob_get_clean();
t('کلید «فقط سفارش‌های پرداخت‌شده ارسال شوند» در فرم است', strpos($html, '[paid_gate]') !== false);
t('فیلد «وضعیت‌های مجاز ارسال» در فرم است', strpos($html, '[send_statuses]') !== false);
t('مقدار ذخیره‌شده در فیلد نمایش داده می‌شود', strpos($html, 'value="processing,completed"') !== false, substr($html, (int) strpos($html, 'wc-tg-send-statuses'), 120));
t('راهنمای اسلاگ وضعیت‌های سایت چاپ می‌شود', strpos($html, 'pending, processing, on-hold') !== false, substr($html, (int) strpos($html, 'وضعیت‌های این سایت'), 160));
t('کارت «شرط ارسال سفارش» وجود دارد', strpos($html, 'شرط ارسال سفارش') !== false);

echo "--- 8) سفارش منقضی‌شده (بیش از ۲۴ ساعت معوق) ---\n";

$old = new WC_Order(601, 'pending', ['meta' => ['_wc_telegram_pending' => 'yes'], 'created' => time() - (2 * DAY_IN_SECONDS)]);
$p   = setup([], [$old]);
$p->sweep_pending_orders();
t('جارو: سفارش قدیمیِ پرداخت‌نشده منقضی می‌شود', $old->get_meta('_wc_telegram_expired') === 'yes' && $old->get_meta('_wc_telegram_pending') === '', var_export($old->wcto_meta(), true));
t('جارو: سفارش منقضی ارسال نمی‌شود', count(send_events()) === 0 && count($p->sent_messages) === 0);

$old->set_status('processing');
$p = setup([], [$old]);
$p->flush_pending_message(601, $old);
t('پرداخت دیرهنگامِ سفارش منقضی → زمان‌بندی ارسال', count(send_events()) === 1, 'رویداد=' . count(send_events()));
t('پرچم انتظار دوباره ثبت می‌شود', $old->get_meta('_wc_telegram_pending') === 'yes');
$p->process_order_send(601);
t('سفارش منقضیِ پرداخت‌شده ارسال می‌شود', count($p->sent_messages) === 1, var_export($p->sent_messages, true));
t('پرچم انقضا بعد از ارسال پاک می‌شود', $old->get_meta('_wc_telegram_expired') === '' && $old->get_meta('_wc_telegram_sent') === 'yes');

echo "--- 9) پاک‌سازی هنگام حذف افزونه ---\n";

preg_match_all("/'(_wc_telegram_[a-z_]+)'/", file_get_contents(dirname(__DIR__) . '/wc-telegram-orders.php'), $mm);
$used_keys = array_values(array_unique($mm[1]));
$uninstall = file_get_contents(dirname(__DIR__) . '/uninstall.php');
$missing_keys = [];
foreach ($used_keys as $key) {
    if (strpos($uninstall, $key) === false) {
        $missing_keys[] = $key;
    }
}
t('همهٔ کلیدهای متای افزونه در uninstall.php پاک می‌شوند (' . count($used_keys) . ' کلید)', $missing_keys === [], 'جامانده: ' . implode(', ', $missing_keys));

echo "--- 10) تاریخ شمسی داخلی ---\n";

$p = setup();
t('۱۴۰۵/۰۶/۲۶ برای 2026-09-17', $p->call('gregorian_to_jalali', [2026, 9, 17]) === [1405, 6, 26], var_export($p->call('gregorian_to_jalali', [2026, 9, 17]), true));
t('نوروز ۱۴۰۳ = 2024-03-20', $p->call('gregorian_to_jalali', [2024, 3, 20]) === [1403, 1, 1], var_export($p->call('gregorian_to_jalali', [2024, 3, 20]), true));
t('نوروز ۱۴۰۴ = 2025-03-21', $p->call('gregorian_to_jalali', [2025, 3, 21]) === [1404, 1, 1]);
t('آخرین روز سال کبیسهٔ ۱۳۹۹ = 2021-03-20', $p->call('gregorian_to_jalali', [2021, 3, 20]) === [1399, 12, 30], var_export($p->call('gregorian_to_jalali', [2021, 3, 20]), true));

$ts  = gmmktime(12, 0, 0, 9, 17, 2026);
$out = $p->format_date('Y/m/d', $ts);
t('فرمت شمسی با رقم فارسی', $out === '۱۴۰۵/۰۶/۲۶', $out);

$p2 = setup(['jalali_digits' => 'no']);
t('فرمت شمسی با رقم لاتین', $p2->format_date('Y/m/d', $ts) === '1405/06/26', $p2->format_date('Y/m/d', $ts));

$p3 = setup(['jalali_date' => 'no']);
t('خاموش بودن شمسی → مسیر میلادی قبلی', strpos($p3->format_date('Y-m-d', $ts), '2026') !== false, $p3->format_date('Y-m-d', $ts));

t('نام ماه شمسی (F)', $p->format_date('d F Y', $ts) === '۲۶ شهریور ۱۴۰۵', $p->format_date('d F Y', $ts));
// نکته: منطقهٔ زمانی از tzdata همان سرور می‌آید؛ پس انتظار را از همان منبعِ زمانِ پلاگین می‌سازیم
$t_hm  = gmmktime(10, 5, 0, 9, 17, 2026);
$greg_hm = $p->call('plugin_date', ['H:i', $t_hm]);
t('ساعت/دقیقه از همان منبع زمان میلادی می‌آید و فارسی می‌شود', $p->format_date('H:i', $t_hm) === $p->call('to_persian_digits', [$greg_hm]), $p->format_date('H:i', $t_hm) . ' vs ' . $greg_hm);
t('human_duration', $p->call('human_duration', [3725]) === '1 ساعت و 2 دقیقه', $p->call('human_duration', [3725]));
t('to_persian_digits', $p->call('to_persian_digits', ['12345']) === '۱۲۳۴۵');

echo "--- 11) اکشن گروهی در لیست سفارش‌ها ---\n";

$actions = setup()->add_bulk_actions([]);
t('دو اکشن گروهی ثبت می‌شود', isset($actions['wc_telegram_bulk_send'], $actions['wc_telegram_bulk_force']));

// سه سفارش: یکی مجاز، دو تا غیرمجاز
$o1 = new WC_Order(701, 'processing', ['meta' => ['_wc_telegram_sent' => 'yes']]);
$o2 = new WC_Order(702, 'pending');
$o3 = new WC_Order(703, 'cancelled');
$p  = setup([], [$o1, $o2, $o3]);
$url = $p->handle_bulk_send('http://example.test/list', 'wc_telegram_bulk_send', [701, 702, 703]);
t('فقط سفارش مجاز در صف می‌رود', count(send_events()) === 1, 'رویداد=' . count(send_events()));
t('پرچم ارسال قبلی پاک می‌شود تا دوباره برود', $o1->get_meta('_wc_telegram_sent') === '');
t('پیوند بازگشت با کد نتیجه', strpos($url, 'wc_telegram_bulk=ok') !== false, $url);
$res = get_transient('wc_telegram_bulk_result');
t('آمار نتیجه: ۱ در صف، ۲ رد', is_array($res) && $res['queued'] === 1 && $res['status'] === 2, var_export($res, true));

// محافظ ضد سیل: اجرای دوم در بازهٔ قفل قبول نمی‌شود
$url2 = $p->handle_bulk_send('http://example.test/list', 'wc_telegram_bulk_send', [701]);
t('اجرای دوم پشت‌سرهم رد می‌شود (قفل ضد سیل)', strpos($url2, 'wc_telegram_bulk=locked') !== false, $url2);
t('قفل، رویداد تازه نمی‌سازد', count(send_events()) === 1, 'رویداد=' . count(send_events()));
delete_transient('wc_telegram_bulk_lock');

// حالت اجباری: شرط پرداخت نادیده گرفته می‌شود و پرچم یک‌بارمصرف می‌خورد
$o4 = new WC_Order(704, 'pending', ['meta' => ['_wc_telegram_pending' => 'yes']]);
$p  = setup([], [$o4]);
$p->handle_bulk_send('http://example.test/list', 'wc_telegram_bulk_force', [704]);
t('حالت اجباری: سفارش پرداخت‌نشده هم در صف می‌رود', count(send_events()) === 1, 'رویداد=' . count(send_events()));
t('پرچم ارسال اجباری ثبت می‌شود', $o4->get_meta('_wc_telegram_force') === 'yes');
t('پرچم انتظار پاک می‌شود', $o4->get_meta('_wc_telegram_pending') === '');
$p->process_order_send(704);
t('پردازشگر کرون با پرچم اجباری، سفارش را می‌فرستد', count($p->sent_messages) === 1, var_export($p->sent_messages, true));
t('پرچم اجباری یک‌بارمصرف است', $o4->get_meta('_wc_telegram_force') === '');

// سقف تعداد + فاصلهٔ پلکانی
$ids = [];
$many = [];
for ($i = 1; $i <= 5; $i++) {
    $oid = 710 + $i;
    $ids[] = $oid;
    $many[$oid] = new WC_Order($oid, 'processing');
}
$p = setup(['bulk_max' => 3, 'bulk_gap' => 5], $many);
$p->handle_bulk_send('http://example.test/list', 'wc_telegram_bulk_send', $ids);
$res = get_transient('wc_telegram_bulk_result');
t('سقف تعداد اعمال می‌شود (۳ از ۵)', is_array($res) && $res['queued'] === 3 && $res['cut'] === 2, var_export($res, true));
$ev = send_events();
t('رویدادها پلکانی زمان‌بندی می‌شوند (فاصلهٔ ۵ ثانیه)', count($ev) === 3 && ($ev[2]['ts'] - $ev[0]['ts']) === 10, 'ts=' . implode(',', array_column($ev, 'ts')));

// اکشن نامرتبط دست‌نخورده برمی‌گردد
t('اکشن بی‌ربط نادیده گرفته می‌شود', $p->handle_bulk_send('http://x', 'delete', [711]) === 'http://x');

echo "--- 12) هشدار سلامت ربات ---\n";

$o = new WC_Order(801, 'processing');
$p = setup(['health_threshold' => 3, 'health_cooldown' => 6], [$o]);
$p->passthrough = true;
$GLOBALS['wcto_http_fail'] = true;
for ($i = 1; $i <= 3; $i++) {
    $p->send_to_all_chats('پیام تست ' . $i);
}
t('شمارش شکست‌های پشت‌سرهم', $p->health_streak() === 3, 'streak=' . $p->health_streak());
t('هشدار سلامت ثبت شد', get_option('wc_telegram_health_alert') > 0);
t('رویداد لاگ health_alert', $p->has_event('health_alert'));
t('هشدار در بازهٔ توقف تکرار نمی‌شود', (function () use ($p) {
    $before = $p->logs;
    $p->send_to_all_chats('پیام بعدی');
    foreach (array_slice($p->logs, count($before)) as $row) {
        if ($row['event'] === 'health_alert') {
            return false;
        }
    }
    return true;
})());
$GLOBALS['wcto_http_fail'] = false;
delete_transient('wc_telegram_429_until');
$p->send_to_all_chats('پیام موفق');
t('با ارسال موفق، شمارنده صفر می‌شود', $p->health_streak() === 0, 'streak=' . $p->health_streak());

echo "--- 13) داشبورد آمار ---\n";

$now = time();
$orders = [
    901 => new WC_Order(901, 'processing', ['total' => 500000, 'phone' => '09121111111', 'payment' => 'زرین‌پال', 'created' => $now - 86400, 'paid_at' => $now - 86000, 'line_items' => [new WC_Order_Item('کیس A', 2, 300000, 11), new WC_Order_Item('قاب B', 1, 50000, 12)]]),
    902 => new WC_Order(902, 'completed', ['total' => 250000, 'phone' => '09122222222', 'payment' => 'کارت به کارت', 'created' => $now - 43200, 'paid_at' => $now - 43000, 'line_items' => [new WC_Order_Item('کیس A', 1, 150000, 11)]]),
    903 => new WC_Order(903, 'pending', ['total' => 999000, 'phone' => '09123333333', 'created' => $now - 100, 'paid_at' => null, 'line_items' => [new WC_Order_Item('کیس C', 9, 999000, 13)]]),
];
$p = setup([], $orders);
$d = $p->collect_stats(30, true);
t('فقط سفارش‌های پرداخت‌شده شمرده می‌شوند', $d['count'] === 2, 'count=' . $d['count']);
t('جمع فروش درست است', abs($d['revenue'] - 750000) < 1, 'revenue=' . $d['revenue']);
t('پرفروش‌ترین محصول: کیس A', (function () use ($d) {
    $top = array_keys($d['products']);
    return !empty($top) && $top[0] === 'کیس A';
})(), implode(',', array_keys($d['products'])));
t('تعداد آیتم پرفروش‌ترین محصول = ۳', $d['products']['کیس A']['qty'] === 3, var_export(isset($d['products']['کیس A']) ? $d['products']['کیس A'] : null, true));
t('محصولِ سفارش پرداخت‌نشده شمرده نمی‌شود', !isset($d['products']['کیس C']));
t('بزرگ‌ترین مشتری = ۰۹۱۲۱۱۱۱۱۱۱', (function () use ($d) {
    $keys = array_keys($d['customers']);
    return !empty($keys) && $keys[0] === '09121111111';
})(), implode(',', array_keys($d['customers'])));
t('مجموع بزرگ‌ترین مشتری = ۵۰۰٬۰۰۰', abs($d['customers']['09121111111']['sum'] - 500000) < 1);
t('میانگین فاصلهٔ بین سفارش‌های پرداخت‌شده محاسبه شد', $d['avg_gap'] > 30000 && $d['avg_gap'] < 60000, 'avg=' . $d['avg_gap']);
t('روش‌های پرداخت تفکیک شده‌اند', isset($d['payments']['زرین‌پال'], $d['payments']['کارت به کارت']));
t('ساعت‌های سفارش ثبت شده', count($d['hours']) >= 1);
$cached = $p->collect_stats(30, false);
t('نتیجه کش می‌شود (generated یکسان)', $cached['generated'] === $d['generated']);
$top = $p->call('stats_top', [['a' => ['sum' => 1], 'b' => ['sum' => 9], 'c' => ['sum' => 5]], 'sum', 2]);
t('stats_top نزولی و محدود می‌کند', array_keys($top) === ['b', 'c'], implode(',', array_keys($top)));
$top_h = $p->call('stats_top', [[10 => 2, 14 => 7, 22 => 4], 0, 2]);
t('stats_top روی فهرست ساده (ساعت => تعداد)', array_keys($top_h) === [14, 22], implode(',', array_keys($top_h)));

// صحت محاسبات: جمع هر تفکیک باید با KPI اصلی بخواند
t('جمع روش‌های پرداخت == کل درآمد', abs(array_sum(array_column($d['payments'], 'sum')) - $d['revenue']) < 1,
    'pay=' . array_sum(array_column($d['payments'], 'sum')) . ' rev=' . $d['revenue']);
t('تعداد روش‌های پرداخت == تعداد سفارش‌ها', array_sum(array_column($d['payments'], 'count')) === $d['count']);
t('جمع مشتریان == کل درآمد', abs(array_sum(array_column($d['customers'], 'sum')) - $d['revenue']) < 1,
    'cust=' . array_sum(array_column($d['customers'], 'sum')));
t('جمع محصول‌ها == جمع آیتم‌های پرداخت‌شده', abs(array_sum(array_column($d['products'], 'sum')) - 500000) < 1,
    'prod=' . array_sum(array_column($d['products'], 'sum')));
t('جمع ساعات == تعداد سفارش‌ها', array_sum($d['hours']) === $d['count'], 'hours=' . array_sum($d['hours']));
t('جمع آیتم‌ها از کل درآمد بیشتر نیست (مابه‌التفاوت = ارسال/مالیات)', array_sum(array_column($d['products'], 'sum')) <= $d['revenue']);

// رندر تب آمار بدون خطا
$_GET['tab'] = 'stats';
$GLOBALS['wcto_log_count'] = 4;
ob_start();
$p->call('render_stats_tab', [$p->get_settings(), 'wc_telegram_orders_settings']);
$html = ob_get_clean();
unset($_GET['tab']);
t('تب آمار رندر می‌شود', strpos($html, 'پرفروش‌ترین محصولات') !== false && strpos($html, 'بزرگ‌ترین مشتریان') !== false);
t('KPI میانگین فاصله در خروجی هست', strpos($html, 'میانگین فاصلهٔ بین دو سفارش پرداخت‌شده') !== false);
t('KPI سلامت ربات در خروجی هست', strpos($html, 'شکست‌های پشت‌سرهم ربات') !== false);
t('نام محصول در جدول چاپ می‌شود', strpos($html, 'کیس A') !== false);
t('فقط دکمهٔ دورهٔ فعال کلاس primary می‌گیرد', substr_count($html, 'tisa-btn tisa-btn--primary') === 1 && strpos($html, '>۳۰ روز</a>') !== false);
t('KPI میانگین سبد و جمع فروش درست حساب شده (750,000 / 2 = 375,000)', strpos($html, '375,000') !== false && strpos($html, '750,000') !== false);
t('سهم درصدی و نشان رتبه در جدول‌ها هست', strpos($html, '٪') !== false && strpos($html, 'rank rank--1') !== false);
t('نمودار ساعات با نوار درصدی رندر می‌شود', strpos($html, 'class="wcto-bar"') !== false && strpos($html, '▇') === false);
t('سهم فروش روش پرداخت نمایش داده می‌شود (۶۷٪)', strpos($html, '۶۷٪') !== false);

echo "--- 14) فالبک کرون (DISABLE_WP_CRON) ---\n";

if (!defined('DISABLE_WP_CRON')) {
    define('DISABLE_WP_CRON', true);
}
$p = setup([], [new WC_Order(1001, 'processing')]);
delete_transient('wc_telegram_cron_spawned');
$GLOBALS['wcto_http'] = [];
$p->process_order_send(1001);   // اول ارسال انجام شود
delete_transient('wc_telegram_cron_spawned');
$GLOBALS['wcto_http'] = [];
$o = new WC_Order(1002, 'processing');
$p = setup([], [$o]);
$p->on_new_order(1002, null);
$pinged = false;
foreach ($GLOBALS['wcto_http'] as $call) {
    if (strpos($call['url'], 'wp-cron.php') !== false) {
        $pinged = true;
        t('پینگ کرون غیرهمزمان است (blocking=false)', isset($call['args']['blocking']) && $call['args']['blocking'] === false);
        t('زمان انتظار پینگ کوتاه است (timeout≤0.01)', isset($call['args']['timeout']) && $call['args']['timeout'] <= 0.01);
    }
}
t('وقتی کرون وردپرس خاموش است، wp-cron.php پینگ می‌شود', $pinged, var_export(array_column($GLOBALS['wcto_http'], 'url'), true));
t('رویداد کرون هم زمان‌بندی شده', count(send_events()) === 1);

echo "--- 15) رندر کامل صفحهٔ تنظیمات ---\n";

$p = setup();
foreach (['orders', 'products', 'stats'] as $tb) {
    $_GET['tab'] = $tb;
    ob_start();
    $p->render_settings_page();
    $page = ob_get_clean();
    t('تب «' . $tb . '» بدون خطا رندر می‌شود و در ناوبری فعال است', strpos($page, 'wcto-tab is-active') !== false && strlen($page) > 500, 'len=' . strlen($page));
}
unset($_GET['tab']);

echo "--- 16) جلوگیری از ارسال تکراری گزارش روزانه ---\n";

$p = setup(['daily_pin' => 'no']);
$auto_first = $p->send_daily_report();
$auto_second = $p->send_daily_report();
t('گزارش خودکارِ نخست ارسال می‌شود', !empty($auto_first['ok']) && count($p->sent_messages) === 1);
t('اجرای خودکار تکراری پس از موفقیت بی‌اثر است', !empty($auto_second['duplicate']) && count($p->sent_messages) === 1);
t('قفل گزارش پس از پایان آزاد می‌شود', get_option(WC_Telegram_Orders::DAILY_REPORT_LOCK_OPTION, false) === false);

$p = setup(['daily_pin' => 'no']);
$held_token = $p->call('acquire_daily_report_lock');
$blocked_report = $p->send_daily_report();
t('اجرای هم‌زمان هنگام در دسترس نبودن قفل پیام نمی‌فرستد', !empty($blocked_report['duplicate']) && empty($p->sent_messages));
$p->call('release_daily_report_lock', [$held_token]);

$p = setup(['daily_pin' => 'no']);
update_option(WC_Telegram_Orders::DAILY_REPORT_LOCK_OPTION, ['token' => 'stale-lock', 'expires' => time() - 1]);
$recovered_token = $p->call('acquire_daily_report_lock');
t('قفل مانده از توقف قبلی پس از انقضا قابل‌بازیابی است', is_string($recovered_token) && $recovered_token !== '');
$p->call('release_daily_report_lock', [$recovered_token]);

$p = setup(['daily_pin' => 'no']);
$manual_start = time() - DAY_IN_SECONDS;
$manual_end   = time();
$p->send_daily_report($manual_start, $manual_end, true);
$p->send_daily_report($manual_start, $manual_end, true);
t('گزارش دستی مستقل است و با اجرای دستیِ قبلی مسدود نمی‌شود', count($p->sent_messages) === 2);

$p = setup();
wp_schedule_event(time() + 100, 'daily', WC_Telegram_Orders::CRON_HOOK);
wp_schedule_event(time() + 200, 'daily', WC_Telegram_Orders::CRON_HOOK);
wp_schedule_event(time() + 300, 'daily', WC_Telegram_Orders::CRON_HOOK, ['legacy']);
$p->maybe_schedule_daily();
$daily_events = wcto_cron_hooks(WC_Telegram_Orders::CRON_HOOK);
t('زمان‌بندی‌های قدیمی و تکراری به یک کرون روزانهٔ بی‌آرگومان پاک‌سازی می‌شوند', count($daily_events) === 1 && $daily_events[0]['args'] === [] && $daily_events[0]['schedule'] === 'daily', var_export($daily_events, true));

/* ---------- نتیجه ---------- */

echo "\nنتیجه: {$pass} موفق، {$fail} ناموفق\n";
exit($fail === 0 ? 0 : 1);
