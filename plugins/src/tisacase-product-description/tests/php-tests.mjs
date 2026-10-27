import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const php = new PHP(await loadNodeRuntime(process.argv[2] || '8.3', { emscriptenOptions: { processId: 2715 } }));
php.mkdirTree('/plugin/includes');
php.mkdirTree('/plugin/tests');
php.writeFile('/plugin/includes/class-tisacase-desc-core.php', fs.readFileSync(path.join(root, 'includes/class-tisacase-desc-core.php')));
php.writeFile('/plugin/includes/class-tisacase-desc-admin.php', fs.readFileSync(path.join(root, 'includes/class-tisacase-desc-admin.php')));
php.writeFile('/plugin/tests/run-tests.php', fs.readFileSync(path.join(root, 'tests/run-tests.php')));
try {
  const result = await php.runStream({ code: '<?php require "/plugin/tests/run-tests.php";' });
  const output = await result.stdoutText;
  const errors = await result.stderrText;
  process.stdout.write(output);
  if (errors) process.stderr.write(errors);
  if (!/^Result: \d+ passed, 0 failed/m.test(output) || errors) process.exitCode = 1;
} finally {
  php.exit(0);
}
