=== TisaCase Bulk Stock Manager ===
Contributors: Alireza Shabanzadeh
Tags: woocommerce, bulk edit, stock, variations, inventory
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.4
WC requires at least: 5.0
WC tested up to: 9.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

مدیریت انبوه موجودی: انتخاب محصول با جست‌وجو، نمایش متغیرها به‌صورت کارت‌های کوچک، فیلتر دسته با سلکت و اعمال یکجا موجودی.

== Description ==

افزونهٔ اختصاصی تیساکیس برای ویرایش انبوه موجودی متغیرهای محصولات ووکامرس در سه گام:

۱. **انتخاب محصول** — جست‌وجوی زنده بر اساس نام، SKU یا شناسه.
۲. **دستهٔ متغیرها** — سلکت دسته (مقادیر ویژگی‌ها مثل مدل/رنگ)؛ با انتخاب، فقط همان متغیرها نمایش و تیک می‌خورند.
۳. **کارت‌های متغیرها** — هر متغیر یک کارت کوچک با SKU، بج موجودی و ورودی عددی؛ با «درج روی همهٔ انتخابی» هم مقدار یکدست قابل درج است.

دکمهٔ «اعمال موجودی» فقط روی متغیرهای انتخاب‌شده (تیک‌خورده و نمایشی) نوشت می‌کند:
`_stock` + `_stock_status` هر متغیر، روشن‌کردن خودکار `manage_stock` برای متغیرهای بدون موجودی‌گیری،
و همگام‌سازی والد (وضعیت موجودی، ترم‌های product_visibility و جدول `wp_wc_product_meta_lookup`).

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory, or upload the zip via WordPress admin.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Access the plugin via WooCommerce → موجودی TisaCase (یا Products → موجودی انبوه)، و از کارت هاب TisaCase.

== Changelog ==

= 1.0.0 =
* نسخهٔ نخست: جست‌وجوی محصول (نام/SKU/شناسه)، کارت‌های کوچک متغیرها، سلکت دسته بر پایهٔ ویژگی‌ها با شمارش، تیک تکی و گروهی، درج سریع مقدار روی انتخابی‌ها، تأیید درون‌خطی اعمال و گزارش «قبل ← بعد» برای هر متغیر؛ همگام‌سازی والد و جدول lookup بعد از اعمال.
