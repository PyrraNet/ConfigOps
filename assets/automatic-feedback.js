(() => {
	'use strict';

	const settings = window.configOpsFeedback;
	if (!settings || typeof settings.endpoint !== 'string' || typeof settings.nonce !== 'string') {
		return;
	}

	const { __, _n, sprintf } = window.wp.i18n;
	let request = null;
	let timers = [];

	const container = () => {
		let node = document.getElementById('configops-evidence-stack');
		if (node) {
			return node;
		}

		node = document.createElement('div');
		node.id = 'configops-evidence-stack';
		node.className = 'configops-evidence-stack';
		node.setAttribute('aria-live', 'polite');
		document.body.append(node);

		return node;
	};

	const metric = (count, singular, plural) => sprintf(_n(singular, plural, count, 'configops'), count);

	const hidden = (name, value) => {
		const input = document.createElement('input');
		input.type = 'hidden';
		input.name = name;
		input.value = String(value);

		return input;
	};

	const render = (item) => {
		if (!item || !Number.isInteger(item.id) || document.querySelector(`[data-configops-evidence="${item.id}"]`)) {
			return;
		}

		const card = document.createElement('section');
		card.className = 'configops-evidence-card';
		card.dataset.configopsEvidence = String(item.id);

		const close = document.createElement('button');
		close.type = 'button';
		close.className = 'configops-evidence-close';
		close.setAttribute('aria-label', __('Dismiss ConfigOps evidence', 'configops'));
		close.textContent = '×';
		close.addEventListener('click', () => card.remove());

		const eyebrow = document.createElement('span');
		eyebrow.className = 'configops-evidence-eyebrow';
		eyebrow.textContent = __('CONFIGOPS · CHANGE RECORDED', 'configops');

		const title = document.createElement('strong');
		title.textContent = item.incomplete
			? __('ConfigOps could not record this save completely', 'configops')
			: sprintf(
				_n('ConfigOps observed %d write', 'ConfigOps observed %d writes', item.writeCount, 'configops'),
				item.writeCount,
			);

		const metrics = document.createElement('p');
		const parts = [
			metric(item.decisionCount, '%d likely decision', '%d likely decisions'),
			metric(item.technicalCount, '%d housekeeping write', '%d housekeeping writes'),
		];
		if (item.secretCount > 0) {
			parts.push(metric(item.secretCount, '%d secret protected', '%d secrets protected'));
		}
		if (item.incomplete) {
			parts.push(__('undo disabled', 'configops'));
		}
		metrics.textContent = parts.join(' · ');

		const actions = document.createElement('div');
		actions.className = 'configops-evidence-actions';
		const review = document.createElement('a');
		review.className = 'button button-primary';
		review.href = item.reviewUrl;
		review.textContent = __('Review', 'configops');
		actions.append(review);

		if (item.undo) {
			const form = document.createElement('form');
			form.method = 'post';
			form.action = item.undo.actionUrl;
			form.append(
				hidden('action', item.undo.action),
				hidden('session_id', item.undo.sessionId),
				hidden('_wpnonce', item.undo.nonce),
			);
			const undo = document.createElement('button');
			undo.type = 'submit';
			undo.className = 'button';
			undo.textContent = __('Undo', 'configops');
			undo.addEventListener('click', (event) => {
				if (!window.confirm(__('Undo this settings save? ConfigOps will stop if a value changed again.', 'configops'))) {
					event.preventDefault();
				}
			});
			form.append(undo);
			actions.append(form);
		}

		card.append(close, eyebrow, title, metrics, actions);
		container().append(card);
	};

	const poll = async () => {
		if (request || document.visibilityState === 'hidden') {
			return;
		}

		request = fetch(settings.endpoint, {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': settings.nonce },
		});
		try {
			const response = await request;
			if (!response.ok) {
				return;
			}
			const payload = await response.json();
			for (const item of Array.isArray(payload.items) ? payload.items : []) {
				render(item);
			}
		} catch (error) {
			// Feedback is progressive enhancement; settings saves must stay untouched.
		} finally {
			request = null;
		}
	};

	const schedule = () => {
		for (const timer of timers) {
			window.clearTimeout(timer);
		}
		timers = [1200, 3000, 7000, 15000].map((delay) => window.setTimeout(poll, delay));
	};

	document.addEventListener('input', schedule, true);
	document.addEventListener('change', schedule, true);
	document.addEventListener('submit', schedule, true);
	document.addEventListener('click', (event) => {
		if (event.target instanceof Element && event.target.closest('button, input[type="button"], input[type="submit"]')) {
			schedule();
		}
	}, true);
	document.addEventListener('visibilitychange', () => {
		if (document.visibilityState === 'visible') {
			poll();
		}
	});
	poll();
})();
