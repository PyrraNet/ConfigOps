import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';

const repositoryRoot = new URL('../', import.meta.url);
const matrix = JSON.parse(await readFile(new URL('tests/adapter-support-matrix.json', repositoryRoot), 'utf8'));
const ciSource = await readFile(new URL('.github/workflows/ci.yml', repositoryRoot), 'utf8');

const compareVersions = (left, right) => {
	const parse = (version) => String(version).split(/[.+-]/).map((part) => /^\d+$/.test(part) ? Number(part) : part);
	const a = parse(left);
	const b = parse(right);
	for (let index = 0; index < Math.max(a.length, b.length); index += 1) {
		const aPart = a[index] ?? 0;
		const bPart = b[index] ?? 0;
		if (aPart === bPart) continue;
		if (typeof aPart === 'number' && typeof bPart === 'number') return aPart < bPart ? -1 : 1;
		return String(aPart).localeCompare(String(bPart));
	}
	return 0;
};

const matchesConstraint = (version, constraint) => constraint.split(/\s*\|\|\s*/).some((alternative) => (
	alternative.trim().split(/\s+/).every((rule) => {
		const match = rule.match(/^(>=|<=|>|<|=)?(.+)$/);
		if (!match) return false;
		const comparison = compareVersions(version, match[2]);
		return {
			'>=': comparison >= 0,
			'<=': comparison <= 0,
			'>': comparison > 0,
			'<': comparison < 0,
			'=': comparison === 0,
		}[match[1] || '='];
	})
));

const fetchJson = async (url) => {
	const response = await fetch(url, { headers: { 'user-agent': 'ConfigOps adapter compatibility CI' } });
	assert.equal(response.ok, true, `${url} returned ${response.status}`);
	return response.json();
};

assert.match(matrix.definition, /official WordPress\.org plugin statistics API/i);
assert.match(matrix.otherBucketPolicy, /does not disclose/i);

for (const adapter of matrix.adapters) {
	const classSource = await readFile(new URL(adapter.classFile, repositoryRoot), 'utf8');
	assert.equal(
		classSource.includes(`'${adapter.constraint}'`),
		true,
		`${adapter.id} manifest does not match the audited support constraint`,
	);

	const statsUrl = `https://api.wordpress.org/stats/plugin/1.0/${encodeURIComponent(adapter.slug)}`;
	const stats = await fetchJson(statsUrl);
	const liveLines = Object.keys(stats).filter((line) => line !== 'other');
	const recordedByLine = new Map(adapter.activeLines.map((entry) => [entry.line, entry]));

	for (const line of liveLines) {
		const recorded = recordedByLine.get(line);
		assert.ok(recorded, `${adapter.slug} ${line} is visible in current WordPress.org usage but has no ConfigOps contract test`);
		const versionProbe = line.split('.').length >= 3 ? line : `${line}.0`;
		assert.equal(matchesConstraint(versionProbe, adapter.constraint), true, `${adapter.slug} ${line} is outside ${adapter.constraint}`);
		assert.equal(
			recorded.contractRelease === line || recorded.contractRelease.startsWith(`${line}.`),
			true,
			`${adapter.slug} ${line} has no representative release from that line`,
		);
	}
	for (const recorded of adapter.activeLines) {
		const escapedAdapter = adapter.id.replaceAll('-', '\\-');
		const escapedVersion = recorded.contractRelease.replaceAll('.', '\\.');
		assert.match(
			ciSource,
			new RegExp(`adapter:\\s*${escapedAdapter}\\s*\\n\\s*version:\\s*["']?${escapedVersion}["']?`),
			`${adapter.id} ${recorded.contractRelease} is missing from the real-plugin CI matrix`,
		);
	}

	const infoUrl = new URL('https://api.wordpress.org/plugins/info/1.2/');
	infoUrl.searchParams.set('action', 'plugin_information');
	infoUrl.searchParams.set('request[slug]', adapter.slug);
	const info = await fetchJson(infoUrl);
	assert.equal(typeof info.version, 'string', `${adapter.slug} did not return a current release`);
	assert.equal(matchesConstraint(info.version, adapter.constraint), true, `${adapter.slug} current release ${info.version} is outside ${adapter.constraint}`);
	assert.equal(
		adapter.activeLines.some((entry) => entry.contractRelease === info.version),
		true,
		`${adapter.slug} current patch ${info.version} has no exact real-plugin contract`,
	);

	process.stdout.write(
		`${adapter.id}: ${liveLines.join(', ')} covered; current ${info.version}; other ${Number(stats.other || 0).toFixed(2)}% remains undisclosed.\n`,
	);
}
