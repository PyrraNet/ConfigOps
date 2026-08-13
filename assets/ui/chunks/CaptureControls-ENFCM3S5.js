import {
  __name,
  dismissNotice,
  startCapture,
  stopCapture,
  useConfigOpsState
} from "./chunk-QIY7J6RF.js";

// ui/components/Notice.jsx
function Notice({ notice }) {
  const { __ } = window.wp.i18n;
  if (!notice?.text) {
    return null;
  }
  return /* @__PURE__ */ wp.element.createElement("div", { className: `notice notice-${notice.kind === "error" ? "error" :
  "success"} configops-notice`, role: notice.kind === "error" ? "alert" : "status" }, /* @__PURE__ */ wp.element.createElement(
  "p", null, notice.text), /* @__PURE__ */ wp.element.createElement("button", { type: "button", className: "notice-dismi\
ss", onClick: dismissNotice }, /* @__PURE__ */ wp.element.createElement("span", { className: "screen-reader-text" }, __(
  "Dismiss this notice.", "configops"))));
}
__name(Notice, "Notice");

// ui/islands/CaptureControls.jsx
function CaptureControls() {
  const { __ } = window.wp.i18n;
  const state = useConfigOpsState();
  const [name, setName] = window.wp.element.useState("");
  const [composerOpen, setComposerOpen] = window.wp.element.useState(state.sessions.length === 0);
  const busy = Boolean(state.ui.pending);
  window.wp.element.useEffect(() => {
    const id = "wp-admin-bar-configops-recording";
    let node = document.getElementById(id);
    if (!state.active) {
      node?.remove();
      return;
    }
    if (!node) {
      const root = document.getElementById("wp-admin-bar-root-default");
      if (!root) return;
      node = document.createElement("li");
      node.id = id;
      node.className = "configops-toolbar-recording";
      const link = document.createElement("a");
      link.className = "ab-item";
      link.href = window.location.href;
      const dot = document.createElement("span");
      dot.className = "configops-recording-dot";
      dot.setAttribute("aria-hidden", "true");
      const label = document.createElement("span");
      label.className = "configops-recording-label";
      label.textContent = __("CONFIGOPS RECORDING", "configops");
      const count2 = document.createElement("span");
      count2.className = "configops-recording-count";
      link.append(dot, label, count2);
      node.append(link);
      root.append(node);
    }
    const count = node.querySelector(".configops-recording-count");
    if (count) count.textContent = String(state.active.reviewChangeCount);
  }, [state.active, __]);
  const submit = /* @__PURE__ */ __name((event) => {
    event.preventDefault();
    startCapture(name.trim());
  }, "submit");
  return /* @__PURE__ */ wp.element.createElement(wp.element.Fragment, null, /* @__PURE__ */ wp.element.createElement(Notice,
  { notice: state.notice }), state.active ? /* @__PURE__ */ wp.element.createElement("section", { className: "configops-\
capture-command is-recording", "aria-labelledby": "configops-recording-title" }, /* @__PURE__ */ wp.element.createElement(
  "div", { className: "configops-recording-state" }, /* @__PURE__ */ wp.element.createElement("span", { className: "conf\
igops-pulse", "aria-hidden": "true" }), /* @__PURE__ */ wp.element.createElement("div", null, /* @__PURE__ */ wp.element.
  createElement("p", { className: "configops-state-label" }, __("Recording now", "configops")), /* @__PURE__ */ wp.element.
  createElement("h2", { id: "configops-recording-title" }, state.active.name))), /* @__PURE__ */ wp.element.createElement(
  "div", { className: "configops-recording-tally" }, /* @__PURE__ */ wp.element.createElement("span", { className: "conf\
igops-recording-primary-count" }, /* @__PURE__ */ wp.element.createElement("strong", null, state.active.reviewChangeCount),
  /* @__PURE__ */ wp.element.createElement("span", null, state.active.reviewChangeCount === 1 ? __("setting", "configops") :
  __("settings", "configops"))), (state.active.technicalChangeCount > 0 || state.active.writeSignalCount > 0) && /* @__PURE__ */ wp.
  element.createElement("span", { className: "configops-recording-secondary-count" }, state.active.technicalChangeCount >
  0 && `${state.active.technicalChangeCount} ${__("technical", "configops")}`, state.active.technicalChangeCount > 0 && state.
  active.writeSignalCount > 0 && " \xB7 ", state.active.writeSignalCount > 0 && `${state.active.writeSignalCount} ${__("\
outside API", "configops")}`)), /* @__PURE__ */ wp.element.createElement("button", { className: "button button-primary b\
utton-large", type: "button", disabled: busy, onClick: stopCapture }, state.ui.pending === "stop-capture" ? __("Stopping\
\u2026", "configops") : __("Stop & review", "configops"))) : !composerOpen && state.sessions.length > 0 ? /* @__PURE__ */ wp.
  element.createElement("section", { className: "configops-capture-command is-compact", "aria-labelledby": "configops-ne\
w-capture-title" }, /* @__PURE__ */ wp.element.createElement("div", null, /* @__PURE__ */ wp.element.createElement("p", {
  className: "configops-state-label" }, __("Capture", "configops")), /* @__PURE__ */ wp.element.createElement("h2", { id: "\
configops-new-capture-title" }, __("Record another settings task", "configops"))), /* @__PURE__ */ wp.element.createElement(
  "button", { className: "button", type: "button", onClick: () => setComposerOpen(true) }, __("New capture", "configops"))) :
  /* @__PURE__ */ wp.element.createElement("section", { className: "configops-capture-command", "aria-labelledby": "conf\
igops-start-title" }, /* @__PURE__ */ wp.element.createElement("form", { className: "configops-capture-form", onSubmit: submit },
  /* @__PURE__ */ wp.element.createElement("div", { className: "configops-capture-intro" }, /* @__PURE__ */ wp.element.createElement(
  "p", { className: "configops-state-label" }, __("New capture", "configops")), /* @__PURE__ */ wp.element.createElement(
  "h2", { id: "configops-start-title" }, __("Record a settings task", "configops")), /* @__PURE__ */ wp.element.createElement(
  "p", null, __("Name it, start recording, then make the change in WordPress.", "configops"))), /* @__PURE__ */ wp.element.
  createElement("div", { className: "configops-capture-field" }, /* @__PURE__ */ wp.element.createElement("label", { className: "\
screen-reader-text", htmlFor: "configops-capture-name" }, __("Capture name", "configops")), /* @__PURE__ */ wp.element.createElement(
    "input",
    {
      id: "configops-capture-name",
      name: "capture_name",
      type: "text",
      maxLength: "191",
      placeholder: __("What are you changing?", "configops"),
      value: name,
      onChange: (event) => setName(event.target.value)
    }
  )), /* @__PURE__ */ wp.element.createElement("div", { className: "configops-capture-compose-actions" }, state.sessions.
  length > 0 && /* @__PURE__ */ wp.element.createElement("button", { className: "button", type: "button", disabled: busy,
  onClick: () => setComposerOpen(false) }, __("Cancel", "configops")), /* @__PURE__ */ wp.element.createElement("button",
  { className: "button button-primary button-large", type: "submit", disabled: busy }, state.ui.pending === "start-captu\
re" ? __("Starting\u2026", "configops") : __("Start recording", "configops"))))));
}
__name(CaptureControls, "CaptureControls");
export {
  CaptureControls as default
};
