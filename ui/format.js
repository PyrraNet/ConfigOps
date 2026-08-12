export const formatValue = (value) => {
	if (typeof value === 'boolean') {
		return value ? 'true' : 'false';
	}
	if (value === null) {
		return 'null';
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
