<?php
defined( 'ABSPATH' ) || exit;
$tcp_phone = $tcp_edit ? $tcp_edit['phone_policy'] : TCP_Coupon_Phones::policy( new WC_Coupon() );
?>
<section class="tcp-card tcp-phone-card">
	<div class="tcp-card-head"><span class="tcp-dot"></span><div><h2>سهمیه بر اساس خرید موفق و شماره موبایل</h2><p>برگشت از درگاه، پرداخت ناموفق و سفارش در انتظار پرداخت، سهمیه را مصرف نمی‌کند.</p></div></div>
	<div class="tcp-card-body">
		<input type="hidden" name="phone_policy_present" value="1">
		<?php $tcp_toggle( 'phone_enabled', 'فعال‌سازی مدیریت مصرف موفق برای این کد', $tcp_phone['enabled'] ); ?>
		<div class="tcp-phone-options">
			<p class="tcp-phone-notice">در این حالت محدودیت‌های بومی «سقف کل استفاده / هر کاربر» جایگزین می‌شوند؛ از دو سقف زیر استفاده کنید. سایر شروط کوپن پابرجا هستند. بعد از فعال‌سازی، همگام‌سازی سوابق را کامل کنید تا کد قابل استفاده شود.</p>
			<div class="tcp-grid-3">
				<label class="tcp-stack">خرید موفق هر شماره <input class="tisa-input" type="number" name="phone_limit" min="1" max="10000" value="<?php echo esc_attr( $tcp_phone['limit'] ); ?>" required></label>
				<label class="tcp-stack">ظرفیت کل خرید موفق <input class="tisa-input" type="number" name="phone_total" min="0" value="<?php echo esc_attr( $tcp_phone['total'] ); ?>"><small>صفر = نامحدود؛ ریست، ظرفیت مصرف‌شده را نیز آزاد می‌کند.</small></label>
			</div>
			<div class="tcp-field"><?php $tcp_toggle( 'phone_selected', 'فقط شماره‌های منتخب زیر', $tcp_phone['selected'], '— فهرست خالی یعنی هیچ شماره‌ای مجاز نیست؛ با خاموش‌کردن، همه شماره‌های معتبر مجازند.' ); ?></div>
			<label class="tcp-stack tcp-field">شماره‌های مجاز — هر شماره یک خط؛ ارقام فارسی، ۰۹، ‎+98 و 0098 پذیرفته می‌شوند.
				<textarea class="tisa-input" name="phone_list" dir="ltr" rows="8" placeholder="09123456789"><?php echo esc_textarea( implode( "\n", $tcp_phone['phones'] ) ); ?></textarea>
			</label>
			<div class="tcp-field"><?php $tcp_toggle( 'phone_login', 'ورود به حساب الزامی باشد', $tcp_phone['login'] ); ?></div>
			<p class="tcp-muted">ورود به حساب، اثبات مالکیت شماره نیست. برای جلوگیری از استفاده از شماره دیگران، افزونه ورود/تأیید موبایل باید شماره صورتحساب را با شماره تأییدشده تطبیق دهد.</p>
			<details class="tcp-field"><summary>انتقال از کد قدیمی و تنظیمات سابقه</summary>
				<label class="tcp-stack tcp-field">نام‌های قبلی کوپن (فقط برای خواندن سوابق؛ هر خط یک کد)
					<textarea class="tisa-input" name="phone_aliases" dir="ltr" rows="2"><?php echo esc_textarea( implode( "\n", $tcp_phone['aliases'] ) ); ?></textarea>
				</label>
				<p>کدهای قدیمی را جداگانه غیرفعال کنید. افزودن نام قبلی، همگام‌سازی را از ابتدا لازم می‌کند. ریست‌های ثبت‌شده در این افزونه حفظ می‌شوند؛ ریست‌های اسنیپت قبلی خودکار منتقل نمی‌شوند.</p>
				<?php $tcp_legacy_phones = get_option( 'tisacase158_allowed_phones', array() ); ?>
				<?php if ( is_array( $tcp_legacy_phones ) && $tcp_legacy_phones ) : ?>
				<button type="button" class="button tcp-phone-import" data-phones="<?php echo esc_attr( implode( "\n", array_filter( array_map( array( 'TCP_Coupon_Phones', 'phone' ), $tcp_legacy_phones ) ) ) ); ?>">جایگزینی فهرست با شماره‌های نسخه ۱۵۸ قدیمی</button>
				<?php endif; ?>
				<p><strong>برای انتقال tisacase158:</strong> اسنیپت current158 را غیرفعال کنید، فهرست شماره‌ها را منتقل کنید، در صورت نیاز TISAKIS158 را به نام‌های قبلی اضافه کنید، ذخیره و سپس سوابق را همگام کنید. مبلغ این فرم بر حسب واحد پول فروشگاه است، نه همیشه تومان.</p>
			</details>
		</div>
	</div>
</section>
