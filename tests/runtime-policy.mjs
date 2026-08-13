import { readFile } from 'node:fs/promises';

const plugin = await readFile(new URL('../configops.php', import.meta.url), 'utf8');
const readme = await readFile(new URL('../readme.txt', import.meta.url), 'utf8');
const composer = JSON.parse(await readFile(new URL('../composer.json', import.meta.url), 'utf8'));

const headerMinimum = plugin.match(/^ \* Requires PHP:\s+(\d+\.\d+)$/m)?.[1];
const readmeMinimum = readme.match(/^Requires PHP:\s+(\d+\.\d+)$/m)?.[1];
const composerMinimum = composer.require?.php?.match(/^>=(\d+\.\d+)$/)?.[1];
if (!headerMinimum || headerMinimum !== readmeMinimum || headerMinimum !== composerMinimum) {
	throw new Error('PHP runtime requirements disagree across plugin header, readme, and Composer.');
}

const securitySupportEnds = new Map([
	['8.2', '2026-12-31T23:59:59Z'],
	['8.3', '2027-12-31T23:59:59Z'],
	['8.4', '2028-12-31T23:59:59Z'],
	['8.5', '2029-12-31T23:59:59Z'],
]);
const end = securitySupportEnds.get(headerMinimum);
if (!end) {
	throw new Error(`No reviewed upstream lifecycle is recorded for PHP ${headerMinimum}.`);
}

const now = process.env.CONFIGOPS_POLICY_DATE ? new Date(process.env.CONFIGOPS_POLICY_DATE) : new Date();
if (Number.isNaN(now.valueOf())) {
	throw new Error('CONFIGOPS_POLICY_DATE is not a valid date.');
}
if (now > new Date(end)) {
	throw new Error(`PHP ${headerMinimum} is past upstream security support (${end.slice(0, 10)}). Raise and retest the minimum before release.`);
}

process.stdout.write(`PHP ${headerMinimum} runtime policy is valid through ${end.slice(0, 10)}.\n`);
