<?php
/**
 * Plugin Name: Bulk Tracking Code Upload for WooCommerce
 * Description: آپلود انبوه کد رهگیری از اکسل و نمایش اطلاعات کامل سفارش + لینک پیگیری پست
 * Version: 2.5.0
 * Author: علیرضا شعبان زاده
 */

if (!defined('ABSPATH')) {
    exit;
}

define('BWT_VERSION', '2.5.0');
define('BWT_UPLOAD_MAX_SIZE', 5 * 1024 * 1024);
define('BWT_UPLOAD_BATCH_SIZE', 50);
define('BWT_UPLOAD_BATCH_SECONDS', 4);
define('BWT_UPLOAD_MAX_ERROR_DETAILS', 500);

// ========== 1. افزودن منو به پیشخوان ==========
add_action('admin_menu', 'bwt_add_admin_menu');
add_action('wp_ajax_bwt_upload_batch', 'bwt_ajax_upload_batch');
add_action('wp_ajax_bwt_upload_status', 'bwt_ajax_upload_status');
add_action('bwt_cleanup_upload_temp_files', 'bwt_cleanup_stale_upload_files');
function bwt_add_admin_menu() {
    add_submenu_page(
        'woocommerce',
        'آپلود کد رهگیری انبوه',
        'آپلود کد رهگیری',
        'manage_options',
        'bulk-tracking-upload',
        'bwt_upload_page'
    );
    
    add_submenu_page(
        'woocommerce',
        'پاک کردن کدهای رهگیری',
        'پاک کردن کدها',
        'manage_options',
        'bwt-cleanup',
        'bwt_cleanup_page'
    );
}

// استایل ادمین فقط روی دو صفحهٔ خودمان (بعد از لایهٔ توکن هاب اگر فعال باشد)
add_action('admin_enqueue_scripts', 'bwt_admin_assets');
function bwt_admin_assets($hook) {
    if ($hook !== 'woocommerce_page_bulk-tracking-upload' && $hook !== 'woocommerce_page_bwt-cleanup') {
        return;
    }
    wp_enqueue_style(
        'bwt-admin',
        plugin_dir_url(__FILE__) . 'assets/admin.css',
        wp_style_is('tisacase-ui', 'registered') ? array('tisacase-ui') : array(),
        BWT_VERSION
    );
    if ($hook === 'woocommerce_page_bulk-tracking-upload') {
        wp_enqueue_script('bwt-admin', plugin_dir_url(__FILE__) . 'assets/admin.js', array(), BWT_VERSION, true);
        wp_localize_script('bwt-admin', 'BWTAdmin', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('bwt_upload_batch'),
            'retryLabel' => 'تلاش دوباره / ادامه پردازش',
        ));
    }
}


// سر سبز مشترک دو صفحه (زبان طراحی TisaCase)
function bwt_render_hero($active) {
    $tabs = array(
        'upload'  => array('label' => 'آپلود کد رهگیری', 'url' => admin_url('admin.php?page=bulk-tracking-upload')),
        'cleanup' => array('label' => 'پاک کردن کدها',   'url' => admin_url('admin.php?page=bwt-cleanup')),
    );
    ?>
    <header class="bwt-hero">
        <div class="bwt-hero-row">
            <div class="bwt-hero-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z"/><path d="M3 7.5 12 12l9-4.5M12 12v9"/></svg>
            </div>
            <div class="bwt-hero-text">
                <h1 class="bwt-hero-title">کد رهگیری پستی</h1>
                <p class="bwt-hero-sub">افزودن انبوه کد رهگیری از CSV و پاک‌کردن کدها — به همراه فرم پیگیری سفارش برای مشتری</p>
            </div>
            <span class="bwt-hero-ver" dir="ltr">v<?php echo esc_html(BWT_VERSION); ?></span>
        </div>
        <nav class="bwt-tabs">
            <?php foreach ($tabs as $key => $t) : ?>
                <a class="bwt-tab <?php echo $key === $active ? 'is-active' : ''; ?>" href="<?php echo esc_url($t['url']); ?>"><?php echo esc_html($t['label']); ?></a>
            <?php endforeach; ?>
        </nav>
    </header>
    <?php
}

