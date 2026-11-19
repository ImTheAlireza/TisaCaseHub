import fs from 'node:fs';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';
const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: 8635 } }));
php.writeFile('/gateway.php', fs.readFileSync('plugins/src/tisacase-snappay-link/includes/class-tspay-gateway.php'));
try {
 const result = await php.run({code: `<?php
 define('ABSPATH', '/'); define('TSPAY_GATEWAY', 'tisacase_snappay_link');
 class WC_Payment_Gateway {}
 require '/gateway.php';
 class ReceiptOrder {
 public $method = TSPAY_GATEWAY; public $paid = false; public $status = 'pending'; public $meta = array();
 function get_payment_method() { return $this->method; }
 function is_paid() { return $this->paid; }
 function has_status($status) { return $this->status === $status; }
 function get_meta($key, $single) { return $this->meta[$key] ?? ''; }
 }
 function check($order, $expected) {
 if (TSPAY_Gateway::order_received_text('original', $order) !== $expected) { throw new Exception('Receipt mismatch'); }
 }
 check(false, 'original');
 $o = new ReceiptOrder(); check($o, '');
 $o->status = 'on-hold'; check($o, '');
 $o->meta['_tspay_customer_reported_paid'] = 'yes'; check($o, '');
 $o->meta['_tspay_customer_tracking_code'] = '123456';
 check($o, 'کد رهگیری شما دریافت شد و نتیجه خدمت شما اعلام می‌گردد.');
 $o->status = 'failed'; check($o, '');
 $o->status = 'cancelled'; check($o, '');
 $o->paid = true; check($o, 'original');
 $o->paid = false; $o->method = 'cod'; check($o, 'original');
 echo 'PASS: 9 receipt scenarios';
 `});
 if (result.errors || result.exitCode) throw new Error(result.errors || 'PHP failed');
 console.log(result.text);
} finally { php.exit(0); }
