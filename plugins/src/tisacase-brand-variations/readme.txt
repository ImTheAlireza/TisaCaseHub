=== TisaCase — Brand Grouped Variations ===
Contributors: tisacase
Tags: woocommerce, variations, brands, swatches, product page
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
WC requires at least: 5.0
Stable tag: 1.5.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Groups WooCommerce variation options by phone brand (iPhone / Samsung / Xiaomi) on the product page — with separator lines, a searchable picker, brand colors and color swatches. Frontend-only: no product, variation or attribute is ever modified.

== Description ==

A variable product with 154 models in one flat dropdown is unusable on mobile. This plugin classifies those option values into brand groups, puts the brands in the order you choose (iPhone first by default), draws a separator line between them, and turns the whole list into a compact searchable panel.

* Brand rules are editable: keywords, regex, manual whitelist per brand, drag order, per-brand color and icon.
* Unknown values go to a configurable bucket; a built-in analyzer reads the real models of any product in your store and lists what is unclassified (one click to assign).
* Color attributes (رنگ/color) are rendered as real color swatches, driven by an editable name → hex map (50+ Persian color names included).
* Nothing is written to the database: no products, no variations, no attributes, no meta. Uninstalling leaves your catalog untouched.
* Works without JavaScript too (server-side `<optgroup>` grouping), and can fall back to grouping-only mode ("safe mode").
* Dashboards: 4 tabs (Brands / Display / Colors / Advanced) + a live preview using the real frontend CSS/JS.
* **Test mode**: on by default. Until you pick test products, the plugin does nothing anywhere. Add up to 20 products and only those are affected — then flip one switch to apply it store-wide.

== Installation ==

1. Upload `tisacase-brand-variations.zip` through Plugins → Add New → Upload Plugin.
2. Activate it.
3. Go to WooCommerce → «گروه‌بندی متغیرها». **Test mode is already on**: search a product by name in the «حالت تست» card, add one or two products, save, and open them on the frontend.
4. Happy with the result? Press «روشن‌کردن برای کل فروشگاه» (or clear the Test mode checkbox) and save.

== Frequently Asked Questions ==

= Does it change my products or variations? =
No. It only filters the HTML of the attribute dropdown on the frontend and enhances it with JavaScript. All values, prices and stock stay where they are.

= What if my theme renders swatches instead of a dropdown? =
The plugin re-groups the DOM items when it finds a swatch list, and falls back to the native dropdown when the theme keeps the original `<select>`. If a theme misbehaves, turn on "safe mode" and grouping is done server-side only.

= Can I limit it to some categories? =
Yes — Advanced tab → product scope (all / only these categories / all except these).

= How do I test it without touching the whole shop? =
Test mode (the card at the top of the settings page) is on by default. Add the products you want to try, save, and only those products are grouped on the frontend. No other product, category or template output changes while Test mode is on.

== Changelog ==

= 1.5.4 =
* Product category scopes now fail closed when no product ID is available, instead of accidentally treating an unknown product as in scope.
* Server-side dropdown grouping is restricted to single-product pages; shop/category archives and unrelated storefront dropdowns remain untouched.
* Added regression coverage for include/exclude scope, missing IDs, test mode and non-product pages.

= 1.5.3 =
* The search box "clear" (×) button escaped the field on themes that force `position`/`float`/`margin` on buttons: it is now pinned inside the input with `!important` physical *and* logical offsets, with an explicit RTL rule (left side in RTL, right side in LTR).

= 1.5.2 =
* Search filtered the counts but not the list on some themes: options were hidden with the `hidden` attribute only, which a theme rule like `.tcbv-item{display:inline-flex}` can override. Hiding is now enforced with an inline `display:none !important` (plus broader `[hidden]` CSS), so filtering, empty groups and closed dropdowns always hide.

= 1.5.1 =
* Model search fixed: the panel indexed the option *slug* (`a20-a30`, or percent-encoded Persian) instead of the label the customer sees, so typing a model name — especially in Persian — found nothing. Items are now indexed on their visible label plus the decoded slug, and typing a brand name ("سامسونگ", "xiaomi") shows that whole group.
* Brand detection uses the visible label too (frontend panel, server-side optgroups and the admin analyzer), so global attributes with Persian term names are no longer dumped into "other models".

= 1.5.0 =
* Detection engine rewritten around multi-model values: `A20/A30`, `A12/m12`, `A30s/A50/A50s`, `Mi11t/tpro` are now split on `/ \ | , ، ; + &` and each part is matched on its own, so anchored brand patterns finally hit instead of falling into "other models".
* Keyword matching understands glued model codes: `Mi13lite`, `Mi11lite`, `iphone13pro`, `redminote12`, `pocox3` match their brand (a keyword now also matches when the token continues with a digit, or as a prefix for keywords of 4+ characters). Single-letter keywords still require an exact token match.
* Samsung default pattern extended (`note`/`tab`/`F` series, 3-digit numbers, hyphen separators); Xiaomi got a `mi/redmi/poco + number` pattern; a ready "ریلمی" brand (realme/realmi/narzo) is now part of the defaults and the preset library.

= 1.1.0 =
* Test mode (on by default): the plugin only affects the products you pick (up to 20), plus an admin-only "test mode" badge on those product pages and a one-click switch to apply it store-wide. Server-side `<optgroup>` grouping now respects the same scope (it previously ignored the scope setting).

= 1.0.0 =
* First release: brand grouping engine, searchable panel, separators, brand colors, color swatches, analyzer, live preview.