// ========== 2. صفحه آپلود اکسل ==========
function bwt_upload_page() {
    if (!current_user_can('manage_options')) {
        wp_die('دسترسی غیر مجاز');
    }

    $upload_error = '';
    $upload_job_id = '';

    // فقط آپلود و ایجاد Job در درخواست صفحه انجام می‌شود؛ ردیف‌ها در درخواست‌های AJAX کوچک پردازش می‌شوند.
    if (isset($_POST['bwt_upload'])) {
        if (!isset($_POST['bwt_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bwt_nonce'])), 'bwt_upload_action')) {
            $upload_error = 'خطای امنیتی: نانس نامعتبر است. لطفا صفحه را رفرش کنید.';
        } elseif (empty($_FILES['tracking_file']) || $_FILES['tracking_file']['error'] !== UPLOAD_ERR_OK) {
            $upload_error = bwt_get_upload_error_message($_FILES['tracking_file']['error'] ?? UPLOAD_ERR_NO_FILE);
        } else {
            $file = $_FILES['tracking_file'];
            if ((int) $file['size'] > BWT_UPLOAD_MAX_SIZE) {
                $upload_error = 'حجم فایل بیش از حد مجاز است (حداکثر ۵ مگابایت).';
            } elseif (!is_uploaded_file($file['tmp_name'])) {
                $upload_error = 'خطای آپلود: فایل به درستی آپلود نشده است.';
            } else {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if ($ext !== 'csv') {
                    $upload_error = 'فرمت فایل نامعتبر است. فقط فایل CSV مجاز است.';
                } elseif (empty($upload_error)) {
                    $created_job = bwt_create_upload_job($file['tmp_name']);
                    if (empty($created_job['job_id'])) {
                        $upload_error = !empty($created_job['error']) ? $created_job['error'] : 'ساخت کار پردازش فایل ناموفق بود.';
                    } else {
                        $upload_job_id = $created_job['job_id'];
                    }
                }
            }
        }
    } elseif (isset($_GET['bwt_job'])) {
        $requested_job_id = bwt_sanitize_upload_job_id(wp_unslash($_GET['bwt_job']));
        $requested_job = $requested_job_id ? bwt_get_upload_job($requested_job_id) : false;
        if ($requested_job && (int) $requested_job['user_id'] === (int) get_current_user_id()) {
            $upload_job_id = $requested_job_id;
        } else {
            $upload_error = 'کار پردازش فایل پیدا نشد یا منقضی شده است. فایل را دوباره انتخاب کنید.';
        }
    }

    ?>
    <div class="wrap tisa-wrap bwt-wrap" dir="rtl">
        <?php bwt_render_hero('upload'); ?>

        <?php
        // نمایش خطای آپلود کلی
        if (!empty($upload_error)) {
            echo '<div class="tisa-notice tisa-notice--danger bwt-notice">' . esc_html($upload_error) . '</div>';
        }

        if ('' !== $upload_job_id) {
            ?>
            <section id="bwt-upload-progress" class="bwt-card bwt-upload-progress" data-job-id="<?php echo esc_attr($upload_job_id); ?>" aria-live="polite">
                <div class="bwt-card-head"><span class="bwt-dot"></span><div><h2>پردازش فایل CSV</h2><p data-bwt-status>در حال آماده‌سازی پردازش دسته‌ای…</p></div></div>
                <div class="bwt-card-body">
                    <progress class="bwt-progress-bar" data-bwt-progress max="100" value="0" aria-label="پیشرفت پردازش"></progress>
                    <div class="bwt-progress-meta" data-bwt-counters></div>
                    <button type="button" class="tisa-btn tisa-btn--secondary" data-bwt-resume hidden>ادامهٔ پردازش</button>
                    <noscript><p class="bwt-hint">برای اجرای پردازش دسته‌ای، جاوااسکریپت مرورگر را فعال کنید.</p></noscript>
                </div>
            </section>
            <div id="bwt-upload-result" data-bwt-result hidden></div>
            <?php
        }

        ?>

        <div class="bwt-grid">
            <form method="post" enctype="multipart/form-data" class="bwt-card bwt-form">
                <div class="bwt-card-head"><span class="bwt-dot"></span><div><h2>آپلود فایل CSV</h2><p>دو ستون <span class="tisa-code">order_id, tracking_code</span> — ردیف اول هدر است و نادیده گرفته می‌شود.</p></div></div>
                <div class="bwt-card-body">
                    <label for="bwt_tracking_file" class="bwt-label">فایل CSV</label>
                    <input type="file" id="bwt_tracking_file" name="tracking_file" accept=".csv" required class="bwt-file" />
                    <p class="bwt-hint">اگر ستون دوم خالی باشد، کد رهگیری آن سفارش حذف می‌شود.</p>
                    <?php wp_nonce_field('bwt_upload_action', 'bwt_nonce'); ?>
                    <div class="bwt-actions"><button type="submit" name="bwt_upload" class="tisa-btn tisa-btn--primary tisa-btn--lg">آپلود و بروزرسانی</button></div>
                </div>
            </form>

            <section class="bwt-card">
                <div class="bwt-card-head"><span class="bwt-dot bwt-dot--muted"></span><div><h2>نمونه فایل صحیح</h2></div></div>
                <div class="bwt-card-body">
                    <pre class="bwt-sample">order_id,tracking_code
1234,12345678901234567890
1235,98765432109876543210
1236,</pre>
                    <ul class="bwt-help">
                        <li>شماره سفارش‌ها می‌توانند با ارقام فارسی هم باشند.</li>
                        <li>ردیف آخر (بدون کد) = حذف کد رهگیری سفارش ۱۲۳۶.</li>
                        <li>شورت‌کد فرم پیگیری مشتری: <span class="tisa-code">[tracking_search]</span></li>
                    </ul>
                </div>
            </section>
        </div>
    </div>
    <?php
}

function bwt_get_upload_error_message($code) {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            return 'حجم فایل بیش از حد مجاز سرور است.';
        case UPLOAD_ERR_PARTIAL:
            return 'فایل ناقص آپلود شده. دوباره تلاش کنید.';
        case UPLOAD_ERR_NO_FILE:
            return 'لطفاً یک فایل انتخاب کنید.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'پوشه موقت سرور یافت نشد. با هاستینگ تماس بگیرید.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'خطا در نوشتن فایل روی دیسک.';
        case UPLOAD_ERR_EXTENSION:
            return 'آپلود توسط افزونه PHP متوقف شد.';
        default:
            return 'خطای نامشخص در آپلود فایل.';
    }
}

// ========== 3. پردازش فایل CSV ==========
function bwt_csv_error_excerpt($value, $limit = 200) {
    $value = (string) $value;
    $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    if ($length <= $limit) {
        return $value;
    }
    $excerpt = function_exists('mb_substr') ? mb_substr($value, 0, $limit, 'UTF-8') : substr($value, 0, $limit);
    return $excerpt . '…';
}

function bwt_process_tracking_csv_row($data, $row_num) {
    $data = is_array($data) ? $data : array();
    if ($row_num === 1 && isset($data[0])) {
        $data[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $data[0]);
        $first_cell = strtolower(trim((string) $data[0]));
        if (!is_numeric(bwt_normalize_digits($first_cell))) {
            return array('updated' => 0, 'error' => null, 'header' => true, 'skipped' => true);
        }
    }

    $is_empty_line = true;
    foreach ($data as $cell) {
        if (trim((string) $cell) !== '') {
            $is_empty_line = false;
            break;
        }
    }
    if ($is_empty_line) {
        return array('updated' => 0, 'error' => null, 'header' => false, 'skipped' => true);
    }

    $order_id_raw = isset($data[0]) ? trim((string) $data[0]) : '';
    $tracking_code_raw = isset($data[1]) ? trim((string) $data[1]) : '';
    $make_error = static function ($reason) use ($row_num, $order_id_raw, $tracking_code_raw) {
        return array(
            'updated' => 0,
            'error' => array(
                'row' => $row_num,
                'order_id_raw' => bwt_csv_error_excerpt($order_id_raw, 100),
                'tracking_code_raw' => bwt_csv_error_excerpt($tracking_code_raw),
                'reason' => $reason,
            ),
            'header' => false,
            'skipped' => false,
        );
    };

    if ($order_id_raw === '') {
        return $make_error('شماره سفارش خالی است.');
    }
    $order_id_normalized = bwt_normalize_digits($order_id_raw);
    if (!ctype_digit($order_id_normalized)) {
        return $make_error('شماره سفارش باید عددی باشد.');
    }
    $order_id = intval($order_id_normalized);
    if ($order_id <= 0) {
        return $make_error('شماره سفارش نامعتبر است (باید بزرگتر از صفر باشد).');
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return $make_error('سفارش با این شماره یافت نشد ( #' . $order_id . ' وجود ندارد ).');
    }
    $order_type = $order->get_type();
    if ($order_type !== 'shop_order' && $order_type !== 'shop_order_placehold') {
        return $make_error('شناسه #' . $order_id . ' سفارش فروشگاهی نیست (نوع: ' . $order_type . ').');
    }

    $tracking_code = sanitize_text_field($tracking_code_raw);
    $tracking_length = function_exists('mb_strlen') ? mb_strlen($tracking_code, 'UTF-8') : strlen($tracking_code);
    if ($tracking_code !== '' && $tracking_length > 100) {
        return $make_error('کد رهگیری خیلی طولانی است (حداکثر ۱۰۰ کاراکتر).');
    }

    if ($tracking_code === '') {
        delete_post_meta($order_id, '_tracking_code');
        if (method_exists($order, 'delete_meta_data')) {
            $order->delete_meta_data('_tracking_code');
            $order->save();
        }
    } else {
        update_post_meta($order_id, '_tracking_code', $tracking_code);
        if (method_exists($order, 'update_meta_data')) {
            $order->update_meta_data('_tracking_code', $tracking_code);
            $order->save();
        }
    }

    return array('updated' => 1, 'error' => null, 'header' => false, 'skipped' => false);
}

