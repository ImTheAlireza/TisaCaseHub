import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';

const pluginRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const php = new PHP(await loadNodeRuntime(process.argv[2] || '8.3', { emscriptenOptions: { processId: 8636 } }));
php.mkdirTree('/plugin/tests');
for (const [source, target] of [
    ['wc-telegram-orders.php', '/plugin/wc-telegram-orders.php'],
    ['uninstall.php', '/plugin/uninstall.php'],
    ['tests/stubs.php', '/plugin/tests/stubs.php'],
    ['tests/run-tests.php', '/plugin/tests/run-tests.php'],
]) {
    php.writeFile(target, fs.readFileSync(path.join(pluginRoot, source)));
}

try {
    const result = await php.runStream({ code: '<?php require "/plugin/tests/run-tests.php";' });
    const output = await result.stdoutText;
    const errors = await result.stderrText;
    process.stdout.write(output);
    if (errors) process.stderr.write(errors);
    if (!/نتیجه: \d+ موفق، 0 ناموفق/.test(output) || errors) process.exitCode = 1;
} finally {
    php.exit(0);
}
