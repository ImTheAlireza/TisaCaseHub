<?php
/**
 * تست فرم پیگیری و پردازش دسته‌ای CSV (Job، checkpoint و AJAX) بدون وردپرس.
 * اجرا:  php tests/run-tests.php
 *
 * @package Bulk_Tracking_Upload
 */

error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

/* ---------- استاب‌های وردپرس/ووکامرس ---------- */

define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);

class BWT_Test_Response extends Exception {
    public $success;
    public $payload;
    public $status;
    public function __construct($success, $payload, $status = 200) { $this->success = $success; $this->payload = $payload; $this->status = $status; }
}
function current_user_can($cap) { return true; }
$GLOBALS['bwt_test_user_id'] = 7;
function get_current_user_id() { return $GLOBALS['bwt_test_user_id']; }
function get_option($key) { return $key === 'siteurl' ? 'https://store.example.test' : false; }
function get_temp_dir() { return sys_get_temp_dir(); }
function wp_generate_uuid4() { return '12345678-1234-4abc-8def-1234567890ab'; }
function wp_next_scheduled($hook) { return false; }
function wp_schedule_event($timestamp, $recurrence, $hook) { return true; }
function check_ajax_referer($action, $query_arg = '_ajax_nonce') {
    if (!in_array($action, array('bwt_upload_batch', 'bwt_cleanup_batch'), true) || !isset($_POST[$query_arg]) || $_POST[$query_arg] !== 'test-nonce') {
        throw new RuntimeException('Invalid test nonce.');
    }
    return 1;
}
function wp_send_json_success($data = null, $status_code = 200) { throw new BWT_Test_Response(true, $data, $status_code); }
function wp_send_json_error($data = null, $status_code = 400) { throw new BWT_Test_Response(false, $data, $status_code); }

