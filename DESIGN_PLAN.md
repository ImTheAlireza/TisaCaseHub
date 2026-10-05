# 🎨 نقشهٔ راه: زبان طراحی واحد + منوی «اختصاصی تیساکیس»

> سند پیشنهادی — قبل از کد زدن. مرجع شناخت پلاگین‌ها: `PLUGIN_MAP.md`

---

## ۱) تشخیص واقعی: چرا ظاهرها فرق دارد

۹ پلاگین الان در **سه اردوگاه** بصری جدا زندگی می‌کنند:

| پلاگین | پالت فعلی | گوشه‌ها | فونت | نحوهٔ تحویل CSS |
|--------|-----------|---------|-------|------------------|
| `tisacase-product-description` | teal `#0E7C6B` + sand `#F4F2EC` + ink `#1F2A2E`، **توکن‌دار** (`--tc-*`) | 14 / 9 / 999 | system + Vazirmatn | فایل `assets/admin.css` (۱۱۱۱ خط) |
| `case-special-package` | teal `#14907D` + کرم `#FBFAF6` + قهوه‌ای `#332E24` | 16 / 13 / 11 / 999 | Vazirmatn از **CDN گوگل** | فایل `admin.css` (۳۷۲) |
| `wc-sku-prefix-bar` | گرادیان `#064E46→#087C70→#129786` | 20 / 7 | Consolas (مونو) | فایل `bar.css` (مینیفای) |
| `bulk-product-cleaner` | **آبی پیش‌فرض وردپرس** `#2271B1` | 8 / 10 / 6 / 11 | پیش‌فرض وردپرس | CSS درون‌خطی با heredoc در PHP |
| `tisacase-bulk-price-manager` | آبی `#2271B1` + `#008A20`/`#B32D2E` | 8 / 10 / 6 / 99 | پیش‌فرض | فایل `admin.css` (۶۰) |
| `tisacase-pricing-manager` | آبی `#2271B1` (استایل ووکامرس خام) | 6 / 10 | پیش‌فرض | فایل `admin.css` (۱ خط مینیفای) |
| `tisa-product-importer` | آبی وردپرس، استایل‌های درون‌خطی | — | — | `style="…"` داخل PHP |
| `bulk-tracking-upload` | **زیتونی `#7A9C59`** + آبی متفرقه `#2196F3` | 8 / 10 / 12 | پیش‌فرض | ۱۸ بلوک `<style>` و `style=` داخل PHP |
| `tisacase-order-phone-exporter` | ترکیبی (پیشرفت درون‌خطی، بقیه قالب) | — | — | درون‌خطی |

سه نتیجهٔ عملی:

1. **پالت «تیساکیس» از قبل وجود دارد** (نسخهٔ `product-description` تنها نسخهٔ توکن‌دار و کامل است) → پس ابداع نمی‌کنیم، **استاندارد می‌کنیم**. سه teal نزدیک (`0E7C6B`، `14907D`، `087C70`) به یک رنگ واحد می‌رسند.
2. پلاگین‌های آبی‌رنگ عملاً «بدون برند»ند؛ پلاگین زیتونی «بدون خانواده» است.
3. **منو هم پخش است:** ۵ آیتم زیر «ووکامرس»، ۲ آیتم زیر «محصولات»، ۱ منوی بالاتoplevel مستقل، ۲ پلاگین اصلاً صفحهٔ مستقل ندارند.

---

## ۲) معماری پیشنهادی (در یک نگاه)

دو بسته، هر دو در قالب افزونهٔ مستقل تا ساختار زیپی فعلی نشکند:

```
tisacase-hub/                      ← «اختصاصی تیساکیس» + مالک زبان طراحی
├── tisacase-hub.php                ریشهٔ منو، صفحهٔ لانچر، ثبت توکن‌ها روی همهٔ اسکرین‌ها
├── includes/
│   ├── class-tsh-ui.php            ⭐ منبع واحد Design Tokens (PHP → CSS vars + فایل CSS)
│   ├── class-tsh-registry.php      ⭐ کاتالوگ آیتم‌ها (filter + کشف خودکار از هدر افزونه‌ها)
│   ├── class-tsh-renderer.php      رندر کارت‌ها، جستجو، گروه‌بندی، وضعیت فعال/غیرفعال
│   └── class-tsh-cleanup.php       hide-submenu (سوییچ اختیاری)
├── assets/
│   ├── tisacase-ui.css             ⭐ توکن‌ها + کامپوننت‌ها + نرمال‌ساز وردپرس
│   ├── tisacase-ui.js              تب‌ها، مودال، toasts (اختیاری)
│   └── fonts/vazirmatn-*.woff2     فونت محلی (رفع وابستگی CDN گوگل)
└── design/
    └── tokens.json                 منبع حقیقت برای اسکریپت همگام‌سازی
```

### چرا همین معماری؟
- **یک نقطهٔ تغییر:** هر اصلاح بصری فقط یک‌جا نوشته می‌شود؛ لازم نیست ۹ فایل CSS دستی همگام بمانند.
- **بدون وابستگی شکننده:** اگر `tisacase-hub` غیرفعال شود، صفحات افزونه‌ها **می‌شکنند نه** — چون همهٔ `var()`ها با fallback نوشته می‌شوند و CSS اختصاصی خود افزونه سرِ جایش می‌ماند. فقط به ظاهر قبلی برمی‌گردد.
- افزودن افزونهٔ جدید در آینده = **یک خط** در هدر فایل اصلی آن.

