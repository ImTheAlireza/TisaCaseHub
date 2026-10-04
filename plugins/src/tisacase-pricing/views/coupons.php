<?php
/**
 * تب «کد تخفیف».
 *
 * @package TisaCase_Pricing
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Coupon' ) ) {
	return;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended
$tcp_search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$tcp_batch   = isset( $_GET['batch'] ) ? sanitize_text_field( wp_unslash( $_GET['batch'] ) ) : '';
$tcp_edit_id = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
$tcp_panel   = isset( $_GET['panel'] ) ? sanitize_key( $_GET['panel'] ) : ( $tcp_edit_id ? 'single' : 'list' );
// phpcs:enable

$tcp_rows    = TCP_Coupons::list_coupons( $tcp_search, $tcp_batch );
$tcp_batches = TCP_Coupons::batches();
$tcp_types   = TCP_Coupons::types();
$tcp_cats    = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false, 'orderby' => 'name' ) );
$tcp_cats    = is_wp_error( $tcp_cats ) ? array() : $tcp_cats;
$tcp_cur     = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '';

$tcp_edit = null;
if ( $tcp_edit_id && 'shop_coupon' === get_post_type( $tcp_edit_id ) ) {
	$tcp_edit = TCP_Coupons::row( new WC_Coupon( $tcp_edit_id ) );
}

$tcp_toggle = static function ( $name, $label, $checked, $hint = '' ) {
	?>
	<label class="tisa-switch tcp-toggle">
		<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?>>
		<span class="tisa-switch__track" aria-hidden="true"></span>
		<span><?php echo esc_html( $label ); ?><?php if ( $hint ) : ?> <span class="tcp-muted"><?php echo esc_html( $hint ); ?></span><?php endif; ?></span>
	</label>
	<?php
};

$tcp_cat_select = static function ( $name, $selected ) use ( $tcp_cats ) {
	?>
	<select name="<?php echo esc_attr( $name ); ?>[]" multiple="multiple" class="wc-enhanced-select" data-tcp-w="full" data-placeholder="دسته‌ها…">
		<?php foreach ( $tcp_cats as $cat ) : ?>
			<option value="<?php echo esc_attr( $cat->term_id ); ?>" <?php selected( in_array( (int) $cat->term_id, (array) $selected, true ) ); ?>><?php echo esc_html( TCP_Admin::cat_label( $cat ) ); ?></option>
		<?php endforeach; ?>
	</select>
	<?php if ( empty( $tcp_cats ) ) : ?>
		<span class="tcp-muted">هیچ دستهٔ محصولی در فروشگاه نیست؛ این لیست خالی است چون دسته‌ای ساخته نشده.</span>
	<?php endif; ?>
	<?php
};

$tcp_prod_select = static function ( $name, $selected ) {
	?>
	<select name="<?php echo esc_attr( $name ); ?>[]" multiple="multiple" class="wc-product-search" data-tcp-w="full" data-placeholder="نام، شناسه یا SKU…" data-action="woocommerce_json_search_products_and_variations">
		<?php
		foreach ( (array) $selected as $pid ) :
			$pr = wc_get_product( $pid );
			if ( $pr ) :
				?>
				<option value="<?php echo esc_attr( $pid ); ?>" selected><?php echo esc_html( $pr->get_formatted_name() ); ?></option>
			<?php endif; endforeach; ?>
	</select>
	<?php
};

/** بدنهٔ مشترک فرم (ساخت تکی و انبوه). */
$tcp_common_fields = static function ( $d, $bulk = false ) use ( $tcp_types, $tcp_toggle, $tcp_cat_select, $tcp_prod_select, $tcp_cur ) {
	$d = wp_parse_args( (array) $d, array( 'type' => 'percent', 'amount' => 10, 'expires' => '', 'min' => 0, 'max' => 0, 'limit' => 0, 'per_user' => 0, 'free_shipping' => false, 'individual' => false, 'excl_sale' => false, 'round' => true, 'products' => array(), 'excl_products' => array(), 'cats' => array(), 'excl_cats' => array(), 'emails' => array(), 'description' => '' ) );
	$exp = $d['expires'] ? gmdate( 'Y-m-d', strtotime( str_replace( '/', '-', $d['expires'] ) ) ) : '';
	?>
	<div class="tcp-grid-3">
		<label class="tcp-stack">نوع تخفیف
			<select name="discount_type" class="tisa-input tcp-cp-type">
				<?php foreach ( $tcp_types as $k => $v ) : ?>
					<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $d['type'], $k ); ?>><?php echo esc_html( $v ); ?></option>
				<?php endforeach; ?>
			</select>
		</label>
		<label class="tcp-stack">مقدار <span class="tcp-cp-unit tcp-muted">(<?php echo 'percent' === $d['type'] ? '٪' : esc_html( $tcp_cur ); ?>)</span>
			<input type="number" class="tisa-input" name="amount" min="0" step="0.01" value="<?php echo esc_attr( $d['amount'] ); ?>" required>
		</label>
		<label class="tcp-stack">تاریخ انقضا <span class="tcp-muted">(خالی = بدون انقضا)</span>
			<input type="date" class="tisa-input" name="expires" value="<?php echo esc_attr( $exp ); ?>">
		</label>
	</div>

	<div class="tcp-grid-4 tcp-field">
		<label class="tcp-stack">حداقل مبلغ سبد <input type="number" class="tisa-input" name="minimum_amount" min="0" step="1000" value="<?php echo esc_attr( $d['min'] ? $d['min'] : '' ); ?>" placeholder="بدون حداقل"></label>
		<label class="tcp-stack">حداکثر مبلغ سبد <input type="number" class="tisa-input" name="maximum_amount" min="0" step="1000" value="<?php echo esc_attr( $d['max'] ? $d['max'] : '' ); ?>" placeholder="بدون حداکثر"></label>
		<?php if ( ! $bulk ) : ?>
			<label class="tcp-stack">سقف کل استفاده <input type="number" class="tisa-input" name="usage_limit" min="0" value="<?php echo esc_attr( $d['limit'] ? $d['limit'] : '' ); ?>" placeholder="نامحدود"></label>
			<label class="tcp-stack">هر کاربر حداکثر <input type="number" class="tisa-input" name="usage_limit_per_user" min="0" value="<?php echo esc_attr( $d['per_user'] ? $d['per_user'] : '' ); ?>" placeholder="نامحدود"></label>
		<?php else : ?>
			<div class="tcp-stack">استفاده <span class="tcp-cp-fixed">هر کد: فقط ۱ بار، ۱ کاربر</span></div>
		<?php endif; ?>
	</div>

	<div class="tcp-cp-toggles">
		<?php $tcp_toggle( 'round_to_8', 'رند به ۸', $d['round'], '— مبلغ نهایی اقلام بعد از کد به نزدیک‌ترین ' . TCP_Round::describe() . ' می‌رود، بالا یا پایین (اختلاف به‌صورت «رند قیمت» در سبد نمایش داده می‌شود).' ); ?>
		<?php $tcp_toggle( 'free_shipping', 'ارسال رایگان', $d['free_shipping'], '— روش حمل «ارسال رایگان» باید در منطقهٔ حمل فعال و روی «نیاز به کوپن» باشد.' ); ?>
		<?php $tcp_toggle( 'individual_use', 'استفادهٔ انفرادی', $d['individual'], '— با کد دیگری جمع نمی‌شود.' ); ?>
		<?php $tcp_toggle( 'exclude_sale_items', 'بدون اقلام حراجی', $d['excl_sale'], '— روی محصولاتی که فروش ویژهٔ واقعی دارند اعمال نمی‌شود.' ); ?>
	</div>

	<details class="tcp-cp-adv">
		<summary>محدودیت محصول / دسته / ایمیل</summary>
		<div class="tcp-grid-2 tcp-field">
			<label class="tcp-stack">فقط این محصولات <?php $tcp_prod_select( 'product_ids', $d['products'] ); ?></label>
			<label class="tcp-stack">به‌جز این محصولات <?php $tcp_prod_select( 'exclude_product_ids', $d['excl_products'] ); ?></label>
			<label class="tcp-stack">فقط این دسته‌ها <?php $tcp_cat_select( 'product_categories', $d['cats'] ); ?></label>
			<label class="tcp-stack">به‌جز این دسته‌ها <?php $tcp_cat_select( 'exclude_product_categories', $d['excl_cats'] ); ?></label>
		</div>
		<label class="tcp-stack tcp-field">ایمیل‌های مجاز <span class="tcp-muted">(با کاما جدا کن؛ خالی = همه)</span>
			<input type="text" class="tisa-input" name="emails" dir="ltr" value="<?php echo esc_attr( implode( ', ', (array) $d['emails'] ) ); ?>">
		</label>
		<label class="tcp-stack tcp-field">توضیح داخلی
			<input type="text" class="tisa-input" name="description" value="<?php echo esc_attr( $d['description'] ); ?>">
		</label>
	</details>
	<?php
};
?>

