<?php
/** Regression checks for conditional WebP/AVIF delivery. */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['tco_test_uploads'] = sys_get_temp_dir() . '/tco-webp-delivery-' . getmypid();
$GLOBALS['tco_test_options'] = array();
$GLOBALS['tco_test_htaccess_fail'] = false;
$GLOBALS['tco_test_pass'] = 0;
$GLOBALS['tco_test_fail'] = 0;

if ( ! is_dir( $GLOBALS['tco_test_uploads'] ) ) {
	mkdir( $GLOBALS['tco_test_uploads'], 0700, true );
}

function tco_test( $name, $condition ) {
	if ( $condition ) {
		$GLOBALS['tco_test_pass']++;
		echo "  OK   {$name}\n";
	} else {
		$GLOBALS['tco_test_fail']++;
		echo "  FAIL {$name}\n";
	}
}
function wp_upload_dir() { return array( 'basedir' => $GLOBALS['tco_test_uploads'], 'baseurl' => 'https://example.test/uploads' ); }
function trailingslashit( $value ) { return rtrim( (string) $value, '/\\' ) . '/'; }
function wp_normalize_path( $path ) { return str_replace( '\\', '/', (string) $path ); }
function esc_url( $url ) { return htmlspecialchars( (string) $url, ENT_QUOTES, 'UTF-8' ); }
function __( $text, $domain = null ) { return $text; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\\-]/', '', strtolower( (string) $key ) ); }
function sanitize_text_field( $text ) { return trim( strip_tags( (string) $text ) ); }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['tco_test_options'] ) ? $GLOBALS['tco_test_options'][ $key ] : $default; }
function update_option( $key, $value, $autoload = null ) { $GLOBALS['tco_test_options'][ $key ] = $value; return true; }
function insert_with_markers( $file, $marker, $lines ) {
	if ( $GLOBALS['tco_test_htaccess_fail'] ) { return false; }
	$content = is_file( $file ) ? file_get_contents( $file ) : '';
	$begin = '# BEGIN ' . $marker;
	$end = '# END ' . $marker;
	$pattern = '/' . preg_quote( $begin, '/' ) . '.*?' . preg_quote( $end, '/' ) . '\\s*/s';
	$content = preg_replace( $pattern, '', $content );
	if ( $lines ) {
		$content = rtrim( $content ) . "\n" . $begin . "\n" . implode( "\n", $lines ) . "\n" . $end . "\n";
	}
	return false !== file_put_contents( $file, $content );
}

class TisaCase_Optimizer_Sidecar_Index {
	public static $files = array();
	public static function has( $rel, $ext = 'webp' ) { return ! empty( self::$files[ $rel ][ $ext ] ); }
}

require_once dirname( __DIR__ ) . '/includes/class-tco-webp-delivery.php';
require_once dirname( __DIR__ ) . '/includes/class-tco-settings.php';

TisaCase_Optimizer_Sidecar_Index::$files['2026/10/case.jpg'] = array( 'avif' => true, 'webp' => true );
$html = '<img class="product" src="https://example.test/uploads/2026/10/case.jpg?ver=2" alt="case">';
$rewritten = TisaCase_Optimizer_WebP_Delivery::rewrite_html_images( $html );
tco_test( 'تنها تصویر مستقل با sidecar به picture تبدیل می‌شود', substr_count( $rewritten, '<picture>' ) === 1 && strpos( $rewritten, 'type="image/avif"' ) !== false && strpos( $rewritten, 'type="image/webp"' ) !== false );
tco_test( 'query string به URL فایل جانبی منتقل می‌شود', strpos( $rewritten, 'case.jpg.avif?ver=2' ) !== false && strpos( $rewritten, 'case.jpg.webp?ver=2' ) !== false );
$quoted_gt = '<img alt="2 > 1" src="https://example.test/uploads/2026/10/case.jpg">';
tco_test( 'علامت > داخل مقدار attribute تگ img را نمی‌شکند', substr_count( TisaCase_Optimizer_WebP_Delivery::rewrite_html_images( $quoted_gt ), '<picture>' ) === 1 );

