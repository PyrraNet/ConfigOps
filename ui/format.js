export const formatValue = (value, emptyLabel = 'Empty') => {
	if (typeof value === 'boolean') {
		return value ? 'On (true)' : 'Off (false)';
	}
	if (value === null || value === '') {
		return emptyLabel;
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