/** پردازش محدود یک دسته از CSV روی یک file handle قابل seek. */
function bwt_process_csv_batch_handle($handle, $offset = 0, $row_num = 0, $has_header = false, $max_rows = BWT_UPLOAD_BATCH_SIZE, $time_budget = BWT_UPLOAD_BATCH_SECONDS) {
    if (!is_resource($handle)) {
        return array('updated' => 0, 'errors' => 0, 'details' => array(), 'offset' => 0, 'row_num' => $row_num, 'has_header' => $has_header, 'done' => true, 'file_error' => 'فایل قابل خواندن نیست.');
    }
    $offset = max(0, (int) $offset);
    if (0 !== fseek($handle, $offset)) {
        return array('updated' => 0, 'errors' => 0, 'details' => array(), 'offset' => $offset, 'row_num' => $row_num, 'has_header' => $has_header, 'done' => true, 'file_error' => 'ادامهٔ خواندن فایل CSV ممکن نشد.');
    }

    $updated = 0;
    $errors = 0;
    $details = array();
    $records = 0;
    $started = microtime(true);
    $done = false;
    $max_rows = max(1, min(500, (int) $max_rows));
    $time_budget = max(0.1, (float) $time_budget);

    while ($records < $max_rows) {
        if ($records > 0 && (microtime(true) - $started) >= $time_budget) {
            break;
        }
        $data = fgetcsv($handle, 0, ',');
        if ($data === false) {
            $done = true;
            break;
        }
        $row_num++;
        $records++;
        $row = bwt_process_tracking_csv_row($data, $row_num);
        if (!empty($row['header'])) {
            $has_header = true;
        }
        $updated += (int) $row['updated'];
        if (!empty($row['error'])) {
            $errors++;
            $details[] = $row['error'];
        }
    }

    $next_offset = ftell($handle);
    $next_offset = false === $next_offset ? $offset : (int) $next_offset;
    $stats = fstat($handle);
    if (!$done && is_array($stats) && isset($stats['size']) && $next_offset >= (int) $stats['size']) {
        $done = true;
    }

    return array(
        'updated' => $updated,
        'errors' => $errors,
        'details' => $details,
        'offset' => $next_offset,
        'row_num' => $row_num,
        'has_header' => $has_header,
        'done' => $done,
        'file_error' => '',
    );
}

/** پردازش کامل برای سازگاری فراخوان‌های قدیمی؛ پنل مدیریت از Jobهای دسته‌ای استفاده می‌کند. */
function bwt_process_uploaded_file($file_path, $original_name = '') {
    if (!is_readable($file_path)) {
        return array('updated' => 0, 'errors' => 0, 'details' => array(), 'file_error' => 'فایل قابل خواندن نیست.');
    }
    $handle = fopen($file_path, 'rb');
    if ($handle === false) {
        return array('updated' => 0, 'errors' => 0, 'details' => array(), 'file_error' => 'خطا در باز کردن فایل. لطفاً از فرمت CSV استفاده کنید.');
    }

    $updated = 0;
    $errors = 0;
    $details = array();
    $offset = 0;
    $row_num = 0;
    $has_header = false;
    $file_error = '';
    do {
        $batch = bwt_process_csv_batch_handle($handle, $offset, $row_num, $has_header);
        if (!empty($batch['file_error'])) {
            $file_error = $batch['file_error'];
            break;
        }
        $updated += (int) $batch['updated'];
        $errors += (int) $batch['errors'];
        $details = array_merge($details, $batch['details']);
        $offset = (int) $batch['offset'];
        $row_num = (int) $batch['row_num'];
        $has_header = !empty($batch['has_header']);
        $done = !empty($batch['done']);
    } while (!$done);
    fclose($handle);

    if ($file_error !== '') {
        return array('updated' => $updated, 'errors' => $errors, 'details' => $details, 'file_error' => $file_error);
    }
    if ($row_num === 0 || ($has_header && $updated === 0 && $errors === 0)) {
        return array('updated' => 0, 'errors' => 0, 'details' => array(), 'file_error' => 'فایل خالی است یا فقط شامل هدر است. داده‌ای برای پردازش یافت نشد.');
    }
    return array('updated' => $updated, 'errors' => $errors, 'details' => $details, 'file_error' => '');
}

function bwt_path_is_within_root($path, $root) {
    $path = realpath($path);
    $root = realpath($root);
    if ($path === false || $root === false) {
        return false;
    }
    $root = rtrim($root, '/\\') . DIRECTORY_SEPARATOR;
    return $path === rtrim($root, DIRECTORY_SEPARATOR) || strpos($path, $root) === 0;
}

function bwt_upload_temp_dir() {
    $candidates = array();
    if (function_exists('get_temp_dir')) {
        $candidates[] = get_temp_dir();
    }
    $candidates[] = sys_get_temp_dir();
    foreach (array_unique($candidates) as $candidate) {
        $dir = realpath((string) $candidate);
        if ($dir === false || !is_dir($dir) || !is_writable($dir) || bwt_path_is_within_root($dir, ABSPATH)) {
            continue;
        }
        if (defined('WP_CONTENT_DIR') && bwt_path_is_within_root($dir, WP_CONTENT_DIR)) {
            continue;
        }
        return rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
    }
    return '';
}

function bwt_upload_temp_prefix() {
    $site_identity = function_exists('get_option') ? get_option('siteurl') : ABSPATH;
    if (!is_string($site_identity) || $site_identity === '') {
        $site_identity = ABSPATH;
    }
    return 'bwt-upload-job-' . substr(md5($site_identity), 0, 12) . '-';
}

function bwt_is_upload_temp_path($path) {
    if (!is_string($path) || $path === '' || is_link($path) || !is_file($path)) {
        return false;
    }
    $base = basename($path);
    if (strpos($base, bwt_upload_temp_prefix()) !== 0) {
        return false;
    }
    $actual_dir = realpath(dirname($path));
    $temp_dir = realpath(bwt_upload_temp_dir());
    return $actual_dir !== false && $temp_dir !== false && $actual_dir === $temp_dir;
}

