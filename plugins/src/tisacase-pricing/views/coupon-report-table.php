<?php defined( 'ABSPATH' ) || exit; ?>
<div class="tcp-report-meta" data-page="<?php echo esc_attr( $data['page'] ); ?>">
	<strong><?php echo esc_html( number_format_i18n( $data['total'] ) ); ?> سفارش مرتبط ثبت‌شده</strong>
	<span>آخرین تازه‌سازی: <?php echo esc_html( wp_date( 'Y/m/d H:i:s' ) ); ?></span>
</div>
<div class="tcp-phone-table"><table class="widefat striped tcp-report-table">
<thead><tr><th>سفارش / وضعیت فعلی</th><th>مشتری</th><th>شماره / ایمیل</th><th>مبلغ‌ها</th><th>تاریخ‌ها</th><th>مصرف و ریست</th><th>جزئیات سفارش</th></tr></thead>
<tbody>
<?php foreach ( $data['ids'] as $id ) :
	$order = wc_get_order( $id );
	$use = $ledger[ $id ] ?? null;
?>
<tr>
<?php if ( ! $order ) : ?>
	<td>#<?php echo esc_html( $id ); ?><br>سفارش حذف شده یا در دسترس نیست</td><td colspan="4">اطلاعات مشتری و مبلغ قابل بازیابی نیست.</td>
	<td><?php if ( $use ) : echo esc_html( $use->phone . ' — ' . ( $use->released ? 'سهمیه ریست شده' : 'مصرف ثبت‌شده' ) ); else : ?>فقط ارتباط تاریخی با کوپن محفوظ است.<?php endif; ?></td><td>—</td>
<?php else :
	$amount = 0; $tax = 0; $matched = false;
	foreach ( $order->get_items( 'coupon' ) as $item ) {
		if ( in_array( strtolower( $item->get_code() ), self::codes( $coupon ), true ) ) { $matched = true; $amount += (float) $item->get_discount(); $tax += (float) $item->get_discount_tax(); }
	}
	$money = static function ( $value ) use ( $order ) { return wp_kses_post( wc_price( $value, array( 'currency' => $order->get_currency() ) ) ); };
	$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
	$current_phone = TCP_Coupon_Phones::phone( $order->get_billing_phone() );
	$dates = array( 'ایجاد' => $order->get_date_created(), 'پرداخت' => $order->get_date_paid(), 'تکمیل' => $order->get_date_completed(), 'آخرین تغییر' => $order->get_date_modified() );
?>
<td><a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>"><strong>#<?php echo esc_html( $order->get_order_number() ); ?></strong></a>
	<small>شناسه: <?php echo esc_html( $id ); ?></small>
	<span class="tcp-badge"><?php echo esc_html( wc_get_order_status_name( $order->get_status() ) ); ?></span>
	<?php if ( ! $matched ) : ?><small>کوپن اکنون روی سفارش نیست یا نام آن تغییر کرده است.</small><?php endif; ?>
</td>
<td><strong><?php echo esc_html( $name ?: 'بدون نام' ); ?></strong><small><?php echo $order->get_customer_id() ? esc_html( 'شناسه حساب: ' . $order->get_customer_id() ) : 'خرید مهمان'; ?></small></td>
<td><span dir="ltr"><?php echo esc_html( $order->get_billing_phone() ?: '—' ); ?></span>
	<?php if ( $use && $use->phone !== $current_phone ) : ?><small>شماره زمان مصرف: <b dir="ltr"><?php echo esc_html( $use->phone ); ?></b></small><?php endif; ?>
	<small dir="ltr"><?php echo esc_html( $order->get_billing_email() ?: '—' ); ?></small>
</td>
<td>تخفیف همین کد: <?php echo $money( $amount ); ?><small>مالیات تخفیف: <?php echo $money( $tax ); ?></small><small>کل سفارش: <?php echo $money( $order->get_total() ); ?></small><small>بازپرداخت کل سفارش: <?php echo $money( $order->get_total_refunded() ); ?></small></td>
<td><?php foreach ( $dates as $label => $date ) : ?><small><?php echo esc_html( $label . ': ' . ( $date ? wp_date( 'Y/m/d H:i', $date->getTimestamp() ) : '—' ) ); ?></small><?php endforeach; ?></td>
<td><?php if ( $use ) : ?>
	<strong><?php echo $use->released ? 'سهمیه آزادشده با ریست' : 'مصرف قطعی ثبت‌شده'; ?></strong>
	<small><?php echo esc_html( wp_date( 'Y/m/d H:i', $use->used_at ) ); ?></small>
	<?php if ( $use->reset_at ) : $actor = get_userdata( $use->reset_by ); ?><small><?php echo esc_html( 'ریست: ' . wp_date( 'Y/m/d H:i', $use->reset_at ) . ' — ' . ( $actor ? $actor->display_name : 'حساب حذف‌شده' ) . ' (#' . $use->reset_by . ')' ); ?></small><?php endif; ?>
	<?php else : ?><span><?php echo TCP_Coupon_Phones::successful( $order ) ? 'سفارش موفق؛ بدون رکورد سهمیه موبایلی' : 'مصرف موفق در دفتر موبایلی ثبت نشده'; ?></span><?php endif; ?>
</td>
<td><details><summary>کالاها و روش پرداخت</summary><p><?php echo esc_html( 'درگاه: ' . ( $order->get_payment_method_title() ?: '—' ) ); ?></p><p dir="ltr"><?php echo esc_html( 'Transaction: ' . ( $order->get_transaction_id() ?: '—' ) ); ?></p>
<ul><?php foreach ( $order->get_items( 'line_item' ) as $item ) : ?><li><?php echo esc_html( $item->get_name() . ' × ' . $item->get_quantity() ); ?></li><?php endforeach; ?></ul>
<p><?php echo esc_html( 'سایر/همه کدهای سفارش: ' . implode( '، ', $order->get_coupon_codes() ) ); ?></p></details></td>
<?php endif; ?>
</tr>
<?php endforeach; ?>
<?php if ( ! $data['ids'] ) : ?><tr><td colspan="7">سفارشی پیدا نشد. برای سوابق قدیمی، همگام‌سازی گزارش را کامل کنید.</td></tr><?php endif; ?>
</tbody></table></div>
<nav class="tcp-actions" aria-label="صفحات گزارش">
<?php foreach ( array( $data['page'] - 1 => 'قبلی', $data['page'] + 1 => 'بعدی' ) as $page => $label ) : if ( $page < 1 || $page > $data['pages'] ) { continue; } ?>
<a class="button tcp-report-page" data-page="<?php echo esc_attr( $page ); ?>" href="<?php echo esc_url( TCP_Admin::url( 'coupons', array( 'panel' => 'report', 'edit' => $coupon->get_id(), 'report_page' => $page, 'order_id' => $order_id ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
<?php endforeach; ?><span><?php echo esc_html( 'صفحه ' . $data['page'] . ' از ' . $data['pages'] ); ?></span></nav>
