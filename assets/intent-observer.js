(() => {
	'use strict';

	const settings = window.configOpsIntent;
	if (!settings || !Number.isInteger(settings.sessionId) || settings.sessionId < 0) {
		return;
	}

	const COOKIE_NAME = typeof settings.cookieName === 'string' ? settings.cookieName : 'configops_intent';
	const MAX_FIELDS = 20;
	const MAX_TEXT = 120;
	const states = new WeakMap();
	const pageStateKey = document.documentElement;
	const cookieSecurity = window.location.protocol === 'https:' ? '; Secure' : '';

	// A completed navigation consumes the previous form observation. Clearing it
	// here prevents a later save with no new field interaction from reusing stale
	// intent evidence.
	document.cookie = `${COOKIE_NAME}=; Max-Age=0; Path=/; SameSite=Strict${cookieSecurity}`;

	const cleanText = (value, limit = MAX_TEXT) => String(value || '')
		.replace(/\s+/g, ' ')
		.trim()
		.slice(0, limit);

	const screenTitle = () => cleanText(
		document.querySelector('.wrap > h1, .wrap > h2, main h1, h1')?.textContent,
	);

	const stateFor = (form) => {
		const key = form || pageStateKey;
		if (!states.has(key)) {
			states.set(key, { action: '', fields: new Map() });
		}

		return states.get(key);
	};

	const fieldLabel = (field) => {
		const rowHeading = field.closest('tr')?.querySelector('th label, th');
		if (rowHeading) {
			return cleanText(rowHeading.textContent);
		}

		const labelledBy = cleanText(field.getAttribute('aria-labelledby'), 191);
		if (labelledBy) {
			const label = labelledBy
				.split(/\s+/)
				.map((id) => document.getElementById(id)?.textContent || '')
				.join(' ');
			if (cleanText(label)) {
				return cleanText(label);
			}
		}

		const explicitLabel = field.labels?.[0];
		const wrappingLabel = field.closest('label');

		return cleanText(
			explicitLabel?.textContent
			|| wrappingLabel?.textContent
			|| field.getAttribute('aria-label')
			|| field.getAttribute('placeholder'),
		);
	};

	const fieldGroup = (field) => {
		const legend = field.closest('fieldset')?.querySelector(':scope > legend');
		if (legend) {
			return cleanText(legend.textContent);
		}

		const panel = field.closest('.postbox, .card, section');
		const panelHeading = panel?.querySelector('h2, h3');
		if (panelHeading) {
			return cleanText(panelHeading.textContent);
		}

		const table = field.closest('table');
		let sibling = table?.previousElementSibling;
		for (let offset = 0; sibling && offset < 3; offset += 1) {
			if (/^H[2-4]$/.test(sibling.tagName)) {
				return cleanText(sibling.textContent);
			}
			sibling = sibling.previousElementSibling;
		}

		return screenTitle();
	};

	const isObservableField = (field) => {
		if (!field.matches('input[name], select[name], textarea[name]') || field.disabled) {
			return false;
		}

		const type = cleanText(field.getAttribute('type')).toLowerCase();

		return !['button', 'file', 'hidden', 'image', 'reset', 'submit'].includes(type);
	};

	const encode = (payload) => {
		const bytes = new TextEncoder().encode(JSON.stringify(payload));
		let binary = '';
		for (const byte of bytes) {
			binary += String.fromCharCode(byte);
		}

		return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/g, '');
	};

	const writeCookie = (state) => {
		const fields = Array.from(state.fields.values()).slice(-MAX_FIELDS);
		if (fields.length === 0) {
			return;
		}

		const payload = {
			v: 1,
			session: settings.sessionId,
			capturedAt: Math.floor(Date.now() / 1000),
			screen: screenTitle(),
			action: cleanText(state.action),
			fields,
		};
		let encoded = encode(payload);
		while (encoded.length > 3700 && payload.fields.length > 1) {
			payload.fields.shift();
			encoded = encode(payload);
		}
		if (encoded.length > 3700) {
			return;
		}

		document.cookie = `${COOKIE_NAME}=${encoded}; Max-Age=180; Path=/; SameSite=Strict${cookieSecurity}`;
	};

	const observeField = (field) => {
		if (!(field instanceof HTMLElement) || !isObservableField(field)) {
			return;
		}

		const name = cleanText(field.getAttribute('name'), 191);
		if (!name) {
			return;
		}

		const state = stateFor(field.closest('form'));
		state.fields.set(name, {
			name,
			label: fieldLabel(field),
			group: fieldGroup(field),
		});
		writeCookie(state);
	};

	const actionLabel = (control) => cleanText(
		control?.textContent
		|| control?.getAttribute('aria-label')
		|| control?.getAttribute('value'),
	);

	document.addEventListener('input', (event) => observeField(event.target), true);
	document.addEventListener('change', (event) => observeField(event.target), true);
	document.addEventListener('click', (event) => {
		const control = event.target instanceof Element
			? event.target.closest('button, input[type="submit"], input[type="button"]')
			: null;
		if (!control) {
			return;
		}

		const state = stateFor(control.closest('form'));
		state.action = actionLabel(control);
		writeCookie(state);
	}, true);
	document.addEventListener('submit', (event) => {
		const state = stateFor(event.target);
		state.action = actionLabel(event.submitter) || state.action;
		writeCookie(state);
	}, true);
})();
