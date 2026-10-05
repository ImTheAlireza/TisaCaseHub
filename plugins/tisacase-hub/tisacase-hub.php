<?php
/**
 * Plugin Name:          اختصاصی تیساکیس (TisaCase Hub)
 * Plugin URI:           https://tisacase.com/
 * Description:           منوی واحد برای همهٔ افزونه‌های اختصاصی، تزریق زبان طراحی مشترک در صفحات آن‌ها، و پایش سلامت/امنیت. افزونه‌ها را اجرا، فعال/غیرفعال و یکدست می‌کند.
 * Version:              1.9.11
 * Requires at least:    5.8
 * Requires PHP:         7.4
 * Author:               TisaCase
 * Author URI:           https://tisacase.com/
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          tisacase-hub
 * Domain Path:          /languages
 * TisaCase Hub:         title="اختصاصی تیساکیس"; icon=grid; self=yes
 *
 * @package TisaCase_Hub
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'TSH_VERSION' ) ) {
	define( 'TSH_VERSION', '1.9.11' );
}
if ( ! defined( 'TSH_FILE' ) ) {
	define( 'TSH_FILE', __FILE__ );
}
if ( ! defined( 'TSH_DIR' ) ) {
	define( 'TSH_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'TSH_URL' ) ) {
	define( 'TSH_URL', plugin_dir_url( __FILE__ ) );
}

/** کلیدِ تنظیمات هاب. */
if ( ! defined( 'TSH_OPTION' ) ) {
	define( 'TSH_OPTION', 'tisacase_hub_settings' );
}
/** متای کاربر برای سنجاق‌ها. */
if ( ! defined( 'TSH_META_PINS' ) ) {
	define( 'TSH_META_PINS', 'tisacase_hub_pins' );
}
/** اسلگ صفحهٔ اصلی هاب (برای شناسایی اسکرین). */
if ( ! defined( 'TSH_SLUG' ) ) {
	define( 'TSH_SLUG', 'tisacase-hub' );
}

require_once TSH_DIR . 'includes/class-tsh-view.php';
require_once TSH_DIR . 'includes/class-tsh-registry.php';
require_once TSH_DIR . 'includes/class-tsh-remote.php';
require_once TSH_DIR . 'includes/class-tsh-ui.php';
require_once TSH_DIR . 'includes/class-tsh-admin.php';
require_once TSH_DIR . 'includes/tpl-card.php';

/**
 * نسخهٔ استایل/اسکریپت. در حالت دیباگ با زمانِ آخرین تغییر فایل تا می‌شود تا کش اذیت نکند.
 *
 * @param string $rel مسیر نسبی فایل از ریشهٔ افزونه.
 * @return string
 */
function tsh_ver( $rel = '' ) {
	$ver = TSH_VERSION;
	if ( ( defined( 'WP_DEBUG' ) && WP_DEBUG ) || ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) ) {
		$file = $rel ? TSH_DIR . $rel : TSH_FILE;
		if ( is_readable( (string) $file ) ) {
			$ver .= '.' . substr( (string) filemtime( (string) $file ), -6 );
		}
	}
	return $ver;
}

/**
 * هدر سفارشی را به وردپرس معرفی می‌کند تا `get_plugins()` آن را برگرداند
 * (بدون این فیلتر، `TisaCase Hub:` در دادهٔ افزونه‌ها دیده نمی‌شود).
 * باید همین‌جا و پیش از هر فراخوانی get_plugins() ثبت شود.
 *
 * @param array $headers هدرهای مجاز.
 * @return array
 */
function tsh_extra_plugin_headers( $headers ) {
	$headers[] = 'TisaCase Hub';
	return $headers;
}
add_filter( 'extra_plugin_headers', 'tsh_extra_plugin_headers' );

add_action( 'init', 'tsh_load_textdomain' );
/**
 * بارگذاری متن‌ها (بستهٔ زبانی اختیاری است).
 *
 * @return void
 */
function tsh_load_textdomain() {
	load_plugin_textdomain( 'tisacase-hub', false, dirname( plugin_basename( TSH_FILE ) ) . '/languages' );
}

register_activation_hook( TSH_FILE, array( 'TSH_Admin', 'activate' ) );
register_deactivation_hook( TSH_FILE, array( 'TSH_Admin', 'deactivate' ) );

if ( is_admin() ) {
	TSH_UI::init();
	TSH_Admin::init();
}
