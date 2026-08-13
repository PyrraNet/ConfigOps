import {
  __name,
  selectSession,
  useConfigOpsState
} from "./chunk-QIY7J6RF.js";

// ui/islands/Sessions.jsx
function Sessions() {
  const { __, sprintf } = window.wp.i18n;
  const state = useConfigOpsState();
  const [query, setQuery] = window.wp.element.useState("");
  const normalizedQuery = query.trim().toLocaleLowerCase();
  const visibleSessions = normalizedQuery ? state.sessions.filter((session) => session.name.toLocaleLowerCase().includes(
  normalizedQuery) || String(session.id).includes(normalizedQuery)) : state.sessions;
  const selected = state.selected;
  return /* @__PURE__ */ wp.element.createElement(wp.element.Fragment, null, state.sessions.length > 0 && /* @__PURE__ */ wp.
  element.createElement("div", { className: "configops-session-picker" }, /* @__PURE__ */ wp.element.createElement("labe\
l", { htmlFor: "configops-session-select" }, __("Selected capture", "configops")), /* @__PURE__ */ wp.element.createElement(
    "select",
    {
      id: "configops-session-select",
      value: selected?.id || "",
      disabled: Boolean(state.ui.pending),
      onChange: (event) => selectSession(Number(event.target.value))
    },
    state.sessions.map((session) => /* @__PURE__ */ wp.element.createElement("option", { value: session.id, key: session.
    id }, `#${session.id} \xB7 ${session.name}`))
  ), selected && /* @__PURE__ */ wp.element.createElement("p", null, selected.reviewChangeCount === 1 ? __("1 setting", "\
configops") : sprintf(__("%d settings", "configops"), selected.reviewChangeCount), selected.technicalChangeCount > 0 && `\
 \xB7 ${sprintf(__("%d technical", "configops"), selected.technicalChangeCount)}`, ` \xB7 ${sprintf(__("%s ago", "config\
ops"), selected.startedAtLabel)}`)), /* @__PURE__ */ wp.element.createElement("div", { className: "configops-section-hea\
ding" }, /* @__PURE__ */ wp.element.createElement("h2", null, __("Capture history", "configops")), /* @__PURE__ */ wp.element.
  createElement("span", { "aria-label": sprintf(__("%d captures", "configops"), state.sessions.length) }, state.sessions.
  length)), state.sessions.length > 5 && /* @__PURE__ */ wp.element.createElement("div", { className: "configops-session\
-search" }, /* @__PURE__ */ wp.element.createElement("label", { className: "screen-reader-text", htmlFor: "configops-ses\
sion-search" }, __("Find a capture", "configops")), /* @__PURE__ */ wp.element.createElement(
    "input",
    {
      id: "configops-session-search",
      type: "search",
      placeholder: __("Find a capture", "configops"),
      value: query,
      onChange: (event) => setQuery(event.target.value)
    }
  )), state.sessions.length === 0 ? /* @__PURE__ */ wp.element.createElement("p", { className: "configops-empty-copy" },
  __("Your captures will appear here.", "configops")) : visibleSessions.length === 0 ? /* @__PURE__ */ wp.element.createElement(
  "p", { className: "configops-empty-copy" }, __("No captures match this search.", "configops")) : /* @__PURE__ */ wp.element.
  createElement("ol", { className: "configops-session-list" }, visibleSessions.map((session) => {
    const isSelected = selected?.id === session.id;
    return /* @__PURE__ */ wp.element.createElement("li", { key: session.id }, /* @__PURE__ */ wp.element.createElement(
      "a",
      {
        className: isSelected ? "is-selected" : "",
        href: `?page=configops&session=${session.id}`,
        "aria-current": isSelected ? "page" : void 0,
        onClick: (event) => {
          event.preventDefault();
          selectSession(session.id);
        }
      },
      /* @__PURE__ */ wp.element.createElement("span", { className: "configops-session-head" }, /* @__PURE__ */ wp.element.
      createElement("span", { className: "configops-session-name" }, session.name), /* @__PURE__ */ wp.element.createElement(
      "time", { dateTime: session.startedAt }, sprintf(__("%s ago", "configops"), session.startedAtLabel))),
      /* @__PURE__ */ wp.element.createElement("span", { className: "configops-session-meta" }, /* @__PURE__ */ wp.element.
      createElement("span", null, session.reviewChangeCount === 1 ? __("1 setting", "configops") : sprintf(__("%d settin\
gs", "configops"), session.reviewChangeCount), session.technicalChangeCount > 0 && /* @__PURE__ */ wp.element.createElement(
      "span", null, sprintf(__(" \xB7 %d technical", "configops"), session.technicalChangeCount)), session.writeSignalCount >
      0 && /* @__PURE__ */ wp.element.createElement("em", null, sprintf(__(" \xB7 %d outside API", "configops"), session.
      writeSignalCount)), session.captureErrorCount > 0 && /* @__PURE__ */ wp.element.createElement("em", null, sprintf(
      __(" \xB7 %d missed", "configops"), session.captureErrorCount))), /* @__PURE__ */ wp.element.createElement("code",
      null, "#", session.id))
    ));
  })));
}
__name(Sessions, "Sessions");
export {
  Sessions as default
};