---

## ۳) زبان طراحی واحد: Design Tokens

نام‌گذاری با پیشوند `--tisa-` (پیشوند `--tc-` افزونهٔ توضیحات به‌علاوه بازنویسی مجدد، همگام نگه داشته می‌شود تا همان افزونه نشکند).

```css
:root{
  /* رنگ‌های اصلی — میانهٔ سه teal موجود */
  --tisa-primary:#0E7C6B;  --tisa-primary-ink:#0A5F52;  --tisa-primary-soft:#E8F0EE;
  --tisa-brand-1:#064E46;  --tisa-brand-2:#087C70;      --tisa-brand-3:#129786;
  --tisa-on-primary:#FFFFFF;

  /* سطح و متن */
  --tisa-bg:#F4F2EC;   --tisa-surface:#FFFFFF;  --tisa-surface-2:#FAFAF8;
  --tisa-ink:#1F2A2E;  --tisa-text:#2A3439;     --tisa-muted:#77828A;
  --tisa-border:#E3E1DA; --tisa-border-strong:#C9DCD7;

  /* معنا */
  --tisa-success:#1A7F37; --tisa-success-soft:#E5F4E7;
  --tisa-warning:#8A6116; --tisa-warning-soft:#FCF3D7;
  --tisa-danger:#B5453A;  --tisa-danger-soft:#FBECEA;
  --tisa-info:#135E96;    --tisa-info-soft:#F0F6FC;

  /* گوشه‌ها — قانون: دکمه/اینپوت ۱۰، کارت ۱۶، هیرو ۲۰، بج/چیپ پیل */
  --tisa-r-xs:6px; --tisa-r-sm:10px; --tisa-r:14px; --tisa-r-lg:16px;
  --tisa-r-xl:20px; --tisa-r-pill:999px;

  /* فاصله (پایه ۴) */
  --tisa-sp-1:4px; --tisa-sp-2:8px; --tisa-sp-3:12px; --tisa-sp-4:16px;
  --tisa-sp-5:24px; --tisa-sp-6:32px; --tisa-sp-7:48px;

  /* تایپ‌اسکیل — ۷ اندازه، تمام کسری‌ها حذف می‌شوند */
  --tisa-fs-hero:26px; --tisa-fs-h1:20px; --tisa-fs-h2:16px; --tisa-fs-body:14px;
  --tisa-fs-control:13px; --tisa-fs-meta:12px; --tisa-fs-tiny:11px;
  --tisa-lh:1.75; --tisa-lh-tight:1.35;

  /* سایه / حرکت / فوکوس */
  --tisa-shadow-sm:0 1px 2px rgba(31,42,46,.05);
  --tisa-shadow:0 1px 2px rgba(31,42,46,.05),0 10px 30px rgba(31,42,46,.06);
  --tisa-ring:0 0 0 3px rgba(14,124,107,.22);
  --tisa-t:150ms cubic-bezier(.4,0,.2,1);

  --tisa-font:"Vazirmatn",-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Tahoma,sans-serif;
  --tisa-mono:ui-monospace,"SFMono-Regular",Consolas,"Courier New",monospace;
}
```

**قوانینی که همه‌جا لازم می‌شود** (همین‌ها «هماهنگی» را می‌سازند، نه فقط رنگ):

| مورد | قانون واحد |
|------|-------------|
| ارتفاع دکمه | `36px` (`.tisa-btn`) / `30px` (`.tisa-btn-sm`) — هیچ‌جا `min-height` متفرقه نباشد |
| پدینگ دکمه | `0 16px` (sm: `0 12px`)، فونت `13px/700`، `border-radius:10px` |
| کارت | `background:#fff; border:1px solid var(--tisa-border); radius 16px; padding 20px 24px; shadow --tisa-shadow` |
| عنوان بخش | `16px/700` + توضیح `12px/muted`؛ عنوان صفحه `20px/800` |
| متن بدنه/جدول | `13px`، سرتیتر جدول `12px/600` با `--tisa-muted` |
| بج/چیپ | `11px/600`، `radius 999px`، پدینگ `2px 9px`، پس‌زمینه `-soft` + متن پررنگ همان رنگ |
| اینپوت/سلکت | ارتفاع `36px`، `radius 10px`، `border 1px --tisa-border`، فوکوس `--tisa-ring` |
| اینپوت عددی/قیمت/SKU | فونت `--tisa-mono`، `direction:ltr`، `text-align:left` |
| سوییچ | یک کامپوننت واحد `.tisa-switch` (به‌جای چک‌باکس خام + متن) |
| جدول‌ها | `.tisa-table` با `striped` حذف‌شده، `hover` ملایم، سطر خالی `.tisa-empty` |
| پیشرفت | ارتفاع `10px`، `radius 999px`، گرادیان برند (به‌جای `#2271b1`) |
| لاگ ترمینال | پس‌زمینه `#1D2327`، `--tisa-mono 12px`، `radius 10px` (استاندارد BDC) |
| ریسپانسیو | بریک‌پوینت واحد `782px`؛ در موبایل گریدها تک‌ستونه |
| RTL | فقط `margin-inline-*` / `padding-inline-*` / `inset-inline-*`؛ هیچ `left/right` فیزیکی نه |
| حرکت | `prefers-reduced-motion` → همهٔ ترنزیشن‌ها خاموش |

