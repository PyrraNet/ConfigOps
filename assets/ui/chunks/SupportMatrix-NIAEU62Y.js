import {
  __name,
  useConfigOpsState
} from "./chunk-TVQASTIY.js";

// ui/islands/SupportMatrix.jsx
var levelLabel = /* @__PURE__ */ __name((level, __) => {
  switch (level) {
    case "full":
      return __("Supported", "configops");
    case "partial":
      return __("With limits", "configops");
    default:
      return __("Unavailable", "configops");
  }
}, "levelLabel");
var PluginState = /* @__PURE__ */ __name(({ adapter }) => {
  const { __ } = window.wp.i18n;
  if (!adapter.installed) {
    return /* @__PURE__ */ wp.element.createElement("span", { className: "configops-plugin-state is-absent" }, __("Not i\
nstalled", "configops"));
  }
  if (!adapter.compatible) {
    return /* @__PURE__ */ wp.element.createElement("span", { className: "configops-plugin-state is-warning" }, __("Unte\
sted version", "configops"));
  }
  if (!adapter.active) {
    return /* @__PURE__ */ wp.element.createElement("span", { className: "configops-plugin-state is-inactive" }, __("Ina\
ctive", "configops"));
  }
  return /* @__PURE__ */ wp.element.createElement("span", { className: "configops-plugin-state is-ready" }, __("Active",
  "configops"));
}, "PluginState");
var Capability = /* @__PURE__ */ __name(({ capability, versionUntested }) => {
  const { __ } = window.wp.i18n;
  const level = versionUntested ? "partial" : capability.level;
  const label = versionUntested ? __("Not tested on this version", "configops") : levelLabel(level, __);
  return /* @__PURE__ */ wp.element.createElement("div", { className: `configops-support-capability is-${level}` }, /* @__PURE__ */ wp.
  element.createElement("span", null, capability.label), /* @__PURE__ */ wp.element.createElement("strong", null, label));
}, "Capability");
var SupportSummary = /* @__PURE__ */ __name(({ capabilities, versionUntested }) => {
  const { __ } = window.wp.i18n;
  if (versionUntested) {
    return /* @__PURE__ */ wp.element.createElement("span", { className: "configops-support-summary is-warning" }, __("A\
utomatic undo off", "configops"));
  }
  const supported = capabilities.filter((capability) => capability.level === "full").length;
  const limited = capabilities.filter((capability) => capability.level === "partial").length;
  return /* @__PURE__ */ wp.element.createElement("div", { className: "configops-support-summary", "aria-label": __("Sup\
port summary", "configops") }, supported > 0 && /* @__PURE__ */ wp.element.createElement("span", { className: "is-full" },
  /* @__PURE__ */ wp.element.createElement("strong", null, supported), " ", __("supported", "configops")), limited > 0 &&
  /* @__PURE__ */ wp.element.createElement("span", { className: "is-partial" }, /* @__PURE__ */ wp.element.createElement(
  "strong", null, limited), " ", __("limited", "configops")));
}, "SupportSummary");
var AdapterRow = /* @__PURE__ */ __name(({ adapter }) => {
  const { __, sprintf } = window.wp.i18n;
  const versionUntested = adapter.installed && !adapter.compatible;
  const capabilities = adapter.capabilities.filter((capability) => capability.level !== "planned");
  const versionLabel = adapter.version ? sprintf(__("Installed %1$s \xB7 Tested %2$s", "configops"), adapter.version, adapter.
  testedVersion) : sprintf(__("Tested %s", "configops"), adapter.testedVersion);
  return /* @__PURE__ */ wp.element.createElement("details", { className: "configops-support-row" }, /* @__PURE__ */ wp.
  element.createElement("summary", null, /* @__PURE__ */ wp.element.createElement("div", { className: "configops-support\
-plugin" }, /* @__PURE__ */ wp.element.createElement("span", { className: "configops-support-mark", "aria-hidden": "true" },
  adapter.name.slice(0, 1)), /* @__PURE__ */ wp.element.createElement("div", null, /* @__PURE__ */ wp.element.createElement(
  "h3", null, adapter.name), /* @__PURE__ */ wp.element.createElement("p", null, versionLabel))), /* @__PURE__ */ wp.element.
  createElement(PluginState, { adapter }), /* @__PURE__ */ wp.element.createElement(SupportSummary, { capabilities, versionUntested }),
  /* @__PURE__ */ wp.element.createElement("span", { className: "configops-chevron", "aria-hidden": "true" })), /* @__PURE__ */ wp.
  element.createElement("div", { className: "configops-support-detail" }, /* @__PURE__ */ wp.element.createElement("sect\
ion", null, /* @__PURE__ */ wp.element.createElement("h4", null, __("Features", "configops")), /* @__PURE__ */ wp.element.
  createElement("div", { className: "configops-support-capabilities" }, capabilities.map((capability) => /* @__PURE__ */ wp.
  element.createElement(Capability, { capability, versionUntested, key: capability.id })))), /* @__PURE__ */ wp.element.
  createElement("div", { className: "configops-support-notes" }, /* @__PURE__ */ wp.element.createElement("section", null,
  /* @__PURE__ */ wp.element.createElement("h4", null, __("Covered", "configops")), /* @__PURE__ */ wp.element.createElement(
  "ul", null, adapter.coverage.map((item) => /* @__PURE__ */ wp.element.createElement("li", { key: item }, item)))), /* @__PURE__ */ wp.
  element.createElement("section", null, /* @__PURE__ */ wp.element.createElement("h4", null, __("Limits", "configops")),
  /* @__PURE__ */ wp.element.createElement("ul", null, adapter.limitations.map((item) => /* @__PURE__ */ wp.element.createElement(
  "li", { key: item }, item)))))));
}, "AdapterRow");
function SupportMatrix() {
  const { __ } = window.wp.i18n;
  const state = useConfigOpsState();
  const adapters = state.adapters || [];
  return /* @__PURE__ */ wp.element.createElement("div", { className: "configops-support-ledger" }, /* @__PURE__ */ wp.element.
  createElement("div", { className: "configops-support-head", "aria-hidden": "true" }, /* @__PURE__ */ wp.element.createElement(
  "span", null, __("Plugin", "configops")), /* @__PURE__ */ wp.element.createElement("span", null, __("Status", "configo\
ps")), /* @__PURE__ */ wp.element.createElement("span", null, __("Support", "configops")), /* @__PURE__ */ wp.element.createElement(
  "span", null)), adapters.map((adapter) => /* @__PURE__ */ wp.element.createElement(AdapterRow, { adapter, key: adapter.
  id })));
}
__name(SupportMatrix, "SupportMatrix");
export {
  SupportMatrix as default
};
