import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright-core';

const baseUrl = process.env.CONFIGOPS_TEST_URL || 'http://127.0.0.1:9401';
const executablePath = process.env.CONFIGOPS_CHROME_PATH || (
	process.platform === 'darwin'
		? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
		: '/usr/bin/google-chrome'
);
const artifacts = new URL('../artifacts/adapter-user-flows/', import.meta.url);
const focusViewport = { width: 1800, height: 1100 };

await mkdir(artifacts, { recursive: true });

const browser = await chromium.launch({ executablePath, headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 }, deviceScaleFactor: 1 });
page.setDefaultTimeout(30_000);
page.setDefaultNavigationTimeout(45_000);

const captureFocus = async (locator, filename, maxHeight = 900) => {
	const previousViewport = page.viewportSize();
	await page.setViewportSize(focusViewport);

	try {
		await locator.waitFor();
		const box = await locator.boundingBox();
		assert.ok(box, `Could not measure ${filename} for its focused screenshot.`);
		await page.screenshot({
			path: new URL(filename, artifacts).pathname,
			clip: {
				x: Math.max(0, Math.floor(box.x)),
				y: Math.max(0, Math.floor(box.y)),
				width: Math.ceil(box.width),
				height: Math.min(Math.ceil(box.height), maxHeight),
			},
		});
	} finally {
		if (previousViewport) await page.setViewportSize(previousViewport);
	}
};

const startCapture = async (name) => {
	await page.goto(`${baseUrl}/wp-admin/admin.php?page=configops`, { waitUntil: 'domcontentloaded' });
	const captureIsland = page.locator('#configops-capture-island');
	await captureIsland.waitFor();
	await page.waitForFunction(() => document.getElementById('configops-capture-island')?.getAttribute('aria-busy') !== 'true');
	if (!await page.locator('#configops-capture-name').isVisible()) {
		await page.getByRole('button', { name: 'Start change session' }).click();
	}
	await page.locator('#configops-capture-name').fill(name);
	await page.getByRole('button', { name: 'Start session' }).click();
	await page.getByText('Recording now', { exact: true }).first().waitFor();
};

const stopCapture = async () => {
	await page.goto(`${baseUrl}/wp-admin/admin.php?page=configops`, { waitUntil: 'domcontentloaded' });
	await page.getByRole('button', { name: 'Stop & review' }).click();
	await page.getByText('Recorded', { exact: true }).first().waitFor();
	await page.locator('.configops-request-group').first().waitFor();
};

const undoVisibleSetting = async (name) => {
	page.once('dialog', (dialog) => dialog.accept());
	await page.getByRole('button', { name }).click();
	await page.getByText(/supported setting values were undone|option was restored/i).waitFor();
	assert.equal(await page.getByRole('button', { name }).count(), 0, 'A completed undo must not offer the same historical action again.');
	await page.getByText('Undone', { exact: true }).first().waitFor();
};

