import { spawn } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';

const host = '127.0.0.1';
const port = 4174;
const origin = `http://${host}:${port}`;
const configuredBase = process.env.DOCS_BASE || '/';
const base = `/${configuredBase.replace(/^\/+|\/+$/g, '')}`.replace(/^\/$/, '');
const site = `${origin}${base}`;
const vitepress = fileURLToPath(new URL('../node_modules/vitepress/bin/vitepress.js', import.meta.url));
const executablePath = process.env.CONFIGOPS_CHROME_PATH || (
	'darwin' === process.platform
		? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
		: undefined
);
const routes = [
	'/',
	'/guide/getting-started',
	'/guide/first-capture',
	'/guide/read-change',
	'/guide/undo-safely',
	'/security/secrets-privacy',
	'/security/failure-model',
	'/reference/support',
	'/reference/operations',
	'/reference/limits',
	'/architecture',
	'/testing',
	'/adapters',
	'/frontend',
	'/wordpress-org-release',
	'/releases/0.4.1',
	'/releases/0.4.0',
	'/releases/0.3.1',
	'/releases/0.3.0',
	'/releases/0.2.0',
	'/releases/0.1.0',
];
const profiles = [
	{ name: 'desktop-light', viewport: { width: 1440, height: 1000 }, colorScheme: 'light' },
	{ name: 'mobile-dark', viewport: { width: 390, height: 844 }, colorScheme: 'dark', reducedMotion: 'reduce' },
];

const server = spawn(
	process.execPath,
	[vitepress, 'preview', 'docs', '--host', host, '--port', String(port)],
	{ stdio: ['ignore', 'pipe', 'pipe'] }
);
let serverOutput = '';
server.stdout.on('data', (chunk) => { serverOutput += chunk.toString(); });
server.stderr.on('data', (chunk) => { serverOutput += chunk.toString(); });

const delay = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));
const stopServer = () => {
	if (!server.killed) server.kill('SIGTERM');
};

try {
	let ready = false;
	for (let attempt = 0; attempt < 80; attempt += 1) {
		try {
			const response = await fetch(`${site}/`);
			if (response.ok) {
				ready = true;
				break;
			}
		} catch {
			// The preview server is still starting.
		}
		await delay(100);
	}
	if (!ready) throw new Error(`documentation preview did not start\n${serverOutput}`);

	const browser = await chromium.launch({ headless: true, ...(executablePath ? { executablePath } : {}) });
	try {
		for (const profile of profiles) {
			const context = await browser.newContext(profile);
			const page = await context.newPage();
			const runtimeErrors = [];
			page.on('console', (message) => {
				if ('error' === message.type()) runtimeErrors.push(`console: ${message.text()}`);
			});
			page.on('pageerror', (error) => runtimeErrors.push(`page: ${error.message}`));

			for (const route of routes) {
				runtimeErrors.length = 0;
				const response = await page.goto(`${site}${route}`, { waitUntil: 'networkidle' });
				if (!response || !response.ok()) {
					throw new Error(`${profile.name} ${route} returned ${response?.status() ?? 'no response'}`);
				}

				const evidence = await page.evaluate(() => ({
					title: document.title.trim(),
					headings: [...document.querySelectorAll('h1')].map((heading) => heading.textContent?.trim()).filter(Boolean),
					overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
					brokenImages: [...document.images].filter((image) => image.complete && 0 === image.naturalWidth).map((image) => image.currentSrc),
				}));
				if ('' === evidence.title) throw new Error(`${profile.name} ${route} has no document title`);
				if (1 !== evidence.headings.length) {
					throw new Error(`${profile.name} ${route} must expose exactly one non-empty h1, received ${evidence.headings.length}`);
				}
				if (evidence.overflow > 1) {
					throw new Error(`${profile.name} ${route} overflows the viewport by ${evidence.overflow}px`);
				}
				if (evidence.brokenImages.length > 0) {
					throw new Error(`${profile.name} ${route} contains broken images: ${evidence.brokenImages.join(', ')}`);
				}
				if ('/' === route) {
					const home = await page.evaluate(() => ({
						text: document.body.textContent || '',
						hasCurrentReleaseLink: [...document.querySelectorAll('a')].some((link) => (
							'v0.4.1' === link.textContent?.trim()
							&& link.getAttribute('href')?.includes('/releases/0.4.1')
						)),
						proofAlt: document.querySelector('.co-proof__figure img')?.getAttribute('alt') || '',
					}));
					if (!home.hasCurrentReleaseLink) {
						throw new Error(`${profile.name} home does not link the current 0.4.1 release`);
					}
					if (!home.text.includes('Save normally. ConfigOps appears with the evidence.')) {
						throw new Error(`${profile.name} home does not lead with the automatic evidence proof`);
					}
					if (home.text.includes('Capture #1') || home.text.includes('Open full capture')) {
						throw new Error(`${profile.name} home still exposes the obsolete manual-capture proof`);
					}
					if (!home.proofAlt.includes('ConfigOps evidence card')) {
						throw new Error(`${profile.name} home does not expose the automatic evidence card image`);
					}
				}
				if (runtimeErrors.length > 0) {
					throw new Error(`${profile.name} ${route} emitted runtime errors:\n${runtimeErrors.join('\n')}`);
				}
			}

			await context.close();
		}
	} finally {
		await browser.close();
	}

	process.stdout.write(`Documentation browser smoke passed for ${routes.length} routes across ${profiles.length} profiles.\n`);
} finally {
	stopServer();
}