### سه لایه‌ای که باعث می‌شود کار به ۹ بازنویسی کامل نکشد
1. **لایهٔ توکن** — فقط متغیرها. بدون تغییر HTML.
2. **لایهٔ نرمال‌ساز (نقطهٔ کلیدی):** hub داخل `body.tisa-scope` (که روی اسکرین‌های شناخته‌شده اضافه می‌شود) استایل ویجت‌های هستهٔ وردپرس را بازنویسی می‌کند:
   ```css
   body.tisa-scope .button{height:36px;border-radius:var(--tisa-r-sm);font:700 13px var(--tisa-font);...}
   body.tisa-scope .button-primary{background:var(--tisa-primary);border-color:var(--tisa-primary-ink)}
   body.tisa-scope .button-link-delete{color:var(--tisa-danger)}
   body.tisa-scope .notice{border-radius:var(--tisa-r);border-inline-start-width:4px}
   body.tisa-scope .widefat th{font-size:var(--tisa-fs-meta)}
   ```
   → یعنی `bulk-product-cleaner`، `tisacase-bulk-price-manager`، `tisacase-pricing-manager`، `tisa-product-importer` که همه‌جا از `.button`, `.widefat`, `.notice` وردپرس استفاده می‌کنند، **بدون یک خط تغییر markup** هماهنگ می‌شوند.
3. **لایهٔ کامپوننت** — فقط برای ۴ پلاگینی که CSS اختصاصیِ زیاد دارند (`product-description`، `case-special-package`، `bulk-tracking-upload`، `sku-prefix-bar`): هگزها و radiusهای هاردکد حذف و با `var()` جایگزین می‌شود. این تنها ویرایش واقعی در فایل‌های فعلی است.

**فونت:** Vazirmatn به‌صورت `woff2` محلی در hub + `@font-face` با `font-display:swap`؛ CDN گوگل در `case-special-package` حذف (همان F9 گزارش قبلی). اعداد فارسی در جدول‌ها → `font-variant-numeric:tabular-nums`.

---

## ۴) منوی «اختصاصی تیساکیس»

### ثبت‌نام آیتم‌ها (Registry)

هر افزونه با ۵ خط خودش را معرفی می‌کند (منبع واحد حقیقت، بدون هاردکد در hub):

```php
add_filter( 'tisacase_hub_items', function ( $items ) {
    $items[] = array(
        'key'      => 'bdc',                                   // شناسهٔ یکتا
        'title'    => 'حذف انبوه پیش‌نویس',
        'desc'     => 'حذف امن محصولات پیش‌نویس + تصاویر، با بکاپ ۹۰ روزه',
        'group'    => 'products',                              // products | pricing | orders | tools
        'icon'     => 'trash',                                 // مجموعهٔ آیکون خطی داخلی hub
        'url'      => admin_url( 'admin.php?page=bdc-cleaner' ),
        'cap'      => 'manage_woocommerce',
        'count'    => fn() => bdc_query_count(),               // اختیاری: بج عددی روی کارت
        'primary'  => true,                                    // آیا آیتم اصلی این افزونه است
    );
    return $items;
} );
```

و **کشف خودکار** برای اینکه چیزی جا نیفتد: hub در `get_plugins()` دنبال هدر سفارشی `TisaCase Hub:` می‌گردد؛ هر افزونه‌ای که این خط را داشته باشد حتی بدون فیلتر، به‌عنوان آیتم «عمومی» فهرست می‌شود. افزونه‌های TisaCase که در فهرست نیستند هم شناسایی و با نشان «بدون ثبت‌نام» نمایش داده می‌شوند (تا یک یادآورِ تمیز باقی بماند).

### لینک‌های دقیق ۹ پلاگین (از روی کد استخراج شد)

| کلید | عنوان | گروه | URL | نکته |
|------|-------|------|-----|-------|
| `bdc` | حذف انبوه پیش‌نویس | products | `admin.php?page=bdc-cleaner` | الان زیر ووکامرس |
| `desc` | قوانین توضیحات محصول | products | `admin.php?page=tisacase-desc` | الان **تoplevel مستقل** |
| `importer` | افزودن/شارژ محصول از تلگرام | products | `edit.php?post_type=product&page=tisa-product-importer` | والدش «محصولات» است |
| `skubar` | نوار پیشوند SKU | products | `edit.php?post_type=product` | صفحهٔ مستقل ندارد → به لیست محصولات می‌رود |
| `tcbpm` | قیمت گروهی | pricing | `edit.php?post_type=product&page=tisacase-bulk-price-manager` | سه تب دارد |
| `pm` | قیمت‌گذاری داینامیک | pricing | `admin.php?page=tisacase-pricing-manager` | الان زیر ووکامرس |
| `package` | پکیج ویژه قاب | pricing | `admin.php?page=wcsp-settings` | داشبورد آماری |
| `tracking` | آپلود کد رهگیری | orders | `admin.php?page=bulk-tracking-upload` | + آیتم دوم `admin.php?page=bwt-cleanup` |
| `exporter` | خروجی گرفتن (شماره تماس، سفارش، مشتری، محصول) | orders | `admin.php?page=tisacase-exporter` | خروجی PII |

