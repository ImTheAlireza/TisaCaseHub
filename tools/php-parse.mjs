#!/usr/bin/env node
/**
 * بررسی **سینتکس واقعی** فایل‌های PHP با پارسر php-parser.
 *
 * چرا لازم است: `tools/php-check.py` فقط توازن آکولاد/پرانتز و باز-بودن رشته‌ها را
 * نگاه می‌کند و خطای واقعی سینتکس مثل **کامای جاافتاده بین دو عضو آرایه** را
 * نمی‌بیند؛ همان خطایی که در نسخهٔ ۱.۳.۲ افزونهٔ انبار انبوه، کل سایت را با
 * «critical error» خواباند. این اسکریپت همان دسته خطاها را می‌گیرد.
 *
 * نصب پیش‌نیاز:  npm install php-parser
 *
 * استفاده:
 *     node tools/php-parse.mjs [مسیر ...]      # پیش‌فرض: plugins/
 *     node tools/php-parse.mjs --all            # کل مخزن (بدون node_modules)
 *
 * کد خروجی: ۰ = سالم · ۱ = خطای سینتکس · ۲ = پارسر نصب نیست
 */

import fs from 'node:fs';
import path from 'node:path';

const ROOT = path.resolve(new URL('..', import.meta.url).pathname);
const args = process.argv.slice(2);
const all = args.includes('--all');
const targets = args.filter((a) => !a.startsWith('--'));

const SKIP_DIRS = new Set(['node_modules', '.git', 'dist', 'build', 'vendor', '.cache', 'coverage']);

function walk(dir, out = []) {
	for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
		if (entry.name.startsWith('.')) continue;
		const full = path.join(dir, entry.name);
		if (entry.isDirectory()) {
			if (SKIP_DIRS.has(entry.name)) continue;
			walk(full, out);
		} else if (entry.name.endsWith('.php')) {
			out.push(full);
		}
	}
	return out;
}

let PhpParser;
try {
	const mod = await import('php-parser');
	PhpParser = mod.default || mod;
} catch (e) {
	console.error('✗ پارسر PHP نصب نیست. یک‌بار اجرا کنید:  npm install php-parser');
	process.exit(2);
}

const parser = new PhpParser({
	parser: { extractDoc: false, suppressErrors: false, php7: true },
	ast: { withPositions: true },
});

const roots = targets.length ? targets.map((t) => path.resolve(ROOT, t)) : [path.join(ROOT, all ? '.' : 'plugins')];
const files = [];
for (const root of roots) {
	if (!fs.existsSync(root)) continue;
	const stat = fs.statSync(root);
	if (stat.isDirectory()) walk(root, files);
	else if (root.endsWith('.php')) files.push(root);
}

const bad = [];
for (const file of files) {
	const src = fs.readFileSync(file, 'utf8');
	try {
		parser.parseCode(src, file);
	} catch (err) {
		const line = err.lineNumber || (err.loc && err.loc.first_line) || 0;
		bad.push({ file: path.relative(ROOT, file), line, message: String(err.message || err).split('\n')[0] });
	}
}

if (bad.length) {
	console.error('✗ خطای سینتکس PHP در ' + bad.length + ' فایل:');
	for (const b of bad) {
		console.error('  • ' + b.file + ':' + b.line + ' — ' + b.message);
	}
	process.exit(1);
}

console.log('✓ سینتکس PHP سالم است — ' + files.length + ' فایل با پارسر واقعی خوانده شد.');
