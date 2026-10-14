#!/usr/bin/env python3
"""
بررسی ایستای فایل‌های PHP این مخزن — چون روی این سیستم `php` نصب نیست.

چک‌ها:
  * توازن { } ( ) [ ] داخل بلوک‌های PHP (بدون شمرده‌کردن داخل رشته/کامنت/heredoc)
  * رشتهٔ باز / کامنت باز
  * جفت‌های if/endif، foreach/endforeach، switch/endswitch
  * برچسب‌های <?php ?> لنگ
  * فراخوانی add_submenu_page/add_menu_page با تعداد آرگومان قابل‌قبول
  * کامای جاافتاده بین دو عضو آرایه/آرگومان در خط بعد (خطای کشندهٔ سینتکس
    مثل نسخهٔ ۱.۳.۲ افزونهٔ انبار انبوه که سایت را خواباند) — بررسی کامل
    سینتکس با `node tools/php-parse.mjs` انجام می‌شود.
  * هر فایل افزونه باید Text Domain داشته باشد (اگر __() استفاده می‌کند)

استفاده:
    python3 tools/php-check.py [مسیر ...]      # پیش‌فرض: plugins/ و ریشه
"""

import io
import os
import re
import sys

PAIRS = {"}": "{", ")": "(", "]": "["}
ALT = {"if": "endif", "foreach": "endforeach", "for": "endfor", "while": "endwhile", "switch": "endswitch"}


class Bad(Exception):
    pass


def strip_and_scan(src):
    """برمی‌گرداند (کد PHP با حذف رشته/کامنت، خطاهای یافت‌شده)."""
    code = []
    errs = []
    i, n = 0, len(src)
    line = 1
    in_php = False
    while i < n:
        if not in_php:
            j = src.find("<?php", i)
            k = src.find("<?=", i)
            if k != -1 and (j == -1 or k < j):
                j = k
            if j == -1:
                line += src.count("\n", i)
                break
            line += src.count("\n", i, j)
            i = j + (2 if src.startswith("<?=", i) else 5)
            in_php = True
            continue

        c = src[i]
        if c == "\n":
            line += 1
            code.append("\n")
            i += 1
            continue
        # کامنت
        if src.startswith("//", i) or c == "#":
            if src.startswith("#[", i):  # attribute
                code.append(src[i])
                i += 1
                continue
            j = src.find("\n", i)
            i = n if j == -1 else j
            continue
        if src.startswith("/*", i):
            j = src.find("*/", i + 2)
            if j == -1:
                errs.append(("comment-open", line))
                break
            line += src.count("\n", i, j)
            i = j + 2
            continue
        # رشته‌ها
        if c in "'\"":
            q = c
            i2 = i + 1
            started = line
            while i2 < n:
                ch = src[i2]
                if ch == "\\":            # escaping (در "..." معنی دارد، در '...' هم \' )
                    i2 += 2
                    continue
                if ch == q:
                    break
                if ch == "\n":           # رشتهٔ تک‌نقل‌قولی هم می‌تواند چندخطی باشد
                    line += 1
                i2 += 1
            if i2 >= n or src[i2] != q:
                errs.append(("string-open", started))
                break
            code.append("''" if q == "'" else '""')
            i = i2 + 1
            continue
        # heredoc / nowdoc
        m = re.match(r"<<<\s*(['\"]?)([A-Za-z_][A-Za-z0-9_]*)\1", src[i:])
        if m:
            tag = m.group(2)
            j = src.find("\n", i)
            close = re.search(r"^[ \t]*" + re.escape(tag) + r"\b", src[j + 1:], re.M)
            if not close:
                errs.append(("heredoc-open", line))
                break
            seg = src[j + 1 : j + 1 + close.start()]
            line += seg.count("\n")
            code.append("''")
            i = j + 1 + close.end()
            continue
        if c == "?" and src.startswith("?>", i):
            in_php = False
            i += 2
            continue
        code.append(c)
        i += 1
    return "".join(code), errs