<p class="tcp-lead">کدها همان کوپن‌های ووکامرس‌اند؛ در سبد، پرداخت و گزارش‌ها طبیعی کار می‌کنند. اینجا ساخت سریع، تولید انبوه کدهای یک‌بارمصرف، رند به ۸ و مدیریت یک‌جا اضافه شده.</p>

<nav class="tcp-subnav">
	<a class="tcp-subtab<?php echo 'list' === $tcp_panel ? ' is-active' : ''; ?>" href="<?php echo esc_url( TCP_Admin::url( 'coupons' ) ); ?>">فهرست کدها <span class="tcp-count"><?php echo esc_html( number_format_i18n( count( $tcp_rows ) ) ); ?></span></a>
	<a class="tcp-subtab<?php echo 'single' === $tcp_panel ? ' is-active' : ''; ?>" href="<?php echo esc_url( TCP_Admin::url( 'coupons', array( 'panel' => 'single' ) ) ); ?>"><?php echo $tcp_edit ? 'ویرایش کد' : 'ساخت کد'; ?></a>
	<a class="tcp-subtab<?php echo 'bulk' === $tcp_panel ? ' is-active' : ''; ?>" href="<?php echo esc_url( TCP_Admin::url( 'coupons', array( 'panel' => 'bulk' ) ) ); ?>">تولید انبوه</a>
