const apiFetch = (options) => window.wp.apiFetch(options);
const API_ROOT = '/configops/v1';
const scopeRoot = (scope) => scope?.type === 'network' ? `${API_ROOT}/network` : API_ROOT;

export const fetchState = (sessionId, scope) => apiFetch({
	path: `${scopeRoot(scope)}/state${sessionId ? `?session=${sessionId}` : ''}`,
});

export const fetchMutationPage = (sessionId, after, scope) => apiFetch({
	path: `${scopeRoot(scope)}/captures/${sessionId}/mutations?after=${after}`,
});

export const createCapture = (name) => apiFetch({
	path: `${API_ROOT}/captures`,
	method: 'POST',
	data: { name },
});

export const stopActiveCapture = () => apiFetch({
	path: `${API_ROOT}/captures/active/stop`,
	method: 'POST',
});

export const restoreMutationRequest = (mutationId) => apiFetch({
	path: `${API_ROOT}/mutations/${mutationId}/restore`,
	method: 'POST',
});

export const restoreSessionRequest = (sessionId) => apiFetch({
	path: `${API_ROOT}/captures/${sessionId}/restore`,
	method: 'POST',
});
