<?php
/**
 * تب «تنظیمات» (عملیات گروهی).
 *
 * @package TisaCase_Pricing
 */

defined( 'ABSPATH' ) || exit;

$s = TCP_Settings::get_settings();

$tcp_num = static function ( $name, $label, $value, $min, $max = null, $hint = '' ) {
	?>
	<div class="tcp-set">
		<label class="tcp-label" for="tcp-set-<?php echo esc_attr( $name ); ?>"><?php echo esc_html( $label ); ?></label>
		<input type="number" id="tcp-set-<?php echo esc_attr( $name ); ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" min="<?php echo esc_attr( $min ); ?>" <?php echo null !== $max ? 'max="' . esc_attr( $max ) . '"' : ''; ?>>
		<?php if ( $hint ) : ?><p class="tcp-muted"><?php echo esc_html( $hint ); ?></p><?php endif; ?>
	</div>
	<?php
};
$tcp_bool = static function ( $name, $label, $value ) {
	?>
	<label class="tisa-switch tcp-toggle tcp-set-check">
		<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $value ); ?>>
		<span class="tisa-switch__track" aria-hidden="true"></span>
		<span><?php echo esc_html( $label ); ?></span>
	</label>
	<?php
};
?>

<form method="post" action="<?php echo esc_url( TCP_Admin::url( 'settings' ) ); ?>">
	<?php wp_nonce_field( TCP_Settings::NONCE ); ?>
	<input type="hidden" name="tcp_settings_save" value="1">

	<section class="tcp-card">
		<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>رندکردن قیمت</h2><p>مشترک بین قوانین داینامیک و تغییر گروهی. الان قیمت‌های رند به «<?php echo esc_html( TCP_Round::describe() ); ?>» ختم می‌شوند.</p></div></div>
		<div class="tcp-card-body tcp-grid-2">
			<div class="tcp-set">
				<label class="tcp-label" for="tcp-set-round_digit">رقم رندشدن</label>
				<select name="round_digit" id="tcp-set-round_digit">
					<?php for ( $d = 0; $d <= 9; $d++ ) : ?>
						<option value="<?php echo esc_attr( $d ); ?>" <?php selected( (int) $s['round_digit'], $d ); ?>><?php echo esc_html( number_format_i18n( $d ) ); ?></option>
					<?php endfor; ?>
				</select>
				<p class="tcp-muted">قیمت به نزدیک‌ترین عددی می‌رود که به این رقم ختم شود (نه همیشه پایین). با ۸ و گام ۱۰: ۳۷۷ ← ۳۷۸. با گام ۱۰٬۰۰۰: ۶۱۲٬۳۰۰ ← ۶۰۸٬۰۰۰ و ۶۱۳٬۱۰۰ ← ۶۱۸٬۰۰۰.</p>
			</div>
			<?php $tcp_num( 'round_step', 'گام رند (۰ = خودکار)', $s['round_step'], 0, null, 'خودکار: تومان ۱۰٬۰۰۰ / ریال ۱۰۰٬۰۰۰. مثلاً با ۱۰۰٬۰۰۰ قیمت‌ها می‌شوند ۵۸۰٬۰۰۰، ۶۸۰٬۰۰۰، …' ); ?>
			<div class="tcp-set">
				<label class="tcp-label" for="tcp-set-jitter_percent">دامنهٔ تخفیف متغیر (±٪)</label>
				<input type="number" id="tcp-set-jitter_percent" name="jitter_percent" value="<?php echo esc_attr( $s['jitter_percent'] ); ?>" min="0.1" max="50" step="0.1">
				<p class="tcp-muted">در حالت «تخفیف متغیر»، اگر ۱۰٪ بدهی هر محصول عددی بین ۵٪ تا ۱۵٪ (با ±۵) می‌گیرد؛ عدد هر محصول ثابت است و با هر بار نمایش عوض نمی‌شود.</p>
			</div>
		</div>
	</section>

	<section class="tcp-card">
		<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>دسترسی و ایمنی</h2></div></div>
		<div class="tcp-card-body tcp-grid-2">
			<div class="tcp-set">
				<label class="tcp-label" for="tcp-set-cap">حداقل سطح دسترسی</label>
				<select name="min_capability" id="tcp-set-cap">
					<option value="manage_woocommerce" <?php selected( $s['min_capability'], 'manage_woocommerce' ); ?>>مدیر فروشگاه و بالاتر</option>
					<option value="edit_products" <?php selected( $s['min_capability'], 'edit_products' ); ?>>هر کس که محصول ویرایش می‌کند</option>
					<option value="manage_options" <?php selected( $s['min_capability'], 'manage_options' ); ?>>فقط مدیر کل سایت</option>
				</select>
			</div>
			<?php $tcp_num( 'confirm_threshold', 'آستانهٔ تأیید دستی (تعداد محصول مادر)', $s['confirm_threshold'], 0 ); ?>
			<?php $tcp_num( 'max_amount', 'سقف مبلغ ورودی (تعیین/ثابت)', (float) $s['max_amount'], 1 ); ?>
			<?php $tcp_num( 'sample_size', 'تعداد نمونهٔ پیش‌نمایش', $s['sample_size'], 1, 100, 'پیش‌فرض ۳۰ نمونه؛ می‌توانی تا ۱۰۰ محصول/متغیر را در پیش‌نمایش ببینی.' ); ?>
		</div>
	</section>

	<section class="tcp-card">
		<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>اجرا</h2></div></div>
		<div class="tcp-card-body tcp-grid-2">
			<?php $tcp_num( 'batch_size', 'اندازهٔ دسته (محصول در هر مرحله)', $s['batch_size'], 1, 100 ); ?>
			<?php $tcp_num( 'lock_minutes', 'مهلت بی‌حرکتی اجرا (دقیقه)', $s['lock_minutes'], 2 ); ?>
			<?php $tcp_num( 'cron_pages', 'تعداد صفحه در هر تیک Cron', $s['cron_pages'], 1, 500 ); ?>
			<div class="tcp-set">
				<?php $tcp_bool( 'scheduled', 'فعال‌بودن «ثبت در صف زمان‌بندی» (WP-Cron)', $s['scheduled'] ); ?>
			</div>
		</div>
	</section>

	<section class="tcp-card">
		<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>گزارش و بازگردانی</h2></div></div>
		<div class="tcp-card-body tcp-grid-2">
			<?php $tcp_num( 'retention_days', 'مدت نگهداری لاگ (روز)', $s['retention_days'], 1, null, 'پس از این مدت گزارش‌ها پاک می‌شوند و بازگردانی ممکن نیست.' ); ?>
			<div class="tcp-set tcp-set--stack">
				<?php $tcp_bool( 'logging', 'ثبت همهٔ تغییرات برای گزارش و بازگردانی', $s['logging'] ); ?>
				<?php $tcp_bool( 'rollback', 'فعال‌بودن دکمهٔ بازگردانی (نیازمند ثبت لاگ)', $s['rollback'] ); ?>
			</div>
		</div>
	</section>

	<div class="tcp-actions">
		<button type="submit" class="tisa-btn tisa-btn--primary tisa-btn--lg">ذخیرهٔ تنظیمات</button>
	</div>
</form>