</nav>

<?php if ( 'single' === $tcp_panel ) : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tcp-cp-form">
		<input type="hidden" name="action" value="<?php echo esc_attr( TCP_Coupons::ACTION_SAVE ); ?>">
		<input type="hidden" name="coupon_id" value="<?php echo esc_attr( $tcp_edit ? $tcp_edit['id'] : 0 ); ?>">
		<?php wp_nonce_field( TCP_Coupons::ACTION_SAVE ); ?>
		<section class="tcp-card">
			<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2><?php echo $tcp_edit ? 'ویرایش «' . esc_html( $tcp_edit['code'] ) . '»' : 'ساخت کد تخفیف'; ?></h2><p>کد را مشتری در سبد وارد می‌کند. حروف بزرگ/کوچک فرقی ندارد.</p></div></div>
			<div class="tcp-card-body">
				<div class="tcp-grid-3">
					<label class="tcp-stack">کد
						<span class="tcp-row"><input type="text" class="tisa-input tisa-code" name="code" id="tcp-cp-code" dir="ltr" value="<?php echo esc_attr( $tcp_edit ? $tcp_edit['code'] : '' ); ?>" required autocomplete="off"><button type="button" class="tisa-btn tisa-btn--ghost tisa-btn--sm" id="tcp-cp-random">تصادفی</button></span>
					</label>
				</div>
				<div class="tcp-field"><?php $tcp_common_fields( $tcp_edit ? $tcp_edit : array() ); ?></div>
			</div>
		</section>
		<div class="tcp-actions">
			<button type="submit" class="tisa-btn tisa-btn--primary tisa-btn--lg"><?php echo $tcp_edit ? 'ذخیرهٔ تغییرات' : 'ساخت کد'; ?></button>
			<?php if ( $tcp_edit ) : ?><a class="tisa-btn tisa-btn--ghost" href="<?php echo esc_url( TCP_Admin::url( 'coupons' ) ); ?>">انصراف</a><?php endif; ?>
		</div>
	</form>

