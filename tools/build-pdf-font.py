#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
سازندهٔ قلم و جدول شکل‌دهی PDF برای افزونهٔ «خروجی گرفتن».

از فونت وزیرمتن (Vazirmatn، پروانهٔ OFL-1.1) دو زیرمجموعهٔ سبک می‌سازد و همهٔ
جدول‌های لازم برای نوشتن متن فارسی در PDF را **از خود فونت** بیرون می‌کشد:

  ۱) assets/fonts/vazirmatn-pdf.ttf و vazirmatn-pdf-bold.ttf  ← قلم‌های جاسازی
  ۲) includes/class-tce-pdf-font-data.php ← cp→GID، چهار فرم هر حرف (از GSUB)،
     لیگاتور لام-الف، عرض پیشروی هر گلیف و متریک‌های FontDescriptor

چرا GID و نه کدپوینت؟ چون PDF با Identity-H کار می‌کند و شکل‌دهی فارسی
(init/medi/fina) در همین اسکریپت از GSUB خودِ فونت استخراج می‌شود؛ پس در زمان
اجرا نه پارس فونت لازم است و نه موتور OpenType.

نیازمندی‌ها (فقط زمان ساخت):
    pip install fonttools brotli
    npm pack vazirmatn     →  package/fonts/ttf/Vazirmatn-{Regular,Bold}.ttf

اجرا:
    python3 tools/build-pdf-font.py /tmp/fontpkg/package/fonts/ttf
