import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';

const pluginRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const php = new PHP(await loadNodeRuntime(process.argv[2] || '8.3', { emscriptenOptions: { processId: 2406 } }));
php.mkdirTree('/plugin/includes');
php.mkdirTree('/plugin/tests');
for (const relative of [
    'includes/class-tco-webp-delivery.php',
    'includes/class-tco-settings.php',
    'tests/webp-delivery-tests.php',
]) {
    php.writeFile('/plugin/' + relative, fs.readFileSync(path.join(pluginRoot, relative)));
}

try {
    const result = await php.runStream({ code: '<?php require "/plugin/tests/webp-delivery-tests.php";' });
    const output = await result.stdoutText;
    const errors = await result.stderrText;
    process.stdout.write(output);
    if (errors) process.stderr.write(errors);
    if (!/نتیجه: \d+ موفق، 0 ناموفق/.test(output) || errors) process.exitCode = 1;
} finally {
    php.exit(0);
}
