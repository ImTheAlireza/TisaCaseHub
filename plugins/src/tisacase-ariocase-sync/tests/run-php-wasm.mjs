#!/usr/bin/env node
/** اجرای تست‌های واقعی PHP بدون نصب PHP روی سیستم توسعه. */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { PHP } from '@php-wasm/universal';
import { loadNodeRuntime } from '@php-wasm/node';

const here = path.dirname(fileURLToPath(import.meta.url));
const plugin = path.resolve(here, '..');
const suites = [
	{ file: 'tests/normalizer-tests.php', sources: ['includes/class-tcas-core.php', 'includes/class-tcas-normalizer.php'] },
	{ file: 'tests/core-api-tests.php', sources: ['includes/class-tcas-core.php', 'includes/class-tcas-normalizer.php', 'includes/class-tcas-api.php'] },
	{ file: 'tests/link-resolver-tests.php', sources: ['includes/class-tcas-local-variation-reader.php', 'includes/class-tcas-link-resolver.php'] },
	{ file: 'tests/matcher-tests.php', sources: ['includes/class-tcas-matcher.php'] },
	{ file: 'tests/stock-live-tests.php', sources: ['includes/class-tcas-core.php', 'includes/class-tcas-mapping.php', 'includes/class-tcas-stock.php', 'includes/class-tcas-live.php'] },
	{ file: 'tests/pairing-tests.php', sources: ['includes/class-tcas-mapping.php', 'includes/class-tcas-stock.php', 'includes/class-tcas-pairing.php'] },
	{ file: 'tests/ajax-job-tests.php', sources: ['includes/class-tcas-matcher.php', 'includes/class-tcas-ajax.php'] },
	{ file: 'tests/smart-match-integration-tests.php', sources: ['includes/class-tcas-local-variation-reader.php', 'includes/class-tcas-link-resolver.php', 'includes/class-tcas-matcher.php', 'includes/class-tcas-ajax.php'] },
	{ file: 'tests/provider-tests.php', sources: ['includes/class-tcas-core.php', 'includes/interface-tcas-partner-provider.php', 'includes/providers/class-tcas-ariocase-provider.php', 'includes/class-tcas-partners.php'] },
];
let failed = false;
let pid = 740;
for (const suite of suites) {
	const php = new PHP(await loadNodeRuntime('8.3', { emscriptenOptions: { processId: ++pid } }));
	php.mkdirTree('/plugin/includes');
	php.mkdirTree('/plugin/includes/providers');
	php.mkdirTree('/plugin/tests');
	for (const file of [...suite.sources, suite.file]) php.writeFile('/plugin/' + file, fs.readFileSync(path.join(plugin, file)));
	const output = await php.runStream({ code: `<?php define("TCAS_TEST_NO_EXIT", true); require "/plugin/${suite.file}";` });
	const stdout = await output.stdoutText;
	const stderr = await output.stderrText;
	process.stdout.write('\n=== ' + suite.file + ' ===\n' + stdout);
	if (stderr) process.stderr.write(stderr);
	php.exit(0);
	if (!/\b0 failed\b/.test(stdout) || stderr) failed = true;
}
if (failed) process.exit(1);
