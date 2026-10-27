<?php
/**
 * Plugin Name:       TisaCase — قوانین توضیحات محصول
 * Plugin URI:        https://tisachap.com
 * Description:       اعمال خودکار قوانین توضیحات محصولات (محصولات چاپی و قاب گوشی) در ووکامرس، همراه با پنل مدیریت شیک و ابزار اصلاح انبوه.
 * Version:           1.6.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 6.0
 * Author:            TisaCase
 * Author URI:        https://tisachap.com
 * Text Domain:       tisacase-desc
 * Domain Path:       /languages
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 *
 * Converted from the original Code Snippets snippet into a full WooCommerce plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TISACASE_DESC_VERSION', '1.6.2' );
define( 'TISACASE_DESC_FILE', __FILE__ );
define( 'TISACASE_DESC_PATH', plugin_dir_path( __FILE__ ) );
define( 'TISACASE_DESC_URL', plugin_dir_url( __FILE__ ) );

require_once TISACASE_DESC_PATH . 'includes/class-tisacase-desc-core.php';
require_once TISACASE_DESC_PATH . 'includes/class-tisacase-desc-backup.php';
require_once TISACASE_DESC_PATH . 'includes/class-tisacase-desc-admin.php';

/**
 * Bootstrap everything after all plugins (including WooCommerce) are loaded.
 */
function tisacase_desc_init() {
	load_plugin_textdomain( 'tisacase-desc', false, dirname( plugin_basename( TISACASE_DESC_FILE ) ) . '/languages' );

	TisaCase_Desc_Core::init();

	if ( is_admin() || wp_doing_ajax() ) {
		TisaCase_Desc_Admin::init();
	}

	if ( defined( 'WP_CLI' ) && WP_CLI ) {
		require_once TISACASE_DESC_PATH . 'includes/class-tisacase-desc-cli.php';
	}
}
add_action( 'plugins_loaded', 'tisacase_desc_init' );

/**
 * Make sure default options exist right after activation.
 */
register_activation_hook( __FILE__, 'tisacase_desc_activate' );
function tisacase_desc_activate() {
	if ( false === get_option( TisaCase_Desc_Core::OPTION ) ) {
		update_option( TisaCase_Desc_Core::OPTION, TisaCase_Desc_Core::defaults() );
	}
	update_option( 'tisacase_desc_version', TISACASE_DESC_VERSION );
}

/**
 * One-time upgrades for existing installs.
 */
function tisacase_desc_upgrade() {
	$stored = (string) get_option( 'tisacase_desc_version', '1.0.0' );

	if ( version_compare( $stored, '1.2.0', '<' ) ) {
		// Since v1.2.0, products that match no rule are left untouched by default.
		$opts = get_option( TisaCase_Desc_Core::OPTION, array() );
		if ( is_array( $opts ) ) {
			$opts['clear_unmatched'] = 0;
			update_option( TisaCase_Desc_Core::OPTION, $opts );
		}
		update_option( 'tisacase_desc_version', TISACASE_DESC_VERSION );
	}
}
add_action( 'plugins_loaded', 'tisacase_desc_upgrade', 15 );
