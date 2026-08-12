import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright-core';

const baseUrl = process.env.CONFIGOPS_TEST_URL || 'http://127.0.0.1:9400';
const executablePath = process.env.CONFIGOPS_CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const artifacts = new URL('../artifacts/', import.meta.url);

await mkdir(artifacts, { recursive: true });

const browser = await chromium.launch({ executablePath, headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 }, deviceScaleFactor: 1 });
const runtimeErrors = [];

page.on('pageerror', (error) => runtimeErrors.push(error.message));

try {
	await page.goto(`${baseUrl}/wp-admin/admin.php?page=configops`, { waitUntil: 'networkidle' });
	await page.getByRole('heading', { name: 'Change review', exact: true }).waitFor();
	for (const removedCopy of ['See what WordPress changed.', 'Capture / Technical spike', 'Configuration control', 'Packs', 'Policies', 'Drift']) {
		if (await page.getByText(removedCopy, { exact: true }).count()) {
			throw new Error(`Operational screen still contains removed marketing or future-product copy: ${removedCopy}.`);
		}
	}
	const brandContract = await page.locator('.configops-shell').evaluate((element) => {
		const wordmark = element.querySelector('.configops-wordmark');
		const appbar = element.querySelector('.configops-appbar');
		return {
			fontFamily: getComputedStyle(element).fontFamily,
			appbarBackgroundImage: appbar ? getComputedStyle(appbar).backgroundImage : '',
			wordmarkLoaded: wordmark instanceof HTMLImageElement && wordmark.complete && wordmark.naturalWidth > 0,
			wordmarkSource: wordmark instanceof HTMLImageElement ? wordmark.currentSrc : '',
		};
	});
	if (!brandContract.fontFamily.includes('Avenir Next')) {
		throw new Error(`ConfigOps brand font stack is missing Avenir Next: ${brandContract.fontFamily}.`);
	}
	if (!brandContract.wordmarkLoaded || !brandContract.wordmarkSource.endsWith('/assets/brand/configops-wordmark-light.svg')) {
		throw new Error(`ConfigOps brand wordmark did not load from the packaged SVG: ${brandContract.wordmarkSource}.`);
	}
	if (brandContract.appbarBackgroundImage !== 'none') {
		throw new Error(`ConfigOps app bar must remain gradient-free: ${brandContract.appbarBackgroundImage}.`);
	}
	const scopeHint = page.locator('.configops-static-hint');
	await scopeHint.focus();
	const scopeTooltip = scopeHint.locator('[role="tooltip"]');
	if ((await scopeTooltip.evaluate((element) => getComputedStyle(element).visibility)) !== 'visible') {
		throw new Error('Options API explanation is not exposed on keyboard focus.');
	}
	await scopeHint.evaluate((element) => element.blur());
	const bootstrap = await page.locator('#configops-bootstrap').evaluate((element) => ({
		bytes: new TextEncoder().encode(element.textContent || '').byteLength,
		data: JSON.parse(element.textContent || '{}'),
	}));
	if (bootstrap.bytes > 64 * 1024) {
		throw new Error(`Initial ConfigOps bootstrap exceeded 64 KiB: ${bootstrap.bytes} bytes.`);
	}
	if (bootstrap.data.selected && (!bootstrap.data.review?.deferred || bootstrap.data.review?.groups?.length !== 0)) {
		throw new Error('Initial ConfigOps HTML included mutation diff history instead of deferring the ledger connection.');
	}

	if (await page.getByRole('button', { name: 'Stop & review' }).isVisible().catch(() => false)) {
		await page.getByRole('button', { name: 'Stop & review' }).click();
		await page.waitForLoadState('networkidle');
	}

	await page.locator('#configops-capture-name').fill('Core reading settings');
	const captureHintButton = page.getByRole('button', { name: 'Why name a capture?' });
	await captureHintButton.focus();
	const captureHintVisibility = await captureHintButton.locator('xpath=..').getByRole('tooltip').evaluate((element) => getComputedStyle(element).visibility);
	if (captureHintVisibility !== 'visible') {
		throw new Error('Capture naming explanation is not exposed on keyboard focus.');
	}
	const recordButton = page.getByRole('button', { name: 'Record changes' });
	const recordButtonBox = await recordButton.boundingBox();
	if (!recordButtonBox || recordButtonBox.width > 220) {
		throw new Error(`Desktop record command expanded into a banner: ${recordButtonBox?.width ?? 'missing'} px.`);
	}
	await recordButton.click();
	await page.waitForLoadState('networkidle');
	await page.locator('#configops-capture-island').getByText('Recording', { exact: true }).waitFor();

	await page.goto(`${baseUrl}/wp-admin/options-general.php`, { waitUntil: 'networkidle' });
	const description = page.locator('#blogdescription');
	const previousDescription = await description.inputValue();
	const verificationDescription = `ConfigOps visual verification ${String(Date.now()).slice(-6)}`;
	await description.fill(verificationDescription);
	await page.locator('#submit').click();
	await page.waitForLoadState('networkidle');

	await page.goto(`${baseUrl}/wp-admin/admin.php?page=configops`, { waitUntil: 'networkidle' });
	await page.getByRole('button', { name: 'Stop & review' }).click();
	await page.locator('#configops-review-island').getByRole('heading', { name: 'Core reading settings' }).waitFor();
	await page.locator('.configops-request-group').first().waitFor();
	for (const islandId of ['configops-capture-island', 'configops-sessions-island', 'configops-review-island']) {
		if (await page.locator(`#${islandId}`).getAttribute('aria-busy')) {
			throw new Error(`${islandId} did not finish hydrating.`);
		}
	}
	if (await page.getByText('There has been a critical error on this website.').count()) {
		throw new Error('WordPress rendered a critical error in the ConfigOps review.');
	}

	const layout = await page.locator('.configops-workspace').evaluate((element) => getComputedStyle(element).display);
	if (layout !== 'grid') {
		throw new Error(`Expected desktop workspace grid, received ${layout}.`);
	}
	const blogDescriptionRow = page.locator('.configops-mutation', { hasText: 'blogdescription' }).first();
	const afterValueText = await blogDescriptionRow.locator('.configops-diff-row').nth(1).locator('pre').nth(1).innerText();
	if (!afterValueText.includes(JSON.stringify(verificationDescription))) {
		throw new Error('The captured string value is not rendered with explicit type-preserving quotes.');
	}
	if (await blogDescriptionRow.locator('.configops-classification-note').count()) {
		throw new Error('Classification explanation is still rendered as persistent prose instead of contextual help.');
	}
	const unknownBadge = blogDescriptionRow.locator('.configops-badge').first();
	const unknownTooltip = await unknownBadge.evaluate((element) => getComputedStyle(element, '::after').content);
	if (!unknownTooltip || unknownTooltip === 'none') {
		throw new Error('Classification badge is missing its contextual hover explanation.');
	}
	const reviewFilter = page.locator('.configops-review-filters button').nth(1);
	await reviewFilter.focus();
	const filterTooltip = await reviewFilter.evaluate((element) => getComputedStyle(element, '::after').content);
	if (!filterTooltip || filterTooltip === 'none') {
		throw new Error('Review filter is missing its contextual keyboard-focus explanation.');
	}
	await reviewFilter.click();
	if (await page.locator('#configops-change-list .configops-mutation.is-derived').count()) {
		throw new Error('React review filter left likely-noise mutations in the review decision set.');
	}
	const noiseFilter = page.locator('.configops-review-filters button').nth(2);
	await noiseFilter.click();
	if (await page.locator('#configops-change-list .configops-mutation:not(.is-derived)').count()) {
		throw new Error('React noise filter left review mutations in the noise candidate set.');
	}
	await page.locator('.configops-review-filters button').first().click();
	const restoreOption = blogDescriptionRow.getByRole('button', { name: 'Restore option' });
	await restoreOption.focus();
	if ((await restoreOption.locator('xpath=..').getByRole('tooltip').evaluate((element) => getComputedStyle(element).visibility)) !== 'visible') {
		throw new Error('Restore safety explanation is not attached to the risky action on keyboard focus.');
	}
	await page.locator('.configops-review-header h2').click();

	await page.screenshot({ path: new URL('configops-review-desktop.png', artifacts).pathname, fullPage: true });

	await page.setViewportSize({ width: 390, height: 844 });
	const mobileSessionLayout = await page.locator('.configops-session-list').evaluate((element) => ({
		display: getComputedStyle(element).display,
		overflowX: getComputedStyle(element).overflowX,
	}));
	if (mobileSessionLayout.display !== 'flex' || mobileSessionLayout.overflowX !== 'auto') {
		throw new Error(`Expected a horizontally scrollable mobile session rail, received ${JSON.stringify(mobileSessionLayout)}.`);
	}
	const mobileViewport = await page.evaluate(() => ({
		clientWidth: document.documentElement.clientWidth,
		scrollWidth: document.documentElement.scrollWidth,
	}));
	if (mobileViewport.scrollWidth > mobileViewport.clientWidth) {
		throw new Error(`ConfigOps caused page-level horizontal overflow on mobile: ${JSON.stringify(mobileViewport)}.`);
	}
	const mobileColumns = await blogDescriptionRow.locator('.configops-diff-row').nth(1).evaluate((element) => getComputedStyle(element).gridTemplateColumns);
	if (mobileColumns.split(' ').length !== 2) {
		throw new Error(`Expected a two-column mobile diff, received ${mobileColumns}.`);
	}
	await page.screenshot({ path: new URL('configops-review-mobile.png', artifacts).pathname, fullPage: true });

	await page.goto(`${baseUrl}/wp-admin/options-general.php`, { waitUntil: 'networkidle' });
	await page.locator('#blogdescription').fill(previousDescription);
	await page.locator('#submit').click();
	await page.waitForLoadState('networkidle');

	if (runtimeErrors.length > 0) {
		throw new Error(`Browser runtime errors:\n${runtimeErrors.join('\n')}`);
	}

	process.stdout.write('ConfigOps visual flow passed; desktop and mobile screenshots written to artifacts/.\n');
} finally {
	await browser.close();
}
