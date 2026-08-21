const apiFetch = (options) => window.wp.apiFetch(options);
const API_ROOT = '/configops/v1';
const scopeRoot = (scope) => scope?.type === 'network' ? `${API_ROOT}/network` : API_ROOT;

export const fetchState = (sessionId, scope) => apiFetch({
	path: `${scopeRoot(scope)}/state${sessionId ? `?session=${sessionId}` : ''}`,
});

export const fetchMutationPage = (sessionId, after, scope) => apiFetch({
	path: `${scopeRoot(scope)}/captures/${sessionId}/mutations?after=${after}`,
});

export const createCapture = (name, scope) => apiFetch({
	path: `${scopeRoot(scope)}/captures`,
	method: 'POST',
	data: { name },
});

export const stopActiveCapture = (scope) => apiFetch({
	path: `${scopeRoot(scope)}/captures/active/stop`,
	method: 'POST',
});

export const restoreMutationRequest = (mutationId, scope) => apiFetch({
	path: `${scopeRoot(scope)}/mutations/${mutationId}/restore`,
	method: 'POST',
});

export const restoreSessionRequest = (sessionId) => apiFetch({
	path: `${API_ROOT}/captures/${sessionId}/restore`,
	method: 'POST',
});

export const setGenericArrayUndoRequest = (enabled) => apiFetch({
	path: `${API_ROOT}/experiments/generic-array-undo`,
	method: 'POST',
	data: { enabled: Boolean(enabled) },
});