<?php elseif ( 'bulk' === $tcp_panel ) : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="tcp-cp-form" onsubmit="return confirm('کدها ساخته شوند؟');">
		<input type="hidden" name="action" value="<?php echo esc_attr( TCP_Coupons::ACTION_GENERATE ); ?>">
		<?php wp_nonce_field( TCP_Coupons::ACTION_GENERATE ); ?>
		<section class="tcp-card">
			<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>تولید انبوه کدهای یک‌بارمصرف</h2><p>مثلاً ۲۰۰ کد با پیشوند «EID» → EID-7KQ2M9XA … هر کد فقط یک بار و توسط یک نفر قابل استفاده است. همهٔ کدها در یک «گروه» ثبت می‌شوند تا بعداً یک‌جا CSV بگیری یا حذف کنی.</p></div></div>
			<div class="tcp-card-body">
				<div class="tcp-grid-3">
					<label class="tcp-stack">تعداد <input type="number" class="tisa-input" name="count" min="1" max="1000" value="50" required></label>
					<label class="tcp-stack">پیشوند <span class="tcp-muted">(اختیاری، انگلیسی)</span> <input type="text" class="tisa-input tisa-code" name="prefix" dir="ltr" maxlength="12" placeholder="EID"></label>
					<label class="tcp-stack">طول بخش تصادفی <input type="number" class="tisa-input" name="length" min="4" max="16" value="8"></label>
				</div>
				<div class="tcp-field"><?php $tcp_common_fields( array(), true ); ?></div>
			</div>
		</section>
		<div class="tcp-actions"><button type="submit" class="tisa-btn tisa-btn--primary tisa-btn--lg">تولید کدها</button></div>
	</form>

