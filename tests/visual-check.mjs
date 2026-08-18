import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright-core';

const baseUrl = process.env.CONFIGOPS_TEST_URL || 'http://127.0.0.1:9400';
const executablePath = process.env.CONFIGOPS_CHROME_PATH || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const artifacts = new URL('../artifacts/', import.meta.url);

await mkdir(artifacts, { recursive: true });

const browser = await chromium.launch({ executablePath, headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 }, deviceScaleFactor: 1 });
const runtimeErrors = [];

const interceptMutationPayload = (transform) => async (route) => {
	const url = decodeURIComponent(route.request().url());
	if (route.request().method() !== 'GET' || !url.includes('/configops/v1/captures/') || !url.includes('/mutations')) {
		await route.continue();

		return;
	}

	const response = await route.fetch();
	const payload = await response.json();
	await transform(payload);
	await route.fulfill({ response, json: payload });
};

const assertNoHorizontalOverflow = async (context) => {
	const viewport = await page.evaluate(() => ({
		clientWidth: document.documentElement.clientWidth,
		scrollWidth: document.documentElement.scrollWidth,
	}));
	if (viewport.scrollWidth > viewport.clientWidth) {
		throw new Error(`${context} caused page-level horizontal overflow on mobile: ${JSON.stringify(viewport)}.`);
	}
};

page.on('pageerror', (error) => runtimeErrors.push(error.message));

