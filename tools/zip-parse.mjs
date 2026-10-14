#!/usr/bin/env node
/**
 * بررسی سینتکس PHP **داخل خودِ زیپ منتشرشده** با پارسر واقعی (php-parser).
 *
 * چرا جدا از `php-parse.mjs`؟ آن ابزار پوشهٔ منبع را می‌خواند؛ ولی حادثهٔ ۱٫۳٫۲
 * نشان داد چیزی که به سایت کاربر می‌رود همان زیپ است، نه پوشهٔ منبع. این ابزار
 * آخرین حلقهٔ زنجیره را می‌سنجد: بسته‌بندی → محتوای زیپ.
 *
 * اجرا:
 *     node tools/zip-parse.mjs plugins/dist/tisacase-bulk-stock-manager.zip
 * خروج ۱ = خطای سینتکس در زیپ.
 */
import fs from 'node:fs';
import path from 'node:path';
import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const require = createRequire(path.join(ROOT, 'package.json'));

let P;
try {
	P = require('php-parser');
} catch (e) {
	console.error('✗ پارسر PHP نصب نیست. یک‌بار اجرا کنید: npm install php-parser');
	process.exit(2);
}

const zip = process.argv[2] || 'plugins/dist/tisacase-bulk-stock-manager.zip';
if (!fs.existsSync(zip)) {
	console.error('زیپ نیست: ' + zip);
	process.exit(2);
}

const list = execFileSync('unzip', ['-Z1', zip], { encoding: 'utf8' })
	.split('\n')
	.filter((f) => f.endsWith('.php'));

const parser = new P({ parser: { php7: true, suppressErrors: false }, ast: {} });
let bad = 0;

for (const name of list) {
	const src = execFileSync('unzip', ['-p', zip, name], { maxBuffer: 1 << 26 });
	try {
		parser.parseCode(src.toString('utf8'), name);
	} catch (e) {
		bad++;
		console.error('  ✗ ' + name + ' → ' + String(e.message).split('\n')[0]);
	}
}

console.log((bad ? '✗ ' + bad : '✓ 0') + ' خطا در ' + list.length + ' فایل PHP داخل ' + path.basename(zip));
process.exit(bad ? 1 : 0);