function bwt_cleanup_stale_upload_files() {
    $temp_dir = bwt_upload_temp_dir();
    if ($temp_dir === '') {
        return 0;
    }
    $files = glob($temp_dir . bwt_upload_temp_prefix() . '*');
    if (!is_array($files)) {
        return 0;
    }
    $removed = 0;
    foreach ($files as $file) {
        if (is_link($file) || !is_file($file)) {
            continue;
        }
        $modified = @filemtime($file);
        if ($modified && (time() - $modified) > DAY_IN_SECONDS) {
            if (@unlink($file)) {
                $removed++;
            }
        }
    }
    return $removed;
}

function bwt_sanitize_upload_job_id($job_id) {
    if (!is_scalar($job_id)) {
        return '';
    }
    $job_id = strtolower(preg_replace('/[^a-f0-9]/i', '', (string) $job_id));
    return strlen($job_id) === 32 ? $job_id : '';
}

function bwt_upload_job_key($job_id) {
    return 'bwt_upload_job_' . md5((string) $job_id);
}

function bwt_get_upload_job($job_id) {
    $job_id = bwt_sanitize_upload_job_id($job_id);
    return $job_id !== '' ? get_transient(bwt_upload_job_key($job_id)) : false;
}

function bwt_create_upload_job($uploaded_path) {
    $size = @filesize($uploaded_path);
    if (!is_readable($uploaded_path) || false === $size || $size > BWT_UPLOAD_MAX_SIZE) {
        return array('job_id' => '', 'error' => 'فایل موقت آپلود معتبر نیست یا از سقف ۵ مگابایت بزرگ‌تر است.');
    }

    bwt_cleanup_stale_upload_files();
    $temp_dir = bwt_upload_temp_dir();
    if ($temp_dir === '') {
        return array('job_id' => '', 'error' => 'پوشهٔ موقت خصوصی و خارج از مسیر عمومی سایت در دسترس نیست.');
    }
    $file_path = @tempnam($temp_dir, bwt_upload_temp_prefix());
    if (!$file_path || !@copy($uploaded_path, $file_path) || (int) @filesize($file_path) !== (int) $size || !bwt_is_upload_temp_path($file_path)) {
        if (is_string($file_path) && is_file($file_path)) {
            @unlink($file_path);
        }
        return array('job_id' => '', 'error' => 'ذخیرهٔ امن فایل موقت برای پردازش دسته‌ای ممکن نشد.');
    }
    @chmod($file_path, 0600);

    $error_path = $file_path . '.errors.jsonl';
    $error_handle = @fopen($error_path, 'xb');
    if (!$error_handle) {
        @unlink($file_path);
        return array('job_id' => '', 'error' => 'ساخت فایل موقت گزارش خطاها ممکن نشد.');
    }
    fclose($error_handle);
    @chmod($error_path, 0600);

    try {
        $job_id = function_exists('wp_generate_uuid4') ? str_replace('-', '', wp_generate_uuid4()) : bin2hex(random_bytes(16));
    } catch (Throwable $e) {
        @unlink($file_path);
        @unlink($error_path);
        return array('job_id' => '', 'error' => 'ساخت شناسهٔ امن برای کار پردازش ممکن نشد.');
    }
    $job_id = bwt_sanitize_upload_job_id($job_id);
    if ($job_id === '') {
        @unlink($file_path);
        @unlink($error_path);
        return array('job_id' => '', 'error' => 'ساخت شناسهٔ کار پردازش ناموفق بود.');
    }

    $job = array(
        'user_id' => (int) get_current_user_id(),
        'file_path' => $file_path,
        'error_path' => $error_path,
        'file_size' => (int) $size,
        'offset' => 0,
        'row_num' => 0,
        'has_header' => false,
        'updated' => 0,
        'errors' => 0,
        'details_count' => 0,
        'details_truncated' => 0,
        'details_warning' => false,
        'done' => false,
        'file_error' => '',
        'created_at' => time(),
    );
    if (!set_transient(bwt_upload_job_key($job_id), $job, 3 * HOUR_IN_SECONDS)) {
        @unlink($file_path);
        @unlink($error_path);
        return array('job_id' => '', 'error' => 'ذخیرهٔ وضعیت کار پردازش ممکن نشد؛ دوباره تلاش کنید.');
    }

    if (function_exists('wp_next_scheduled') && function_exists('wp_schedule_event') && !wp_next_scheduled('bwt_cleanup_upload_temp_files')) {
        wp_schedule_event(time() + DAY_IN_SECONDS, 'daily', 'bwt_cleanup_upload_temp_files');
    }
    return array('job_id' => $job_id, 'error' => '');
}

function bwt_read_upload_job_details($job) {
    $path = isset($job['error_path']) ? $job['error_path'] : '';
    if (!bwt_is_upload_temp_path($path)) {
        return array();
    }
    $handle = @fopen($path, 'rb');
    if (!$handle) {
        return array();
    }
    $details = array();
    $seen_rows = array();
    while (!feof($handle) && count($details) < BWT_UPLOAD_MAX_ERROR_DETAILS) {
        $line = fgets($handle);
        if (false === $line) {
            break;
        }
        $detail = json_decode($line, true);
        if (!is_array($detail) || !isset($detail['row'])) {
            continue;
        }
        $row = (int) $detail['row'];
        if (isset($seen_rows[$row])) {
            continue;
        }
        $seen_rows[$row] = true;
        $details[] = $detail;
    }
    fclose($handle);
    return $details;
}

function bwt_append_upload_job_details(&$job, $details) {
    $saved_details = bwt_read_upload_job_details($job);
    $seen_rows = array();
    foreach ($saved_details as $detail) {
        $seen_rows[(int) $detail['row']] = true;
    }

    $to_write = array();
    $omitted = 0;
    foreach ((array) $details as $detail) {
        $row = isset($detail['row']) ? (int) $detail['row'] : 0;
        if ($row && isset($seen_rows[$row])) {
            continue;
        }
        if (count($saved_details) + count($to_write) < BWT_UPLOAD_MAX_ERROR_DETAILS) {
            $to_write[] = $detail;
            if ($row) {
                $seen_rows[$row] = true;
            }
        } else {
            $omitted++;
        }
    }

    $written_details = array();
    if ($to_write) {
        $path = isset($job['error_path']) ? $job['error_path'] : '';
        if (!bwt_is_upload_temp_path($path)) {
            $job['details_warning'] = true;
            $job['details_truncated'] += count($to_write) + $omitted;
            return array();
        }
        $lines = array();
        $json_flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        foreach ($to_write as $detail) {
            $json = function_exists('wp_json_encode') ? wp_json_encode($detail, $json_flags) : json_encode($detail, $json_flags);
            if (false !== $json) {
                $lines[] = $json;
                $written_details[] = $detail;
            }
        }
        $encoding_omitted = count($to_write) - count($written_details);
        if ($encoding_omitted > 0) {
            $job['details_warning'] = true;
            $omitted += $encoding_omitted;
        }
        if ($lines && false === @file_put_contents($path, implode("\n", $lines) . "\n", FILE_APPEND | LOCK_EX)) {
            $job['details_warning'] = true;
            $job['details_truncated'] += count($written_details) + $omitted;
            return array();
        }
    }

    $job['details_count'] = count($saved_details) + count($written_details);
    $job['details_truncated'] += $omitted;
    return $written_details;
}