(چون URLها متفاوت‌اند — بعضی `admin.php` بعضی `edit.php` — نمی‌شود آن‌ها را از روی الگو ساخت؛ پس فیلد `url` در registry الزامی است.)

### صفحهٔ لانچر

```
┌──────────────────────────────────────────────────────────────┐
│  [هیرو: گرادیان برند + لوگو]   اختصاصی تیساکیس               │
│  ۹ ابزار اختصاصی تیساکیس · ووکامرس ۸٫۹ · PHP ۸٫۲            │
│  ┌ چип ┐┌ چип ┐┌ چип ┐   جستجو ( / ) برای فیلتر            │
│  │فعال ۹│غیرفعال ۰│هشدار ۱│                                   │
├──────────────────────────────────────────────────────────────┤
│  محصول و محتوا                                                │
│  ┌───────────┐ ┌───────────┐ ┌───────────┐ ┌───────────┐    │
│  │ [icon]    │ │  v12.4.0  │ │  ...      │ │  ۵۱۲ مورد  │    │
│  │ حذف انبوه│ │ پیش‌نویس  │ │           │ │  badge     │    │
│  │ ↗ بازکردن│ │  فعال ●   │ │           │ │           │    │
│  └───────────┘ └───────────┘ └───────────┘ └───────────┘    │
│  قیمت‌گذاری …  /  سفارش و ارسال …                             │
├──────────────────────────────────────────────────────────────┤
│  ▸ میان‌بُرهای سیستمی: افزونه‌ها · ووکامرس · تنظیمات · لاگ    │
└──────────────────────────────────────────────────────────────┘
```

- کارت‌ها با `target="_blank" rel="noopener"` (طبق خواستهٔ شما) + `Ctrl/Cmd+Click` همان رفتار مرورگر.
- **کیبورد:** `/` = فیلتر، `↑↓` جابه‌جایی، `Enter` باز کردن، `Esc` پاک‌کردن فیلتر.
- **فیلتر زنده** روی عنوان/توضیح/گروه (بدون سرور).
- وضعیت افزونه از `get_plugins()` + `is_plugin_active()`: فعال / غیرفعال (دکمهٔ «فعال‌سازی» با لینک `activate.php` + nonce) / نیازمند ووکامرس / نسخهٔ قدیمی.
- اگر افزونه‌ای capability کاربر جاری را نداشت، کارت با توضیح «دسترسی ندارید» کم‌رنگ می‌شود (نه حذف) — تا فهرست گمراه‌کننده نباشد.
- بج عددی (مثل تعداد پیش‌نویس‌ها یا اجراهای در صف) از callback افزونه، با کش ۵ دقیقه‌ای تا رندر لانچر ارزان بماند.

### پاک‌سازی منوی شلوغ (اصلاح‌شده در ۱٫۱٫۰ — این مهم‌ترین باگ نسخه‌های قبل بود)
گزینهٔ `hide_scattered` (پیش‌فرض: **روشن**) در همین hub. پیاده‌سازی اولیه این بود:

```php
// ❌ اشتباه: مسیر admin.php?page=… را می‌بندد
remove_submenu_page( 'woocommerce', 'bdc-cleaner' );
remove_menu_page( 'tisacase-desc' );
```

**چرا غلط بود:** وردپرس در `wp-admin/admin.php` صفحهٔ درخواستی را با **گشتن در خودِ `$menu`/`$submenu`** پیدا می‌کند تا `$parent_file` و `page_hook` را بسازد. اگر آیتم از آن آرایه‌ها حذف شود، صفحه دیگر قابل رمزگشایی نیست و هسته پیش از رندر می‌میرد: «با عرض پوزش، شما اجازهٔ دسترسی به این برگه را ندارید» (۴۰۳). یعنی حذفِ آیتم منو = حذفِ مسیر، نه فقط حذفِ لینک. ترفند `add_submenu_page( null, … )` کار می‌کند چون آیتم در `$submenu['']` **می‌ماند**.

```php
// ✅ درست: آرایهٔ منو دست‌نخورده؛ فقط دیده نمی‌شود
add_action( 'admin_print_styles', [ 'TSH_Admin', 'hide_scattered' ], 5 );
// خروجی: <style id="tisa-hide-scattered">
//   #adminmenu .wp-submenu li:has(> a[href*="bdc-cleaner"]){display:none}
//   #adminmenu #toplevel_page_tisacase-desc{display:none}</style>
```

- قاعدهٔ `:has()` آیتم `<li>` را می‌کَنَد و قاعدهٔ دوم (فقط `<a>`) برای مرورگری است که `:has()` ندارد؛ **جدا نوشته می‌شوند** چون یک سلکتور نامعتبر کل rule را باطل می‌کند.
- سلکتورها از `TSH_Registry::menu_entries()` ساخته می‌شوند، پس افزونهٔ جدید بدون تغییر این کد هم پوشش داده می‌شود.
- روی `admin_menu` چاپ **نمی‌کنیم** — آنجا هنوز HTML شروع نشده و `<style>` قبل از `<!DOCTYPE>` می‌افتد (quirks mode).
- با غیرفعال‌کردن hub همه‌چیز برمی‌گردد؛ اگر CSS بلاک شود هم صفحهٔ افزونه باز است (زیبایی، نه دسترسی).

