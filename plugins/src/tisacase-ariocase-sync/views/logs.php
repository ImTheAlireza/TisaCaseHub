<?php
defined( 'ABSPATH' ) || exit;
$logs = TCAS_Logger::all();
?>
<section class="tcas-card">
	<div class="tcas-card-head"><div><span class="tcas-step">≡</span><h2>گزارش عملیات</h2></div><?php if ( $logs ) : ?><button type="button" id="tcas-clear-logs" class="button">پاک‌کردن گزارش‌ها</button><?php endif; ?></div>
	<p class="tcas-help">حداکثر ۱۰۰ رویداد آخر نگه‌داری می‌شود. API Key و محتوای کامل پاسخ‌ها ثبت نمی‌شود.</p>
	<div id="tcas-log-status" class="tcas-status" role="status" aria-live="polite"></div>
	<?php if ( ! $logs ) : ?>
		<div class="tcas-empty">هنوز رویدادی ثبت نشده است.</div>
	<?php else : ?>
		<div class="tcas-table-wrap"><table class="widefat striped tcas-table"><thead><tr><th>زمان</th><th>رویداد</th><th>کد</th><th>کاربر</th><th>پیام</th></tr></thead><tbody>
		<?php foreach ( $logs as $log ) : ?>
			<tr><td><?php echo esc_html( wp_date( 'Y/m/d H:i:s', (int) $log['time'] ) ); ?></td><td><span class="tcas-tag"><?php echo esc_html( $log['event'] ); ?></span></td><td><code dir="ltr"><?php echo esc_html( $log['code'] ); ?></code></td><td>#<?php echo (int) $log['user_id']; ?></td><td><?php echo esc_html( $log['message'] ); ?></td></tr>
		<?php endforeach; ?>
		</tbody></table></div>
	<?php endif; ?>
</section>

<section class="tcas-card tcas-info-card">
	<h2>محدودیت سینک خواندنی</h2>
	<p class="tcas-help">این نسخه تغییرات ArioCase را به تیساکیس می‌آورد و قبل از خرید دوباره بررسی می‌کند. کاهش موجودی ArioCase بعد از فروش تیساکیس به Endpoint نوشتنی/رزرو و قرارداد Idempotency نیاز دارد و بدون مستندات آن، عمداً انجام نمی‌شود.</p>
</section>
