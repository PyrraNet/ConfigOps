var __defProp = Object.defineProperty;
var __name = (target, value) => __defProp(target, "name", { value, configurable: true });

// ui/data/api.js
var apiFetch = /* @__PURE__ */ __name((options) => window.wp.apiFetch(options), "apiFetch");
var fetchState = /* @__PURE__ */ __name((sessionId) => apiFetch({
  path: `/configops/v1/state${sessionId ? `?session=${sessionId}` : ""}`
}), "fetchState");
var fetchMutationPage = /* @__PURE__ */ __name((sessionId, after) => apiFetch({
  path: `/configops/v1/captures/${sessionId}/mutations?after=${after}&limit=25`
}), "fetchMutationPage");
var createCapture = /* @__PURE__ */ __name((name) => apiFetch({
  path: "/configops/v1/captures",
  method: "POST",
  data: { name }
}), "createCapture");
var stopActiveCapture = /* @__PURE__ */ __name(() => apiFetch({
  path: "/configops/v1/captures/active/stop",
  method: "POST"
}), "stopActiveCapture");
var restoreMutationRequest = /* @__PURE__ */ __name((mutationId) => apiFetch({
  path: `/configops/v1/mutations/${mutationId}/restore`,
  method: "POST"
}), "restoreMutationRequest");
var restoreSessionRequest = /* @__PURE__ */ __name((sessionId) => apiFetch({
  path: `/configops/v1/captures/${sessionId}/restore`,
  method: "POST"
}), "restoreSessionRequest");

// ui/data/store.js
var snapshot = null;
var listeners = /* @__PURE__ */ new Set();
var publish = /* @__PURE__ */ __name((next) => {
  snapshot = next;
  for (const listener of listeners) {
    listener();
  }
}, "publish");
var withPending = /* @__PURE__ */ __name((pending) => {
  publish({
    ...snapshot,
    ui: { ...snapshot.ui, pending }
  });
}, "withPending");
var errorMessage = /* @__PURE__ */ __name((error) => {
  if (error && typeof error.message === "string" && error.message.length > 0) {
    return error.message;
  }
  return window.wp.i18n.__("ConfigOps could not complete that operation.", "configops");
}, "errorMessage");
var publishError = /* @__PURE__ */ __name((error, overrides = {}) => {
  publish({
    ...snapshot,
    ...overrides,
    notice: { code: "error", kind: "error", text: errorMessage(error) },
    ui: { pending: null }
  });
}, "publishError");
var command = /* @__PURE__ */ __name(async (pending, operation) => {
  if (snapshot.ui.pending) {
    return;
  }
  withPending(pending);
  try {
    const next = await operation();
    publish({ ...next, ui: { pending: null } });
  } catch (error) {
    publishError(error);
  }
}, "command");
var reindexGroups = /* @__PURE__ */ __name((groups) => groups.map((group, index) => ({
  ...group,
  index: String(index + 1).padStart(2, "0")
})), "reindexGroups");
var mergeGroups = /* @__PURE__ */ __name((current, incoming) => {
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
      writeSignals: [...lastCurrent.writeSignals || [], ...firstIncoming.writeSignals || []]
    };
    merged.push(...incoming.slice(1));
  } else {
    merged.push(...incoming);
  }
  return reindexGroups(merged);
}, "mergeGroups");
var configureStore = /* @__PURE__ */ __name((initialState) => {
  snapshot = { ...initialState, ui: { pending: null } };
}, "configureStore");
var useConfigOpsState = /* @__PURE__ */ __name(() => window.wp.element.useSyncExternalStore(
  (listener) => {
    listeners.add(listener);
    return () => listeners.delete(listener);
  },
  () => snapshot,
  () => snapshot
), "useConfigOpsState");
var dismissNotice = /* @__PURE__ */ __name(() => {
  publish({ ...snapshot, notice: { code: "", kind: "success", text: "" } });
}, "dismissNotice");
var startCapture = /* @__PURE__ */ __name((name) => command("start-capture", () => createCapture(name)), "startCapture");
var stopCapture = /* @__PURE__ */ __name(() => command("stop-capture", stopActiveCapture), "stopCapture");
var restoreMutation = /* @__PURE__ */ __name((id) => command(`restore-mutation-${id}`, () => restoreMutationRequest(id)),
"restoreMutation");
var restoreSession = /* @__PURE__ */ __name((id) => command(`restore-session-${id}`, () => restoreSessionRequest(id)), "\
restoreSession");
var selectSession = /* @__PURE__ */ __name(async (id) => {
  if (snapshot.ui.pending || snapshot.selected?.id === id) {
    return;
  }
  withPending(`select-session-${id}`);
  try {
    const next = await fetchState(id);
    publish({ ...next, ui: { pending: null } });
    const url = new URL(window.location.href);
    url.searchParams.set("page", "configops");
    url.searchParams.set("session", String(id));
    window.history.replaceState({}, "", url);
  } catch (error) {
    publishError(error);
  }
}, "selectSession");
var loadMoreMutations = /* @__PURE__ */ __name(async () => {
  const selectedId = snapshot.selected?.id;
  const cursor = snapshot.review.pageInfo.nextCursor;
  if (!selectedId || !cursor || snapshot.ui.pending) {
    return;
  }
  withPending("load-more");
  try {
    const page = await fetchMutationPage(selectedId, cursor);
    publish({
      ...snapshot,
      review: {
        ...snapshot.review,
        groups: mergeGroups(snapshot.review.groups, page.groups),
        pageInfo: page.pageInfo
      },
      ui: { pending: null }
    });
  } catch (error) {
    publishError(error);
  }
}, "loadMoreMutations");
var hydrateReview = /* @__PURE__ */ __name(async () => {
  const selectedId = snapshot.selected?.id;
  if (!selectedId || !snapshot.review.deferred || snapshot.ui.pending) {
    return;
  }
  withPending("hydrate-review");
  try {
    const review = await fetchMutationPage(selectedId, 0);
    publish({ ...snapshot, review, ui: { pending: null } });
  } catch (error) {
    publishError(error, { review: { ...snapshot.review, deferred: false } });
  }
}, "hydrateReview");

export {
  __name,
  configureStore,
  useConfigOpsState,
  dismissNotice,
  startCapture,
  stopCapture,
  restoreMutation,
  restoreSession,
  selectSession,
  loadMoreMutations,
  hydrateReview
};
