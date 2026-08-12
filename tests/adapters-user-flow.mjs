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

await mkdir(artifacts, { recursive: true });

const browser = await chromium.launch({ executablePath, headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 }, deviceScaleFactor: 1 });
page.setDefaultTimeout(10_000);

const startCapture = async (name) => {
	await page.goto(`${baseUrl}/wp-admin/admin.php?page=configops`, { waitUntil: 'domcontentloaded' });
	await page.locator('#configops-capture-name').fill(name);
	await page.getByRole('button', { name: 'Record changes' }).click();
	await page.getByText('Recording', { exact: true }).first().waitFor();
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
};

try {
	await page.goto(`${baseUrl}/wp-login.php`, { waitUntil: 'domcontentloaded' });
	await page.locator('#user_login').fill('admin');
	await page.locator('#user_pass').fill('password');
	await Promise.all([
		page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 20_000 }),
		page.locator('#wp-submit').click(),
	]);

	await page.goto(`${baseUrl}/wp-admin/admin.php?page=configops&view=support`, { waitUntil: 'networkidle' });
	assert.equal(await page.getByText('Ready on this website', { exact: true }).count(), 2, 'Both exact plugin releases should be ready before user-flow testing.');

	await startCapture('Configure SMTP delivery');
	await page.goto(`${baseUrl}/wp-admin/admin.php?page=wp-mail-smtp`, { waitUntil: 'domcontentloaded' });
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
	assert.equal(await page.locator('#wp-admin-bar-configops-recording').count(), 0, 'Stopping from the React control should remove the recording badge immediately.');
	assert.equal(await mailReview.locator('.configops-request-index').first().innerText(), '01', 'Filtered request groups should begin at one.');
	await page.screenshot({ path: new URL('wp-mail-smtp-review.png', artifacts).pathname, fullPage: true });
	await page.setViewportSize({ width: 390, height: 844 });
	await page.screenshot({ path: new URL('wp-mail-smtp-review-mobile.png', artifacts).pathname, fullPage: true });
	await page.setViewportSize({ width: 1440, height: 1100 });

	await undoVisibleSetting('Undo 7 safe settings');
	await page.goto(`${baseUrl}/wp-admin/admin.php?page=wp-mail-smtp`, { waitUntil: 'networkidle' });
	assert.equal(await page.locator('#wp-mail-smtp-setting-from_email').inputValue(), 'admin@localhost.com', 'Safe undo should restore the previous sender email.');
	assert.equal(await page.locator('#wp-mail-smtp-setting-mailer-mail').isChecked(), true, 'Safe undo should restore the previous delivery method.');

	await startCapture('Turn off XML sitemaps');
	await page.goto(`${baseUrl}/wp-admin/admin.php?page=wpseo_page_settings#/site-features`, { waitUntil: 'networkidle' });
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
	await page.waitForTimeout(1_200);
	await stopCapture();

	const yoastReview = page.locator('#configops-review-island');
	await yoastReview.getByText('XML sitemaps', { exact: true }).waitFor();
	assert.equal(await yoastReview.locator('.configops-diff-row:not(.configops-diff-head)').count(), 1, 'One Yoast toggle should read as one setting in the default review.');
	assert.equal(await yoastReview.getByText('XML sitemaps', { exact: true }).count(), 1, 'The Yoast setting should use the same wording the user clicked.');
	assert.equal(await yoastReview.getByText('Organization logo cache', { exact: true }).count(), 0, 'Yoast housekeeping must stay out of the default settings review.');
	assert.equal(await yoastReview.locator('.configops-write-signal').count(), 0, 'Core user preference writes must not block a Yoast settings review.');
	assert.equal(await yoastReview.getByRole('button', { name: 'Undo this change' }).count(), 1, 'An unchanged hidden credential must not block undoing the visible Yoast toggle.');
	assert.equal(await page.locator('#wp-admin-bar-configops-recording').count(), 0, 'The recording badge should not survive a completed Yoast capture.');
	assert.equal(await yoastReview.locator('.configops-request-index').first().innerText(), '01', 'Technical requests hidden by the Settings filter must not create a confusing numbering gap.');
	await page.screenshot({ path: new URL('yoast-review.png', artifacts).pathname, fullPage: true });
	await page.setViewportSize({ width: 390, height: 844 });
	await page.screenshot({ path: new URL('yoast-review-mobile.png', artifacts).pathname, fullPage: true });
	await page.setViewportSize({ width: 1440, height: 1100 });

	await undoVisibleSetting('Undo this change');
	await page.goto(`${baseUrl}/wp-admin/admin.php?page=wpseo_page_settings#/site-features`, { waitUntil: 'networkidle' });
	assert.equal(await page.locator('#card-wpseo-enable_xml_sitemap [role=switch]').getAttribute('aria-checked'), 'true', 'Safe undo should restore the Yoast toggle through the real plugin screen.');

	process.stdout.write('Real WP Mail SMTP and Yoast user flows passed: save, explain, hide noise, preserve secrets, and undo.\n');
} finally {
	await browser.close();
}
