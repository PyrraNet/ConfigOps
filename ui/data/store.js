import {
	createCapture,
	fetchMutationPage,
	fetchState,
	restoreMutationRequest,
	restoreSessionRequest,
	setGenericArrayUndoRequest,
	stopActiveCapture,
} from './api.js';

let snapshot = null;
const listeners = new Set();

const publish = (next) => {
	snapshot = next;
	for (const listener of listeners) {
		listener();
	}
};

const withPending = (pending) => {
	publish({
		...snapshot,
		ui: { ...snapshot.ui, pending },
	});
};

const withoutPending = (next) => ({
	...next,
	ui: { ...next.ui, pending: null },
});

const errorMessage = (error) => {
	if (error && typeof error.message === 'string' && error.message.length > 0) {
		return error.message;
	}

	return window.wp.i18n.__('ConfigOps did not receive a usable response. Reload the page and try again.', 'configops');
};

const publishError = (error, overrides = {}) => {
	publish({
		...snapshot,
		...overrides,
		notice: { code: 'error', kind: 'error', text: errorMessage(error) },
		ui: { ...snapshot.ui, pending: null },
	});
};

const runPending = async (pending, operation, createNext = (result) => result, errorOverrides = {}) => {
	if (snapshot.ui.pending) {
		return false;
	}

	withPending(pending);
	try {
		const result = await operation();
		publish(withoutPending(createNext(result)));
		return true;
	} catch (error) {
		publishError(error, errorOverrides);
		return false;
	}
};

const reindexGroups = (groups) => groups.map((group, index) => ({
	...group,
	index: String(index + 1).padStart(2, '0'),
}));

const mergeGroups = (current, incoming) => {
	if (current.length === 0 || incoming.length === 0) {
		return reindexGroups([...current, ...incoming]);
	}

	const merged = [...current];
	const firstIncoming = incoming[0];
	const lastCurrent = merged[merged.length - 1];
	if (lastCurrent.requestId === firstIncoming.requestId) {
		merged[merged.length - 1] = {
			...lastCurrent,
			mutations: [...lastCurrent.mutations, ...firstIncoming.mutations],
			writeSignals: [...(lastCurrent.writeSignals || []), ...(firstIncoming.writeSignals || [])],
		};
		merged.push(...incoming.slice(1));
	} else {
		merged.push(...incoming);
	}

	return reindexGroups(merged);
};

export const configureStore = (initialState) => {
	snapshot = { ...initialState, ui: { pending: null } };
};

export const useConfigOpsState = () => window.wp.element.useSyncExternalStore(
	(listener) => {
		listeners.add(listener);

		return () => listeners.delete(listener);
	},
	() => snapshot,
	() => snapshot,
);

export const dismissNotice = () => {
	publish({ ...snapshot, notice: { code: '', kind: 'success', text: '' } });
};

export const startCapture = (name) => runPending('start-capture', () => createCapture(name, snapshot.scope));
export const stopCapture = () => runPending('stop-capture', () => stopActiveCapture(snapshot.scope));
export const restoreMutation = (id) => runPending(
	`restore-mutation-${id}`,
	() => restoreMutationRequest(id, snapshot.scope),
);
export const restoreSession = (id) => runPending(`restore-session-${id}`, () => restoreSessionRequest(id));
export const setGenericArrayUndo = (enabled) => runPending(
	'generic-array-undo',
	() => setGenericArrayUndoRequest(enabled),
);

export const selectSession = async (id) => {
	if (snapshot.ui.pending || snapshot.selected?.id === id) {
		return;
	}

	const selected = await runPending(`select-session-${id}`, () => fetchState(id, snapshot.scope));
	if (selected) {
		const url = new URL(window.location.href);
		url.searchParams.set('page', 'configops');
		url.searchParams.set('session', String(id));
		window.history.replaceState({}, '', url);
	}
};

export const loadMoreMutations = async () => {
	const selectedId = snapshot.selected?.id;
	const cursor = snapshot.review.pageInfo.nextCursor;
	if (!selectedId || !cursor || snapshot.ui.pending) {
		return;
	}

	await runPending(
		'load-more',
		() => fetchMutationPage(selectedId, cursor, snapshot.scope),
		(page) => ({
			...snapshot,
			review: {
				...snapshot.review,
				groups: mergeGroups(snapshot.review.groups, page.groups),
				pageInfo: page.pageInfo,
			},
		}),
	);
};

export const hydrateReview = async () => {
	const selectedId = snapshot.selected?.id;
	if (!selectedId || !snapshot.review.deferred || snapshot.ui.pending) {
		return;
	}

	await runPending(
		'hydrate-review',
		() => fetchMutationPage(selectedId, 0, snapshot.scope),
		(review) => ({ ...snapshot, review }),
		{ review: { ...snapshot.review, deferred: false } },
	);
};
