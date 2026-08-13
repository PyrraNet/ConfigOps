import { configureStore } from './data/store.js';

const bootstrapNode = document.getElementById('configops-bootstrap');
const roots = new Map();

const renderFailure = (element) => {
	element.removeAttribute('aria-busy');
	const message = document.createElement('p');
	message.className = 'configops-island-error';
	message.setAttribute('role', 'alert');
	message.textContent = window.wp.i18n.__('This ConfigOps instrument could not be loaded. Reload the page to try again.', 'configops');
	element.replaceChildren(message);
};

const mount = async (id, importer) => {
	const element = document.getElementById(id);
	if (!element || roots.has(id)) {
		return;
	}

	try {
		const { default: Component } = await importer();
		const root = window.wp.element.createRoot(element);
		roots.set(id, root);
		root.render(window.wp.element.createElement(Component));
		element.removeAttribute('aria-busy');
	} catch (error) {
		window.console.error('ConfigOps island failed to mount.', error);
		renderFailure(element);
	}
};

const onIdle = (callback) => {
	if ('requestIdleCallback' in window) {
		window.requestIdleCallback(callback, { timeout: 800 });
	} else {
		window.setTimeout(callback, 1);
	}
};

const whenVisible = (element, callback) => {
	if (!element || !('IntersectionObserver' in window)) {
		callback();
		return;
	}

	const observer = new IntersectionObserver((entries) => {
		if (entries.some((entry) => entry.isIntersecting)) {
			observer.disconnect();
			callback();
		}
	}, { rootMargin: '240px' });
	observer.observe(element);
};

try {
	configureStore(JSON.parse(bootstrapNode?.textContent || '{}'));
	mount('configops-support-island', () => import('./islands/SupportMatrix.jsx'));
	mount('configops-capture-island', () => import('./islands/CaptureControls.jsx'));
	onIdle(() => mount('configops-sessions-island', () => import('./islands/Sessions.jsx')));
	whenVisible(
		document.getElementById('configops-review-island'),
		() => mount('configops-review-island', () => import('./islands/ReviewLedger.jsx')),
	);
} catch (error) {
	window.console.error('ConfigOps bootstrap could not be parsed.', error);
	for (const id of ['configops-support-island', 'configops-capture-island', 'configops-sessions-island', 'configops-review-island']) {
		const element = document.getElementById(id);
		if (element) {
			renderFailure(element);
		}
	}
}