function bwt_upload_job_payload($job, $include_all_details = false, $batch_details = array()) {
    $done = !empty($job['done']);
    $size = max(0, isset($job['file_size']) ? (int) $job['file_size'] : 0);
    $offset = max(0, isset($job['offset']) ? (int) $job['offset'] : 0);
    $progress = $done ? 100 : ($size > 0 ? min(99, (int) floor(100 * $offset / $size)) : 0);
    return array(
        'done' => $done,
        'progress' => $progress,
        'processed' => (int) $job['updated'] + (int) $job['errors'],
        'updated' => (int) $job['updated'],
        'errors' => (int) $job['errors'],
        'details' => $include_all_details ? bwt_read_upload_job_details($job) : (array) $batch_details,
        'details_truncated' => (int) $job['details_truncated'],
        'details_warning' => !empty($job['details_warning']),
        'file_error' => isset($job['file_error']) ? (string) $job['file_error'] : '',
    );
}

function bwt_ajax_upload_authorize() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error(array('message' => 'دسترسی غیرمجاز است.'), 403);
    }
    check_ajax_referer('bwt_upload_batch', 'nonce');
    $job_id = isset($_POST['job_id']) ? bwt_sanitize_upload_job_id(wp_unslash($_POST['job_id'])) : '';
    $job = $job_id !== '' ? bwt_get_upload_job($job_id) : false;
    if (!$job || !is_array($job) || (int) $job['user_id'] !== (int) get_current_user_id()) {
        wp_send_json_error(array('message' => 'کار پردازش پیدا نشد یا دسترسی آن منقضی شده است.'), 404);
    }
    return array($job_id, $job);
}

function bwt_ajax_upload_status() {
    list($job_id, $job) = bwt_ajax_upload_authorize();
    wp_send_json_success(bwt_upload_job_payload($job, true));
}

function bwt_ajax_upload_batch() {
    list($job_id, $job) = bwt_ajax_upload_authorize();
    if (!empty($job['done'])) {
        wp_send_json_success(bwt_upload_job_payload($job, true));
    }

    $file_path = isset($job['file_path']) ? $job['file_path'] : '';
    if (!bwt_is_upload_temp_path($file_path)) {
        $job['done'] = true;
        $job['file_error'] = 'فایل موقت پردازش پیدا نشد یا دیگر قابل خواندن نیست.';
        $job['file_path'] = '';
        set_transient(bwt_upload_job_key($job_id), $job, 3 * HOUR_IN_SECONDS);
        wp_send_json_success(bwt_upload_job_payload($job, true));
    }

    $handle = @fopen($file_path, 'rb');
    if (!$handle) {
        $job['done'] = true;
        $job['file_error'] = 'باز کردن فایل موقت پردازش ممکن نشد.';
        $job['file_path'] = '';
        set_transient(bwt_upload_job_key($job_id), $job, 3 * HOUR_IN_SECONDS);
        wp_send_json_success(bwt_upload_job_payload($job, true));
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        wp_send_json_error(array('message' => 'یک دستهٔ دیگر در حال پردازش است؛ لحظه‌ای دیگر ادامه می‌دهیم.'), 409);
    }

    // وضعیت را پس از گرفتن قفل دوباره می‌خوانیم تا درخواست هم‌زمان همان ردیف‌ها را دوبار جلو نبرد.
    $job = bwt_get_upload_job($job_id);
    if (!$job || !is_array($job) || (int) $job['user_id'] !== (int) get_current_user_id()) {
        flock($handle, LOCK_UN);
        fclose($handle);
        wp_send_json_error(array('message' => 'کار پردازش پیدا نشد یا منقضی شده است.'), 404);
    }
    if (!empty($job['done'])) {
        flock($handle, LOCK_UN);
        fclose($handle);
        wp_send_json_success(bwt_upload_job_payload($job, true));
    }

    $batch = bwt_process_csv_batch_handle(
        $handle,
        (int) $job['offset'],
        (int) $job['row_num'],
        !empty($job['has_header']),
        BWT_UPLOAD_BATCH_SIZE,
        BWT_UPLOAD_BATCH_SECONDS
    );
    $new_details = array();
    $job['updated'] += (int) $batch['updated'];
    $job['errors'] += (int) $batch['errors'];
    if (!empty($batch['details'])) {
        $new_details = bwt_append_upload_job_details($job, $batch['details']);
    }
    $job['offset'] = (int) $batch['offset'];
    $job['row_num'] = (int) $batch['row_num'];
    $job['has_header'] = !empty($batch['has_header']);

    if (!empty($batch['file_error'])) {
        $job['done'] = true;
        $job['file_error'] = $batch['file_error'];
    } elseif (!empty($batch['done'])) {
        $job['done'] = true;
        if ($job['row_num'] === 0 || (!empty($job['has_header']) && $job['updated'] === 0 && $job['errors'] === 0)) {
            $job['file_error'] = 'فایل خالی است یا فقط شامل هدر است. داده‌ای برای پردازش یافت نشد.';
        }
    }

    if (!empty($job['done'])) {
        if (!empty($job['file_path']) && bwt_is_upload_temp_path($job['file_path'])) {
            @unlink($job['file_path']);
        }
        $job['file_path'] = '';
        if (empty($job['details_count']) && empty($job['details_truncated']) && !empty($job['error_path']) && bwt_is_upload_temp_path($job['error_path'])) {
            @unlink($job['error_path']);
            $job['error_path'] = '';
        }
    }

    if (empty($job['done']) && !empty($job['file_path']) && bwt_is_upload_temp_path($job['file_path'])) {
        @touch($job['file_path']);
    }
    if (!empty($job['error_path']) && bwt_is_upload_temp_path($job['error_path'])) {
        @touch($job['error_path']);
    }
    set_transient(bwt_upload_job_key($job_id), $job, 3 * HOUR_IN_SECONDS);
    flock($handle, LOCK_UN);
    fclose($handle);
    wp_send_json_success(bwt_upload_job_payload($job, !empty($job['done']), $new_details));
}

function bwt_normalize_digits($str) {
    $persian = array('۰','۱','۲','۳','۴','۵','۶','۷','۸','۹');
    $arabic  = array('٠','١','٢','٣','٤','٥','٦','٧','٨','٩');
    $english = array('0','1','2','3','4','5','6','7','8','9');
    $str = str_replace($persian, $english, $str);
    $str = str_replace($arabic, $english, $str);
    return trim($str);
}

