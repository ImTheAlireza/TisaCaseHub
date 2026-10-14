#!/usr/bin/env bash
# هماهنگ‌کردن CSS هاب با فایل مرجع طراحی + ساخت زیپ نصبی.
#   ./tools/build-hub-zip.sh
set -euo pipefail
cd "$(dirname "$0")/.."

SRC=design/tisacase-ui.css
DST=plugins/tisacase-hub/assets/tisacase-ui.css

cp "$SRC" "$DST"
echo "✓ $SRC → $DST ($(wc -l < "$DST") خط)"

python3 tools/php-check.py plugins/tisacase-hub
# گارد چیدمان: پدینگ #wpcontent و مرکزچینی .wrap + هم‌گامی دو نسخهٔ tisacase-ui.css
[ -f tools/layout-guard.mjs ] && node tools/layout-guard.mjs
node --check plugins/tisacase-hub/assets/hub.js && echo "✓ hub.js syntax"

OUT=tisacase-hub.zip
rm -f "$OUT"
( cd plugins && zip -qr "../$OUT" tisacase-hub -x "*.DS_Store" )
echo "✓ $OUT ($(du -h "$OUT" | cut -f1)) — نصب: پیشخوان › افزونه‌ها › افزودن › بارگذاری"
