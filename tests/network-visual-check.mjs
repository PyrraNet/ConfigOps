import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright-core';

const baseUrl = process.env.CONFIGOPS_TEST_URL || 'http://configops.test';
const executablePath = process.env.CONFIGOPS_CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const hostResolverRules = process.env.CONFIGOPS_TEST_HOST_RESOLVER_RULES || 'MAP configops.test 127.0.0.1';
const artifacts = new URL('../artifacts/', import.meta.url);

await mkdir(artifacts, { recursive: true });

const browser = await chromium.launch({
	executablePath,
	headless: true,
	args: [`--host-resolver-rules=${hostResolverRules}`],
});
const page = await browser.newPage({ viewport: { width: 1440, height: 1000 }, deviceScaleFactor: 1 });
const runtimeErrors = [];
page.on('pageerror', (error) => runtimeErrors.push(error.message));

try {
	const loginUrl = `${baseUrl}/wp-login.php`;
	await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });
	await page.evaluate(async (redirectTo) => {
		const body = new URLSearchParams({
			log: 'admin',
			pwd: 'password',
			'wp-submit': 'Log In',
			redirect_to: redirectTo,
			testcookie: '1',
		});
		await fetch('/wp-login.php', {
			method: 'POST',
			body,
			credentials: 'include',
			redirect: 'manual',
		});
	}, `${baseUrl}/wp-admin/network/`);

	await page.goto(`${baseUrl}/wp-admin/network/settings.php`, { waitUntil: 'networkidle' });
	const networkName = page.locator('#site_name');
	if (!await networkName.isVisible().catch(() => false)) {
		const diagnostic = await page.locator('body').innerText().catch(() => 'Body unavailable.');
		throw new Error(`Network Settings did not become ready at ${page.url()}: ${diagnostic.slice(0, 500)}`);
	}
	const previousName = await networkName.inputValue();
	await networkName.fill(`${previousName} evidence`);
	await page.locator('#submit').click();
	await page.waitForLoadState('networkidle');

	await page.goto(`${baseUrl}/wp-admin/network/admin.php?page=configops`, { waitUntil: 'networkidle' });
	await page.getByRole('heading', { name: 'Network changes', exact: true }).waitFor();
	if (!await page.locator('#adminmenu').getByRole('link', { name: 'ConfigOps', exact: true }).isVisible()) {
		throw new Error('ConfigOps is missing from the Network Admin navigation.');
	}
	await page.waitForFunction(() => ['configops-sessions-island', 'configops-review-island']
		.every((id) => document.getElementById(id)?.getAttribute('aria-busy') !== 'true'));

	const bootstrap = await page.locator('#configops-bootstrap').evaluate((element) => JSON.parse(element.textContent || '{}'));
	if (bootstrap.scope?.type !== 'network' || bootstrap.scope?.networkId < 1) {
		throw new Error(`Network bootstrap did not retain its scope identity: ${JSON.stringify(bootstrap.scope)}.`);
	}
	if (
		bootstrap.capabilities?.capture !== false
		|| bootstrap.capabilities?.rollback !== true
		|| bootstrap.capabilities?.sessionRollback !== false
	) {
		throw new Error('Network evidence must expose mutation undo without capture or whole-session controls.');
	}
	if (await page.locator('#configops-capture-island').count()) {
		throw new Error('The Network Admin view rendered site capture controls.');
	}
	if (!await page.getByText('Add/update undo', { exact: true }).isVisible()) {
		throw new Error('The permanent network scope band does not disclose the undo boundary.');
	}
	if (!await page.getByText('Network-wide', { exact: true }).isVisible()) {
		throw new Error('The selected evidence does not disclose network-wide scope.');
	}
	const networkNameMutation = page.locator('.configops-mutation').filter({
		has: page.getByText('site_name', { exact: true }),
	}).first();
	if (!await networkNameMutation.isVisible()) {
		throw new Error('The real Network Settings save did not hydrate into the evidence ledger.');
	}
	if (await page.getByRole('button', { name: 'Undo capture', exact: true }).count()) {
		throw new Error('The mutation-only network ledger exposed whole-capture undo.');
	}
	const undoButton = networkNameMutation.getByRole('button', { name: 'Undo this setting', exact: true });
	if (!await undoButton.isVisible()) {
		throw new Error('The restorable Network Settings update did not expose mutation undo.');
	}
	if (runtimeErrors.length > 0) {
		throw new Error(`Network Admin emitted browser runtime errors: ${runtimeErrors.join(' | ')}`);
	}

	await page.screenshot({ path: new URL('configops-network-admin-desktop.png', artifacts).pathname, fullPage: true });
	let confirmation = '';
	page.once('dialog', async (dialog) => {
		confirmation = dialog.message();
		await dialog.accept();
	});
	await undoButton.click();
	await networkNameMutation.getByText('Undone', { exact: true }).waitFor();
	if (!confirmation.includes('network setting') || !confirmation.includes('current network value changed')) {
		throw new Error(`Network undo did not disclose its conflict check: ${confirmation}`);
	}
	await page.goto(`${baseUrl}/wp-admin/network/settings.php`, { waitUntil: 'networkidle' });
	if (await page.locator('#site_name').inputValue() !== previousName) {
		throw new Error('Network mutation undo did not restore the prior Network Title.');
	}
	await page.goto(`${baseUrl}/wp-admin/network/admin.php?page=configops`, { waitUntil: 'networkidle' });
	await page.getByText('Undone', { exact: true }).first().waitFor();
	await page.screenshot({
		path: new URL('configops-network-admin-focus.png', artifacts).pathname,
		clip: { x: 0, y: 0, width: 1440, height: 1100 },
	});
	await page.setViewportSize({ width: 390, height: 844 });
	const overflow = await page.evaluate(() => ({
		clientWidth: document.documentElement.clientWidth,
		scrollWidth: document.documentElement.scrollWidth,
	}));
	if (overflow.scrollWidth > overflow.clientWidth) {
		throw new Error(`Network Admin caused page-level horizontal overflow: ${JSON.stringify(overflow)}.`);
	}
	await page.screenshot({ path: new URL('configops-network-admin-mobile.png', artifacts).pathname, fullPage: true });

	process.stdout.write('ConfigOps Network Admin browser contract passed.\n');
} finally {
	await browser.close();
}
