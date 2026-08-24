import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright-core';

const baseUrl = process.env.CONFIGOPS_TEST_URL || 'http://127.0.0.1:9403';
const executablePath = process.env.CONFIGOPS_CHROME_PATH || (
	process.platform === 'darwin'
		? '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
		: '/usr/bin/google-chrome'
);
const artifacts = new URL('../artifacts/playground-preview/', import.meta.url);

await mkdir(artifacts, { recursive: true });

const browser = await chromium.launch({ executablePath, headless: true });
const page = await browser.newPage({ viewport: { width: 1440, height: 1100 }, deviceScaleFactor: 1 });
page.setDefaultTimeout(45_000);
page.setDefaultNavigationTimeout(60_000);

try {
	const instruction = page.getByText(
		'Change the sender email, save, then inspect what WordPress actually wrote.',
		{ exact: true },
	);
	let guidedLandingReady = false;
	for (let attempt = 0; attempt < 12; attempt += 1) {
		await page.goto(`${baseUrl}/wp-admin/admin.php?page=wp-mail-smtp`, { waitUntil: 'domcontentloaded' });
		if (await instruction.isVisible({ timeout: 5_000 }).catch(() => false)) {
			guidedLandingReady = true;
			break;
		}
		await page.waitForTimeout(1_000);
	}
	assert.equal(guidedLandingReady, true, 'The preview must finish booting on the guided settings screen.');
	assert.match(page.url(), /\/wp-admin\/admin\.php\?page=wp-mail-smtp/, 'The preview must land on the guided settings screen.');
	await page.waitForTimeout(1_000);
	assert.equal(
		await page.locator('.configops-evidence-card').count(),
		0,
		'The preview must begin without setup noise masquerading as a user change.',
	);

	const senderEmail = page.locator('#wp-mail-smtp-setting-from_email');
	await senderEmail.waitFor();
	const baseline = await senderEmail.inputValue();
	const changedEmail = baseline === 'preview@configops.test'
		? 'try-configops@configops.test'
		: 'preview@configops.test';
	await senderEmail.fill(changedEmail);
	await page.locator('form.wp-mail-smtp-connection-settings-form button[type=submit]').click();

	await page.waitForTimeout(400);
	const confirmation = page.locator('.jconfirm-box');
	if (await confirmation.isVisible().catch(() => false)) {
		await confirmation.getByRole('button', { name: 'Save Settings' }).click();
	}
	await page.getByText(/Settings were successfully saved/i).waitFor();

	const evidenceCard = page.locator('.configops-evidence-card').last();
	await evidenceCard.waitFor();
	await page.waitForTimeout(1_000);
	assert.equal(
		await page.locator('.configops-evidence-card').count(),
		1,
		'The one guided field change should produce one focused evidence card.',
	);
	assert.match(
		await evidenceCard.innerText(),
		/(?:ConfigOps observed|This save produced) \d+ (?:recorded )?writes?/,
		'The guided save must produce immediate evidence.',
	);
	const reviewLink = evidenceCard.getByRole('link', { name: /^Review(?: writes)?$/ });
	await reviewLink.waitFor();
	await page.screenshot({ path: new URL('save-evidence.png', artifacts).pathname, fullPage: true });

	await reviewLink.click();
	await page.getByRole('heading', { name: /^(?:Change evidence|Review changes)$/ }).waitFor();
	const review = page.locator('#configops-review-island');
	await review.getByText('Sender email', { exact: true }).waitFor();
	await review.getByText('WP Mail SMTP', { exact: true }).first().waitFor();
	assert.equal(
		await review.locator('.configops-diff-row').count(),
		1,
		'The guided sender-email change should review as exactly one setting.',
	);
	assert.equal(
		(await review.innerText()).includes(changedEmail),
		true,
		'The review must show the value WordPress actually stored.',
	);
	await page.screenshot({ path: new URL('review.png', artifacts).pathname, fullPage: true });

	const undo = review.getByRole('button', { name: /^Undo/ }).first();
	await undo.waitFor();
	page.once('dialog', (dialog) => dialog.accept());
	await undo.click();
	await page.getByText(/current value matched the recording|option was restored|supported setting values were undone after a conflict check/i).waitFor();

	await page.goto(`${baseUrl}/wp-admin/admin.php?page=wp-mail-smtp`, { waitUntil: 'domcontentloaded' });
	assert.equal(
		await page.locator('#wp-mail-smtp-setting-from_email').inputValue(),
		baseline,
		'The preview undo must restore the sender email baseline.',
	);

	process.stdout.write('Public Playground preview passed: guided landing, save, evidence, review, and undo.\n');
} catch (error) {
	await page.screenshot({ path: new URL('failure-state.png', artifacts).pathname, fullPage: true }).catch(() => {});
	const pageSummary = await page.locator('body').innerText({ timeout: 2_000 }).catch(() => '[body unavailable]');
	process.stderr.write(`Playground preview failed at ${page.url()}:\n${pageSummary.slice(0, 8_000)}\n`);
	throw error;
} finally {
	await browser.close();
}
