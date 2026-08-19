import fs from 'node:fs';
import path from 'node:path';
import { validateBlueprint } from '@wp-playground/blueprints';

const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const fail = (message) => {
	throw new Error(`Release metadata check failed: ${message}`);
};
const match = (content, pattern, label) => {
	const result = content.match(pattern);
	if (!result) fail(`could not read ${label}`);
	return result[1];
};

const plugin = read('configops.php');
const packageJson = JSON.parse(read('package.json'));
const packageLock = JSON.parse(read('package-lock.json'));
const composer = JSON.parse(read('composer.json'));
const githubReadme = read('README.md');
const readme = read('readme.txt');
const changelog = read('CHANGELOG.md');
const security = read('SECURITY.md');
const docsConfig = read('docs/.vitepress/config.mjs');
const docsHome = read('docs/.vitepress/theme/components/DocsHome.vue');
const docsRelease = read(`docs/releases/${packageJson.version}.md`);
const wordpressReleaseWorkflow = read('.github/workflows/wordpress-org-release.yml');
const previewBlueprintPath = new URL('../.wordpress-org/blueprints/blueprint.json', import.meta.url);
const previewBlueprintSource = fs.readFileSync(previewBlueprintPath, 'utf8');
const previewBlueprint = JSON.parse(previewBlueprintSource);
const previewBlueprintValidation = validateBlueprint(previewBlueprint);

const headerVersion = match(plugin, /^ \* Version:\s+([^\s]+)$/m, 'plugin header version');
const constantVersion = match(plugin, /^define\('CONFIGOPS_VERSION', '([^']+)'\);$/m, 'CONFIGOPS_VERSION');
const stableTag = match(readme, /^Stable tag:\s+([^\s]+)$/m, 'WordPress stable tag');
const headerPhp = match(plugin, /^ \* Requires PHP:\s+([^\s]+)$/m, 'plugin PHP requirement');
const readmePhp = match(readme, /^Requires PHP:\s+([^\s]+)$/m, 'readme PHP requirement');
const versions = new Set([
	headerVersion,
	constantVersion,
	stableTag,
	packageJson.version,
	packageLock.version,
	packageLock.packages?.['']?.version,
]);

