<?php
/**
 * Plugin Name:          Bulk Product Cleaner (Drafts + Images)
 * Plugin URI:           https://example.com/bulk-product-cleaner
 * Description:          حذف دسته‌جمعی محصولات پیش‌نویس همراه با تصاویر، به‌همراه پشتیبان‌گیری کامل و بازیابی تا ۹۰ روز.
 * Version:              12.6.3
 * Requires at least:    6.0
 * Requires PHP:         7.4
 * Author:               علیرضا شعبان زاده
 * Author URI:           https://example.com
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          bulk-product-cleaner
 * Domain Path:          /languages
 * WC requires at least: 7.0
 * WC tested up to:      9.5
 *
 * @package BulkProductCleaner
 */

defined( 'ABSPATH' ) || exit;

define( 'BDC_VERSION', '12.6.3' );
define( 'BDC_FILE', __FILE__ );
define( 'BDC_DIR', plugin_dir_path( __FILE__ ) );
define( 'BDC_URL', plugin_dir_url( __FILE__ ) );
define( 'BDC_BASENAME', plugin_basename( __FILE__ ) );

/** Backup retention in days. Filterable via `bdc_backup_retention_days`. */
define( 'BDC_RETENTION_DAYS', 90 );

/**
 * Lazy autoloader.
 *
 * Only the bootstrap class is parsed on a normal page view; the heavy classes
 * (admin UI, backup engine, query builder) are loaded the moment they are
 * actually referenced, which on a front-end request is never.
 *
 * @param string $class Class name being resolved.
 * @return void
 */
function bdc_autoload( $class ) {
	static $map = array(
		'BDC_Install' => 'class-bdc-install.php',
		'BDC_Query'   => 'class-bdc-query.php',
		'BDC_Backup'  => 'class-bdc-backup.php',
		'BDC_Deleter' => 'class-bdc-deleter.php',
		'BDC_Ajax'    => 'class-bdc-ajax.php',
		'BDC_Admin'   => 'class-bdc-admin.php',
		'BDC_Plugin'  => 'class-bdc-plugin.php',
		'BDC_CLI'     => 'class-bdc-cli.php',
	);

	if ( isset( $map[ $class ] ) ) {
		require_once BDC_DIR . 'includes/' . $map[ $class ];
	}
}

spl_autoload_register( 'bdc_autoload' );

register_activation_hook( __FILE__, array( 'BDC_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BDC_Install', 'deactivate' ) );

/**
 * Declare WooCommerce feature compatibility (HPOS / custom order tables).
 */
add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', BDC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', BDC_FILE, true );
		}
	}
);

/**
 * Main plugin accessor.
 *
 * @return BDC_Plugin
 */
function bdc() {
	return BDC_Plugin::instance();
}

/**
 * Boots the plugin defensively.
 *
 * A shop's checkout must never go down because a maintenance tool threw. Any
 * unexpected failure here is logged and swallowed: the plugin disables itself
 * for the request and WordPress carries on untouched.
 *
 * @return void
 */
function bdc_boot() {
	try {
		bdc();
	} catch ( Throwable $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Bulk Product Cleaner failed to boot: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}

		if ( is_admin() ) {
			add_action(
				'admin_notices',
				static function () use ( $e ) {
					if ( ! current_user_can( 'activate_plugins' ) ) {
						return;
					}
					echo '<div class="notice notice-error"><p><strong>'
						. esc_html__( 'حذف انبوه پیش‌نویس', 'bulk-product-cleaner' )
						. '</strong> — '
						. esc_html__( 'افزونه به دلیل خطایی غیرفعال شد. بقیه سایت تحت تأثیر قرار نگرفته است.', 'bulk-product-cleaner' )
						. ' <code>' . esc_html( $e->getMessage() ) . '</code></p></div>';
				}
			);
		}
	}
}

add_action( 'plugins_loaded', 'bdc_boot', 20 );
