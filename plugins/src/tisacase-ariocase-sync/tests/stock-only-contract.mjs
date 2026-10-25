#!/usr/bin/env node
/** قرارداد اصلی: اتصال دو محصول موجود و نوشتن فقط Stock. */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const files = [];
function walk(dir) { for (const entry of fs.readdirSync(dir, { withFileTypes: true })) { if (entry.name === 'tests') continue; const full = path.join(dir, entry.name); if (entry.isDirectory()) walk(full); else if (/\.(?:php|js)$/.test(entry.name)) files.push(full); } }
walk(root);
let pass = 0; let fail = 0;
function ok(label, condition) { if (condition) { pass++; console.log('OK   ' + label); } else { fail++; console.error('FAIL ' + label); } }
const all = files.map((file) => fs.readFileSync(file, 'utf8')).join('\n');
const forbidden = [
	['ساخت محصول', /new\s+WC_Product|wp_insert_post\s*\(/],
	['حذف محصول', /wp_delete_post\s*\(/],
	['نوشتن نام/SKU/قیمت', /->set_(?:name|sku|price|regular_price|sale_price)\s*\(/],
	['نوشتن توضیح/انتشار/ویژگی', /->set_(?:description|short_description|status|attributes|category_ids|tag_ids)\s*\(/],
	['تغییر تصویر', /media_sideload_image|set_post_thumbnail|->set_(?:image_id|gallery_image_ids)\s*\(/],
	['مسیر Import', /TCAS_Importer|import_start|import_step/],
];
for (const [label, pattern] of forbidden) ok('Production بدون ' + label, !pattern.test(all));
const stock = fs.readFileSync(path.join(root, 'includes/class-tcas-stock.php'), 'utf8');
ok('ذخیرهٔ Stock عددی فعال است', stock.includes('set_stock_quantity'));
ok('ذخیرهٔ وضعیت Stock فعال است', stock.includes('set_stock_status'));
ok('مدیریت انبار فقط در لایهٔ Stock است', stock.includes('set_manage_stock'));
const pairing = fs.readFileSync(path.join(root, 'includes/class-tcas-pairing.php'), 'utf8');
ok('Pairing از لایهٔ Stock استفاده می‌کند', pairing.includes('TCAS_Stock::apply'));
const js = fs.readFileSync(path.join(root, 'assets/admin.js'), 'utf8');
ok('رابط فقط connect_start/connect_step دارد', js.includes("request('connect_start'") && js.includes("request('connect_step'") && !js.includes('prices') && !js.includes('images'));
const main = fs.readFileSync(path.join(root, 'tisacase-ariocase-sync.php'), 'utf8');
const readme = fs.readFileSync(path.join(root, 'readme.txt'), 'utf8');
ok('نسخهٔ Pair-only یکسان است', main.includes('Version:           1.9.0') && main.includes("TCAS_VERSION', '1.9.0'") && readme.includes('Stable tag: 1.9.0'));
console.log(`\n${pass} passed, ${fail} failed`); if (fail) process.exit(1);
