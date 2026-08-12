import {
	createCapture,
	fetchMutationPage,
	fetchState,
	restoreMutationRequest,
	restoreSessionRequest,
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

const errorMessage = (error) => {
	if (error && typeof error.message === 'string' && error.message.length > 0) {
		return error.message;
	}

	return window.wp.i18n.__('ConfigOps could not complete that operation.', 'configops');
};

const command = async (pending, operation) => {
	if (snapshot.ui.pending) {
		return;
	}

	withPending(pending);
	try {
		const next = await operation();
		publish({ ...next, ui: { pending: null } });
	} catch (error) {
		publish({
			...snapshot,
			notice: { code: 'error', kind: 'error', text: errorMessage(error) },
			ui: { pending: null },
		});
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

export const startCapture = (name) => command('start-capture', () => createCapture(name));
export const stopCapture = () => command('stop-capture', stopActiveCapture);
export const restoreMutation = (id) => command(`restore-mutation-${id}`, () => restoreMutationRequest(id));
export const restoreSession = (id) => command(`restore-session-${id}`, () => restoreSessionRequest(id));

export const selectSession = async (id) => {
	if (snapshot.ui.pending || snapshot.selected?.id === id) {
		return;
	}

	withPending(`select-session-${id}`);
	try {
		const next = await fetchState(id);
		publish({ ...next, ui: { pending: null } });
		const url = new URL(window.location.href);
		url.searchParams.set('page', 'configops');
		url.searchParams.set('session', String(id));
		window.history.replaceState({}, '', url);
	} catch (error) {
		publish({
			...snapshot,
			notice: { code: 'error', kind: 'error', text: errorMessage(error) },
			ui: { pending: null },
		});
	}
};

export const loadMoreMutations = async () => {
	const selectedId = snapshot.selected?.id;
	const cursor = snapshot.review.pageInfo.nextCursor;
	if (!selectedId || !cursor || snapshot.ui.pending) {
		return;
	}

	withPending('load-more');
	try {
		const page = await fetchMutationPage(selectedId, cursor);
		publish({
			...snapshot,
			review: {
				...snapshot.review,
				groups: mergeGroups(snapshot.review.groups, page.groups),
				pageInfo: page.pageInfo,
			},
			ui: { pending: null },
		});
	} catch (error) {
		publish({
			...snapshot,
			notice: { code: 'error', kind: 'error', text: errorMessage(error) },
			ui: { pending: null },
		});
	}
};

export const hydrateReview = async () => {
	const selectedId = snapshot.selected?.id;
	if (!selectedId || !snapshot.review.deferred || snapshot.ui.pending) {
		return;
	}

	withPending('hydrate-review');
	try {
		const review = await fetchMutationPage(selectedId, 0);
		publish({ ...snapshot, review, ui: { pending: null } });
	} catch (error) {
		publish({
			...snapshot,
			notice: { code: 'error', kind: 'error', text: errorMessage(error) },
			review: { ...snapshot.review, deferred: false },
			ui: { pending: null },
		});
	}
};