if (versions.size !== 1 || versions.has(undefined)) {
	fail(`version sources disagree: ${[...versions].join(', ')}`);
}
if (headerPhp !== readmePhp || composer.require?.php !== `>=${headerPhp}` || headerPhp !== '8.2') {
	fail(`PHP requirements disagree: header ${headerPhp}, readme ${readmePhp}, Composer ${composer.require?.php}`);
}
if (!changelog.includes(`## ${headerVersion} `)) {
	fail(`CHANGELOG.md has no ${headerVersion} release`);
}
if (!readme.includes(`= ${headerVersion} =`)) {
	fail(`readme.txt has no ${headerVersion} changelog entry`);
}
if (!docsHome.includes(`Documentation · ${headerVersion}`) || !docsRelease.includes(`# ConfigOps ${headerVersion}`)) {
	fail(`documentation does not identify the ${headerVersion} release`);
}
if (
	!wordpressReleaseWorkflow.includes('types: [published]')
	|| !wordpressReleaseWorkflow.includes("if: github.event_name == 'workflow_dispatch'")
	|| !wordpressReleaseWorkflow.includes('dry-run: true')
	|| !wordpressReleaseWorkflow.includes("if: github.event_name == 'release'")
	|| !wordpressReleaseWorkflow.includes('name: wordpress-org')
	|| !wordpressReleaseWorkflow.includes('SVN_USERNAME: ${{ secrets.SVN_USERNAME }}')
	|| !wordpressReleaseWorkflow.includes('SVN_PASSWORD: ${{ secrets.SVN_PASSWORD }}')
) {
	fail('WordPress.org deployment must remain release-only, environment-gated, and dry-run safe');
}
if (/github\.com\/PyrraNet(?:\/ConfigOps)?/i.test(`${plugin}\n${githubReadme}\n${readme}`)) {
	fail('public plugin metadata must not link to the private GitHub repository or organization page');
}
if (!/^ \* Plugin URI:\s+https:\/\/configops\.pyrra\.net\/$/m.test(plugin)) {
	fail('plugin URI must use the public ConfigOps website');
}
if (!/^ \* Author URI:\s+https:\/\/configops\.pyrra\.net\/$/m.test(plugin)) {
	fail('plugin author URI must use the public ConfigOps website');
}
if (!/^ \* Author:\s+pyrra$/m.test(plugin)) {
	fail('plugin author must be pyrra');
}
if (!githubReadme.includes('https://configops.pyrra.net/docs/') || !docsConfig.includes("hostname: 'https://configops.pyrra.net/docs/'")) {
	fail('public documentation URLs must use the ConfigOps /docs/ site');
}
if (!/^Contributors:\s+pyrra$/m.test(readme)) {
	fail('WordPress contributor must be pyrra');
}
if (composer.name !== 'pyrra/configops' || composer.authors?.[0]?.name !== 'pyrra' || composer.authors?.[0]?.email !== 'felix@pyrra.net') {
	fail('Composer ownership must be pyrra/configops by pyrra with the private security contact');
}
if (packageJson.private !== true) {
	fail('the tooling package must remain private on npm');
}
if (!security.includes('felix@pyrra.net')) {
	fail('SECURITY.md must use felix@pyrra.net as the private reporting channel');
}
if (Buffer.byteLength(previewBlueprintSource) > 100 * 1024) {
	fail('WordPress.org Playground Blueprint exceeds the 100 KiB directory limit');
}
if (!previewBlueprintValidation.valid) {
	fail(`WordPress.org Playground Blueprint is invalid: ${JSON.stringify(previewBlueprintValidation.errors)}`);
}
if (
	previewBlueprint.$schema !== 'https://playground.wordpress.net/blueprint-schema.json'
	|| previewBlueprint.landingPage !== '/wp-admin/admin.php?page=wp-mail-smtp'
	|| previewBlueprint.login?.username !== 'admin'
	|| previewBlueprint.login?.password !== 'password'
) {
	fail('Playground Blueprint must log in and land on the guided WP Mail SMTP screen');
}
const previewSteps = Array.isArray(previewBlueprint.steps) ? previewBlueprint.steps : [];
const previewPluginInstalls = previewSteps.filter((step) => step?.step === 'installPlugin');
if (
	!previewPluginInstalls.some((step) => step.pluginData?.resource === 'wordpress.org/plugins' && step.pluginData?.slug === 'configops' && step.options?.activate === true)
	|| !previewPluginInstalls.some((step) => step.pluginData?.url === 'https://downloads.wordpress.org/plugin/wp-mail-smtp.4.9.0.zip' && step.options?.activate === true)
) {
	fail('Playground Blueprint must activate ConfigOps and the exact supported WP Mail SMTP release');
}
if (!previewBlueprintSource.includes('Change the sender email, save, then inspect what WordPress actually wrote.')) {
	fail('Playground Blueprint is missing its guided demo instruction');
}
if (!/^Stable tag:\s+\d+(?:\.\d+)*$/m.test(readme)) {
	fail('WordPress Stable tag must contain only numbers and periods');
}
for (const required of ['LICENSE', 'CHANGELOG.md', 'SECURITY.md']) {
	if (!fs.existsSync(new URL(`../${required}`, import.meta.url))) fail(`${required} is missing`);
}

const pngDimensions = (filename) => {
	const buffer = fs.readFileSync(filename);
	if (buffer.length < 24 || buffer.toString('ascii', 1, 4) !== 'PNG') fail(`${filename} is not a PNG`);

	return { width: buffer.readUInt32BE(16), height: buffer.readUInt32BE(20), bytes: buffer.length };
};
const directoryAssets = new Map([
	['banner-772x250.png', { width: 772, height: 250, maxBytes: 4 * 1024 * 1024 }],
	['banner-1544x500.png', { width: 1544, height: 500, maxBytes: 4 * 1024 * 1024 }],
	['icon-128x128.png', { width: 128, height: 128, maxBytes: 1024 * 1024 }],
	['icon-256x256.png', { width: 256, height: 256, maxBytes: 1024 * 1024 }],
]);

