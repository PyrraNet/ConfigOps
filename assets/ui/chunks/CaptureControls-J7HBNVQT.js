import {
  Hint
} from "./chunk-JEVHJMKH.js";
import {
  __name,
  dismissNotice,
  startCapture,
  stopCapture,
  useConfigOpsState
} from "./chunk-TVQASTIY.js";

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
  createElement("p", { className: "configops-state-label" }, __("Recording", "configops")), /* @__PURE__ */ wp.element.createElement(
  "h2", { id: "configops-recording-title" }, state.active.name))), /* @__PURE__ */ wp.element.createElement("div", { className: "\
configops-recording-tally" }, /* @__PURE__ */ wp.element.createElement("strong", null, state.active.reviewChangeCount), /* @__PURE__ */ wp.
  element.createElement("span", null, state.active.reviewChangeCount === 1 ? __("setting found", "configops") : __("sett\
ings found", "configops")), state.active.technicalChangeCount > 0 && /* @__PURE__ */ wp.element.createElement("span", { className: "\
configops-recording-writes" }, `+ ${state.active.technicalChangeCount} ${__("technical", "configops")}`), state.active.writeSignalCount >
  0 && /* @__PURE__ */ wp.element.createElement("span", { className: "configops-recording-writes" }, `+ ${state.active.writeSignalCount}\
 ${__("outside the settings API", "configops")}`), /* @__PURE__ */ wp.element.createElement(Hint, { label: __("What coun\
ts as a change?", "configops"), align: "end" }, __("The main number counts individual settings worth reviewing. Plugin d\
efaults, caches, and maintenance values stay available under Technical.", "configops"))), /* @__PURE__ */ wp.element.createElement(
  "button", { className: "button button-primary button-large", type: "button", disabled: busy, onClick: stopCapture }, state.
  ui.pending === "stop-capture" ? __("Stopping\u2026", "configops") : __("Stop & review", "configops"))) : /* @__PURE__ */ wp.
  element.createElement("section", { className: "configops-capture-command", "aria-labelledby": "configops-start-title" },
  /* @__PURE__ */ wp.element.createElement("h2", { id: "configops-start-title", className: "screen-reader-text" }, __("S\
tart a capture", "configops")), /* @__PURE__ */ wp.element.createElement("form", { className: "configops-capture-form", onSubmit: submit },
  /* @__PURE__ */ wp.element.createElement("div", { className: "configops-capture-field" }, /* @__PURE__ */ wp.element.createElement(
  "div", { className: "configops-field-label" }, /* @__PURE__ */ wp.element.createElement("label", { htmlFor: "configops\
-capture-name" }, __("Capture name", "configops")), /* @__PURE__ */ wp.element.createElement(Hint, { label: __("Why name\
 a capture?", "configops") }, __("Name the task you are about to do. ConfigOps will keep everything until Stop together \
in one review.", "configops"))), /* @__PURE__ */ wp.element.createElement(
    "input",
    {
      id: "configops-capture-name",
      name: "capture_name",
      type: "text",
      maxLength: "191",
      placeholder: __("SMTP production baseline", "configops"),
      value: name,
      onChange: (event) => setName(event.target.value)
    }
  )), /* @__PURE__ */ wp.element.createElement("button", { className: "button button-primary button-large", type: "submi\
t", disabled: busy }, state.ui.pending === "start-capture" ? __("Starting\u2026", "configops") : __("Record changes", "c\
onfigops")))));
}
__name(CaptureControls, "CaptureControls");
export {
  CaptureControls as default
};
