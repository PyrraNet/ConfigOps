import { readdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';

const root = path.resolve('coverage');
const rawDirectory = path.join(root, 'raw');
const threshold = (name, fallback) => {
	const argument = process.argv.find((candidate) => candidate.startsWith(`--${name}=`));
	const value = Number(argument?.split('=')[1] ?? fallback);
	if (!Number.isFinite(value) || value < 0 || value > 100) {
		throw new Error(`${name} must be between 0 and 100.`);
	}
	return value;
};
const minimum = threshold('minimum', 70);
const criticalMinimum = threshold('critical-minimum', 75);
const criticalPrefixes = [
	'src/Access/',
	'src/Api/',
	'src/Capture/',
	'src/Database/',
	'src/Execution/',
	'src/Maintenance/',
	'src/Multisite/',
	'src/Pack/',
	'src/Reference/',
	'src/Release/',
	'src/Restore/',
];

const fragments = (await readdir(rawDirectory))
	.filter((filename) => filename.endsWith('.json'))
	.sort();
if (fragments.length === 0) {
	throw new Error('No raw coverage fragments were found.');
}

const merged = new Map();
for (const filename of fragments) {
	const payload = JSON.parse(await readFile(path.join(rawDirectory, filename), 'utf8'));
	if (payload.schemaVersion !== 1 || !payload.files || typeof payload.files !== 'object') {
		throw new Error(`Invalid coverage fragment: ${filename}.`);
	}
	for (const [sourceFile, lines] of Object.entries(payload.files)) {
		if (!merged.has(sourceFile)) merged.set(sourceFile, new Map());
		const target = merged.get(sourceFile);
		for (const [line, status] of Object.entries(lines)) {
			const lineNumber = Number(line);
			const hits = Number(status) > 0 ? 1 : 0;
			if (Number.isInteger(lineNumber) && lineNumber > 0) {
				target.set(lineNumber, Math.max(target.get(lineNumber) || 0, hits));
			}
		}
	}
}

const files = [];
let coveredLines = 0;
let executableLines = 0;
for (const [sourceFile, lines] of [...merged.entries()].sort(([left], [right]) => left.localeCompare(right))) {
	const entries = [...lines.entries()].sort(([left], [right]) => left - right);
	const covered = entries.filter(([, hits]) => hits > 0).length;
	const executable = entries.length;
	coveredLines += covered;
	executableLines += executable;
	files.push({
		file: sourceFile,
		covered,
		executable,
		percent: executable === 0 ? 100 : covered / executable * 100,
		lines: Object.fromEntries(entries),
	});
}
if (executableLines === 0) {
	throw new Error('Xdebug returned no executable production lines.');
}

const percent = coveredLines / executableLines * 100;
const passed = percent + Number.EPSILON >= minimum;
const criticalFiles = files.filter((file) => criticalPrefixes.some((prefix) => file.file.startsWith(prefix)));
const criticalCoveredLines = criticalFiles.reduce((total, file) => total + file.covered, 0);
const criticalExecutableLines = criticalFiles.reduce((total, file) => total + file.executable, 0);
if (criticalExecutableLines === 0) {
	throw new Error('Xdebug returned no executable trust-boundary lines.');
}
const criticalPercent = criticalCoveredLines / criticalExecutableLines * 100;
const criticalPassed = criticalPercent + Number.EPSILON >= criticalMinimum;
const summary = {
	schemaVersion: 1,
	metric: 'line',
	scope: 'src/**/*.php',
	minimum,
	coveredLines,
	executableLines,
	percent: Number(percent.toFixed(2)),
	passed,
	critical: {
		name: 'trust-boundaries',
		prefixes: criticalPrefixes,
		minimum: criticalMinimum,
		coveredLines: criticalCoveredLines,
		executableLines: criticalExecutableLines,
		percent: Number(criticalPercent.toFixed(2)),
		passed: criticalPassed,
	},
	fragments,
	files: files.map(({ lines, ...file }) => ({ ...file, percent: Number(file.percent.toFixed(2)) })),
};

const lcov = files.map((file) => [
	'TN:ConfigOps',
	`SF:${file.file}`,
	...Object.entries(file.lines).map(([line, hits]) => `DA:${line},${hits}`),
	`LF:${file.executable}`,
	`LH:${file.covered}`,
	'end_of_record',
].join('\n')).join('\n') + '\n';

const xmlEscape = (value) => String(value)
	.replaceAll('&', '&amp;')
	.replaceAll('<', '&lt;')
	.replaceAll('>', '&gt;')
	.replaceAll('"', '&quot;');
const cloverFiles = files.map((file) => `      <file name="${xmlEscape(file.file)}">
        <metrics statements="${file.executable}" coveredstatements="${file.covered}" elements="${file.executable}" coveredelements="${file.covered}"/>
      </file>`).join('\n');
const clover = `<?xml version="1.0" encoding="UTF-8"?>
<coverage generated="${Math.floor(Date.now() / 1000)}">
  <project timestamp="${Math.floor(Date.now() / 1000)}" name="ConfigOps">
    <package name="ConfigOps">
${cloverFiles}
    </package>
    <metrics statements="${executableLines}" coveredstatements="${coveredLines}" elements="${executableLines}" coveredelements="${coveredLines}"/>
  </project>
</coverage>
`;

await writeFile(path.join(root, 'summary.json'), `${JSON.stringify(summary, null, 2)}\n`);
await writeFile(path.join(root, 'lcov.info'), lcov);
await writeFile(path.join(root, 'clover.xml'), clover);

const lowest = files
	.filter((file) => file.executable > 0)
	.sort((left, right) => left.percent - right.percent || right.executable - left.executable)
	.slice(0, 10);
process.stdout.write(`ConfigOps line coverage: ${percent.toFixed(2)}% (${coveredLines}/${executableLines}); required: ${minimum.toFixed(2)}%.\n`);
process.stdout.write(`Trust-boundary line coverage: ${criticalPercent.toFixed(2)}% (${criticalCoveredLines}/${criticalExecutableLines}); required: ${criticalMinimum.toFixed(2)}%.\n`);
process.stdout.write('Lowest-covered production files:\n');
for (const file of lowest) {
	process.stdout.write(`  ${file.percent.toFixed(2).padStart(6)}%  ${String(file.covered).padStart(4)}/${String(file.executable).padEnd(4)}  ${file.file}\n`);
}
if (!passed) {
	throw new Error(`Line coverage ${percent.toFixed(2)}% is below the required ${minimum.toFixed(2)}%.`);
}
if (!criticalPassed) {
	throw new Error(`Trust-boundary coverage ${criticalPercent.toFixed(2)}% is below the required ${criticalMinimum.toFixed(2)}%.`);
}
process.stdout.write('Coverage gate passed.\n');