try {
	const loginUrl = `${baseUrl}/wp-login.php`;
	await page.goto(loginUrl, { waitUntil: 'domcontentloaded' });
	const loginResponse = await page.request.post(loginUrl, {
		form: {
			log: 'admin',
			pwd: 'password',
			'wp-submit': 'Log In',
			redirect_to: `${baseUrl}/wp-admin/`,
			testcookie: '1',
		},
		maxRedirects: 0,
	});
	if (loginResponse.status() !== 302) {
		throw new Error(`Could not authenticate the visual-flow user: ${loginResponse.status()}.`);
	}
	let reviewReady = false;
	for (let attempt = 0; attempt < 6 && !reviewReady; attempt += 1) {
		await page.goto(`${baseUrl}/wp-admin/admin.php?page=configops`, { waitUntil: 'domcontentloaded' });
		reviewReady = await page.getByRole('heading', { name: 'Review changes', exact: true }).isVisible().catch(() => false);
	}
	if (!reviewReady) {
		throw new Error('The authenticated ConfigOps review did not become ready.');
	}
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
	const appNavigation = page.locator('.configops-app-nav');
	if ((await appNavigation.getByRole('link', { name: 'Changes' }).getAttribute('aria-current')) !== 'page') {
		throw new Error('Server-routed navigation does not expose the current page to assistive technology.');
	}
	if (!await appNavigation.getByRole('link', { name: 'Plugin support' }).isVisible()) {
		throw new Error('Plugin support is missing from the product navigation.');
	}
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
	const captureIsland = page.locator('#configops-capture-island');
	await captureIsland.waitFor();
	await page.waitForFunction(() => document.getElementById('configops-capture-island')?.getAttribute('aria-busy') !== 'true');
	await captureIsland.getByText('Automatic recording is on', { exact: true }).waitFor();

	await page.goto(`${baseUrl}/wp-admin/options-reading.php`, { waitUntil: 'networkidle' });
	const automaticPageSize = page.locator('#posts_per_page');
	const automaticBaseline = await automaticPageSize.inputValue();
	await automaticPageSize.fill(automaticBaseline === '10' ? '11' : '10');
	await page.locator('#submit').click();
	await page.waitForLoadState('networkidle');
	const evidenceCard = page.locator('.configops-evidence-card').last();
	await evidenceCard.waitFor();
	if (!await evidenceCard.getByText(/ConfigOps observed \d+ write/).isVisible()) {
		throw new Error('An ordinary settings save did not produce immediate ConfigOps evidence.');
	}
	if (!await evidenceCard.getByRole('link', { name: 'Review' }).isVisible()) {
		throw new Error('Automatic evidence did not expose its review action.');
	}
	await page.screenshot({ path: new URL('configops-automatic-evidence.png', artifacts).pathname, fullPage: true });
	await page.screenshot({ path: new URL('configops-automatic-evidence-focus.png', artifacts).pathname, fullPage: false });
	const evidenceStack = page.locator('#configops-evidence-stack');
	const evidenceStackStyle = await evidenceStack.getAttribute('style');
	await evidenceStack.evaluate((element) => {
		element.style.setProperty('width', '390px');
		element.style.setProperty('right', '0');
		element.style.setProperty('bottom', '0');
		element.style.setProperty('zoom', '2');
	});
	await evidenceCard.screenshot({ path: new URL('configops-evidence-card-focus.png', artifacts).pathname });
	await evidenceStack.evaluate((element, previousStyle) => {
		if (previousStyle === null) element.removeAttribute('style');
		else element.setAttribute('style', previousStyle);
	}, evidenceStackStyle);
	page.once('dialog', (dialog) => dialog.accept());
	await evidenceCard.getByRole('button', { name: 'Undo' }).click();
	await page.waitForLoadState('networkidle');
	await page.getByText(/\d+ options? (?:was|were) restored\./).waitFor();
	await page.goto(`${baseUrl}/wp-admin/options-reading.php`, { waitUntil: 'networkidle' });
	if (await page.locator('#posts_per_page').inputValue() !== automaticBaseline) {
		throw new Error('Direct evidence-card undo did not restore the conflict-checked baseline.');
	}

	await page.goto(`${baseUrl}/wp-admin/admin.php?page=configops`, { waitUntil: 'networkidle' });
	if (!await page.locator('#configops-capture-name').isVisible()) {
		await page.getByRole('button', { name: 'Start change session' }).click();
	}

	await page.locator('#configops-capture-name').fill('Core reading settings');
	const recordButton = page.getByRole('button', { name: 'Start session' });
	const recordButtonBox = await recordButton.boundingBox();
	if (!recordButtonBox || recordButtonBox.width > 220) {
		throw new Error(`Desktop record command expanded into a banner: ${recordButtonBox?.width ?? 'missing'} px.`);
	}
	await recordButton.click();
	await page.waitForLoadState('networkidle');
	await page.locator('#configops-capture-island').getByText('Recording now', { exact: true }).waitFor();

	await page.goto(`${baseUrl}/wp-admin/options-general.php`, { waitUntil: 'networkidle' });
	const description = page.locator('#blogdescription');
	const previousDescription = await description.inputValue();
	const verificationDescription = `ConfigOps visual verification ${String(Date.now()).slice(-6)}`;
	await description.fill(verificationDescription);
	const intentCookie = await page.evaluate(() => {
		const encoded = document.cookie
			.split('; ')
			.find((part) => part.startsWith('configops_intent='))
			?.split('=')[1] || '';
		if (!encoded) return null;
		const padded = encoded.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(encoded.length / 4) * 4, '=');
		const bytes = Uint8Array.from(window.atob(padded), (character) => character.charCodeAt(0));

		return JSON.parse(new TextDecoder().decode(bytes));
	});
	if (!intentCookie?.fields?.some((field) => field.name === 'blogdescription' && field.label === 'Tagline')) {
		throw new Error(`The local intent cookie did not retain the touched field identity: ${JSON.stringify(intentCookie)}.`);
	}
	const serializedIntent = JSON.stringify(intentCookie);
	if (serializedIntent.includes(verificationDescription) || (previousDescription && serializedIntent.includes(previousDescription))) {
		throw new Error('The local intent cookie retained a configuration field value.');
	}
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
	if (await page.locator('.configops-write-signal').count()) {
		throw new Error('An ordinary Options API save was duplicated as an unmanaged database write signal.');
	}

	const layout = await page.locator('.configops-workspace').evaluate((element) => getComputedStyle(element).display);
	if (layout !== 'grid') {
		throw new Error(`Expected desktop workspace grid, received ${layout}.`);
	}
	const blogDescriptionRow = page.locator('.configops-mutation', { hasText: 'blogdescription' }).first();
	const intentSummary = page.locator('.configops-intent-summary').first();
	if (!await intentSummary.getByText('Observed intent', { exact: true }).isVisible()) {
		throw new Error('The settings save did not expose its locally observed form intent.');
	}
	if (!await blogDescriptionRow.getByText('Observed field', { exact: true }).isVisible()) {
		throw new Error('The exact admin field-to-option match is missing from review evidence.');
	}
	const afterValueText = await blogDescriptionRow.locator('.configops-diff-value.is-after pre').innerText();
	if (!afterValueText.includes(JSON.stringify(verificationDescription))) {
		throw new Error('The captured string value is not rendered with explicit type-preserving quotes.');
	}

	const injectEmptyBeforeValue = interceptMutationPayload((payload) => {
		const taglineMutation = payload.groups
			?.flatMap((group) => group.mutations || [])
			.find((mutation) => mutation.optionName === 'blogdescription');
		if (taglineMutation?.diff?.[0]) {
			taglineMutation.diff[0].before = null;
		}
	});
	await page.route('**/*', injectEmptyBeforeValue);
	await page.reload({ waitUntil: 'networkidle' });
	const emptyBeforeValue = page.locator('.configops-mutation', { hasText: 'blogdescription' }).first()
		.locator('.configops-diff-value.is-before pre');
	if (await emptyBeforeValue.innerText() !== 'Empty' || !await emptyBeforeValue.evaluate((element) => element.classList.contains('is-empty'))) {
		throw new Error('A semantically empty field is not rendered as the quiet Empty state.');
	}
	await page.screenshot({ path: new URL('configops-empty-value-desktop.png', artifacts).pathname, fullPage: true });
	await page.unroute('**/*', injectEmptyBeforeValue);
	await page.reload({ waitUntil: 'networkidle' });
	const unknownBadge = blogDescriptionRow.locator('.configops-badge').first();
	if (!await unknownBadge.isVisible()) {
		throw new Error('The setting count is missing from the mutation summary.');
	}
	const technicalEvidence = blogDescriptionRow.locator('.configops-technical-evidence');
	await technicalEvidence.locator('summary').click();
	if (!await technicalEvidence.getByText('Why it is here', { exact: true }).isVisible()) {
		throw new Error('Classification evidence is not available through native disclosure.');
	}
	const reviewFilter = page.locator('.configops-review-filters button').first();
	await reviewFilter.click();
	if (await page.locator('#configops-change-list .configops-mutation.is-derived').count()) {
		throw new Error('React review filter left likely-noise mutations in the review decision set.');
	}
	const noiseFilter = page.locator('.configops-review-filters button').nth(1);
	await noiseFilter.click();
	if (await page.locator('#configops-change-list .configops-mutation:not(.is-derived)').count()) {
		throw new Error('React noise filter left review mutations in the noise candidate set.');
	}
	await page.locator('.configops-review-filters button').nth(2).click();
	const restoreOption = blogDescriptionRow.getByRole('button', { name: 'Undo this setting' });
	await restoreOption.focus();
	if (!await restoreOption.locator('xpath=..').getByText('Current value is checked first.', { exact: true }).isVisible()) {
		throw new Error('Restore safety explanation is not attached to the risky action.');
	}
	await page.locator('.configops-review-header h2').click();
	await page.locator('.configops-review-filters button').first().click();
	await page.locator('.configops-review-header h2').click();

	await page.screenshot({ path: new URL('configops-review-desktop.png', artifacts).pathname, fullPage: true });

	await page.setViewportSize({ width: 390, height: 844 });
	const mobileSessionPicker = page.locator('#configops-session-select');
	if (!await mobileSessionPicker.isVisible()) {
		throw new Error('The compact mobile capture chooser is not visible.');
	}
	if (await page.locator('.configops-session-list').isVisible()) {
		throw new Error('The long capture history stayed visible beside the mobile chooser.');
	}
	await assertNoHorizontalOverflow('ConfigOps review');
	const mobileTransition = await blogDescriptionRow.locator('.configops-diff-row').first().evaluate((element) => ({
		display: getComputedStyle(element).display,
		direction: getComputedStyle(element).flexDirection,
		nowOrder: getComputedStyle(element.querySelector('.is-after')).order,
		beforeOrder: getComputedStyle(element.querySelector('.is-before')).order,
	}));
	if (mobileTransition.display !== 'flex' || mobileTransition.direction !== 'column' || Number(mobileTransition.nowOrder) >= Number(mobileTransition.beforeOrder)) {
		throw new Error(`Expected a stacked Now-before-Before mobile decision, received ${JSON.stringify(mobileTransition)}.`);
	}
	await page.screenshot({ path: new URL('configops-review-mobile.png', artifacts).pathname, fullPage: true });

	const injectMediaReference = interceptMutationPayload((payload) => {
		const firstMutation = payload.groups
			?.flatMap((group) => group.mutations || [])
			.find((mutation) => mutation.classification !== 'derived');
		const firstChange = firstMutation?.diff?.[0];
		if (firstMutation && firstChange) {
			firstMutation.classification = 'reference';
			firstMutation.classificationLabel = 'Website-specific link';
			firstMutation.displayName = 'Site icon';
			firstMutation.optionName = 'site_icon';
			firstChange.label = 'Site icon';
			firstChange.group = 'Site identity';
			firstChange.kind = 'reference';
			firstChange.reference_type = 'media';
			firstChange.before_reference = {
				type: 'media',
				id: 41,
				status: 'available',
				current_status: 'missing',
				title: 'Previous brand mark',
				filename: 'brand-mark-old.png',
				mime: 'image/png',
				width: 512,
				height: 512,
				filesize: 18432,
				preview_url: '',
			};
			firstChange.after_reference = {
				type: 'media',
				id: 42,
				status: 'available',
				current_status: 'available',
				title: 'Current brand mark',
				filename: 'brand-mark.png',
				mime: 'image/png',
				width: 512,
				height: 512,
				filesize: 24576,
				preview_url: `${baseUrl}/wp-includes/images/w-logo-blue-white-bg.png`,
			};
		}
	});
	await page.route('**/*', injectMediaReference);
	await page.setViewportSize({ width: 1440, height: 1100 });
	await page.reload({ waitUntil: 'networkidle' });
	const mediaRow = page.locator('.configops-mutation').first();
	await mediaRow.getByText('Site icon', { exact: true }).first().waitFor();
	if (await mediaRow.locator('.configops-reference-value').count() !== 2) {
		throw new Error('A media diff did not replace both raw attachment IDs with reference evidence.');
	}
	if (await mediaRow.locator('.configops-reference-mark img').count() !== 1 || !await mediaRow.getByText('Missing', { exact: true }).isVisible()) {
		throw new Error('Media review does not expose both the thumbnail and missing-attachment state.');
	}
	if (await mediaRow.getByRole('button', { name: 'Undo this setting' }).count()) {
		throw new Error('A missing media target still exposes an undo command.');
	}
	if (!await mediaRow.getByText('Undo unavailable', { exact: true }).isVisible()) {
		throw new Error('A missing media target does not explain why undo is unavailable.');
	}
	await page.screenshot({ path: new URL('configops-media-review-desktop.png', artifacts).pathname, fullPage: true });

	await page.setViewportSize({ width: 390, height: 844 });
	await assertNoHorizontalOverflow('Media reference review');
	await page.screenshot({ path: new URL('configops-media-review-mobile.png', artifacts).pathname, fullPage: true });
	await page.unroute('**/*', injectMediaReference);

	const injectContentReference = interceptMutationPayload((payload) => {
		const firstMutation = payload.groups?.flatMap((group) => group.mutations || [])[0];
		const firstChange = firstMutation?.diff?.[0];
		if (firstMutation && firstChange) {
			firstMutation.classification = 'reference';
			firstMutation.classificationLabel = 'Website-specific link';
			firstMutation.displayName = 'Yoast SEO';
			firstMutation.optionName = 'wpseo_llmstxt';
			firstChange.label = 'Contact page';
			firstChange.group = 'AI discovery';
			firstChange.kind = 'reference';
			firstChange.reference_type = 'content';
			firstChange.before_reference = {
				type: 'content',
				id: 71,
				status: 'available',
				current_status: 'available',
				title: 'Original contact page',
				post_type: 'page',
				type_label: 'Page',
				post_status: 'publish',
			};
			firstChange.after_reference = {
				type: 'content',
				id: 72,
				status: 'available',
				current_status: 'available',
				title: 'Current contact page',
				post_type: 'page',
				type_label: 'Page',
				post_status: 'publish',
			};
		}
	});
	await page.route('**/*', injectContentReference);
	await page.setViewportSize({ width: 1440, height: 1100 });
	await page.reload({ waitUntil: 'networkidle' });
	const contentRow = page.locator('.configops-mutation').first();
	await contentRow.getByText('Contact page', { exact: true }).first().waitFor();
	if (await contentRow.locator('.configops-reference-value').count() !== 2) {
		throw new Error('A content diff did not replace both raw post IDs with reference evidence.');
	}
	if (!await contentRow.getByText('Original contact page', { exact: true }).isVisible() || !await contentRow.getByText('Content #72', { exact: true }).isVisible()) {
		throw new Error('Content reference review does not expose bounded page identity.');
	}
	await page.screenshot({ path: new URL('configops-content-review-desktop.png', artifacts).pathname, fullPage: true });

	await page.setViewportSize({ width: 390, height: 844 });
	await assertNoHorizontalOverflow('Content reference review');
	await page.screenshot({ path: new URL('configops-content-review-mobile.png', artifacts).pathname, fullPage: true });
	await page.unroute('**/*', injectContentReference);

	const injectIncompleteCapture = interceptMutationPayload((payload) => {
		payload.summary.captureErrors = 2;
		payload.summary.allRestorable = false;
		const firstMutation = payload.groups
			?.flatMap((group) => group.mutations || [])
			.find((mutation) => mutation.classification !== 'derived');
		if (firstMutation) {
			firstMutation.lastRestore = {
				id: 1,
				status: 'succeeded',
				restoredOptionCount: 1,
				failureCode: '',
				actorName: 'admin',
				finishedAt: '2026-08-12T12:00:00+00:00',
				finishedAtLabel: '2026-08-12 12:00:00',
			};
		}
	});
	await page.route('**/*', injectIncompleteCapture);
	await page.setViewportSize({ width: 1440, height: 1100 });
	await page.reload({ waitUntil: 'networkidle' });
	const integrityWarning = page.getByRole('alert').filter({ hasText: 'Capture incomplete' });
	await integrityWarning.waitFor();
	if (await page.getByRole('button', { name: 'Undo capture' }).count()) {
		throw new Error('An incomplete capture still exposes whole-capture undo.');
	}
	if (!await integrityWarning.getByText('2', { exact: true }).isVisible()) {
		throw new Error('The integrity warning does not expose the number of missed observations.');
	}
	await page.getByText('Undone', { exact: true }).first().waitFor();
	const firstAuditedMutation = page.locator('.configops-mutation').first();
	if (await firstAuditedMutation.getByRole('button', { name: 'Undo this setting' }).count()) {
		throw new Error('A successfully audited mutation still offers the same undo action.');
	}
	await page.screenshot({ path: new URL('configops-incomplete-capture-desktop.png', artifacts).pathname, fullPage: true });

	await page.setViewportSize({ width: 390, height: 844 });
	await assertNoHorizontalOverflow('Capture integrity warning');
	await page.screenshot({ path: new URL('configops-incomplete-capture-mobile.png', artifacts).pathname, fullPage: true });
	await page.unroute('**/*', injectIncompleteCapture);

	await page.setViewportSize({ width: 1440, height: 1100 });
	await page.goto(`${baseUrl}/wp-admin/admin.php?page=configops&view=support`, { waitUntil: 'networkidle' });
	await page.getByRole('heading', { name: 'Plugin support', exact: true }).waitFor();
	if (await page.locator('.configops-support-row').count() !== 3) {
		throw new Error('The support contract should list WordPress Core and both shipped real-plugin adapters.');
	}
	const firstSupport = page.locator('.configops-support-row').first();
	await firstSupport.locator('summary').click();
	if (await firstSupport.locator('.configops-support-capability').count() !== 5) {
		throw new Error('The expanded plugin row does not disclose all five current capabilities.');
	}
	if (await page.getByText('Know what ConfigOps understands.', { exact: true }).count()) {
		throw new Error('The removed support marketing copy is still rendered.');
	}
	await page.screenshot({ path: new URL('configops-support-desktop.png', artifacts).pathname, fullPage: true });

	await page.setViewportSize({ width: 390, height: 844 });
	await assertNoHorizontalOverflow('Supported plugins');
	await page.screenshot({ path: new URL('configops-support-mobile.png', artifacts).pathname, fullPage: true });

	await page.goto(`${baseUrl}/wp-admin/options-general.php`, { waitUntil: 'networkidle' });
	await page.locator('#blogdescription').fill(previousDescription);
	await page.locator('#submit').click();
	await page.waitForLoadState('networkidle');

	if (runtimeErrors.length > 0) {
		throw new Error(`Browser runtime errors:\n${runtimeErrors.join('\n')}`);
	}

	process.stdout.write('ConfigOps review and plugin-support visual flow passed; responsive screenshots written to artifacts/.\n');
} finally {
	await browser.close();
}