### چیزی که عمداً انجام **نمی‌دهیم**
- **iframe/تب داخلی برای نمایش صفحات افزونه:** با اینکه «خوشگل‌تر» به‌نظر می‌رسد، `get_current_screen()` در صفحهٔ iframe خراب می‌شود، noticeهای وردپرس و `postboxes` و JSهایی که `window.top` را می‌خوانند می‌شکنند، فرم‌های بزرگ در عرض کم به‌هم می‌ریزند و scroll-lock دردسر می‌شود. پس «باز شدن در صفحهٔ جدید» همان انتخاب درست است.
- **ساختن Framework جدا/کتابخانهٔ composer:** برای ۹ افزونهٔ زیپی روی هاست اشتراکی، اضافه‌برندگی است.

---

## ۵) فازهای اجرا

| فاز | کار | خروجی | برآورد |
|-----|-----|-------|--------|
| **۰** | تأیید ۳ تصمیم پایین | — | — |
| **۱** | اسکلت `tisacase-hub`: منو، registry (فیلتر + کشف خودکار از هدر)، رندر کارت‌ها | لانچر کار می‌کند (با استایل فعلی) | ۰٫۵ روز |
| **۲** | `class-tsh-ui.php` + `tisacase-ui.css`: توکن‌ها، کامپوننت‌ها، نرمال‌ساز `body.tisa-scope`، فونت محلی، RTL، reduced-motion | همهٔ صفحات آبی‌رنگ (BDC/TCBPM/PM/importer) یک‌دست می‌شوند | ۰٫۵ روز |
| **۳** | افزودن `add_filter('tisacase_hub_items')` به ۹ افزونه + خط هدر `TisaCase Hub:` | registry کامل، بدون hardcode | ۱–۲ ساعت |
| **۴** | توکنی‌کردن ۴ CSS اختصاصی (حذف هگزها/کسری‌ها، یکسان‌سازی radius و سایز) + حذف CDN فونت | ۳ اردوگاه بصری ادغام می‌شوند | ۰٫۵ روز |
| **۵** | `hide_scattered` + یک `stylelint` کوچک (ممنوعیت هگز خام و `!important` در CSS افزونه‌ها) + اسکرین‌شات‌های مرجع | ضدپوسیدگی | ۲–۳ ساعت |

هر فاز زیپ قابل نصب می‌دهد؛ اگر فازی را نپسندیدید، فقط همان زیپ را عوض می‌کنید. فایل‌های `*.zip` موجود در مخزن را **دست نمی‌زنم**؛ کد را داخل پوشه‌های جدا (مثلاً `plugins/tisacase-hub/`) می‌نویسم و در انتها زیپ تازه می‌سازم.

---

## ۶) سه تصمیمی که قبل از شروع لازم دارم

1. **میزبان توکن‌ها:** (الف) داخل `tisacase-hub` — پیشنهاد من؛ (ب) افزونهٔ جدا `tisacase-ui` که همه وابسته‌اش می‌شوند (تمیزتر ولی یک وابستگی جدید)؛ (ج) کپی vendored در هر افزونه + اسکریپت sync (بدون وابستگی، ولی ۹ نسخه).
2. **آیتم‌های پخش‌شده در ووکامرس/محصولات:** (الف) مخفی شوند و hub تنها ورودی باشد؛ (ب) هر دو بمانند؛ (ج) زیرمنوی افزونه‌ها به hub منتقل شود (`add_submenu_page('tisacase-hub', …)` — یکدست‌ترین، ولی نیازمند ویرایش هر افزونه).
3. **مبنای بصری:** (الف) همان teal+sandِ افزونهٔ توضیحات (بزرگ‌ترین سازگاری با آنچه هست)؛ (ب) مینیمالِ وردپرسی + لهجهٔ teal (کمترین ریسک در تضاد با قالب‌های ادمین)؛ (ج) تیره/مدرن با گرادیان‌های برند.

---

## ۷) ملخّص فنی: «هاب میزبان توکن‌ها» دقیقاً چطور کار می‌کند؟

هیچ‌کدام از ۹ افزونه **هیچ کدی** برای آشنایی با هاب نمی‌نویسند (حالت اختیاری). هاب خودش روی اسکرین‌های آن‌ها سبک‌ها را تزریق می‌کند:

```php
/* tisacase-hub.php — یک‌جا، در فایل اصلی */
add_action( 'admin_enqueue_scripts', 'tisa_ui_inject', 1 );   // اولویت ۱ = زودتر از همه
add_filter( 'body_class',            'tisa_ui_body_class',  20 );

function tisa_ui_screen_ids() {                                 // فهرست مجاز از registry
    $ids = array( 'tisa-case-hub' );
    foreach ( tisacase_hub_items() as $item ) {
        if ( ! empty( $item['screen'] ) ) { $ids[] = $item['screen']; }
    }
    return $ids;   // مثال: woocommerce_page_bdc-cleaner · product_page_tisacase-bulk-price-manager
}

function tisa_ui_inject( $hook ) {
    if ( ! in_array( (string) $hook, tisa_ui_screen_ids(), true ) ) { return; }

    wp_enqueue_style( 'tisacase-ui', TISA_HUB_URL . 'assets/tisacase-ui.css', array(), TISA_HUB_VERSION );
    wp_add_inline_style( 'tisacase-ui', ':root{' . tisa_ui_accent_vars() . '}' );  // رنگ انتخابی کاربر
    wp_enqueue_script( 'tisacase-ui', TISA_HUB_URL . 'assets/tisacase-ui.js', array(), TISA_HUB_VERSION, true );
}

function tisa_ui_body_class( $classes ) {
    if ( in_array( (string) get_current_screen()->id, tisa_ui_screen_ids(), true ) ) {
        $classes[] = 'tisa-scope';        // ← کلید فعال‌شدن لایهٔ نرمال‌ساز
    }
    return $classes;
}
```

