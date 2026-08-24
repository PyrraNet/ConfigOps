import assert from 'node:assert/strict';
import { readFile, writeFile } from 'node:fs/promises';

const [targetPath, adapterId, version] = process.argv.slice(2);
if (!targetPath || !adapterId || !version) {
	throw new Error('Usage: node tests/materialize-adapter-version-blueprint.mjs <target> <adapter-id> <version>');
}

const matrix = JSON.parse(await readFile(new URL('adapter-support-matrix.json', import.meta.url), 'utf8'));
const adapter = matrix.adapters.find((entry) => entry.id === adapterId);
assert.ok(adapter, `Unknown adapter ${adapterId}`);
assert.ok(
	adapter.activeLines.some((entry) => entry.contractRelease === version),
	`${adapterId} ${version} is not a recorded active-line contract release`,
);

const pluginFiles = {
	'wp-mail-smtp': 'wp-mail-smtp/wp_mail_smtp.php',
	'yoast-seo': 'wordpress-seo/wp-seo.php',
	woocommerce: 'woocommerce/woocommerce.php',
};
const phpString = (value) => `'${String(value).replaceAll('\\', '\\\\').replaceAll("'", "\\'")}'`;
const expectation = {
	id: adapter.id,
	slug: adapter.slug,
	version,
	pluginFile: pluginFiles[adapter.id],
};
const expectationPhp = `array(${Object.entries(expectation)
	.map(([key, value]) => `${phpString(key)} => ${phpString(value)}`)
	.join(', ')})`;

const blueprint = {
	$schema: 'https://playground.wordpress.net/blueprint-schema.json',
	preferredVersions: { php: '8.3', wp: 'latest' },
	steps: [
		{
			step: 'defineWpConfigConsts',
			consts: { WP_DISABLE_FATAL_ERROR_HANDLER: true },
		},
		{
			step: 'installPlugin',
			pluginData: {
				resource: 'url',
				url: `https://downloads.wordpress.org/plugin/${adapter.slug}.${version}.zip`,
			},
			options: { activate: true },
		},
		{
			step: 'runPHP',
			code: `<?php require '/wordpress/wp-load.php'; add_option('configops_adapter_contract_expectation', ${expectationPhp}, '', false);`,
		},
		{ step: 'activatePlugin', pluginPath: 'configops/configops.php' },
	],
};

await writeFile(targetPath, `${JSON.stringify(blueprint, null, 2)}\n`);
