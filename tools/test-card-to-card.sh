#!/usr/bin/env bash
# Real PHP execution (native or WASM) + SQLite repository + actual PHP-markup/jsdom tests.
set -euo pipefail
cd "$(dirname "$0")/.."
if command -v php >/dev/null 2>&1; then PHP_CMD=(php)
elif [ -f node_modules/@php-wasm/cli/php-wasm.js ]; then PHP_CMD=(node node_modules/@php-wasm/cli/php-wasm.js)
else echo 'PHP لازم است؛ یا npm install --no-save --package-lock=false @php-wasm/cli php-parser jsdom jquery@3.7.1' >&2; exit 2; fi
export TCC_TEST_OUTPUT="$PWD/.cache/tcc-tests"
mkdir -p "$TCC_TEST_OUTPUT"
src='plugins/src/tisacase-card-to-card'
# Some php-wasm CLI releases don't propagate PHP exit(1) to Node's exit code.
# Require the explicit all-green summary as well as a successful process exit.
run_php_test() {
  local output
  if ! output=$("${PHP_CMD[@]}" "$@" 2>&1); then printf '%s\n' "$output"; return 1; fi
  printf '%s\n' "$output"
  if printf '%s\n' "$output" | grep -Eq '^FAIL |Fatal error|Parse error'; then return 1; fi
  if ! printf '%s\n' "$output" | grep -Eq '^Result: .*[, ]0 failed([[:space:](]|$)'; then
    echo 'Missing all-green PHP test summary.' >&2; return 1
  fi
}
run_php_test "$src/tests/run-tests.php"
run_php_test "$src/tests/sql-tests.php"
run_php_test "$src/tests/permissions-tests.php"
for mode in auto unsafe symlink missing; do run_php_test "$src/tests/storage-tests.php" "$mode"; done
node "$src/tests/ui-tests.mjs"
node tools/build-card-to-card-checklist.mjs --check
lint_output=$("${PHP_CMD[@]}" -r '$n=0; foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator("plugins/src/tisacase-card-to-card")) as $f){if($f->getExtension()==="php"){token_get_all(file_get_contents($f->getPathname()),TOKEN_PARSE);$n++;}} echo "PHP ".PHP_VERSION.": $n files parsed\n";')
printf '%s\n' "$lint_output"
if ! printf '%s\n' "$lint_output" | grep -Eq '^PHP [0-9].*: [0-9]+ files parsed$'; then exit 1; fi
python3 tools/php-check.py "$src"
node tools/php-parse.mjs "$src"
for f in "$src"/assets/*.js; do node --check "$f"; done