سه نکتهٔ مهم:

1. **آی‌دی اسکرین** (`get_current_screen()->id`) تنها راه قابل‌اتکاست، نه `$hook`:
   `woocommerce_page_bdc-cleaner`، `product_page_tisacase-bpm`، `toplevel_page_tisacase-desc`، `edit` (برای نوار SKU) و `post` (برای متاباکس SKU). هر افزونه این رشته را در registry اعلام می‌کند؛ پس اگر افزونه‌ای صفحهٔ جدیدی اضافه کرد، فقط registry به‌روز می‌شود.
2. **ترتیب CASCADE:** استایل مشترک باید **قبل** از CSS خودِ افزونه چاپ شود تا افزونه بتواند در موارد لازم override کند. چون هاب در اولویت ۱ enqueue می‌کند این خودکار درست می‌شود؛ برای اطمینان ۱۰۰٪، هر افزونه یک dependency اضافه می‌کند:
   ```php
   $dep = wp_style_is( 'tisacase-ui', 'registered' ) ? array( 'tisacase-ui' ) : array();
   wp_enqueue_style( 'bdc-admin', ..., $dep, BDC_VERSION );   // همین یک خط در هر افزونه
   ```
3. **همیشه‌سازگار بودن (چیزی که در نسخهٔ پیش‌نمایش با دکمهٔ «روشن/خاموش» تست کردید):** چون توکن‌ها در `:root` یک فایل جدا هستند، در CSS اختصاصی هر افزونه مقدار `var()` باید fallback داشته باشد:
   ```css
   /* داخل case-special-package/assets/admin.css */
   border-radius: var(--tisa-r-lg, 16px);
   color: var(--tisa-primary-ink, #0A5F52);
   ```
   اگر روزی هاب غیرفعال/حذف شود، صفحه‌ها به استایل فعلی خودشان برمی‌گردند — نه به‌هم‌ریخته، نه سفید.

**خروجی جانبی و رایگان:** چون همهٔ توکن‌ها در یک `:root` هستند، یک color-picker در تنظیمات هاب (ذخیره در یک آپشن + چاپ `wp_add_inline_style`) **هر ۹ افزونه را یک‌جا rebrand می‌کند** — بدون لمس دوبارهٔ آن‌ها.

---

## ۸) قرارداد اعداد و قلم (افزوده در نسخهٔ ۰٫۲ — رفع باگِ ستون قیمت)

باگ نسخهٔ ۰٫۱: `font-family: var(--tisa-mono)` روی سلول‌های عددی تحمیل شده بود. `Consolas/Courier` رقم فارسی ندارد → مرورگر رقم را قلم‌به‌قلم عوض می‌کرد و جداکنندهٔ `٬` هم می‌شکست.

**قاعدهٔ نهایی:**

| کاربرد | کلاس | قلم |
|--------|------|-----|
| مبلغ | `.tisa-money` → `.tisa-money__v` + `.tisa-money__cur` | قلم رابط، `tabular-nums lining-nums`، واحد پول کوچک و muted |
| عدد/کمیت/درصد | `.tisa-num` (+ `--lg` برای درشت) | قلم رابط، `tabular` |
| شمارندهٔ بج | `.tisa-count` | قلم رابط + `tabular` |
| SKU، شناسه، نام فایل، کد رهگیری، لاگ | `.tisa-code` / `.tisa-mono` / `.tisa-log` | **مونو** (فقط اینجا) |
| عدد داخل متن فارسی | `.tisa-num--fa` | قلم رابط با `direction: rtl` |
| ورودی عددی | `.tisa-input--number` | قلم رابط + `tabular`، `dir=ltr` |
| ورودی کد | `.tisa-input--code` | مونو، `dir=ltr` |

توکن‌های جدید: `--tisa-num-font` (پیش‌فرض = `--tisa-font`)، `--tisa-num-weight: 600`، `--tisa-num-fs`، `--tisa-num-lh`، `--tisa-num-feat: "tnum" 1, "lnum" 1`.

سه خط قرمز برای همهٔ افزونه‌ها:
1. **یک الگوی رقم در هر صفحه** — یا همه لاتین `1,234,000` یا همه فارسی `۱٬۲۳۴٬۰۰۰`؛ هرگز قاطی (رقم فارسی با ویرگول لاتین برعکس).
2. ستون ترازِ عددی روی **سلول** (`is-num` / `is-money`) و خودِ رقم داخل `.tisa-num` ایزوله می‌شود؛ `direction: ltr` را روی `td` نگذارید چون `text-align:end` را جابه‌جا می‌کند.
3. عدد صفر/ناموجود: `<span class="tisa-num" style>` نه — کلاس `.tisa-num` + رنگ `--tisa-muted-2` و خط تیرهٔ چسبان (`—`).

