import fs from 'node:fs';
import path from 'node:path';

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

const headerVersion = match(plugin, /^ \* Version:\s+([^\s]+)$/m, 'plugin header version');
const constantVersion = match(plugin, /^define\('CONFIGOPS_VERSION', '([^']+)'\);$/m, 'CONFIGOPS_VERSION');
const stableTag = match(readme, /^Stable tag:\s+([^\s]+)$/m, 'WordPress stable tag');
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
if (!changelog.includes(`## ${headerVersion} `)) {
	fail(`CHANGELOG.md has no ${headerVersion} release`);
}
if (!readme.includes(`= ${headerVersion} =`)) {
	fail(`readme.txt has no ${headerVersion} changelog entry`);
}
for (const releaseReference of [
	`releases/tag/v${headerVersion}`,
	`releases/download/v${headerVersion}/configops-${headerVersion}.zip`,
]) {
	if (!githubReadme.includes(releaseReference)) fail(`README.md is missing ${releaseReference}`);
}
if (!/^ \* Author:\s+pyrra$/m.test(plugin)) {
	fail('plugin author must be pyrra');
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
if (!/^Stable tag:\s+\d+(?:\.\d+)*$/m.test(readme)) {
	fail('WordPress Stable tag must contain only numbers and periods');
}
for (const required of ['LICENSE', 'CHANGELOG.md', 'SECURITY.md']) {
	if (!fs.existsSync(new URL(`../${required}`, import.meta.url))) fail(`${required} is missing`);
}
if (!read('LICENSE').includes('GNU GENERAL PUBLIC LICENSE\n                       Version 2')) {
	fail('LICENSE does not contain the GPL version 2 text');
}

const productionRoots = ['configops.php', 'src', 'templates', 'assets'];
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
