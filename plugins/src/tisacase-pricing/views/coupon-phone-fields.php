<?php
defined( 'ABSPATH' ) || exit;
$tcp_phone = $tcp_edit ? $tcp_edit['phone_policy'] : TCP_Coupon_Phones::policy( new WC_Coupon() );
?>
<section class="tcp-card tcp-phone-card">
	<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>محدودیت مصرف با شماره موبایل</h2><p>فقط خرید موفق از سهمیه کم می‌شود.</p></div></div>
	<div class="tcp-card-body">
		<input type="hidden" name="phone_policy_present" value="1">
		<div class="tcp-phone-switch-row tcp-phone-switch-row--main">
			<?php $tcp_toggle( 'phone_enabled', 'فعال‌سازی محدودیت مصرف', $tcp_phone['enabled'] ); ?>
		</div>
		<div class="tcp-phone-options" id="tcp-phone-options">
			<div class="tcp-phone-limits">
				<label class="tcp-phone-input" for="tcp-phone-limit"><span>سقف هر شماره</span>
					<input id="tcp-phone-limit" class="tisa-input" type="number" name="phone_limit" min="1" max="10000" value="<?php echo esc_attr( $tcp_phone['limit'] ); ?>" required>
				</label>
				<label class="tcp-phone-input" for="tcp-phone-total"><span>سقف کل کد <small>۰ = نامحدود</small></span>
					<input id="tcp-phone-total" class="tisa-input" type="number" name="phone_total" min="0" value="<?php echo esc_attr( $tcp_phone['total'] ); ?>">
				</label>
			</div>
			<div class="tcp-phone-audience">
				<div class="tcp-phone-switch-row">
					<?php $tcp_toggle( 'phone_selected', 'فقط شماره‌های منتخب', $tcp_phone['selected'] ); ?>
				</div>
				<div class="tcp-phone-list" id="tcp-phone-list">
					<div class="tcp-phone-list-heading"><label for="tcp-phone-list-input">شماره‌های مجاز</label><span id="tcp-phone-list-hint">هر شماره در یک خط</span></div>
					<textarea id="tcp-phone-list-input" class="tisa-input" name="phone_list" dir="ltr" rows="6" aria-describedby="tcp-phone-list-hint tcp-phone-list-empty" placeholder="09123456789"><?php echo esc_textarea( implode( "\n", $tcp_phone['phones'] ) ); ?></textarea>
					<p class="tcp-phone-caption" id="tcp-phone-list-empty">فهرست خالی باشد، هیچ شماره‌ای مجاز نیست.</p>
				</div>
				<p class="tcp-phone-caption tcp-phone-all"<?php if ( $tcp_phone['selected'] ) { echo ' style="display:none"'; } ?>>همه شماره‌های معتبر مجازند.</p>
			</div>
			<div class="tcp-phone-switch-row tcp-phone-login">
				<?php $tcp_toggle( 'phone_login', 'ورود به حساب الزامی باشد', $tcp_phone['login'] ); ?>
			</div>
			<p class="tcp-phone-setup">پس از ذخیره، همگام‌سازی سوابق را کامل کنید.</p>
			<details class="tcp-phone-help"><summary>راهنما و انتقال سوابق</summary>
				<div class="tcp-phone-help-body">
					<ul>
						<li>سقف‌های بالا جایگزین محدودیت‌های بومی کوپن می‌شوند. ریست، ظرفیت مصرف‌شده را آزاد می‌کند.</li>
						<li>ورود به حساب به معنی تأیید مالکیت شماره نیست؛ تطبیق شماره با OTP به افزونه احراز موبایل نیاز دارد.</li>
						<li>شماره‌ها با ارقام فارسی، ‎+98 و 0098 هم پذیرفته می‌شوند.</li>
					</ul>
					<label class="tcp-phone-input" for="tcp-phone-aliases"><span>نام‌های قبلی کوپن <small>هر خط یک کد</small></span>
						<textarea id="tcp-phone-aliases" class="tisa-input" name="phone_aliases" dir="ltr" rows="2"><?php echo esc_textarea( implode( "\n", $tcp_phone['aliases'] ) ); ?></textarea>
					</label>
					<p>کدها و اسنیپت قدیمی را غیرفعال کنید. تغییر نام‌های قبلی به همگام‌سازی مجدد نیاز دارد؛ ریست‌های اسنیپت قبلی منتقل نمی‌شوند.</p>
					<?php $tcp_legacy_phones = get_option( 'tisacase158_allowed_phones', array() ); ?>
					<?php if ( is_array( $tcp_legacy_phones ) && $tcp_legacy_phones ) : ?>
					<button type="button" class="button tcp-phone-import" data-phones="<?php echo esc_attr( implode( "\n", array_filter( array_map( array( 'TCP_Coupon_Phones', 'phone' ), $tcp_legacy_phones ) ) ) ); ?>">انتقال شماره‌های نسخه ۱۵۸</button>
					<?php endif; ?>
				</div>
			</details>
		</div>
	</div>
</section>