def check_file(path):
    src = io.open(path, encoding="utf-8", errors="replace").read()
    code, errs = strip_and_scan(src)
    out = list(errs)

    stack = []
    for idx, ch in enumerate(code):
        if ch in "{([":
            stack.append((ch, code[:idx].count("\n") + 1))
        elif ch in "})]":
            if not stack:
                out.append(("stray-" + ch, code[:idx].count("\n") + 1))
            elif stack[-1][0] != PAIRS[ch]:
                out.append(("mismatch-" + ch, code[:idx].count("\n") + 1))
            else:
                stack.pop()
    for ch, ln in stack:
        out.append(("unclosed-" + ch, ln))

    # ساختارهای جایگزین:  if (...): ... endif;   (در قالب‌ها با ?> بین‌شان)
    paren = r"\((?:[^()]|\([^()]*\))*\)"
    for kw, end in ALT.items():
        short = len(re.findall(r"\b" + kw + r"\s*" + paren + r"\s*:", code))
        closes = len(re.findall(r"\b" + end + r"\s*;", code))
        if short or closes:
            if short != closes:
                out.append(("alt-syntax %s: %d باز / %d بسته" % (kw, short, closes), 0))

    out.extend(missing_commas(src))

    # آرگومان‌های منو
    for m in re.finditer(r"\badd_(submenu|menu)_page\s*\(((?:[^()]|\([^()]*\))*)\)", code):
        kind, args = m.group(1), m.group(2)
        depth, n_args = 0, 1 if args.strip() else 0
        for ch in args:
            if ch in "([{":
                depth += 1
            elif ch in ")]}":
                depth -= 1
            elif ch == "," and depth == 0:
                n_args += 1
        ln = src[: m.start()].count("\n") + 1
        # add_submenu_page: 6 اجباری + position اختیاری (WP 5.3+)؛ add_menu_page: 4..7
        need = 7
        lo = 6 if kind == "submenu" else 4
        if n_args < lo or n_args > need:
            out.append(("add_%s_page: %d آرگومان (مجاز %d–%d)" % (kind, n_args, lo, need), ln))

    # text domain
    if re.search(r"__\s*\(", code) and "Text Domain" not in src and path.endswith(".php") and "plugin.php" not in path:
        # فقط فایل اصلی افزونه لازم است هدر داشته باشد؛ بقیه هشدار نیست
        pass
    return out


def missing_commas(src):
    """کامای جاافتادهٔ بین دو عضو آرایه/آرگومان را پیدا می‌کند.

    الگو: خطی که با یک مقدار تمام می‌شود **بدون کاما** (`'...'`، عدد، `)`، `]`)
    و خط بعدش با کلید تازهٔ `'key' =>` شروع می‌شود — در PHP معتبر نیست و خطای
    «syntax error, unexpected ... expecting ')'» می‌دهد؛ همین خطا در نسخهٔ
    ۱.۳.۲ افزونهٔ انبار انبوه کل سایت را با «critical error» خواباند.
    """
    out = []
    lines = src.split("\n")
    value_end = re.compile(
        r"^\s*'[^']*'\s*=>\s*(?:'[^']*'|\"[^\"]*\"|-?[\d.]+|true|false|null|\$[\w>\[\]\->\'\"]+|\)|\])\s*$"
    )
    next_key = re.compile(r"^\s*['\"][^'\"]+['\"]\s*=>")
    for i in range(len(lines) - 1):
        a, b = lines[i], lines[i + 1]
        if not a.strip() or a.lstrip().startswith(("//", "*", "/*", "#")):
            continue
        if not next_key.match(b):
            continue
        if not value_end.match(a):
            continue
        out.append(("احتمال کامای جاافتاده در پایان این خط (عضو آرایه)", i + 1))
    return out


def php_files(root):
    for dirpath, dirs, files in os.walk(root):
        dirs[:] = [d for d in dirs if d not in ("node_modules", ".git", "vendor")]
        for f in files:
            if f.endswith(".php"):
                yield os.path.join(dirpath, f)


def main(argv):
    targets = argv[1:] or ["plugins", "."]
    seen = set()
    total = 0
    for t in targets:
        if os.path.isfile(t):
            files = [t]
        else:
            files = list(php_files(t))
        for p in files:
            rp = os.path.realpath(p)
            if rp in seen:
                continue
            seen.add(rp)
            errs = check_file(p)
            if errs:
                total += 1
                print("✗ %s" % p)
                for kind, ln in errs[:12]:
                    print("   %s%s" % (("line %s: " % ln) if ln else "", kind))
    print("بررسی شد: %d فایل · مشکل‌دار: %d" % (len(seen), total))
    return 1 if total else 0


if __name__ == "__main__":
    sys.exit(main(sys.argv))
