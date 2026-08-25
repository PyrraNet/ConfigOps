import { gzipSync } from 'node:zlib';
import { readdir, readFile, stat } from 'node:fs/promises';
import { join, relative } from 'node:path';

const root = 'assets/ui';
const limits = {
	runtime: 3 * 1024,
	total: 24 * 1024,
	styles: 10 * 1024,
	wordmark: 16 * 1024,
};

const stylesPath = 'assets/admin.css';
const wordmarkPath = 'assets/brand/configops-wordmark-light.svg';

const files = [];
const walk = async (directory) => {
	for (const entry of await readdir(directory)) {
		const path = join(directory, entry);
		const info = await stat(path);
		if (info.isDirectory()) {
			await walk(path);
		} else if (path.endsWith('.js')) {
			files.push(path);
		}
	}
};

await walk(root);

let total = 0;
let runtime = 0;
for (const file of files.sort()) {
	const bytes = gzipSync(await readFile(file), { level: 9 }).byteLength;
	total += bytes;
	if (file.endsWith('/runtime.js')) {
		runtime = bytes;
	}
	process.stdout.write(`${relative(root, file)}: ${bytes} B gzip\n`);
}

if (runtime === 0 || runtime > limits.runtime) {
	throw new Error(`Runtime budget exceeded: ${runtime} B gzip (limit ${limits.runtime} B).`);
}
if (total > limits.total) {
	throw new Error(`Total ConfigOps JavaScript budget exceeded: ${total} B gzip (limit ${limits.total} B).`);
}

const stylesSource = await readFile(stylesPath);
const styles = gzipSync(stylesSource, { level: 9 }).byteLength;
if (styles > limits.styles) {
	throw new Error(`ConfigOps admin CSS budget exceeded: ${styles} B gzip (limit ${limits.styles} B).`);
}
if (/gradient\s*\(/i.test(stylesSource.toString('utf8'))) {
	throw new Error('ConfigOps admin CSS contains a gradient, which violates the two-field brand system.');
}

const wordmark = gzipSync(await readFile(wordmarkPath), { level: 9 }).byteLength;
if (wordmark > limits.wordmark) {
	throw new Error(`ConfigOps wordmark budget exceeded: ${wordmark} B gzip (limit ${limits.wordmark} B).`);
}

process.stdout.write(`admin.css: ${styles} B gzip\n`);
process.stdout.write(`configops-wordmark-light.svg: ${wordmark} B gzip\n`);
process.stdout.write(`ConfigOps UI budget passed: ${total} B gzip JavaScript, ${styles} B gzip CSS, React externalized to WordPress.\n`);