$existing_picture = '<picture><source media="(max-width: 600px)" srcset="mobile.jpg"><img src="https://example.test/uploads/2026/10/case.jpg" alt="case"></picture>';
tco_test( 'picture موجود دست‌نخورده می‌ماند و wrapper تو‌در‌تو نمی‌گیرد', TisaCase_Optimizer_WebP_Delivery::rewrite_html_images( $existing_picture ) === $existing_picture && substr_count( TisaCase_Optimizer_WebP_Delivery::rewrite_html_images( $existing_picture ), '<picture' ) === 1 );

$protected = '<img data-no-webp src="https://example.test/uploads/2026/10/case.jpg">';
tco_test( 'data-no-webp همچنان مسیر استثناست', TisaCase_Optimizer_WebP_Delivery::rewrite_html_images( $protected ) === $protected );
$external = '<img src="https://cdn.example.test/2026/10/case.jpg">';
tco_test( 'URL خارج از uploads بازنویسی نمی‌شود', TisaCase_Optimizer_WebP_Delivery::rewrite_html_images( $external ) === $external );
$script = '<script>const html = \'<img src="https://example.test/uploads/2026/10/case.jpg">\';</script>';
tco_test( 'تگ شبیه تصویر داخل script دست‌نخورده می‌ماند', TisaCase_Optimizer_WebP_Delivery::rewrite_html_images( $script ) === $script );
$script_gt = '<script data-value=">">const html = \'<img src="https://example.test/uploads/2026/10/case.jpg">\';</script>';
tco_test( 'علامت > در ویژگی script محدودهٔ محتوا را نمی‌شکند', TisaCase_Optimizer_WebP_Delivery::rewrite_html_images( $script_gt ) === $script_gt );
$comment = '<!-- <img src="https://example.test/uploads/2026/10/case.jpg"> -->';
tco_test( 'تگ تصویر داخل comment دست‌نخورده می‌ماند', TisaCase_Optimizer_WebP_Delivery::rewrite_html_images( $comment ) === $comment );
$style = '<style>.x:after{content:"<img src=\\"https://example.test/uploads/2026/10/case.jpg\\">"}</style>';
tco_test( 'تگ شبیه تصویر داخل style دست‌نخورده می‌ماند', TisaCase_Optimizer_WebP_Delivery::rewrite_html_images( $style ) === $style );

$rules = TisaCase_Optimizer_WebP_Delivery::htaccess_rules();
$rule_text = implode( "\n", $rules );
tco_test( 'Vary: Accept با merge تنظیم می‌شود، نه متغیر محیطی نامطمئن', strpos( $rule_text, 'Header merge Vary Accept' ) !== false && strpos( $rule_text, 'REDIRECT_accept' ) === false );
function tco_test_accepts_format( $rules, $accept, $format ) {
	$positive = false;
	$negative = '';
	foreach ( $rules as $line ) {
		if ( 0 === strpos( $line, 'RewriteCond %{HTTP_ACCEPT} ' . $format . ' ' ) ) {
			$positive = true;
		}
		$prefix = 'RewriteCond %{HTTP_ACCEPT} !';
		if ( 0 === strpos( $line, $prefix ) && false !== stripos( $line, $format ) ) {
			$negative = substr( $line, strlen( $prefix ), -5 );
		}
	}
	return $positive && '' !== $negative && 1 === preg_match( '~' . preg_quote( $format, '~' ) . '~i', $accept ) && 0 === preg_match( '~' . $negative . '~i', $accept );
}
tco_test( 'q=0 قالب AVIF واقعاً از negotiation کنار می‌رود', ! tco_test_accepts_format( $rules, 'image/avif;q=0', 'image/avif' ) );
tco_test( 'q=0 با پارامتر بعدی هم قالب را از negotiation کنار می‌برد', ! tco_test_accepts_format( $rules, 'image/avif;q=0;foo=bar', 'image/avif' ) );
tco_test( 'q=0.000 قالب WebP واقعاً از negotiation کنار می‌رود', ! tco_test_accepts_format( $rules, 'image/webp; q=0.000', 'image/webp' ) );
tco_test( 'فرمت با q مثبت پذیرفته می‌شود', tco_test_accepts_format( $rules, 'image/avif;q=0.5,image/webp;q=0.8', 'image/avif' ) && tco_test_accepts_format( $rules, 'image/avif;q=0.5,image/webp;q=0.8', 'image/webp' ) );
tco_test( 'فرمت بدون پارامتر q پذیرفته می‌شود', tco_test_accepts_format( $rules, 'image/webp', 'image/webp' ) );