**چگالی و رنگ انتخابی (افزودهٔ ۰٫۲):** هاب دو کنترل سراسری دارد که هیچ افزونه‌ای از وجودشان خبر ندارد —
`body.tisa-compact` (کاهش فاصله/گوشه/ارتفاع کنترل‌ها) و یک `wp_add_inline_style` که `--tisa-primary*` را از تنظیمات هاب بازنویسی می‌کند. نتیجه: انتخاب یک رنگ در هاب، هر ۹ افزونه را rebrand می‌کند.

پیش‌نمایش زنده: `design/preview/index.html` → بخش‌های ۲ (اعداد) و ۱۲ (هاب: جستجو، `↑↓`، `Enter`، سنجاق، پنل سلامت، رنگ برند، چگالی).


---

## ۹) نسخهٔ ۱٫۰٫۰ — چیزی که ساخته شد (هاب واقعی)

مخزن: `plugins/tisacase-hub/` · زیپ: `tisacase-hub.zip` · راهنما: `HUB.md`

| فایل | نقش |
|------|-----|
| `tisacase-hub.php` | هدر افزونه، ثابت‌ها، `tsh_ver()` (کش‌شکن در WP_DEBUG)، بارگذاری کلاس‌ها |
| `includes/class-tsh-registry.php` | کاتالوگ ۹ افزونه + ۳ میان‌بُر سیستمی؛ کشف خودکار از هدر `TisaCase Hub:`؛ فیلتر `tisacase_hub_items`؛ تولید `screens()` / `page_slugs()` / `menu_entries()` |
| `includes/class-tsh-ui.php` | تنظیمات، دامنهٔ اسکرین (`scope()`)، `admin_enqueue_scripts` با اولویت ۱، `body_class`، مشتق‌سازی پالت از یک هگز، `@font-face` خودکار از `assets/fonts/` |
| `includes/class-tsh-admin.php` | منو/ساب‌منو، مخفی‌کردن آیتم‌های پخش‌شده (اولویت ۹۹)، فعال/غیرفعال‌سازی، مخفی‌کردن کارت، ذخیرهٔ تنظیمات با admin-post (نه options.php تا shop manager هم برود)، ۴ endpoint ajax |
| `includes/class-tsh-counts.php` | ۸ شمارندهٔ کش‌شده با `$wpdb->prepare` / API ووکامرس |
| `includes/class-tsh-health.php` | ۱۰ بررسی ایستا (nopriv، guard در AJAX، REST باز، `extractTo`، uninstall، CDN قلم، هاردکد CSS، i18n، HPOS، خودِ بسته) با کش امضا‌مبتنی بر mtime |
| `includes/class-tsh-view.php` + `tpl-card.php` | آیکون‌های SVG خطی، `num()`، رندر کارت |
| `assets/tisacase-ui.css` | همان فایل مرجع `design/` (توکن‌ها + کامپوننت‌ها + نرمال‌ساز + قرارداد اعداد ۰٫۲ + `body.tisa-compact`) |
| `assets/hub.css` / `assets/hub.js` | فقط لایهٔ لانچر: پوستهٔ ادمین صفحهٔ هاب، سنجاق، جستجو، کیبورد، رنگ برند، ⟳ شمارنده‌ها |
| `templates/hub.php` / `settings.php` / `health.php` | رندر با escape کامل؛ هیچ `echo` خام از ورودی کاربر |
| `uninstall.php` | پاک‌کردن `tisacase_hub_settings`، کش‌ها، متای `tisacase_hub_pins` |

**تصمیم‌های فنی که در متن این سند بازتعریف شد:**
- دامنهٔ استایل با **هر دو** روش ست می‌شود: `screen id` دقیق (از registry) و پسوند `_page_<slug>` — تا اگر وردپرس نام والد را جور دیگری ساخت (`product_page_x` / `edit_page_x`)، باز هم درست باشد.
- صفحه‌های مشترکِ ووکامرس (`edit-product`، `product`، `add-product`) کلید جدا دارند: `style_product_screens`. اگر جدول واریاسیون‌ها به‌هم ریخت، فقط همان خاموش می‌شود.
- تنظیمات با `admin_post` ذخیره می‌شود (نه `options.php`)، چون `options.php` پشت `manage_options` می‌نشیند و shop manager را رد می‌کند.
- `register_setting` هم ثبت شده تا مسیر استاندارد وردپرسی بسته باشد.

**بررسی‌هایی که روی همین ماشین شد (بدون `php`):** `tools/php-check.py` (توازن `{}`, `<?php ?>`, جفت `if/endforeach`، تعداد آرگومان `add_*_page`) روی ۱۲ فایل تمیز؛ `node --check` روی `hub.js`؛ توازن آکولاد CSS (۲۹۹/۲۹۹ و ۶۲/۶۲)؛ ممیز تطبیق selectors بین `hub.js` و قالب‌ها (۱۲ selector، صفر مورد جاافتاده). اجرای واقعی روی هاست شماست.


**بازبینی ۱٫۰٫۱ (تصمیم شما):** هاب زیرمجموعهٔ ووکامرس نمی‌شود. آن «میان‌بُر داخل منوی ووکامرس» که در پیاده‌سازی اول گذاشته بودم حذف شد و جایگاه منو به یک تنظیم تبدیل شد:
`top` (پیش‌فرض، `2.5` — بعد از داشبورد) · `before_wc` (`55.4`) · `after_products` (`30.5`) · `before_tools` (`74.5`) · `default` (خالی = انتهای فهرست) · `custom` (عدد دلخواه، اعشاری هم قبول).
مکانیزم: `add_menu_page()` با آرگومان جایگاه صدا زده می‌شود و اگر انتخاب `default` باشد همان آرگومان فرستاده نمی‌شود؛ `apply_filters('tisacase_hub_menu_position', $pos, $key)` هم برای override کد‌ی باز است.

