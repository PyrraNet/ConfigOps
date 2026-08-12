import { execFileSync } from 'node:child_process';
import { copyFileSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const repositoryRoot = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const outputDirectory = path.join(repositoryRoot, '.wordpress-org');
const temporaryDirectory = mkdtempSync(path.join(tmpdir(), 'configops-wp-assets-'));

const colors = {
	blue: '#0D57F6',
	blueLight: '#4D7FFF',
	ink: '#0B1424',
	inkMuted: '#98A3B3',
	white: '#FBFBFB',
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

const banner = `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="1544" height="500" viewBox="0 0 1544 500">
	<rect width="1544" height="500" fill="${colors.ink}"/>
	<path d="M 80 184 H 1464" stroke="#344052" stroke-width="2"/>
	${nestedSvg(wordmark, 'x="80" y="54" width="360" height="80" preserveAspectRatio="xMinYMid meet"')}
	<text x="80" y="286" fill="${colors.white}" font-family="Avenir Next, Avenir, Helvetica Neue, Arial, sans-serif" font-size="70" font-weight="600" letter-spacing="-3">Know what WordPress</text>
	<text x="80" y="367" fill="${colors.white}" font-family="Avenir Next, Avenir, Helvetica Neue, Arial, sans-serif" font-size="70" font-weight="600" letter-spacing="-3">changed.</text>
	<rect x="80" y="421" width="126" height="8" fill="${colors.blueLight}"/>
	<text x="229" y="432" fill="${colors.inkMuted}" font-family="Avenir Next, Avenir, Helvetica Neue, Arial, sans-serif" font-size="19" font-weight="600" letter-spacing="2">CAPTURE  /  REVIEW  /  RESTORE</text>
	<g transform="translate(1144 233)">
		<text x="0" y="0" fill="${colors.inkMuted}" font-family="Avenir Next, Avenir, Helvetica Neue, Arial, sans-serif" font-size="17" font-weight="600" letter-spacing="2">BEFORE</text>
		<text x="0" y="50" fill="${colors.white}" font-family="ui-monospace, SFMono-Regular, Consolas, monospace" font-size="28">&quot;mail&quot;</text>
		<path d="M 0 87 H 126" stroke="${colors.blueLight}" stroke-width="7"/>
		<text x="155" y="0" fill="${colors.blueLight}" font-family="Avenir Next, Avenir, Helvetica Neue, Arial, sans-serif" font-size="17" font-weight="600" letter-spacing="2">AFTER</text>
		<text x="155" y="50" fill="${colors.white}" font-family="ui-monospace, SFMono-Regular, Consolas, monospace" font-size="28">&quot;smtp&quot;</text>
	</g>
</svg>`;

const icon = `<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" width="256" height="256" viewBox="0 0 256 256" role="img" aria-label="ConfigOps">
	<rect width="256" height="256" fill="${colors.ink}"/>
	${nestedSvg(mark, 'x="28" y="62" width="200" height="102" preserveAspectRatio="xMidYMid meet"')}
	<rect x="28" y="204" width="74" height="7" fill="${colors.blueLight}"/>
</svg>`;

const renderSvg = (source, filename, width, height) => {
	const input = path.join(temporaryDirectory, `${filename}.svg`);
	writeFileSync(input, source);
	execFileSync('rsvg-convert', ['--width', String(width), '--height', String(height), '--output', path.join(outputDirectory, filename), input]);
};

mkdirSync(outputDirectory, { recursive: true });

try {
	renderSvg(banner, 'banner-1544x500.png', 1544, 500);
	renderSvg(banner, 'banner-772x250.png', 772, 250);
	writeFileSync(path.join(outputDirectory, 'icon.svg'), icon);
	renderSvg(icon, 'icon-256x256.png', 256, 256);
	renderSvg(icon, 'icon-128x128.png', 128, 128);

	const screenshots = [
		['artifacts/adapter-user-flows/wp-mail-smtp-review.png', 'screenshot-1.png'],
		['artifacts/adapter-user-flows/yoast-review.png', 'screenshot-2.png'],
		['artifacts/configops-support-desktop.png', 'screenshot-3.png'],
		['artifacts/configops-incomplete-capture-desktop.png', 'screenshot-4.png'],
	];

	for (const [source, destination] of screenshots) {
		copyFileSync(path.join(repositoryRoot, source), path.join(outputDirectory, destination));
	}

	process.stdout.write(`WordPress.org assets written to ${outputDirectory}.\n`);
} finally {
	rmSync(temporaryDirectory, { recursive: true, force: true });
}