try {
	const loginUrl = `${baseUrl}/wp-login.php`;
	const anonymousBurst = await Promise.all(
		Array.from({ length: 48 }, (_, index) => page.request.get(`${baseUrl}/?configops_burst=${index}`))
	);
	assert.equal(anonymousBurst.every((response) => response.ok()), true, 'A concurrent anonymous frontend burst must complete without HTTP errors.');
	const anonymousHtml = await anonymousBurst[0].text();
	assert.equal(
		/configops-(?:admin|intent-observer|runtime)|wp-content\/plugins\/configops\/assets/i.test(anonymousHtml),
		false,
		'Anonymous frontend HTML must not expose ConfigOps CSS, JavaScript, or bootstrap state.'
	);
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
	assert.equal(loginResponse.status(), 302, 'The isolated Playground user should authenticate before plugin testing.');
	let dashboardReady = false;
	for (let attempt = 0; attempt < 6 && !dashboardReady; attempt += 1) {
		await page.goto(`${baseUrl}/wp-admin/`, { waitUntil: 'domcontentloaded' });
		dashboardReady = await page.getByRole('heading', { name: 'Dashboard', exact: true }).isVisible().catch(() => false);
	}
	assert.equal(dashboardReady, true, 'Plugin onboarding redirects should settle before the settings workflow begins.');

	await page.goto(`${baseUrl}/wp-admin/admin.php?page=configops&view=support`, { waitUntil: 'domcontentloaded' });
	assert.match(page.url(), /\/wp-admin\/admin\.php/, 'The browser should reach the authenticated ConfigOps support screen.');
	await page.getByRole('heading', { name: 'Plugin support', exact: true }).waitFor();
	const readyPlugins = page.getByText('Active', { exact: true });
	await readyPlugins.first().waitFor();
	assert.equal(await readyPlugins.count(), 3, 'WordPress Core and both exact plugin releases should be active before user-flow testing.');
	await captureFocus(page.locator('#configops-support-island'), 'support-focus.png');

	await startCapture('Configure SMTP delivery');
	await page.goto(`${baseUrl}/wp-admin/admin.php?page=wp-mail-smtp`, { waitUntil: 'domcontentloaded' });
	const initialSenderEmail = await page.locator('#wp-mail-smtp-setting-from_email').inputValue();
	const initialDefaultMailer = await page.locator('#wp-mail-smtp-setting-mailer-mail').isChecked();
	await page.locator('#wp-mail-smtp-setting-from_email').fill('noreply@agency.example');
	await page.locator('#wp-mail-smtp-setting-from_name').fill('Agency Mail');
	await page.locator('#wp-mail-smtp-setting-mailer-smtp').check();
	await page.locator('#wp-mail-smtp-setting-smtp-host').fill('smtp.agency.example');
	await page.locator('#wp-mail-smtp-setting-smtp-enc-tls').check();
	await page.locator('#wp-mail-smtp-setting-smtp-port').fill('587');
	await page.locator('#wp-mail-smtp-setting-smtp-user').fill('agency-user');
	await page.locator('#wp-mail-smtp-setting-smtp-pass').fill('not-a-real-password');
	await page.locator('form.wp-mail-smtp-connection-settings-form button[type=submit]').click();
	await page.waitForTimeout(400);
	const confirmation = page.locator('.jconfirm-box');
	if (await confirmation.isVisible().catch(() => false)) {
		await confirmation.getByRole('button', { name: 'Save Settings' }).click();
	}
	await page.getByText(/Settings were successfully saved/i).waitFor();
	await stopCapture();

	const mailReview = page.locator('#configops-review-island');
	assert.equal(await mailReview.getByText('SMTP password', { exact: true }).count(), 1, 'The chosen SMTP password should be identified exactly once.');
	assert.equal(await mailReview.getByText(/defaults$/, { exact: false }).count(), 0, 'Unused provider defaults must not pollute the default settings review.');
	assert.equal(await mailReview.locator('.configops-write-signal').count(), 0, 'Known runtime locks must not be presented as unmanaged user changes.');
	assert.equal((await mailReview.innerText()).includes('not-a-real-password'), false, 'The typed SMTP password must never be rendered or bootstrapped.');
	assert.equal(await mailReview.getByRole('button', { name: 'Undo 7 safe settings' }).count(), 1, 'Visible non-secret SMTP fields should remain individually reversible.');
	assert.equal(await mailReview.locator('.configops-option > span').getByText('WP Mail SMTP', { exact: true }).count(), 1, 'A user should see the responsible plugin before its technical source path.');
	assert.equal(await page.locator('#wp-admin-bar-configops-recording').count(), 0, 'Stopping from the React control should remove the recording badge immediately.');
	assert.equal(await mailReview.locator('.configops-request-index').first().innerText(), 'SAVE ACTION 01', 'Filtered request groups should begin at one.');
	await captureFocus(mailReview, 'wp-mail-smtp-review-focus.png', 840);
	await captureFocus(mailReview.locator('.configops-request-group').first(), 'wp-mail-smtp-observation-focus.png', 660);
	await page.screenshot({ path: new URL('wp-mail-smtp-review.png', artifacts).pathname, fullPage: true });
	await page.setViewportSize({ width: 390, height: 844 });
	await page.screenshot({ path: new URL('wp-mail-smtp-review-mobile.png', artifacts).pathname, fullPage: true });
	await page.setViewportSize({ width: 1440, height: 1100 });

	await undoVisibleSetting('Undo 7 safe settings');
	await page.goto(`${baseUrl}/wp-admin/admin.php?page=wp-mail-smtp`, { waitUntil: 'domcontentloaded' });
	assert.equal(await page.locator('#wp-mail-smtp-setting-from_email').inputValue(), initialSenderEmail, 'Safe undo should restore the site’s actual previous sender email.');
	assert.equal(await page.locator('#wp-mail-smtp-setting-mailer-mail').isChecked(), initialDefaultMailer, 'Safe undo should restore the site’s actual previous delivery method.');

	await startCapture('Turn off XML sitemaps');
	await page.goto(`${baseUrl}/wp-admin/admin.php?page=wpseo_page_settings#/site-features`, { waitUntil: 'domcontentloaded' });
	await page.waitForTimeout(750);
	const yoastOverlay = page.locator('.yst-modal__overlay');
	if (await yoastOverlay.count()) {
		const closeButton = page.locator('[role=dialog]').getByRole('button', { name: 'Close', exact: true });
		if (await closeButton.count()) {
			await closeButton.first().evaluate((button) => button.click());
			await page.waitForTimeout(350);
		}
	}
	const sitemapToggle = page.locator('#card-wpseo-enable_xml_sitemap [role=switch]');
	assert.equal(await sitemapToggle.getAttribute('aria-checked'), 'true', 'Yoast XML sitemaps should begin enabled.');
	await sitemapToggle.click();
	await page.locator('#button-submit-settings').click();
	await page.getByText('Great! Your settings were saved successfully.', { exact: true }).waitFor();
	await stopCapture();

	const yoastReview = page.locator('#configops-review-island');
	await yoastReview.getByText('XML sitemaps', { exact: true }).waitFor();
	await page.screenshot({ path: new URL('yoast-review.png', artifacts).pathname, fullPage: true });
	const yoastVisibleRows = await yoastReview.locator('.configops-diff-row').count();
	if (yoastVisibleRows !== 1) {
		process.stderr.write(`Unexpected Yoast review (${yoastVisibleRows} rows):\n${await yoastReview.innerText()}\n`);
	}
	assert.equal(yoastVisibleRows, 1, 'One Yoast toggle should read as one setting in the default review.');
	assert.equal(await yoastReview.getByText('XML sitemaps', { exact: true }).count(), 1, 'The Yoast setting should use the same wording the user clicked.');
	assert.equal(await yoastReview.getByText('Organization logo cache', { exact: true }).count(), 0, 'Yoast housekeeping must stay out of the default settings review.');
	assert.equal(await yoastReview.locator('.configops-write-signal').count(), 0, 'Core user preference writes must not block a Yoast settings review.');
	assert.equal(await yoastReview.getByRole('button', { name: 'Undo this change' }).count(), 1, 'An unchanged hidden credential must not block undoing the visible Yoast toggle.');
	assert.equal(await yoastReview.locator('.configops-option > span').getByText('Yoast SEO', { exact: true }).count(), 1, 'Yoast provenance should lead with a recognizable product name.');
	assert.equal(await page.getByRole('button', { name: 'Why can’t I undo the whole capture?' }).count(), 1, 'Undo limits should say they affect the whole capture, not the visible per-setting action.');
	assert.equal(await page.locator('#wp-admin-bar-configops-recording').count(), 0, 'The recording badge should not survive a completed Yoast capture.');
	assert.equal(await yoastReview.locator('.configops-request-index').first().innerText(), 'SAVE ACTION 01', 'Technical requests hidden by the Review filter must not create a confusing numbering gap.');
	await captureFocus(yoastReview, 'yoast-review-focus.png', 605);
	await captureFocus(yoastReview.locator('.configops-request-group').first(), 'yoast-observation-focus.png', 470);
	await page.setViewportSize({ width: 390, height: 844 });
	await page.screenshot({ path: new URL('yoast-review-mobile.png', artifacts).pathname, fullPage: true });
	await page.setViewportSize({ width: 1440, height: 1100 });

	await undoVisibleSetting('Undo this change');
	await page.goto(`${baseUrl}/wp-admin/admin.php?page=wpseo_page_settings#/site-features`, { waitUntil: 'domcontentloaded' });
	assert.equal(await page.locator('#card-wpseo-enable_xml_sitemap [role=switch]').getAttribute('aria-checked'), 'true', 'Safe undo should restore the Yoast toggle through the real plugin screen.');

	process.stdout.write('Real WP Mail SMTP and Yoast user flows passed: save, explain, hide noise, preserve secrets, and undo.\n');
} catch (error) {
	await page.screenshot({ path: new URL('failure-state.png', artifacts).pathname, fullPage: true }).catch(() => {});
	const pageSummary = await page.locator('body').innerText({ timeout: 2_000 }).catch(() => '[body unavailable]');
	process.stderr.write(`Browser flow failed at ${page.url()}:\n${pageSummary.slice(0, 8_000)}\n`);
	throw error;
} finally {
	await browser.close();
}
