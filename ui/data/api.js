const apiFetch = (options) => window.wp.apiFetch(options);

export const fetchState = (sessionId) => apiFetch({
	path: `/configops/v1/state${sessionId ? `?session=${sessionId}` : ''}`,
});

export const fetchMutationPage = (sessionId, after) => apiFetch({
	path: `/configops/v1/captures/${sessionId}/mutations?after=${after}&limit=25`,
});

export const createCapture = (name) => apiFetch({
	path: '/configops/v1/captures',
	method: 'POST',
	data: { name },
});

export const stopActiveCapture = () => apiFetch({
	path: '/configops/v1/captures/active/stop',
	method: 'POST',
});

export const restoreMutationRequest = (mutationId) => apiFetch({
	path: `/configops/v1/mutations/${mutationId}/restore`,
	method: 'POST',
});

export const restoreSessionRequest = (sessionId) => apiFetch({
	path: `/configops/v1/captures/${sessionId}/restore`,
	method: 'POST',
});