<?php else : ?>
	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="tcp-cp-filter">
		<input type="hidden" name="page" value="<?php echo esc_attr( TCP_Settings::MAIN_PAGE ); ?>"><input type="hidden" name="tab" value="coupons">
		<input type="search" class="tisa-input" name="s" value="<?php echo esc_attr( $tcp_search ); ?>" placeholder="جستجوی کد…">
		<select name="batch" class="tisa-input">
			<option value="">همهٔ گروه‌ها</option>
			<?php foreach ( $tcp_batches as $b => $n ) : ?>
				<option value="<?php echo esc_attr( $b ); ?>" <?php selected( $tcp_batch, $b ); ?>><?php echo esc_html( $b . ' (' . number_format_i18n( $n ) . ')' ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" class="tisa-btn tisa-btn--secondary">فیلتر</button>
		<?php if ( $tcp_batch ) : ?>
			<a class="tisa-btn tisa-btn--ghost" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . TCP_Coupons::ACTION_EXPORT . '&batch=' . rawurlencode( $tcp_batch ) ), TCP_Coupons::ACTION_EXPORT ) ); ?>">CSV این گروه</a>
			<button type="button" class="tisa-btn tisa-btn--danger-ghost" id="tcp-cp-delete-batch" data-batch="<?php echo esc_attr( $tcp_batch ); ?>">حذف کل گروه</button>
		<?php else : ?>
			<a class="tisa-btn tisa-btn--ghost" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . TCP_Coupons::ACTION_EXPORT ), TCP_Coupons::ACTION_EXPORT ) ); ?>">CSV همه</a>
		<?php endif; ?>
	</form>

	<?php if ( empty( $tcp_rows ) ) : ?>
		<div class="tcp-card tcp-card--empty">کدی پیدا نشد.</div>
	<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tcp-cp-list-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( TCP_Coupons::ACTION_DELETE ); ?>">
			<input type="hidden" name="batch" value="" id="tcp-cp-delete-batch-input">
			<?php wp_nonce_field( TCP_Coupons::ACTION_DELETE ); ?>
			<div class="tcp-card tcp-card--table">
				<table class="widefat striped tcp-cp-table">
					<thead><tr>
						<th class="tcp-cp-check"><input type="checkbox" id="tcp-cp-all" title="انتخاب همه"></th>
						<th>کد</th><th>نوع / مقدار</th><th>استفاده</th><th>انقضا</th><th>ویژگی‌ها</th><th>وضعیت</th><th>اقدامات</th>
					</tr></thead>
					<tbody>
					<?php foreach ( $tcp_rows as $r ) : ?>
						<tr>
							<td class="tcp-cp-check"><input type="checkbox" name="coupon_ids[]" value="<?php echo esc_attr( $r['id'] ); ?>"></td>
							<td><button type="button" class="tcp-cp-code tisa-code" data-code="<?php echo esc_attr( $r['code'] ); ?>" title="کپی"><?php echo esc_html( $r['code'] ); ?></button><?php if ( $r['batch'] ) : ?><div class="tcp-muted"><?php echo esc_html( $r['batch'] ); ?></div><?php endif; ?></td>
							<td><?php echo esc_html( isset( $tcp_types[ $r['type'] ] ) ? $tcp_types[ $r['type'] ] : $r['type'] ); ?><br><strong><?php echo 'percent' === $r['type'] ? esc_html( number_format_i18n( $r['amount'], 2 ) . '٪' ) : esc_html( number_format_i18n( $r['amount'] ) . ' ' . $tcp_cur ); ?></strong></td>
							<td><?php echo esc_html( number_format_i18n( $r['usage'] ) ); ?> / <?php echo $r['limit'] ? esc_html( number_format_i18n( $r['limit'] ) ) : '∞'; ?></td>
							<td><?php echo $r['expires'] ? esc_html( $r['expires'] ) : '—'; ?></td>
							<td class="tcp-cp-feats">
								<?php if ( $r['round'] ) : ?><span class="tcp-badge tcp-st-done">رند ۸</span><?php endif; ?>
								<?php if ( $r['free_shipping'] ) : ?><span class="tcp-badge tcp-st-running">ارسال رایگان</span><?php endif; ?>
								<?php if ( $r['individual'] ) : ?><span class="tcp-badge tcp-st-rolled_back">انفرادی</span><?php endif; ?>
								<?php if ( $r['min'] ) : ?><span class="tcp-badge tcp-st-rolled_back">حداقل <?php echo esc_html( number_format_i18n( $r['min'] ) ); ?></span><?php endif; ?>
							</td>
							<td><span class="tcp-badge tcp-cp-<?php echo esc_attr( $r['status'] ); ?>"><?php echo esc_html( TCP_Coupons::status_label( $r['status'] ) ); ?></span></td>
							<td class="tcp-run-actions">
								<a class="button button-small" href="<?php echo esc_url( TCP_Admin::url( 'coupons', array( 'edit' => $r['id'] ) ) ); ?>">ویرایش</a>
								<button type="button" class="button button-small tcp-cp-toggle" data-id="<?php echo esc_attr( $r['id'] ); ?>"><?php echo 'off' === $r['status'] ? 'فعال کن' : 'غیرفعال'; ?></button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<div class="tcp-actions">
				<button type="submit" class="tisa-btn tisa-btn--danger-ghost" id="tcp-cp-delete-selected" disabled>حذف انتخاب‌شده‌ها</button>
			</div>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="tcp-cp-toggle-form" hidden>
			<input type="hidden" name="action" value="<?php echo esc_attr( TCP_Coupons::ACTION_TOGGLE ); ?>">
			<input type="hidden" name="coupon_id" value="" id="tcp-cp-toggle-id">
			<?php wp_nonce_field( TCP_Coupons::ACTION_TOGGLE ); ?>
		</form>
	<?php endif; ?>
<?php endif; ?>