---

## ۱۰) نسخهٔ ۱٫۱٫۰ — لانچر فقط «دیدن و باز کردن»

سه بازخورد شما روی نسخهٔ نصب‌شده:

| بازخورد | چیزی که شد |
|---|---|
| «صفحهٔ محصولات / افزونه‌ها هم به‌عنوان افزونه نمایش داده می‌شود» | آیتم‌های `system` (plugins.php، `page=wc-admin`، لیست سفارش‌ها) **کاملاً از registry حذف شدند**؛ گروه‌بندی `system` هم رفت. ردیف «میان‌بُرهای وردپرس» و گزینهٔ `system_shortcuts` هم حذف شد — لانچر فقط ۹ افزونهٔ خودتان است. |
| «یک صفحه فقط برای دیدن و باز کردن؛ تحلیل و توضیحات اضافه نمی‌خوام» | از لانچر بیرون رفت: پنل سلامت، دکمهٔ «سلامت افزونه‌ها»، چیپ‌های آمار، نوار سیستم (Woo/PHP/HPOS/قلم)، شمارنده‌های روی کارت + زیرساختشان (`TSH_Counts`)، یادآور «ثبت‌نشده‌ها»، ردیف لینک‌های فرعی، خط توضیح افزونه روی کارت. صفحهٔ «سلامت افزونه‌ها» هم حذف شد (`templates/health.php`، `class-tsh-health.php`). کارت الان این است: آیکون، عنوان، نسخه، وضعیت، [فعال‌سازی]، [باز کردن]، ✕، ★. |
| «روی بازکردن می‌زنم ولی اجازه دسترسی نمیده» | ریشه همان `remove_submenu_page()` بود (بخش «پاک‌سازی منوی شلوغ» بالا) → مخفی‌سازی فقط CSS شد. ضمناً `TSH_Registry::page_cap()` دسترسی **واقعیِ** همان آیتم منو را از `$menu`/`$submenu` می‌خواند (مثلاً `bdc` و `bulk-tracking-upload` روی `manage_options` قفل‌اند، بقیه `manage_woocommerce`)؛ اگر نقش شما کمتر از آن باشد، دکمه به‌جای ۴۰۳، کم‌رنگ و غیرکلیکی با tooltip توضیح می‌دهد. |

کلیدهای تنظیمی که حذف شدند: `show_counts`، `cache_ttl`، `system_shortcuts`، `show_unregistered`. `sanitize_settings()` هم همان‌ها را از لیست بیرون کرد؛ مقادیر قدیمیِ ذخیره‌شده در دیتابیس بی‌ضررند (خوانده نمی‌شوند). جستجو همچنان عنوان + توضیح + پوشهٔ افزونه را می‌خواند، پس با نبودِ خط توضیح روی کارت چیزی گم نمی‌شود.

---

## ۱۱) نسخهٔ ۱٫۲٫۰ — سه تصمیمِ ساختاریِ لانچر

1. **ردیف، نه کارت گرید.** با ۹ ابزار و هدف «فقط ببین و باز کن»، گرید سه‌ستونه همیشه یک ردیف ناقص می‌گذارد (۴+۳+۲). چیدمان `flex-direction: column` با سطرهای ۵۴px هم جا را می‌پُر هم ردیفِ شکستهٔ پایینِ کارت را حذف می‌کند. کامپوننت `.tisa-plugin-card` در لایهٔ مشترک دست‌نخورده ماند (افزونه‌های دیگر همان کارت را در صفحهٔ خودشان دارند)؛ فقط `.tisa-plugin-card--row` در `hub.css` ظاهر را ردیفی می‌کند.
2. **ذخیرهٔ بی‌صدا ممنوع.** رنگ برند و حالت کم‌فضا روی هر ۹ افزونه اثر می‌گذارند، پس روی لانچر با یک کلیک نباید نوشته شوند. الان فقط پیش‌نمایش زنده‌اند (`#tsh-live`) و «ذخیره تنظیمات» آن‌ها را می‌نویسد. سنجاق چون تنظیمِ شخصیِ یک کاربر است، همان‌جا بی‌صدا ذخیره می‌شود.
3. **روشن، همیشه.** ادمین وردپرس پوسته‌های تیره دارد و ما با `color-scheme: light` در `.tisa-wrap/.tisa-scope` اعلام می‌کنیم صفحهٔ کاری روشن است (کنترل‌های بومی — `input[type=color]`، `date`، اسکرول‌بار — سیاه‌روی‌سیاه نمی‌شوند). به‌جای ساختن توکن‌های per-scheme که برای ۹ افزونه به‌صرفه نیست.

قرارداد §۸ (اعداد) این‌بار در JS هم رعایت شد: هر عددی که JS روی صفحه می‌نویسد از `faDigits()` می‌گذرد و پرچم `cfg.fa` از `TSH_View::num(10)` گرفته می‌شود — وگرنه شمارندهٔ لانچر لاتین و بَج گروه‌ها فارسی می‌شد (همان باگی که در v0.1 دیدیم).