// ========== 4. شورت‌کد جدید با اطلاعات کامل ==========
add_shortcode('tracking_search_form', 'bwt_tracking_search_form');
/**
 * نرمال‌سازی شمارهٔ موبایل به قالب یکتای 989XXXXXXXXX.
 * ورودی: فارسی/عربی، فاصله/خط‌تیره/پرانتز، پیشوندهای +98 / 0098 / 98 / 0.
 * خروجی نامعتبر = رشتهٔ خالی.
 */
function bwt_normalize_phone($input) {
    $s = bwt_normalize_digits((string) $input);
    $s = preg_replace('/[^0-9]/', '', $s);
    if ($s === '') {
        return '';
    }
    if (strpos($s, '0098') === 0) {
        $s = substr($s, 4);
    } elseif (strlen($s) === 12 && strpos($s, '98') === 0) {
        $s = substr($s, 2);
    }
    if (strpos($s, '0') === 0) {
        $s = substr($s, 1);
    }
    if (strlen($s) === 10 && strpos($s, '9') === 0) {
        return '98' . $s;
    }
    return '';
}

// مقایسهٔ شمارهٔ ثبت‌شده روی سفارش با ورودی کاربر (بدون افشای اطلاعات، زمان‌ثابت)
function bwt_phones_match($stored, $input) {
    $a = bwt_normalize_phone($stored);
    $b = bwt_normalize_phone($input);
    if ($a === '' || $b === '') {
        return false;
    }
    return hash_equals($a, $b);
}

// محدودسازی تلاش‌های ناموفق پیگیری بر اساس IP (ضد درو کردن شماره سفارش‌ها)
function bwt_client_ip() {
    return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : 'cli';
}
function bwt_track_throttle_key() {
    return 'bwt_track_fail_' . md5(bwt_client_ip());
}
function bwt_track_is_throttled() {
    return (int) get_transient(bwt_track_throttle_key()) >= 10;
}
function bwt_track_note_fail() {
    $key = bwt_track_throttle_key();
    set_transient($key, (int) get_transient($key) + 1, 15 * MINUTE_IN_SECONDS);
}
function bwt_track_reset_fails() {
    delete_transient(bwt_track_throttle_key());
}

