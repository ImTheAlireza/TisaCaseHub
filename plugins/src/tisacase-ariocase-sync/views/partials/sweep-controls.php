<?php
/**
 * کارت «همگام‌سازی خودکار موجودی» در تنظیمات.
 *
 * @package TisaCase_ArioCase_Sync
 */

defined( 'ABSPATH' ) || exit;

$sweep_state    = class_exists( 'TCAS_Sweeper' ) ? TCAS_Sweeper::state() : array();
$sweep_backlog  = class_exists( 'TCAS_Sweeper' ) ? TCAS_Sweeper::remaining() : 0;
$sweep_intervals = class_exists( 'TCAS_Sweeper' ) ? TCAS_Sweeper::intervals() : array( 0 => 'خاموش' );
$sweep_interval = class_exists( 'TCAS_Sweeper' ) ? TCAS_Sweeper::interval() : 0;
$sweep_next     = class_exists( 'TCAS_Sweeper' ) && function_exists( 'wp_date' ) ? TCAS_Sweeper::next_run() : 0;
$sweep_clock    = function( $timestamp ) {
	return $timestamp && function_exists( 'wp_date' ) ? wp_date( 'H:i', $timestamp ) : '';
};
$sweep_fa       = function( $value ) {
	return strtr( (string) $value, array( '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹' ) );
};
$sweep_backoff  = class_exists( 'TCAS_Sweeper' ) ? TCAS_Sweeper::backoff() : array();
$sweep_active   = class_exists( 'TCAS_Sweeper' ) && TCAS_Sweeper::in_backoff();
?>
<section class="tcas-card" id="tcas-sweep-card">
	<div class="tcas-card-head">
		<div><span class="tcas-step">↻</span><h2>همگام‌سازی خودکار موجودی</h2></div>
		<span class="tcas-tag">پس‌زمینه، بدون فشار روی API</span>
	</div>
	<p class="tcas-muted">هر کد متصل حداکثر یک‌بار در هر چرخه خوانده می‌شود؛ درخواست‌ها دسته‌ای، با مکث بین هر کد و بدون درخواست هم‌زمان هنگام بازشدن صفحهٔ محصول. اگر موجودی همکار به صفر برسد، همان Variation در تیساکیس ناموجود می‌شود.</p>
	<div class="tcas-settings-grid">
		<label><span class="tcas-label">چرخهٔ تازه‌سازی</span>
			<select name="tcas_settings[sweep_interval]">
				<?php foreach ( $sweep_intervals as $minutes => $label ) : ?>
					<option value="<?php echo esc_attr( $minutes ); ?>" <?php selected( (int) $sweep_interval, (int) $minutes ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label><span class="tcas-label">نوشتن موجودی در ووکامرس</span>
			<select name="tcas_settings[stock_mode]">
				<option value="flag" <?php selected( $settings['stock_mode'], 'flag' ); ?>>فقط موجود / ناموجود (عدد انبار محلی حفظ می‌شود)</option>
				<option value="quantity" <?php selected( $settings['stock_mode'], 'quantity' ); ?>>عدد دقیق موجودی همکار</option>
			</select>
		</label>
		<label><span class="tcas-label">اگر همکار پاسخ نداد</span>
			<select name="tcas_settings[fail_mode]">
				<option value="open" <?php selected( $settings['fail_mode'], 'open' ); ?>>فروش ادامه یابد (آخرین موجودی ثبت‌شده)</option>
				<option value="closed" <?php selected( $settings['fail_mode'], 'closed' ); ?>>افزودن به سبد متوقف شود</option>
			</select>
		</label>
		<label><span class="tcas-label">اندازهٔ هر دسته <small>(کد در هر اجرا)</small></span>
			<input type="number" min="1" max="50" name="tcas_settings[sweep_batch]" value="<?php echo (int) ( isset( $settings['sweep_batch'] ) ? $settings['sweep_batch'] : 20 ); ?>">
		</label>
	</div>
	<div class="tcas-sweep-status" id="tcas-sweep-status" role="status" aria-live="polite">
		<p><strong>وضعیت:</strong> <?php echo esc_html( class_exists( 'TCAS_Sweeper' ) ? TCAS_Sweeper::summary_text() : 'در دسترس نیست.' ); ?></p>
		<p class="tcas-muted">
			<?php if ( $sweep_backlog > 0 ) : ?>
				<?php echo esc_html( $sweep_fa( $sweep_backlog ) ); ?> کد در صف است.
			<?php else : ?>
				همهٔ موجودی‌های متصل تازه هستند.
			<?php endif; ?>
			<?php if ( $sweep_next ) : ?>
				اجرای بعدی حدوداً <?php echo esc_html( $sweep_clock( $sweep_next ) ); ?>.
			<?php endif; ?>
			<?php if ( $sweep_active && ! empty( $sweep_backoff['until'] ) ) : ?>
				<strong>همکار محدودیت اعمال کرده است؛ تا <?php echo esc_html( $sweep_clock( $sweep_backoff['until'] ) ); ?> درخواستی ارسال نمی‌شود.</strong>
			<?php endif; ?>
		</p>
		<?php if ( ! empty( $sweep_state['message'] ) ) : ?>
			<p class="tcas-muted">آخرین نتیجه: <?php echo esc_html( $sweep_state['message'] ); ?></p>
		<?php endif; ?>
	</div>
	<div class="tcas-sweep-actions">
		<button type="button" id="tcas-sweep-now" class="button">همگام‌سازی الان</button>
		<span class="tcas-muted">چند کد تازه خوانده می‌شود و بقیهٔ صف در پس‌زمینه ادامه می‌یابد.</span>
	</div>
	<p class="tcas-sweep-result" id="tcas-sweep-result" role="status" aria-live="polite"></p>
</section>
