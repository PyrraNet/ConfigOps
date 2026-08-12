import {
  Hint
} from "./chunk-JEVHJMKH.js";
import {
  __name,
  selectSession,
  useConfigOpsState
} from "./chunk-TVQASTIY.js";

// ui/islands/Sessions.jsx
function Sessions() {
  const { __, sprintf } = window.wp.i18n;
  const state = useConfigOpsState();
  return /* @__PURE__ */ wp.element.createElement(wp.element.Fragment, null, /* @__PURE__ */ wp.element.createElement("d\
iv", { className: "configops-section-heading" }, /* @__PURE__ */ wp.element.createElement("div", null, /* @__PURE__ */ wp.
  element.createElement("h2", null, __("Captures", "configops")), /* @__PURE__ */ wp.element.createElement(Hint, { label: __(
  "What is a capture?", "configops") }, sprintf(__("Everything ConfigOps observes between Record and Stop, kept together\
 as one review for %d days.", "configops"), state.retentionDays))), /* @__PURE__ */ wp.element.createElement("span", { "\
aria-label": sprintf(__("%d captures", "configops"), state.sessions.length) }, state.sessions.length)), state.sessions.length ===
  0 ? /* @__PURE__ */ wp.element.createElement("p", { className: "configops-empty-copy" }, __("Your captures will appear\
 here.", "configops")) : /* @__PURE__ */ wp.element.createElement("ol", { className: "configops-session-list" }, state.sessions.
  map((session) => {
    const selected = state.selected?.id === session.id;
    return /* @__PURE__ */ wp.element.createElement("li", { key: session.id }, /* @__PURE__ */ wp.element.createElement(
      "a",
      {
        className: selected ? "is-selected" : "",
        href: `?page=configops&session=${session.id}`,
        "aria-current": selected ? "page" : void 0,
        onClick: (event) => {
          event.preventDefault();
          selectSession(session.id);
        }
      },
      /* @__PURE__ */ wp.element.createElement("span", { className: "configops-session-head" }, /* @__PURE__ */ wp.element.
      createElement("span", { className: "configops-session-name" }, session.name), /* @__PURE__ */ wp.element.createElement(
      "code", null, "#", session.id)),
      /* @__PURE__ */ wp.element.createElement("span", { className: "configops-session-meta" }, /* @__PURE__ */ wp.element.
      createElement("span", null, session.reviewChangeCount === 1 ? __("1 setting", "configops") : sprintf(__("%d settin\
gs", "configops"), session.reviewChangeCount), session.technicalChangeCount > 0 && /* @__PURE__ */ wp.element.createElement(
      "span", null, sprintf(__(" \xB7 %d technical", "configops"), session.technicalChangeCount)), session.writeSignalCount >
      0 && /* @__PURE__ */ wp.element.createElement("em", null, sprintf(__(" \xB7 %d outside API", "configops"), session.
      writeSignalCount)), session.captureErrorCount > 0 && /* @__PURE__ */ wp.element.createElement("em", null, sprintf(
      __(" \xB7 %d missed", "configops"), session.captureErrorCount))), /* @__PURE__ */ wp.element.createElement("time",
      { dateTime: session.startedAt }, sprintf(__("%s ago", "configops"), session.startedAtLabel)))
    ));
  })));
}
__name(Sessions, "Sessions");
export {
  Sessions as default
};
