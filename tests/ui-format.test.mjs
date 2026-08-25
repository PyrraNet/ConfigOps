import assert from 'node:assert/strict';
import test from 'node:test';
import { fileSizeParts, formatValue, mutationSourceIdentity } from '../ui/format.js';

const labels = {
	empty: 'Leer',
	booleanTrue: 'An (wahr)',
	booleanFalse: 'Aus (falsch)',
};

test('formatValue uses caller-provided localized labels', () => {
	assert.equal(formatValue(true, labels), labels.booleanTrue);
	assert.equal(formatValue(false, labels), labels.booleanFalse);
	assert.equal(formatValue('', labels), labels.empty);
	assert.equal(formatValue(null, labels), labels.empty);
});

test('fileSizeParts returns locale-neutral values and semantic units', () => {
	assert.deepEqual(fileSizeParts(1000), { value: 1000, unit: 'bytes' });
	assert.deepEqual(fileSizeParts(1536), { value: 1.5, unit: 'kilobytes' });
	assert.deepEqual(fileSizeParts(1572864), { value: 1.5, unit: 'megabytes' });
	assert.equal(fileSizeParts(-1), null);
	assert.equal(fileSizeParts(Number.NaN), null);
});

test('mutationSourceIdentity exposes adapterless plugin provenance and keeps adapter precedence', () => {
	const adapterless = mutationSourceIdentity({
		source: {
			component: 'configops-hostile-fixture',
			displayName: 'ConfigOps Hostile Fixture',
			version: '2.0.0',
		},
	}, 'WordPress');
	assert.deepEqual(adapterless, { owner: 'ConfigOps Hostile Fixture', version: '2.0.0', basis: 'caller' });

	const registered = mutationSourceIdentity({
		source: {
			displayName: 'ConfigOps Hostile Fixture',
			version: '2.0.0',
			basis: 'registered-setting',
		},
	}, 'WordPress');
	assert.deepEqual(registered, { owner: 'ConfigOps Hostile Fixture', version: '2.0.0', basis: 'registered-setting' });

	const adapted = mutationSourceIdentity({
		adapter: { name: 'WooCommerce', componentVersion: '11.0.1' },
		source: { displayName: 'Another Plugin', version: '1.0.0' },
	}, 'WordPress');
	assert.deepEqual(adapted, { owner: 'WooCommerce', version: '11.0.1', basis: 'caller' });
});
