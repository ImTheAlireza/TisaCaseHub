import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';

const pluginRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const php = new PHP(await loadNodeRuntime(process.argv[2] || '8.3', { emscriptenOptions: { processId: 2452 } }));
php.mkdirTree('/plugin/tests');
php.writeFile('/plugin/tisa-product-importer.php', fs.readFileSync(path.join(pluginRoot, 'tisa-product-importer.php')));
php.writeFile('/plugin/tests/php-tests.php', fs.readFileSync(path.join(pluginRoot, 'tests/php-tests.php')));

try {
    const result = await php.runStream({ code: '<?php require "/plugin/tests/php-tests.php";' });
    const output = await result.stdoutText;
    const errors = await result.stderrText;
    process.stdout.write(output);
    if (errors) process.stderr.write(errors);
    if (!/^Result: \d+ passed, 0 failed/m.test(output) || errors) process.exitCode = 1;
} finally {
    php.exit(0);
}
