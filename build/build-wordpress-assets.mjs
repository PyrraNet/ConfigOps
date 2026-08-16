import { copyFileSync, mkdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright-core';

const repositoryRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const outputDirectory = path.join(repositoryRoot, '.wordpress-org');
const executablePath = process.env.CONFIGOPS_CHROME_PATH || (
	process.platform === 'darwin'
		? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
		: '/usr/bin/google-chrome'
);

const colors = {
	ink: '#0B1424',
};

const extractSvg = (filename, directory = path.join('assets', 'brand')) => {
	const source = readFileSync(path.join(repositoryRoot, directory, filename), 'utf8');
	const viewBox = source.match(/viewBox="([^"]+)"/i)?.[1];
	const body = source.match(/<svg[^>]*>([\s\S]*)<\/svg>/i)?.[1];
	if (!viewBox || !body) throw new Error(`Could not parse ${filename}.`);

	return { viewBox, body };
};

const nestedSvg = ({ viewBox, body }, attributes) => `<svg ${attributes} viewBox="${viewBox}">${body}</svg>`;
const wordmark = extractSvg('configops-wordmark-dark.svg');
const mark = extractSvg('configops-mark-dark.svg', path.join('build', 'brand'));
const evidenceBuffer = readFileSync(
	path.join(repositoryRoot, 'artifacts', 'adapter-user-flows', 'wp-mail-smtp-review-focus.png')
);
const evidence = evidenceBuffer.toString('base64');
const evidenceWidth = evidenceBuffer.readUInt32BE(16);
const evidenceHeight = evidenceBuffer.readUInt32BE(20);

const banner = `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="1544" height="500" viewBox="0 0 1544 500" role="img" aria-label="ConfigOps">
	<defs>
		<clipPath id="evidence-window">
			<rect x="96" y="325" width="1352" height="125"/>
		</clipPath>
	</defs>
	<rect width="1544" height="500" fill="${colors.ink}"/>
	${nestedSvg(wordmark, 'x="360" y="48" width="824" height="184" preserveAspectRatio="xMidYMid meet"')}
	<g clip-path="url(#evidence-window)">
		<svg x="96" y="325" width="1352" height="125" viewBox="0 698 ${evidenceWidth} 96" preserveAspectRatio="none">
			<image href="data:image/png;base64,${evidence}" width="${evidenceWidth}" height="${evidenceHeight}"/>
		</svg>
	</g>
</svg>`;

const icon = `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256" role="img" aria-label="ConfigOps">
	<rect width="256" height="256" fill="${colors.ink}"/>
	${nestedSvg(mark, 'x="28" y="77" width="200" height="102" preserveAspectRatio="xMidYMid meet"')}
</svg>`;

const browser = await chromium.launch({ executablePath, headless: true });

const renderSvg = async (source, filename, width, height) => {
	const renderPage = await browser.newPage({ viewport: { width, height } });
	const sizedSource = source.replace(/width="\d+" height="\d+"/, `width="${width}" height="${height}"`);
	try {
		await renderPage.setContent(`<style>html,body{margin:0;overflow:hidden}body>svg{display:block}</style>${sizedSource}`);
		await renderPage.screenshot({ path: path.join(outputDirectory, filename) });
	} finally {
		await renderPage.close();
	}
};

const renderPng = async (sourceFilename, filename, width, height) => {
	const renderPage = await browser.newPage();
	const source = readFileSync(path.join(outputDirectory, sourceFilename)).toString('base64');
	try {
		const rendered = await renderPage.evaluate(async ({ encodedSource, outputWidth, outputHeight }) => {
			const image = new Image();
			image.src = `data:image/png;base64,${encodedSource}`;
			await image.decode();
			const canvas = document.createElement('canvas');
			canvas.width = outputWidth;
			canvas.height = outputHeight;
			const context = canvas.getContext('2d');
			context.imageSmoothingEnabled = true;
			context.imageSmoothingQuality = 'high';
			context.drawImage(image, 0, 0, outputWidth, outputHeight);

			return canvas.toDataURL('image/png').split(',')[1];
		}, { encodedSource: source, outputWidth: width, outputHeight: height });
		writeFileSync(path.join(outputDirectory, filename), Buffer.from(rendered, 'base64'));
	} finally {
		await renderPage.close();
	}
};

mkdirSync(outputDirectory, { recursive: true });

try {
	await renderSvg(banner, 'banner-1544x500.png', 1544, 500);
	await renderPng('banner-1544x500.png', 'banner-772x250.png', 772, 250);
	writeFileSync(path.join(outputDirectory, 'icon.svg'), icon);
	await renderSvg(icon, 'icon-256x256.png', 256, 256);
	await renderPng('icon-256x256.png', 'icon-128x128.png', 128, 128);

	const screenshots = [
		['artifacts/adapter-user-flows/wp-mail-smtp-review-focus.png', 'screenshot-1.png'],
		['artifacts/adapter-user-flows/yoast-review-focus.png', 'screenshot-2.png'],
		['artifacts/adapter-user-flows/support-focus.png', 'screenshot-3.png'],
	];

	for (const filename of ['screenshot-1.png', 'screenshot-2.png', 'screenshot-3.png', 'screenshot-4.png']) {
		rmSync(path.join(outputDirectory, filename), { force: true });
	}

	for (const [source, destination] of screenshots) {
		copyFileSync(path.join(repositoryRoot, source), path.join(outputDirectory, destination));
	}

	process.stdout.write(`WordPress.org assets written to ${outputDirectory}.\n`);
} finally {
	await browser.close();
}