function bwt_tracking_search_form() {
    ob_start();
    $result_html = '';
    $submitted_order_id = isset($_POST['order_id']) ? sanitize_text_field(wp_unslash($_POST['order_id'])) : '';
    $submitted_phone    = isset($_POST['billing_phone']) ? sanitize_text_field(wp_unslash($_POST['billing_phone'])) : '';

    if (isset($_POST['tracking_submit'])) {
        if (bwt_track_is_throttled()) {
            $result_html = '<div class="bwt-result error">❌ تعداد تلاش‌های ناموفق بیش از حد مجاز است. لطفاً ۱۵ دقیقه دیگر دوباره تلاش کنید.</div>';
        } else {
            $order_id_raw = $submitted_order_id;
            $order_id_normalized = bwt_normalize_digits($order_id_raw);

            if ($order_id_raw === '' || $submitted_phone === '') {
                $result_html = '<div class="bwt-result error">❌ وارد کردن شماره سفارش و شماره موبایلی که سفارش با آن ثبت شده، الزامی است.</div>';
            } elseif (!ctype_digit($order_id_normalized)) {
                $result_html = '<div class="bwt-result error">❌ شماره سفارش باید عددی باشد.</div>';
            } else {
                $order_id = intval($order_id_normalized);
                $order = wc_get_order($order_id);

                // احراز مالکیت: شماره سفارش به‌تنهایی کافی نیست — موبایل ثبت‌شده روی سفارش هم باید بخواند.
                // پیام خطا برای «سفارش نامعتبر» و «موبایل نامطابق» یکی است تا وجود سفارش لو نرود.
                $verified = $order && bwt_phones_match($order->get_billing_phone(), $submitted_phone);

                if (!$verified) {
                    bwt_track_note_fail();
                    $result_html = '<div class="bwt-result error">❌ شماره سفارش یا شماره موبایل واردشده معتبر نیست. شماره موبایل باید همان شماره‌ای باشد که سفارش با آن ثبت شده است.</div>';
                } else {
                    bwt_track_reset_fails();
                    $tracking_code = get_post_meta($order_id, '_tracking_code', true);
                    if (empty($tracking_code) && method_exists($order, 'get_meta')) {
                        $tracking_code = $order->get_meta('_tracking_code');
                    }

                    if ($tracking_code) {
                        $billing_first_name = $order->get_billing_first_name();
                        $billing_last_name  = $order->get_billing_last_name();
                        $billing_address_1  = $order->get_billing_address_1();
                        $billing_address_2  = $order->get_billing_address_2();
                        $billing_city       = $order->get_billing_city();
                        $billing_state      = $order->get_billing_state();
                        $billing_postcode   = $order->get_billing_postcode();

                        $full_address = $billing_address_1;
                        if ($billing_address_2) {
                            $full_address .= ' - ' . $billing_address_2;
                        }
                        $full_address .= '، ' . $billing_city;
                        if ($billing_state) {
                            $full_address .= '، ' . $billing_state;
                        }
                        if ($billing_postcode) {
                            $full_address .= '، کد پستی: ' . $billing_postcode;
                        }

                        $order_date = $order->get_date_created();
                        $order_date_formatted = $order_date ? $order_date->date_i18n('j F Y - H:i') : '—';

                        $post_tracking_link = 'https://tracking.post.ir/?id=' . urlencode($tracking_code);

                        $result_html  = '<div class="bwt-result success">';
                        $result_html .= '<h3>📦 اطلاعات سفارش شما</h3>';
                        $result_html .= '<div class="order-info">';
                        $result_html .= '<p><strong>👤 نام و نام خانوادگی:</strong> ' . esc_html($billing_first_name . ' ' . $billing_last_name) . '</p>';
                        $result_html .= '<p><strong>📍 آدرس:</strong> ' . esc_html($full_address) . '</p>';
                        $result_html .= '<p><strong>📅 تاریخ ثبت سفارش:</strong> ' . esc_html($order_date_formatted) . '</p>';
                        $result_html .= '<p><strong>🔢 شماره سفارش:</strong> ' . esc_html($order_id) . '</p>';
                        $result_html .= '<hr>';
                        $result_html .= '<p><strong>📮 کد رهگیری پستی:</strong></p>';
                        $result_html .= '<div class="tracking-code-box">' . esc_html($tracking_code) . '</div>';
                        $result_html .= '<p><a href="' . esc_url($post_tracking_link) . '" target="_blank" rel="noopener noreferrer" class="post-tracking-btn">🚚 پیگیری مرسوله در سایت پست</a></p>';
                        $result_html .= '</div></div>';
                    } else {
                        $result_html = '<div class="bwt-result error">'
                        . 'چنانچه در این بخش هنوز شماره مرسوله سفارش شما نمایش داده نشده است، به این معناست که سفارش در یکی از مراحل آماده‌سازی، تولید، بسته‌بندی یا ارسال قرار دارد و هنوز به شرکت حمل‌ونقل تحویل نشده است.<br><br>'
                        . 'زمان آماده‌سازی سفارش‌ها به شرح زیر است:<br>'
                        . 'کالاهای عادی: حداکثر ۷ روز کاری<br>'
                        . 'کالاهای چاپی و سفارشی: بین ۷ تا ۱۸ روز کاری (با توجه به فرآیند تولید)<br>'
                        . 'روزهای کاری صرفاً از شنبه تا چهارشنبه محاسبه می‌شوند و پنج‌شنبه، جمعه و تعطیلات رسمی جزو روزهای کاری محسوب نمی‌گردند.<br>'
                        . 'پس از تحویل سفارش به شرکت حمل‌ونقل، شماره مرسوله به‌صورت خودکار در همین صفحه ثبت و قابل مشاهده خواهد بود.<br><br>'
                        . 'خواهشمند است تا پیش از ثبت شماره مرسوله، از ارسال درخواست یا پیگیری جداگانه از طریق پیام خصوصی یا ارتباط با ادمین خودداری فرمایید؛ زیرا تمامی مراحل اطلاع‌رسانی و ثبت اطلاعات مرسوله از طریق همین صفحه انجام می‌شود.<br><br>'
                        . 'از شکیبایی و همراهی ارزشمند شما سپاسگزاریم.</div>';
                    }
                }
            }
        }
    }

    ?>
    <div class="bwt-tracking-container">
        <form method="post" class="bwt-tracking-form">
            <p>
                <label for="bwt_order_id">🔍 شماره سفارش خود را وارد کنید:</label>
                <input type="text" id="bwt_order_id" name="order_id" required placeholder="کد سفارش را به صورت لاتین وارد کنید.." value="<?php echo esc_attr($submitted_order_id); ?>" inputmode="numeric" autocomplete="off" />
            </p>
            <p>
                <label for="bwt_billing_phone">📱 شماره موبایلی که سفارش با آن ثبت شده:</label>
                <input type="text" id="bwt_billing_phone" name="billing_phone" required placeholder="مثال: ۰۹۱۲۳۴۵۶۷۸۹" value="<?php echo esc_attr($submitted_phone); ?>" inputmode="tel" autocomplete="off" />
            </p>
            <p>
                <button type="submit" name="tracking_submit">پیگیری سفارش</button>
            </p>
        </form>

        <?php echo wp_kses_post($result_html); ?>
    </div>

    <style>
    /* فرانت — بدون وابستگی به هاب؛ توکن‌ها اگر بودند، وگرنه fallback */
    .bwt-tracking-container{max-width:600px;margin:0 auto;padding:24px;background:var(--tisa-surface,#fff);border:1px solid var(--tisa-border,#E3E1DA);border-radius:var(--tisa-r,14px);font-variant-numeric:tabular-nums}
    .bwt-tracking-form label{display:block;margin-bottom:8px;font-weight:600;color:var(--tisa-ink,#1F2A2E)}
    .bwt-tracking-form input{width:100%;padding:12px;border:1px solid var(--tisa-border,#E3E1DA);border-radius:var(--tisa-r-sm,10px);font-size:16px;box-sizing:border-box;background:var(--tisa-surface,#fff);color:var(--tisa-ink,#1F2A2E)}
    .bwt-tracking-form input:focus{outline:none;border-color:var(--tisa-primary,#0E7C6B);box-shadow:0 0 0 3px var(--tisa-primary-soft,#E8F0EE)}
    .bwt-tracking-form button{width:100%;padding:12px 24px;border:0;border-radius:var(--tisa-r-sm,10px);background:var(--tisa-primary,#0E7C6B);color:var(--tisa-on-primary,#fff);font-size:16px;font-weight:600;cursor:pointer;transition:background .2s}
    .bwt-tracking-form button:hover{background:var(--tisa-primary-ink,#0A5F52)}
    .bwt-result{margin-top:24px;padding:20px;border-radius:var(--tisa-r-sm,10px);font-size:14px;line-height:1.8;border:1px solid var(--tisa-border,#E3E1DA);background:var(--tisa-surface-2,#FAFAF8);color:var(--tisa-ink,#1F2A2E)}
    .bwt-result.success{border-inline-start:3px solid var(--tisa-success,#1A7F37)}
    .bwt-result.error{border-inline-start:3px solid var(--tisa-danger,#B5453A);background:var(--tisa-danger-soft,#FBECEA);color:var(--tisa-danger-ink,#8E3128)}
    .bwt-result h3{margin:0 0 8px;font-size:16px}
    .order-info{margin-top:8px}
    .order-info p{margin:8px 0;font-size:15px}
    .order-info hr{border:0;border-top:1px dashed var(--tisa-border,#E3E1DA)}
    .tracking-code-box{margin:10px 0;padding:12px;border:1px dashed var(--tisa-border-strong,#C9DCD7);border-radius:var(--tisa-r-sm,10px);background:var(--tisa-surface,#fff);color:var(--tisa-ink,#1F2A2E);font-family:var(--tisa-mono,ui-monospace,Consolas,monospace);font-size:16px;font-weight:700;letter-spacing:1px;direction:ltr;text-align:center;word-break:break-all}
    .post-tracking-btn{display:inline-block;margin-top:10px;padding:10px 20px;border:1px solid var(--tisa-primary,#0E7C6B);border-radius:var(--tisa-r-sm,10px);background:transparent;color:var(--tisa-primary,#0E7C6B) !important;font-weight:600;text-decoration:none;transition:background .2s,color .2s}
    .post-tracking-btn:hover{background:var(--tisa-primary,#0E7C6B);color:var(--tisa-on-primary,#fff) !important;text-decoration:none}
    </style>
    <?php
    return ob_get_clean();
}

// ========== 5. صفحه پاک کردن کدها ==========
function bwt_cleanup_page() {
    if (!current_user_can('manage_options')) {
        wp_die('دسترسی غیر مجاز');
    }

    // پردازش قبل از نمایش فرم
    $message = '';
    if (isset($_POST['bwt_delete_all'])) {
        if (!isset($_POST['bwt_cleanup_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bwt_cleanup_nonce'])), 'bwt_cleanup_action')) {
            $message = '<div class="tisa-notice tisa-notice--danger bwt-notice"><p>خطای امنیتی: نانس نامعتبر.</p></div>';
        } else {
            $message = bwt_delete_all_tracking_codes();
        }
    } elseif (isset($_POST['bwt_delete_selected'])) {
        if (!isset($_POST['bwt_cleanup_nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['bwt_cleanup_nonce'])), 'bwt_cleanup_action')) {
            $message = '<div class="tisa-notice tisa-notice--danger bwt-notice"><p>خطای امنیتی: نانس نامعتبر.</p></div>';
        } elseif (empty($_POST['order_ids'])) {
            $message = '<div class="tisa-notice tisa-notice--danger bwt-notice"><p>لطفاً حداقل یک شماره سفارش وارد کنید.</p></div>';
        } else {
            $raw = sanitize_text_field(wp_unslash($_POST['order_ids']));
            // تبدیل اعداد فارسی به انگلیسی
            $raw = bwt_normalize_digits($raw);
            $parts = array_filter(array_map('trim', explode(',', $raw)));
            $order_ids = array();
            foreach ($parts as $p) {
                if (ctype_digit($p)) {
                    $order_ids[] = intval($p);
                }
            }
            if (empty($order_ids)) {
                $message = '<div class="tisa-notice tisa-notice--danger bwt-notice"><p>شماره سفارش معتبری یافت نشد.</p></div>';
            } else {
                $message = bwt_delete_selected_tracking_codes($order_ids);
            }
        }
    }

    ?>
    <div class="wrap tisa-wrap bwt-wrap" dir="rtl">
        <?php bwt_render_hero('cleanup'); ?>

        <?php echo wp_kses_post($message); ?>

        <form method="post" class="bwt-grid">
            <?php wp_nonce_field('bwt_cleanup_action', 'bwt_cleanup_nonce'); ?>

            <section class="bwt-card">
                <div class="bwt-card-head"><span class="bwt-dot"></span><div><h2>پاک کردن انتخابی</h2><p>کد رهگیری فقط از سفارش‌هایی که وارد می‌کنید حذف می‌شود.</p></div></div>
                <div class="bwt-card-body">
                    <label for="bwt_order_ids" class="bwt-label">شماره سفارش‌ها (با کاما جدا کنید)</label>
                    <input type="text" id="bwt_order_ids" name="order_ids" class="tisa-input bwt-ids" dir="ltr" inputmode="numeric" placeholder="123,124,125" />
                    <div class="bwt-actions">
                        <button type="submit" name="bwt_delete_selected" class="tisa-btn tisa-btn--secondary" onclick="return confirm('کد رهگیری سفارش‌های انتخابی پاک میشه؟')">پاک کردن انتخابی</button>
                    </div>
                </div>
            </section>

            <section class="bwt-card bwt-card--danger">
                <div class="bwt-card-head"><span class="bwt-dot bwt-dot--bad"></span><div><h2>پاک کردن همهٔ کدها</h2><p>غیرقابل بازگشت — از همهٔ سفارش‌ها. قبل از انجام بکاپ بگیرید.</p></div></div>
                <div class="bwt-card-body">
                    <div class="bwt-actions">
                        <button type="submit" name="bwt_delete_all" class="tisa-btn tisa-btn--danger" onclick="return confirm('همه کدهای رهگیری پاک می‌شه! مطمئنی؟ این کار برگشت نداره!')">پاک کردن همه کدهای رهگیری</button>
                    </div>
                </div>
            </section>
        </form>
    </div>
    <?php
}

function bwt_delete_all_tracking_codes() {
    if (!current_user_can('manage_options')) {
        return '<div class="tisa-notice tisa-notice--danger bwt-notice"><p>دسترسی غیر مجاز.</p></div>';
    }
    global $wpdb;
    // حذف از postmeta
    $deleted = $wpdb->delete(
        $wpdb->postmeta,
        array('meta_key' => '_tracking_code'),
        array('%s')
    );
    // برای HPOS (جدول سفارشات جدید) هم پاک کن اگر وجود داشت
    $orders_table = $wpdb->prefix . 'wc_orders_meta';
    $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $orders_table));
    $deleted_hpos = 0;
    if ($table_exists === $orders_table) {
        $deleted_hpos = $wpdb->delete($orders_table, array('meta_key' => '_tracking_code'), array('%s'));
    }
    $total = intval($deleted) + intval($deleted_hpos);
    if ($total > 0) {
        return '<div class="tisa-notice tisa-notice--success bwt-notice"><p><strong>' . esc_html($total) . '</strong> کد رهگیری با موفقیت حذف شد.</p></div>';
    }
    return '<div class="tisa-notice tisa-notice--warning bwt-notice"><p>کدی برای حذف یافت نشد.</p></div>';
}

function bwt_delete_selected_tracking_codes($order_ids) {
    if (!current_user_can('manage_options')) {
        return '<div class="tisa-notice tisa-notice--danger bwt-notice"><p>دسترسی غیر مجاز.</p></div>';
    }
    $order_ids = array_map('intval', $order_ids);
    $order_ids = array_unique(array_filter($order_ids));

    $deleted = 0;
    $not_found = 0;
    $invalid = 0;
    $details = array();
    
    foreach ($order_ids as $order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            $invalid++;
            $details[] = '#' . $order_id . ' (سفارش یافت نشد)';
            continue;
        }
        $meta_exists = get_post_meta($order_id, '_tracking_code', true);
        if (empty($meta_exists) && method_exists($order, 'get_meta')) {
            $meta_exists = $order->get_meta('_tracking_code');
        }
        if ($meta_exists) {
            $ok1 = delete_post_meta($order_id, '_tracking_code');
            $ok2 = false;
            if (method_exists($order, 'delete_meta_data')) {
                $order->delete_meta_data('_tracking_code');
                $ok2 = $order->save();
            }
            if ($ok1 || $ok2) {
                $deleted++;
            } else {
                $not_found++;
            }
        } else {
            $not_found++;
            $details[] = '#' . $order_id . ' (کد نداشت)';
        }
    }
    
    $msg = '<div class="tisa-notice tisa-notice--success bwt-notice"><p><strong>' . esc_html($deleted) . '</strong> کد رهگیری حذف شد. ';
    if ($not_found > 0) {
        $msg .= '<strong>' . esc_html($not_found) . '</strong> سفارش کد رهگیری نداشت. ';
    }
    if ($invalid > 0) {
        $msg .= '<strong>' . esc_html($invalid) . '</strong> شماره نامعتبر بود.';
    }
    $msg .= '</p>';
    if (!empty($details) && ($not_found + $invalid) > 0) {
        $msg .= '<p class="bwt-hint">جزئیات: ' . esc_html(implode('، ', array_slice($details, 0, 20))) . (count($details) > 20 ? ' و...' : '') . '</p>';
    }
    $msg .= '</div>';
    return $msg;
}