#!/usr/bin/env bash
# plugins/src/<dir> را بررسی ایستا می‌کند و به plugins/dist/<dir>.zip بسته‌بندی می‌کند.
# زیپ‌های ریشهٔ مخزن منبع‌اند و دست نمی‌خورند.
set -euo pipefail
cd "$(dirname "$0")/.."
name="${1:-}"
# افزونه‌های مجموعه در plugins/src/ هستند؛ خودِ هاب در plugins/<name>/ می‌ماند.
if [ -d "plugins/src/$name" ]; then src="plugins/src/$name";
elif [ -d "plugins/$name" ]; then src="plugins/$name";
else echo "پوشه نیست: plugins/src/$name یا plugins/$name" >&2; exit 2; fi
top=$(basename "$src")

python3 tools/php-check.py "$src" >/dev/null
# بررسی سینتکس واقعی PHP (خطای کشنده مثل کامای جاافتاده را می‌گیرد).
#   نبودن پارسر = هشدار و ادامه؛ وجود خطای سینتکس = توقف با خطا.
if node -e "require.resolve('php-parser')" >/dev/null 2>&1; then
  node tools/php-parse.mjs "$src"
else
  echo "⚠ php-parser نصب نیست — بررسی سینتکس واقعی PHP رد شد (npm install php-parser)." >&2
fi
# قرارداد AJAX بین PHP و JS: جلوی باگ‌هایی مثل «آرایهٔ کلیددار در برابر آرایهٔ
# تخت» و «کلیدی که لایهٔ AJAX می‌خواند ولی متد برنمی‌گرداند» را می‌گیرد.
if [ -f tools/ajax-contract-check.mjs ]; then
  node tools/ajax-contract-check.mjs "$src" || { echo "✗ قرارداد AJAX نقض شده — زیپ ساخته نشد." >&2; exit 1; }
fi
# تست رگرسیون پیش‌نمایش (jsdom) — اگر پیش‌نیازهایش نصب باشند اجرا می‌شود.
if [ -f tools/bulk-stock-manager-preview-test.mjs ] && node -e "require.resolve('jsdom')" >/dev/null 2>&1; then
  # مهم: خروجی را پایپ نمی‌کنیم تا کد خروج تست پاک نشود؛ تست قرمز = بسته‌بندی متوقف می‌شود.
  if ! test_out=$(node tools/bulk-stock-manager-preview-test.mjs); then
    echo "$test_out" | tail -25 >&2
    echo "✗ تست رگرسیون پیش‌نمایش قرمز شد — زیپ ساخته نشد." >&2
    exit 1
  fi
  echo "$test_out" | tail -1
else
  echo "⚠ jsdom نصب نیست — تست رگرسیون پیش‌نمایش رد شد (npm install jsdom jquery@3.7.1)." >&2
fi
js=$(find "$src" -name "*.js" -not -name "*.min.js" | head -20 || true)
for f in $js; do node --check "$f" >/dev/null || { echo "JS خطا دارد: $f" >&2; exit 1; }; done

mkdir -p plugins/dist
out="plugins/dist/$top.zip"
rm -f "$out"
( cd "$(dirname "$src")" && zip -qrD "$OLDPWD/$out" "$top" -x "*/.DS_Store" "*/__MACOSX/*" )

files=$(unzip -l "$out" | tail -1 | awk '{print $2}')
echo "✓ $out ($(du -h "$out" | cut -f1 | tr -d ' ') · $files فایل) — نصب: پیشخوان › افزونه‌ها › بارگذاری (یا حذف و جایگزینی)"
