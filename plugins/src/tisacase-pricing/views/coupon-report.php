<?php
defined( 'ABSPATH' ) || exit;
if ( ! TCP_Settings::can() || ! $tcp_edit ) { echo '<p>کوپن معتبر نیست یا دسترسی ندارید.</p>'; return; }
$tcp_rc = new WC_Coupon( $tcp_edit['id'] );
$tcp_rs = $tcp_rc->get_meta( '_tcp_report_sync' );
?>
<section class="tcp-card" id="tcp-coupon-report" data-id="<?php echo esc_attr( $tcp_rc->get_id() ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( TCP_Coupon_Reports::ACTION ) ); ?>" data-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>گزارش اختصاصی «<?php echo esc_html( $tcp_rc->get_code() ); ?>»</h2><p>اطلاعات مشتری، مبالغ و وضعیت فعلی مستقیماً از سفارش خوانده می‌شود؛ سفارش پرداخت‌نشده، مصرف موفق نیست.</p></div></div>
<div class="tcp-card-body">
<p class="tcp-phone-notice">گزارش فقط سفارش‌های ثبت‌شده را نشان می‌دهد، نه واردکردن کد در سبد بدون ثبت سفارش. <span id="tcp-report-sync-state"><?php echo empty( $tcp_rs['done'] ) ? 'نمایه سوابق قدیمی هنوز کامل نشده است؛ همگام‌سازی را اجرا کنید.' : 'سوابق قبلی همگام شده‌اند؛ سفارش‌های جدید در مسیر استاندارد ووکامرس خودکار اضافه می‌شوند.'; ?></span> همگام‌سازی این گزارش مستقل از سهمیه است و کوپن را قفل یا ریست نمی‌کند.</p>
<div class="tcp-actions">
<button type="button" class="button" id="tcp-report-sync" data-restart="<?php echo empty( $tcp_rs['done'] ) ? '0' : '1'; ?>">همگام‌سازی / بازبینی سوابق گزارش</button>
<button type="button" class="button" id="tcp-report-stop" hidden>توقف پس از دسته جاری</button>
<button type="button" class="button" id="tcp-report-refresh">تازه‌سازی الآن</button>
<label><input type="checkbox" id="tcp-report-auto" checked> تازه‌سازی خودکار هر ۳۰ ثانیه</label>
</div>
<form id="tcp-report-filter" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="tcp-actions">
<input type="hidden" name="page" value="<?php echo esc_attr( TCP_Settings::MAIN_PAGE ); ?>"><input type="hidden" name="tab" value="coupons"><input type="hidden" name="panel" value="report"><input type="hidden" name="edit" value="<?php echo esc_attr( $tcp_rc->get_id() ); ?>">
<label>شناسه سفارش <input type="number" name="order_id" min="1" value="<?php echo esc_attr( absint( $_GET['order_id'] ?? 0 ) ?: '' ); ?>" placeholder="همه سفارش‌ها"></label><button class="button">نمایش</button>
</form>
<p id="tcp-report-status" role="status" aria-live="polite"></p>
<div id="tcp-report-content"><?php try { echo TCP_Coupon_Reports::render( $tcp_rc, absint( $_GET['report_page'] ?? 1 ), absint( $_GET['order_id'] ?? 0 ) ); } catch ( Exception $e ) { echo '<p>' . esc_html( $e->getMessage() ) . '</p>'; } ?></div>
<noscript><p>برای تازه‌سازی خودکار و همگام‌سازی، JavaScript را فعال کنید؛ با بارگذاری مجدد صفحه، جزئیات فعلی سفارش‌ها خوانده می‌شوند.</p></noscript>
</div></section>