$htaccess = $GLOBALS['tco_test_uploads'] . '/.htaccess';
file_put_contents( $htaccess, "# existing rules\nRewriteEngine On\n" );
tco_test( 'قوانین htaccess نصب می‌شوند و Vary در فایل واقعی حاضر است', TisaCase_Optimizer_WebP_Delivery::sync_htaccess( true ) && strpos( file_get_contents( $htaccess ), 'Header merge Vary Accept' ) !== false );
$GLOBALS['tco_test_htaccess_fail'] = true;
tco_test( 'شکست پاک‌سازی marker به‌عنوان شکست گزارش می‌شود', ! TisaCase_Optimizer_WebP_Delivery::sync_htaccess( false ) && strpos( file_get_contents( $htaccess ), TisaCase_Optimizer_WebP_Delivery::HTACCESS_MARKER ) !== false );
$GLOBALS['tco_test_htaccess_fail'] = false;
tco_test( 'غیرفعال‌سازی فقط بلوک افزونه را حذف می‌کند', TisaCase_Optimizer_WebP_Delivery::sync_htaccess( false ) && strpos( file_get_contents( $htaccess ), '# existing rules' ) !== false && strpos( file_get_contents( $htaccess ), TisaCase_Optimizer_WebP_Delivery::HTACCESS_MARKER ) === false );
@unlink( $htaccess );
tco_test( 'غیرفعال‌سازی فایل تازه نمی‌سازد', TisaCase_Optimizer_WebP_Delivery::sync_htaccess( false ) && ! file_exists( $htaccess ) );
$uploads_dir = $GLOBALS['tco_test_uploads'];
$GLOBALS['tco_test_uploads'] = $uploads_dir . '-missing';
tco_test( 'اگر پوشهٔ uploads وجود ندارد، پاک‌سازی بی‌خطر موفق است اما فعال‌سازی رد می‌شود', TisaCase_Optimizer_WebP_Delivery::sync_htaccess( false ) && ! TisaCase_Optimizer_WebP_Delivery::sync_htaccess( true ) );
$GLOBALS['tco_test_uploads'] = $uploads_dir;

$GLOBALS['tco_test_htaccess_fail'] = true;
$settings = TisaCase_Optimizer_Settings::update( array( 'delivery_method' => 'htaccess' ) );
tco_test( 'اگر نوشتن htaccess شکست بخورد تنظیم روی picture می‌افتد', $settings['delivery_method'] === 'picture' && get_option( TisaCase_Optimizer_Settings::OPTION_SETTINGS )['delivery_method'] === 'picture' );
tco_test( 'هشدار شکست روش تحویل برای رابط ذخیره می‌شود', '' !== TisaCase_Optimizer_Settings::delivery_warning() );
$settings = TisaCase_Optimizer_Settings::update( array( 'per_page' => 35 ) );
tco_test( 'ذخیرهٔ تنظیمات نامرتبط روش تحویل را دوباره همگام نمی‌کند', 35 === $settings['per_page'] && '' === TisaCase_Optimizer_Settings::delivery_warning() );
$GLOBALS['tco_test_htaccess_fail'] = false;
$settings = TisaCase_Optimizer_Settings::update( array( 'delivery_method' => 'htaccess' ) );
tco_test( 'در صورت نوشتن موفق، روش htaccess فعال می‌ماند و هشدار پاک است', $settings['delivery_method'] === 'htaccess' && '' === TisaCase_Optimizer_Settings::delivery_warning() );
$settings = TisaCase_Optimizer_Settings::update( array( 'delivery_method' => 'none' ) );
tco_test( 'روش none حفظ و قوانین خودکار پاک می‌شوند', $settings['delivery_method'] === 'none' && false === strpos( file_get_contents( $htaccess ), TisaCase_Optimizer_WebP_Delivery::HTACCESS_MARKER ) );

foreach ( glob( $GLOBALS['tco_test_uploads'] . '/*' ) as $file ) { if ( is_file( $file ) ) { unlink( $file ); } }
if ( is_file( $htaccess ) ) { unlink( $htaccess ); }
@rmdir( $GLOBALS['tco_test_uploads'] );
echo "نتیجه: {$GLOBALS['tco_test_pass']} موفق، {$GLOBALS['tco_test_fail']} ناموفق\n";
exit( $GLOBALS['tco_test_fail'] === 0 ? 0 : 1 );
