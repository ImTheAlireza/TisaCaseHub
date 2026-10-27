<?php
/**
 * پاک‌سازی هنگام حذف افزونهٔ «نوار پیشوند SKU».
 *
 * کش نهایی/موقت SKU و گزینهٔ generation افزونه پاک می‌شوند.
 * این افزونه چیزی روی محصولات نمی‌نویسد (SKUها دادهٔ خود ووکامرس‌اند).
 *
 * @package WC_SKU_Prefix_Bar
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_transient( 'wcspb_latest_skus' );
delete_transient( 'wcspb_latest_skus_v2' );
delete_transient( 'wcspb_sku_summary_build' );
delete_option( 'wcspb_sku_summary_generation' );
delete_option( 'wcspb_sku_summary_lock' );