function add_action(...$a) {}
function add_shortcode(...$a) {}
function add_filter(...$a) {}
function sanitize_text_field($s) { return is_string($s) ? trim(strip_tags($s)) : ''; }
function wp_unslash($s) { return is_string($s) ? stripslashes($s) : $s; }
function esc_html($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_attr($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function esc_url($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
function wp_kses_post($s) { return $s; }
function __($s, $d = null) { return $s; }

$GLOBALS['bwt_transients'] = [];
function get_transient($k) { return isset($GLOBALS['bwt_transients'][$k]) ? $GLOBALS['bwt_transients'][$k] : false; }
function set_transient($k, $v, $ttl = 0) { $GLOBALS['bwt_transients'][$k] = $v; return true; }
function delete_transient($k) { unset($GLOBALS['bwt_transients'][$k]); return true; }

class BWT_Fake_Order {
    private $d;
    public function __construct($d) { $this->d = $d; }
    public function get_billing_phone() { return $this->d['phone']; }
    public function get_billing_first_name() { return $this->d['first']; }
    public function get_billing_last_name() { return $this->d['last']; }
    public function get_billing_address_1() { return $this->d['addr1']; }
    public function get_billing_address_2() { return $this->d['addr2']; }
    public function get_billing_city() { return $this->d['city']; }
    public function get_billing_state() { return $this->d['state']; }
    public function get_billing_postcode() { return $this->d['postcode']; }
    public function get_meta($k) { return isset($this->d['meta'][$k]) ? $this->d['meta'][$k] : ''; }
    public function get_type() { return 'shop_order'; }
    public function update_meta_data($k, $value) { $this->d['meta'][$k] = $value; }
    public function delete_meta_data($k) { unset($this->d['meta'][$k]); }
    public function save() { $GLOBALS['bwt_order_saves']++; return true; }
    public function get_date_created() { return null; }
}

$GLOBALS['bwt_orders'] = [];
function wc_get_order($id) { return isset($GLOBALS['bwt_orders'][$id]) ? $GLOBALS['bwt_orders'][$id] : false; }
$GLOBALS['bwt_post_meta'] = [];
$GLOBALS['bwt_order_saves'] = 0;
function get_post_meta($id, $k, $single = false) { return isset($GLOBALS['bwt_post_meta'][$id][$k]) ? $GLOBALS['bwt_post_meta'][$id][$k] : ''; }
function update_post_meta($id, $k, $value) { $GLOBALS['bwt_post_meta'][$id][$k] = $value; return true; }
function delete_post_meta($id, $k) { unset($GLOBALS['bwt_post_meta'][$id][$k]); return true; }

class BWT_Test_DB {
    public $postmeta = 'wp_postmeta';
    public $prefix = 'wp_';
    public $last_sql = '';
    public $query_result = 500;
    public function prepare($sql, ...$args) {
        foreach ($args as $arg) {
            $replacement = is_int($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
            $sql = preg_replace('/%[sd]/', $replacement, $sql, 1);
        }
        return $sql;
    }
    public function query($sql) { $this->last_sql = $sql; return $this->query_result; }
    public function get_var($sql) { return 'wp_wc_orders_meta'; }
}
$GLOBALS['wpdb'] = new BWT_Test_DB();

require_once dirname(__DIR__) . '/bulk-tracking-upload.php';

/* ---------- هارنس ---------- */

$pass = 0; $fail = 0;
function t($name, $ok, $info = '') {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  OK   $name\n"; }
    else { $fail++; echo "  FAIL $name" . ($info !== '' ? "  >> $info" : '') . "\n"; }
}

$_SERVER['REMOTE_ADDR'] = '203.0.113.7';

$GLOBALS['bwt_orders'][101] = new BWT_Fake_Order([
    'phone' => '09123456789',
    'first' => 'علی', 'last' => 'رضایی',
    'addr1' => 'خ ولیعصر', 'addr2' => '', 'city' => 'تهران', 'state' => 'تهران', 'postcode' => '1234567890',
    'meta' => ['_tracking_code' => 'TRACK123456789'],
]);
$GLOBALS['bwt_orders'][102] = new BWT_Fake_Order([
    'phone' => '+98 935-111-2233',
    'first' => 'سارا', 'last' => 'محمدی',
    'addr1' => 'خ آزادی', 'addr2' => '', 'city' => 'شیراز', 'state' => 'فارس', 'postcode' => '',
    'meta' => [], // هنوز کد رهگیری ندارد
]);

function submit($order_id, $phone) {
    $_POST = ['tracking_submit' => '1', 'order_id' => $order_id, 'billing_phone' => $phone];
    $html = bwt_tracking_search_form();
    $_POST = [];
    return $html;
}

echo "--- 1) نرمال‌سازی شماره موبایل ---\n";
t('شمارهٔ صفر-پیشرو', bwt_normalize_phone('09123456789') === '989123456789', bwt_normalize_phone('09123456789'));
t('رقم‌های فارسی + جداکننده', bwt_normalize_phone('۰۹۱۲ ۳۴۵ ۶۷۸۹') === '989123456789', bwt_normalize_phone('۰۹۱۲ ۳۴۵ ۶۷۸۹'));
t('پیشوند +98', bwt_normalize_phone('+989123456789') === '989123456789');
t('پیشوند 0098', bwt_normalize_phone('00989123456789') === '989123456789');
t('بدون پیشوند (۱۰ رقم)', bwt_normalize_phone('9123456789') === '989123456789');
t('شمارهٔ نامعتبر → رشتهٔ خالی', bwt_normalize_phone('12345') === '' && bwt_normalize_phone('') === '');
t('تطبیق قالب‌های متفاوت یک شماره', bwt_phones_match('09123456789', '+98 912-345-6789') === true);
t('شمارهٔ متفاوت → عدم تطبیق', bwt_phones_match('09123456789', '09120000000') === false);
t('شمارهٔ ثبت‌شدهٔ خالی → عدم تطبیق (نه خطای PHP)', bwt_phones_match('', '09123456789') === false);

echo "--- 2) احراز هویت فرم پیگیری ---\n";
$html = submit('101', '۰۹۱۲۳۴۵۶۷۸۹');
t('سفارش + موبایل درست → اطلاعات نمایش داده می‌شود', strpos($html, 'TRACK123456789') !== false && strpos($html, 'علی') !== false);
t('لینک پیگیری پست ساخته می‌شود', strpos($html, 'tracking.post.ir/?id=TRACK123456789') !== false);

$html = submit('101', '09120000000');
t('موبایل غلط → هیچ PII نمایش داده نمی‌شود', strpos($html, 'علی') === false && strpos($html, 'TRACK123456789') === false && strpos($html, 'ولیعصر') === false);
t('موبایل غلط → پیام خطای یکسان', strpos($html, 'معتبر نیست') !== false);

$html = submit('999', '09123456789');
t('شماره سفارش نامعتبر → همان پیام (لو نرفتن وجود سفارش)', strpos($html, 'معتبر نیست') !== false);

$html = submit('101', '');
t('موبایل خالی → پیام الزامی‌بودن', strpos($html, 'الزامی است') !== false && strpos($html, 'علی') === false);

$html = submit('', '09123456789');
t('شماره سفارش خالی → پیام الزامی‌بودن', strpos($html, 'الزامی است') !== false);

echo "--- 3) محدودسازی تلاش ناموفق (ضد درو) ---\n";
$GLOBALS['bwt_transients'] = [];
for ($i = 0; $i < 10; $i++) {
    submit('101', '0912000000' . $i);
}
t('۱۰ تلاش ناموفق شمرده شد', (int) get_transient('bwt_track_fail_' . md5('203.0.113.7')) === 10);
$html = submit('101', '09123456789');
t('بعد از سقف تلاش، حتی رمز درست هم رد می‌شود', strpos($html, '۱۵ دقیقه') !== false && strpos($html, 'TRACK123456789') === false);

$GLOBALS['bwt_transients'] = [];
$html = submit('102', '09351112233');
t('سفارش بدون کد رهگیری → پیام «در حال آماده‌سازی»', strpos($html, 'آماده‌سازی') !== false && strpos($html, 'سارا') === false);
submit('101', '09120000000'); // یک شکست
submit('101', '09123456789'); // موفق → ریست
t('پس از موفقیت شمارندهٔ تلاش ریست می‌شود', get_transient('bwt_track_fail_' . md5('203.0.113.7')) === false);

echo "--- 4) خودِ فرم ---\n";
$html = bwt_tracking_search_form();
t('فیلد موبایل اجباری در فرم هست', strpos($html, 'name="billing_phone"') !== false && strpos($html, 'bwt_billing_phone') !== false);
t('فیلد شماره سفارش همچنان هست', strpos($html, 'name="order_id"') !== false);

echo "--- 5) پردازش دسته‌ای و ادامه از checkpoint ---\n";
$csv_handle = tmpfile();
fwrite($csv_handle, "order_id,tracking_code\n101,TRACK-NEW\nnot-a-number,TRACK-BAD\n102,\n");
fflush($csv_handle);
$batch_one = bwt_process_csv_batch_handle($csv_handle, 0, 0, false, 2, 10);
$batch_two = bwt_process_csv_batch_handle($csv_handle, $batch_one['offset'], $batch_one['row_num'], $batch_one['has_header'], 2, 10);
t('دستهٔ اول هدر را رد می‌کند و یک سفارش را ذخیره می‌کند', $batch_one['updated'] === 1 && $batch_one['errors'] === 0 && $batch_one['row_num'] === 2 && !$batch_one['done']);
t('دستهٔ دوم از offset ادامه می‌دهد و خطای ردیف ۳ را گزارش می‌کند', $batch_two['updated'] === 1 && $batch_two['errors'] === 1 && $batch_two['details'][0]['row'] === 3 && $batch_two['done']);
t('نوشتن کد جدید و حذف کد با WooCommerce CRUD انجام می‌شود', $GLOBALS['bwt_post_meta'][101]['_tracking_code'] === 'TRACK-NEW' && $GLOBALS['bwt_order_saves'] === 2);
$batch_eof = bwt_process_csv_batch_handle($csv_handle, $batch_two['offset'], $batch_two['row_num'], $batch_two['has_header'], 2, 10);
t('درخواست ادامه پس از EOF بدون بازپردازش تمام‌شده برمی‌گردد', $batch_eof['done'] && $batch_eof['updated'] === 0 && $batch_eof['errors'] === 0);
t('پیش‌نمایش مقادیر ردیف خطا برای محدودماندن حافظه کوتاه می‌شود', bwt_csv_error_excerpt(str_repeat('X', 250)) === str_repeat('X', 200) . '…');
fclose($csv_handle);
$persian_csv = tmpfile();
fwrite($persian_csv, "۱۰۱,FA-TRACK\n");
fflush($persian_csv);
$persian_first_row = bwt_process_csv_batch_handle($persian_csv, 0, 0, false, 1, 10);
t('ردیف اول بدون هدر با رقم فارسی به‌عنوان داده تشخیص داده می‌شود', $persian_first_row['updated'] === 1 && !$persian_first_row['has_header'] && $GLOBALS['bwt_post_meta'][101]['_tracking_code'] === 'FA-TRACK');
fclose($persian_csv);
$large_csv = tmpfile();
$large_rows = '';
for ($i = 0; $i < 52; $i++) {
    $large_rows .= '101,BATCH-' . $i . "\n";
}
fwrite($large_csv, $large_rows);
fflush($large_csv);
$limited_batch = bwt_process_csv_batch_handle($large_csv, 0, 0, false, BWT_UPLOAD_BATCH_SIZE, 10);
$last_batch = bwt_process_csv_batch_handle($large_csv, $limited_batch['offset'], $limited_batch['row_num'], false, BWT_UPLOAD_BATCH_SIZE, 10);
t('حد دسته رعایت می‌شود و ۵۲ ردیف در دو درخواست ۵۰+۲تایی کامل می‌شوند', $limited_batch['updated'] === 50 && !$limited_batch['done'] && $last_batch['updated'] === 2 && $last_batch['done']);
fclose($large_csv);

$job_source = tempnam(sys_get_temp_dir(), 'bwt-source-');
file_put_contents($job_source, "order_id,tracking_code\n101,JOB-TRACK\n");
$created_job = bwt_create_upload_job($job_source);
@unlink($job_source);
t('آپلود یک Job با فایل موقت خصوصی و شناسهٔ ۳۲رقمی می‌سازد', !empty($created_job['job_id']) && strlen($created_job['job_id']) === 32, var_export($created_job, true));
t('پیشوند فایل موقت به سایت جاری محدود است', strpos(basename(get_transient(bwt_upload_job_key($created_job['job_id']))['file_path']), bwt_upload_temp_prefix()) === 0);
$_POST = array('nonce' => 'test-nonce', 'job_id' => $created_job['job_id']);
$GLOBALS['bwt_test_user_id'] = 8;
$other_user_response = null;
try { bwt_ajax_upload_status(); } catch (BWT_Test_Response $response) { $other_user_response = $response; }
t('کاربر دیگری نمی‌تواند Job را بخواند یا اجرا کند', $other_user_response instanceof BWT_Test_Response && !$other_user_response->success && $other_user_response->status === 404);
$GLOBALS['bwt_test_user_id'] = 7;
$status_response = null;
try { bwt_ajax_upload_status(); } catch (BWT_Test_Response $response) { $status_response = $response; }
t('مالک Job با nonce معتبر وضعیت را می‌خواند', $status_response instanceof BWT_Test_Response && $status_response->success && !$status_response->payload['done']);
$batch_response = null;
try { bwt_ajax_upload_batch(); } catch (BWT_Test_Response $response) { $batch_response = $response; }
t('اندپوینت AJAX دسته را اجرا و نتیجهٔ تکمیل را برمی‌گرداند', $batch_response instanceof BWT_Test_Response && $batch_response->success && $batch_response->payload['done'] && $batch_response->payload['updated'] === 1 && $batch_response->payload['errors'] === 0);
$completed_job_state = !empty($created_job['job_id']) ? get_transient(bwt_upload_job_key($created_job['job_id'])) : false;
t('Job تکمیل‌شده file data موقت را حذف و برای بازیابی نتیجه نگه می‌دارد', $GLOBALS['bwt_post_meta'][101]['_tracking_code'] === 'JOB-TRACK' && is_array($completed_job_state) && isset($completed_job_state['file_path']) && $completed_job_state['file_path'] === '');
$_POST = array();



echo "--- 6) سقف سازگاری و پاک‌سازی دسته‌ای ---\n";
$legacy_csv = tempnam(sys_get_temp_dir(), 'bwt-legacy-');
$legacy_data = '';
for ($i = 0; $i < 52; $i++) $legacy_data .= '999999,TRACK-' . $i . "\n";
file_put_contents($legacy_csv, $legacy_data);
$legacy_batch = bwt_process_uploaded_file($legacy_csv);
$legacy_total_errors = $legacy_batch['errors'];
$legacy_details_bounded = count($legacy_batch['details']) <= BWT_UPLOAD_BATCH_SIZE;
$legacy_calls = 1;
while (!$legacy_batch['done'] && $legacy_calls < 10) {
    $legacy_batch = bwt_process_uploaded_file($legacy_csv, '', $legacy_batch['offset'], $legacy_batch['row_num'], $legacy_batch['has_header']);
    $legacy_total_errors += $legacy_batch['errors'];
    $legacy_details_bounded = $legacy_details_bounded && count($legacy_batch['details']) <= BWT_UPLOAD_BATCH_SIZE;
    $legacy_calls++;
}
t('تابع سازگاری CSV در هر درخواست فقط یک batch محدود و حداکثر ۵۰ جزئیات نگه می‌دارد', $legacy_calls >= 2 && $legacy_details_bounded && $legacy_total_errors === 52, var_export(array($legacy_calls, $legacy_total_errors, $legacy_details_bounded, $legacy_batch['row_num']), true));
t('فراخوان‌های دارای checkpoint تا EOF ادامه پیدا می‌کنند', $legacy_batch['done'] && $legacy_batch['row_num'] === 52);
unlink($legacy_csv);

$GLOBALS['wpdb']->query_result = 500;
$limited_delete = bwt_delete_tracking_meta_batch('wp_postmeta', 99999);
t('حذف SQL به LIMIT امن ۵۰۰ محدود و فقط روی جدول مجاز انجام می‌شود', $limited_delete === 500 && strpos($GLOBALS['wpdb']->last_sql, 'LIMIT 500') !== false && bwt_delete_tracking_meta_batch('wp_postmeta; DROP TABLE wp_users', 1) === false);
$cleanup_ids = range(1000, 1059);
$cleanup_job = bwt_create_cleanup_job('selected', $cleanup_ids);
$cleanup_state = bwt_get_cleanup_job($cleanup_job['job_id']);
$first_cleanup_batch = bwt_process_selected_cleanup_batch($cleanup_state, 5000);
t('پاک‌سازی انتخابی حتی با سقف بالاتر فقط ۵۰ سفارش را در هر batch می‌پردازد', $first_cleanup_batch === 50 && $cleanup_state['processed'] === 50 && !$cleanup_state['done']);
$second_cleanup_batch = bwt_process_selected_cleanup_batch($cleanup_state, 50);
t('پاک‌سازی انتخابی با checkpoint تا انتهای لیست ادامه می‌یابد', $second_cleanup_batch === 10 && $cleanup_state['processed'] === 60 && $cleanup_state['done']);
t('جزئیات پاک‌سازی انتخابی حداکثر ۲۰ مورد نگه داشته می‌شود', count($cleanup_state['details']) === 20 && $cleanup_state['details_truncated'] === 40);

$all_cleanup_job = bwt_create_cleanup_job('all');
$_POST = array('nonce' => 'test-nonce', 'job_id' => $all_cleanup_job['job_id']);
$GLOBALS['wpdb']->query_result = 500;
$all_cleanup_batch_one = null;
try { bwt_ajax_cleanup_batch(); } catch (BWT_Test_Response $response) { $all_cleanup_batch_one = $response; }
t('پاک‌سازی همهٔ metadata در هر AJAX درخواست حذف را به ۵۰۰ ردیف محدود می‌کند', $all_cleanup_batch_one instanceof BWT_Test_Response && $all_cleanup_batch_one->success && !$all_cleanup_batch_one->payload['done'] && $all_cleanup_batch_one->payload['deleted'] === 500 && strpos($GLOBALS['wpdb']->last_sql, 'LIMIT 500') !== false);
$GLOBALS['wpdb']->query_result = 0;
$all_cleanup_batch_two = null;
try { bwt_ajax_cleanup_batch(); } catch (BWT_Test_Response $response) { $all_cleanup_batch_two = $response; }
$all_cleanup_batch_three = null;
try { bwt_ajax_cleanup_batch(); } catch (BWT_Test_Response $response) { $all_cleanup_batch_three = $response; }
t('پاک‌سازی همه پس از خالی‌شدن جدول نخست به جدول HPOS ادامه می‌دهد و قابل بازیابی است', $all_cleanup_batch_two instanceof BWT_Test_Response && !$all_cleanup_batch_two->payload['done'] && $all_cleanup_batch_three instanceof BWT_Test_Response && $all_cleanup_batch_three->payload['done'] && $all_cleanup_batch_three->payload['deleted'] === 500);
$_POST = array();

$many_errors_source = tempnam(sys_get_temp_dir(), 'bwt-many-errors-');
$many_errors_csv = str_repeat("999999,TRACK\n", 520);
file_put_contents($many_errors_source, $many_errors_csv);
$many_errors_job = bwt_create_upload_job($many_errors_source);
@unlink($many_errors_source);
$many_error_payload = array();
$many_error_calls = 0;
while ($many_error_calls < 20) {
    $_POST = array('nonce' => 'test-nonce', 'job_id' => $many_errors_job['job_id']);
    try { bwt_ajax_upload_batch(); } catch (BWT_Test_Response $response) { $many_error_payload = $response->payload; }
    $many_error_calls++;
    if (!empty($many_error_payload['done'])) break;
}
$many_errors_state = bwt_get_upload_job($many_errors_job['job_id']);
t('جزئیات خطا در طول پردازش تا سقف ۵۰۰ مورد ذخیره می‌ماند و بقیه فقط شمارش می‌شوند', !empty($many_error_payload['done']) && $many_error_payload['errors'] === 520 && count($many_error_payload['details']) === 500 && $many_error_payload['details_truncated'] === 20 && $many_errors_state['details_count'] === 500);
if (!empty($many_errors_state['error_path']) && bwt_is_upload_temp_path($many_errors_state['error_path'])) @unlink($many_errors_state['error_path']);
$_POST = array();

echo "\nنتیجه: $pass موفق، $fail ناموفق\n";
exit($fail === 0 ? 0 : 1);