for (const [requiredAsset, expected] of directoryAssets) {
	const filename = new URL(`../.wordpress-org/${requiredAsset}`, import.meta.url);
	if (!fs.existsSync(filename)) fail(`WordPress.org directory asset is missing: ${requiredAsset}`);
	const actual = pngDimensions(filename);
	if (actual.width !== expected.width || actual.height !== expected.height) {
		fail(`${requiredAsset} must be ${expected.width}x${expected.height}, received ${actual.width}x${actual.height}`);
	}
	if (actual.bytes > expected.maxBytes) fail(`${requiredAsset} exceeds the WordPress.org file-size limit`);
}

const iconSvg = new URL('../.wordpress-org/icon.svg', import.meta.url);
if (!fs.existsSync(iconSvg) || !fs.readFileSync(iconSvg, 'utf8').includes('aria-label="ConfigOps"')) {
	fail('accessible WordPress.org SVG icon is missing');
}

const directoryScreenshots = new Map([
	['screenshot-1.png', { minWidth: 1200, minHeight: 800 }],
	['screenshot-2.png', { minWidth: 1200, minHeight: 550 }],
	['screenshot-3.png', { minWidth: 1200, minHeight: 350 }],
	['screenshot-4.png', { minWidth: 1200, minHeight: 800 }],
]);

for (const [requiredScreenshot, expected] of directoryScreenshots) {
	const filename = new URL(`../.wordpress-org/${requiredScreenshot}`, import.meta.url);
	if (!fs.existsSync(filename)) fail(`WordPress.org screenshot is missing: ${requiredScreenshot}`);
	const actual = pngDimensions(filename);
	if (actual.width < expected.minWidth || actual.height < expected.minHeight) {
		fail(`${requiredScreenshot} is too small for its focused directory preview`);
	}
	if (actual.bytes > 10 * 1024 * 1024) fail(`${requiredScreenshot} exceeds the WordPress.org file-size limit`);
}

const staleScreenshot = new URL('../.wordpress-org/screenshot-5.png', import.meta.url);
if (fs.existsSync(staleScreenshot)) fail('stale WordPress.org screenshot must be removed: screenshot-5.png');
if (!read('LICENSE').includes('GNU GENERAL PUBLIC LICENSE\n                       Version 2')) {
	fail('LICENSE does not contain the GPL version 2 text');
}

const productionRoots = ['configops.php', 'uninstall.php', 'src', 'templates', 'assets'];
const productionFiles = [];
const walk = (entry) => {
	const info = fs.statSync(entry);
	if (info.isDirectory()) {
		for (const child of fs.readdirSync(entry).sort()) walk(path.join(entry, child));
		return;
	}
	productionFiles.push(entry);
};
for (const root of productionRoots) walk(root);

const assistantArtifactPattern = /(?:\bcodex\b|\bchatgpt\b|\bclaude\b|\banthropic\b|\bopenai\b|system prompt|prompt injection|\.design-review)/i;
for (const file of productionFiles) {
	if (file.endsWith('.map')) fail(`source map is not allowed in the production build: ${file}`);
	if (!/\.(?:php|js|css|svg|md|txt)$/.test(file)) continue;
	const source = fs.readFileSync(file, 'utf8');
	if (assistantArtifactPattern.test(source)) fail(`assistant work artifact found in ${file}`);
	if (file.endsWith('.js')) {
		if (/\beval\s*\(|\bnew\s+Function\s*\(/.test(source)) fail(`dynamic code execution found in ${file}`);
		if (/sourceMappingURL=/.test(source)) fail(`source map reference found in ${file}`);
		const longestLine = source.split(/\r?\n/).reduce((longest, line) => Math.max(longest, line.length), 0);
		if (longestLine > 240) fail(`JavaScript is not human-readable in ${file} (${longestLine}-character line)`);
	}
}

process.stdout.write(`Release metadata is consistent for ${headerVersion} by pyrra.\n`);