"""

import os
import sys

from fontTools import subset
from fontTools.ttLib import TTFont

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PLUGIN = os.path.join(ROOT, 'plugins', 'src', 'tisacase-exporter')
FONT_DIR = os.path.join(PLUGIN, 'assets', 'fonts')
DATA_OUT = os.path.join(PLUGIN, 'includes', 'class-tce-pdf-font-data.php')

WEIGHTS = [
    ('regular', 'Vazirmatn-Regular.ttf', 'vazirmatn-pdf.ttf', 'Vazirmatn'),
    ('bold', 'Vazirmatn-Bold.ttf', 'vazirmatn-pdf-bold.ttf', 'Vazirmatn-Bold'),
]

# محدوده‌های نگه‌داشته‌شده: لاتین پایه، عربی/فارسی، فرم‌های نمایشی، نشانه‌ها.
RANGES = [
    (0x0020, 0x007E), (0x00A0, 0x00FF), (0x0600, 0x06FF), (0x0700, 0x074F),
    (0x200C, 0x200F), (0x2010, 0x2027), (0x2030, 0x203B), (0x2044, 0x2044),
    (0x20AC, 0x20AC), (0x20AA, 0x20AA), (0x2190, 0x2199),
    (0xFB50, 0xFDFF), (0xFE70, 0xFEFF),
]
# لام + الف: (فرم جدا، فرم پایانی) طبق بلوک فرم‌های نمایشی یونیکد.
LAM_ALEF = {0x0622: (0xFEF5, 0xFEF6), 0x0623: (0xFEF7, 0xFEF8),
            0x0625: (0xFEF9, 0xFEFA), 0x0627: (0xFEFB, 0xFEFC)}
# نشان‌های اعرابی: با عرض صفر روی حرف قبل رسم می‌شوند.
MARKS = list(range(0x064B, 0x0656)) + [0x0670] + list(range(0x06D6, 0x06EE))


def wanted(cp):
    return any(a <= cp <= b for a, b in RANGES)


def feature_lookups(gsub, tags):
    """مپِ نام‌گلیف→نام‌گلیف برای فیچرهای خواسته‌شده (فقط SingleSubst)."""
    out, seen = {}, set()
    for record in gsub.table.FeatureList.FeatureRecord:
        if record.FeatureTag not in tags:
            continue
        for index in record.Feature.LookupListIndex:
            if index in seen:
                continue
            seen.add(index)
            for sub in gsub.table.LookupList.Lookup[index].SubTable:
                out.update(getattr(sub, 'mapping', {}) or {})
    return out


def build(weight, src_name, out_name):
    src = os.path.join(sys.argv[1], src_name)
    font = TTFont(src)
    cmap = font.getBestCmap()
    gsub = font.get('GSUB')
    init = feature_lookups(gsub, {'init'})
    medi = feature_lookups(gsub, {'medi'})
    fina = feature_lookups(gsub, {'fina'})

    keep = sorted(cp for cp in cmap if wanted(cp))
    extra = sorted({t for table in (init, medi, fina) for t in table.values()
                    if isinstance(t, str)})
    print('%-8s GSUB init:%d medi:%d fina:%d · cp:%d · جانشین:%d'
          % (weight, len(init), len(medi), len(fina), len(keep), len(extra)))

    opts = subset.Options()
    opts.hinting = False
    opts.desubroutinize = True
    opts.drop_tables += ['GSUB', 'GPOS', 'GDEF', 'kern', 'gasp', 'prep', 'DSIG',
                         'VORG', 'MATH']
    opts.name_IDs = ['*']
    opts.notdef_outline = True
    opts.glyph_names = True  # نام‌ها باید بمانند تا نقشهٔ نام→GID معتبر باشد.

    out = subset.load_font(src, opts)
    sub = subset.Subsetter(options=opts)
    sub.populate(unicodes=keep, glyphs=extra)
    sub.subset(out)
    os.makedirs(FONT_DIR, exist_ok=True)
    dest = os.path.join(FONT_DIR, out_name)
    subset.save_font(out, dest, opts)

    # ---- جدول‌ها از فونتِ زیرمجموعه (GID نهایی) ----
    subfont = TTFont(dest)
    glyphs = subfont.getGlyphOrder()
    gid_of = {name: i for i, name in enumerate(glyphs)}
    submap = subfont.getBestCmap()
    cp_gid = {cp: gid_of[name] for cp, name in submap.items()
              if wanted(cp) and name in gid_of}

    forms = {}
    for cp in sorted(cp_gid):
        if not (0x0600 <= cp <= 0x08FF or 0xFB50 <= cp <= 0xFDFF):
            continue
        if cp in MARKS:
            continue
        name = cmap.get(cp)
        row = [cp_gid[cp],
               gid_of.get(fina.get(name, ''), 0),
               gid_of.get(init.get(name, ''), 0),
               gid_of.get(medi.get(name, ''), 0)]
        if max(row) > 0:
            forms[cp] = row

    lig = {}
    for alef, pair in LAM_ALEF.items():
        a, b = cp_gid.get(pair[0], 0), cp_gid.get(pair[1], 0)
        if a and b:
            lig[(0x0644, alef)] = [a, b]

    hmtx = subfont['hmtx']
    head, hhea, os2, post = subfont['head'], subfont['hhea'], subfont['OS/2'], subfont['post']
    metrics = {
        'unitsPerEm': head.unitsPerEm,
        'ascent': hhea.ascent,
        'descent': hhea.descent,
        'lineGap': hhea.lineGap,
        'xMin': head.xMin, 'yMin': head.yMin, 'xMax': head.xMax, 'yMax': head.yMax,
        'capHeight': getattr(os2, 'sCapHeight', 0) or int(head.unitsPerEm * 0.7),
        'italicAngle': float(post.italicAngle or 0),
        'stemV': 80 if weight == 'regular' else 160,
        'numGlyphs': len(glyphs),
        'size': os.path.getsize(dest),
    }
    widths = [(i, hmtx[glyphs[i]][0]) for i in range(len(glyphs))]
    print('%-8s گلیف:%d · شکل‌پذیر:%d · لیگاتور:%d · %d بایت'
          % (weight, len(glyphs), len(forms), len(lig), metrics['size']))
    return {
        'file': out_name, 'family': 'Vazirmatn', 'weight': weight,
        'metrics': metrics, 'codepoints': sorted(cp_gid.items()),
        'forms': sorted(forms.items()), 'ligatures': sorted(lig.items()),
        'widths': widths,
    }


def php_rows(rows, fmt, indent='\t\t\t\t'):
    return [indent + fmt(k, v) for k, v in rows]


def php_font(key, data):
    lines = ["\t\t\t'%s' => array(" % key]
    lines.append("\t\t\t\t'file'    => '%s'," % data['file'])
    lines.append("\t\t\t\t'family'  => '%s'," % data['family'])
    lines.append("\t\t\t\t'weight'  => '%s'," % data['weight'])
    lines.append("\t\t\t\t'metrics' => array(")
    for k, v in data['metrics'].items():
        lines.append("\t\t\t\t\t'%s' => %s," % (k, ('%d' % v) if isinstance(v, int) else repr(v)))
    lines.append("\t\t\t\t),")
    lines.append("\t\t\t\t'codepoints' => array(")
    lines += php_rows(data['codepoints'], lambda k, v: '%d => %d,' % (k, v))
    lines.append("\t\t\t\t),")
    lines.append("\t\t\t\t'forms' => array(")
    lines += php_rows(data['forms'],
                      lambda k, v: '%d => array( %s ),' % (k, ', '.join(str(x) for x in v)))
    lines.append("\t\t\t\t),")
    lines.append("\t\t\t\t'ligatures' => array(")
    lines += php_rows(data['ligatures'],
                      lambda k, v: "'%d-%d' => array( %d, %d )," % (k[0], k[1], v[0], v[1]))
    lines.append("\t\t\t\t),")
    lines.append("\t\t\t\t'widths' => array(")
    lines += php_rows(data['widths'], lambda k, v: '%d => %d,' % (k, v))
    lines.append("\t\t\t\t),")
    lines.append("\t\t\t),")
    return lines


def main():
    if len(sys.argv) < 2:
        print(__doc__)
        return 2

    fonts = [(key, build(key, src, dest)) for key, src, dest, _ in WEIGHTS]

    parts = [
        '<?php',
        '/**',
        ' * دادهٔ قلم‌های PDF (وزیرمتن، OFL-1.1) — ساختهٔ tools/build-pdf-font.py؛ دست نزنید.',
        ' *',
        ' * @package TisaCase_Exporter',
        ' */',
        '',
        "defined( 'ABSPATH' ) || exit;",
        '',
        "if ( ! class_exists( 'TisaCase_Exporter_Pdf_Font_Data' ) ) {",
        '',
        "\tfinal class TisaCase_Exporter_Pdf_Font_Data {",
        '',
        "\t\t/** پروانهٔ قلم — برای درج در readme و صفحهٔ راهنما. */",
        "\t\tconst LICENSE = 'SIL Open Font License 1.1';",
        "\t\tconst SOURCE = 'https://github.com/rastikerdar/vazirmatn';",
        '',
        "\t\t/** نشان‌های اعرابی: عرض صفر، روی حرف قبل رسم می‌شوند. */",
        "\t\tconst MARKS = array( " + ', '.join(str(c) for c in MARKS) + " );",
        '',
        "\t\t/** نیم‌فاصله: گلیف ندارد، فقط پیوند را می‌شکند. */",
        "\t\tconst ZWNJ = 0x200C;",
        '',
        "\t\t/**",
        "\t\t * قلم‌های جاسازی‌شده: کلید → مشخصات کامل.",
        "\t\t *",
        "\t\t * - metrics    : متریک‌های قلم (واحد em) برای FontDescriptor و چیدمان.",
        "\t\t * - codepoints : کدپوینت → GID (فقط نویسه‌های پشتیبانی‌شده).",
        "\t\t * - forms      : حرف → (isol، fina، init، medi) به‌صورت GID؛ ۰ یعنی ندارد.",
        "\t\t * - ligatures  : لام + الف → (GID فرم جدا، GID فرم پایانی).",
        "\t\t * - widths     : GID → عرض پیشروی (واحد em).",
        "\t\t */",
        "\t\tconst FONTS = array(",
    ]
    for key, data in fonts:
        parts += php_font(key, data)
    parts += [
        "\t\t);",
        "\t}",
        "}",
        '',
    ]

    with open(DATA_OUT, 'w', encoding='utf-8') as fh:
        fh.write('\n'.join(parts))
    print('جدول: %s (%d بایت)' % (os.path.relpath(DATA_OUT, ROOT), os.path.getsize(DATA_OUT)))
    total = sum(d['metrics']['size'] for _, d in fonts)
    print('حجم قلم‌ها: %d بایت' % total)
    return 0


if __name__ == '__main__':
    sys.exit(main())
