<?php
defined( 'ABSPATH' ) || exit;
$tcp_pc = new WC_Coupon( $tcp_edit['id'] );
$tcp_sync = $tcp_pc->get_meta( '_tcp_phone_sync' );
$tcp_hp = max( 1, absint( $_GET['history_page'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
$tcp_history = TCP_Coupon_Phones::history( $tcp_pc->get_id(), $tcp_hp );
$tcp_action_fields = static function () use ( $tcp_pc ) {
	wp_nonce_field( TCP_Coupon_Phones::ACTION );
	echo '<input type="hidden" name="action" value="' . esc_attr( TCP_Coupon_Phones::ACTION ) . '"><input type="hidden" name="coupon_id" value="' . esc_attr( $tcp_pc->get_id() ) . '">';
};
?>
<section class="tcp-card tcp-field">
	<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>سوابق و آزادسازی سهمیه این کد</h2><p>مصرف هر سفارش فقط یک بار ثبت می‌شود. تغییر وضعیت دوباره یا همگام‌سازی، ریست را خنثی نمی‌کند. بازپرداخت سهمیه را خودکار آزاد نمی‌کند؛ در صورت نیاز دستی ریست کنید.</p></div></div>
	<div class="tcp-card-body">
		<?php if ( isset( $_GET['phone_saved'] ) ) : ?><p role="status" class="tcp-phone-notice">عملیات انجام شد.</p><?php endif; ?>
		<?php if ( empty( $tcp_sync['done'] ) ) : ?>
		<p role="status" class="tcp-phone-notice"><strong>کد تا پایان بررسی سابقه، موقتاً قابل استفاده نیست.</strong> هر بار ۱۰۰ سفارش بررسی می‌شود. دسته بعدی: <?php echo esc_html( $tcp_sync['page'] ?? 1 ); ?>. با شروع، دسته‌ها خودکار بررسی می‌شوند؛ تا پایان این صفحه را باز نگه دارید. بعد از قطع اتصال، ادامه از دسته ذخیره‌شده ممکن است.</p>
		<?php else : ?><p>✓ همگام‌سازی کامل شد. مصرف زنده سفارش‌های جدید خودکار ثبت می‌شود.</p><?php endif; ?>
		<form method="post" class="tcp-phone-sync" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php $tcp_action_fields(); ?><input type="hidden" name="operation" value="sync">
			<button class="button button-secondary"><?php echo empty( $tcp_sync['done'] ) ? 'شروع / ادامه همگام‌سازی' : 'بررسی مجدد سوابق از ابتدا'; ?></button>
			<button type="button" class="button tcp-phone-sync-stop" hidden>توقف بعد از دسته جاری</button>
			<span class="tcp-phone-sync-status" role="status" aria-live="polite"></span>
		</form>
		<?php if ( ! empty( $tcp_sync['done'] ) ) : ?>
		<div class="tcp-grid-3 tcp-field">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('سهمیه این شماره در همین کوپن آزاد شود؟');">
				<?php $tcp_action_fields(); ?><input type="hidden" name="operation" value="reset_one">
				<label class="tcp-stack">ریست یک شماره<input class="tisa-input" name="phone" dir="ltr" inputmode="tel" required placeholder="09123456789"></label>
				<button class="button tcp-field">آزادسازی این شماره</button>
			</form>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('سهمیه تمام شماره‌ها و ظرفیت کل همین کوپن آزاد شود؟ سوابق پاک نمی‌شوند.');">
				<?php $tcp_action_fields(); ?><input type="hidden" name="operation" value="reset_all">
				<p>این عملیات فقط همین کوپن را ریست می‌کند.</p><button class="button">آزادسازی همه شماره‌ها</button>
			</form>
		</div>
		<?php endif; ?>
		<div class="tcp-phone-table tcp-field"><table class="widefat striped">
			<thead><tr><th>شماره</th><th>سفارش</th><th>زمان خرید</th><th>سهمیه</th><th>زمان ریست / مدیر</th></tr></thead><tbody>
			<?php foreach ( $tcp_history as $tcp_use ) : $tcp_order = wc_get_order( $tcp_use->order_id ); ?>
			<tr><td dir="ltr"><?php echo esc_html( $tcp_use->phone ); ?></td><td><?php if ( $tcp_order ) : ?><a href="<?php echo esc_url( $tcp_order->get_edit_order_url() ); ?>">#<?php echo esc_html( $tcp_use->order_id ); ?></a><?php else : echo esc_html( '#' . $tcp_use->order_id ); endif; ?></td>
			<td><?php echo esc_html( wp_date( 'Y-m-d H:i', $tcp_use->used_at ) ); ?></td><td><?php echo $tcp_use->released ? 'آزادشده با ریست' : 'مصرف‌شده'; ?></td>
			<td><?php echo $tcp_use->reset_at ? esc_html( wp_date( 'Y-m-d H:i', $tcp_use->reset_at ) . ' / #' . $tcp_use->reset_by ) : '—'; ?></td></tr>
			<?php endforeach; ?>
			<?php if ( ! $tcp_history ) : ?><tr><td colspan="5">سابقه‌ای در این صفحه ثبت نشده است.</td></tr><?php endif; ?>
		</tbody></table></div>
		<nav class="tcp-actions" aria-label="صفحات سابقه">
		<?php if ( $tcp_hp > 1 ) : ?><a class="button" href="<?php echo esc_url( TCP_Admin::url( 'coupons', array( 'edit' => $tcp_pc->get_id(), 'history_page' => $tcp_hp - 1 ) ) ); ?>">قبلی</a><?php endif; ?>
		<span>صفحه <?php echo esc_html( $tcp_hp ); ?></span>
		<?php if ( count( $tcp_history ) === 50 ) : ?><a class="button" href="<?php echo esc_url( TCP_Admin::url( 'coupons', array( 'edit' => $tcp_pc->get_id(), 'history_page' => $tcp_hp + 1 ) ) ); ?>">بعدی</a><?php endif; ?>
		</nav>
	</div>
</section>
