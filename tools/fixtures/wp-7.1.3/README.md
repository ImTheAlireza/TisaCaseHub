# فیکسچرهای CSS پیشخوان وردپرس (۷٫۱٫۳ · RTL)

این فایل‌ها کپی دست‌نخوردهٔ CSS هستهٔ وردپرس از تگ `7.1.3` هستند و فقط برای
سنجش **هندسهٔ واقعی** صفحه در `tools/layout-geometry.mjs` استفاده می‌شوند:

| فایل | منبع |
|---|---|
| `common-rtl.css` | `wp-admin/css/common-rtl.css` |
| `admin-menu-rtl.css` | `wp-admin/css/admin-menu-rtl.css` |
| `forms-rtl.css` | `wp-admin/css/forms-rtl.css` |
| `l10n-rtl.css` | `wp-admin/css/l10n-rtl.css` |

دلیل وجودشان: صفحهٔ ما زیر همین قواعد چیده می‌شود (`#wpcontent{padding-right:20px}`
و `#wpcontent,#wpfooter{margin-right:160px}` و `#wpbody-content{float:right;width:100%}`)؛
بدون آن‌ها سنجهٔ چیدمان بی‌معنا می‌شود. وردپرس GPLv2 است.

بازتولید (در صورت نیاز به نسخهٔ دیگر):

```bash
gh api "repos/WordPress/WordPress/contents/wp-admin/css/common-rtl.css?ref=7.1.3" --jq .content | base64 -d > common-rtl.css
```
