export const formatValue = (value, labels) => {
	if (typeof value === 'boolean') {
		return value ? labels.booleanTrue : labels.booleanFalse;
	}
	if (value === null || value === '') {
		return labels.empty;
	}
	if (typeof value === 'string') {
		if (value === '[not set]' || value === '••••••••' || value.startsWith('[unsupported')) {
			return value;
		}

		return JSON.stringify(value);
	}
	if (typeof value === 'object') {
		return JSON.stringify(value, null, 2);
	}

	return String(value);
};

export const fileSizeParts = (bytes) => {
	if (!Number.isFinite(bytes) || bytes < 0) return null;
	if (bytes < 1024) return { value: bytes, unit: 'bytes' };
	if (bytes < 1024 * 1024) return { value: Math.round(bytes / 102.4) / 10, unit: 'kilobytes' };

	return { value: Math.round(bytes / 1024 / 102.4) / 10, unit: 'megabytes' };
};

export const mutationSourceIdentity = (mutation, fallbackOwner) => ({
	owner: mutation?.adapter?.name
		|| mutation?.source?.displayName
		|| mutation?.source?.component
		|| fallbackOwner,
	version: mutation?.adapter?.componentVersion || mutation?.source?.version || '',
	basis: mutation?.source?.basis === 'registered-setting' ? 'registered-setting' : 'caller',
});
