import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';

const pluginRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const phpVersion = process.argv[2] || '8.3';
const php = new PHP(await loadNodeRuntime(phpVersion, { emscriptenOptions: { processId: 2405 } }));
php.mkdirTree('/plugin/tests');
php.writeFile('/plugin/bulk-tracking-upload.php', fs.readFileSync(path.join(pluginRoot, 'bulk-tracking-upload.php')));
php.writeFile('/plugin/tests/run-tests.php', fs.readFileSync(path.join(pluginRoot, 'tests/run-tests.php')));

try {
    const result = await php.runStream({ code: '<?php require "/plugin/tests/run-tests.php";' });
    const output = await result.stdoutText;
    const errors = await result.stderrText;
    process.stdout.write(output);
    if (errors) process.stderr.write(errors);
    if (!/نتیجه: \d+ موفق، 0 ناموفق/.test(output) || errors) {
        process.exitCode = 1;
    }
} finally {
    php.exit(0);
}
