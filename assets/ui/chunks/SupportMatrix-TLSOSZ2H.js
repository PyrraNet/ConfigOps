import {
  Hint
} from "./chunk-JEVHJMKH.js";
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
      return __("Not available yet", "configops");
  }
}, "levelLabel");
var PluginState = /* @__PURE__ */ __name(({ adapter }) => {
  const { __ } = window.wp.i18n;
  if (!adapter.installed) {
    return /* @__PURE__ */ wp.element.createElement("span", { className: "configops-plugin-state is-absent" }, __("Not i\
nstalled here", "configops"));
  }
  if (!adapter.compatible) {
    return /* @__PURE__ */ wp.element.createElement("span", { className: "configops-plugin-state is-warning" }, __("Vers\
ion not tested", "configops"));
  }
  if (!adapter.active) {
    return /* @__PURE__ */ wp.element.createElement("span", { className: "configops-plugin-state is-inactive" }, __("Ins\
talled, inactive", "configops"));
  }
  return /* @__PURE__ */ wp.element.createElement("span", { className: "configops-plugin-state is-ready" }, __("Ready on\
 this website", "configops"));
}, "PluginState");
var Capability = /* @__PURE__ */ __name(({ capability, versionUntested }) => {
  const { __ } = window.wp.i18n;
  const level = versionUntested ? "partial" : capability.level;
  const label = versionUntested ? __("Not tested on this version", "configops") : levelLabel(level, __);
  return /* @__PURE__ */ wp.element.createElement("div", { className: `configops-support-capability is-${level}` }, /* @__PURE__ */ wp.
  element.createElement("span", null, capability.label), /* @__PURE__ */ wp.element.createElement(Hint, { label: `${capability.
  label}: ${label}`, trigger: label }, versionUntested ? __("The installed version is outside this adapter\u2019s tested rang\
e. ConfigOps records evidence but disables automatic undo.", "configops") : capability.note));
}, "Capability");
var AdapterRow = /* @__PURE__ */ __name(({ adapter }) => {
  const { __ } = window.wp.i18n;
  const versionUntested = adapter.installed && !adapter.compatible;
  return /* @__PURE__ */ wp.element.createElement("details", { className: "configops-support-row" }, /* @__PURE__ */ wp.
  element.createElement("summary", null, /* @__PURE__ */ wp.element.createElement("div", { className: "configops-support\
-plugin" }, /* @__PURE__ */ wp.element.createElement("span", { className: "configops-support-mark", "aria-hidden": "true" },
  adapter.name.slice(0, 1)), /* @__PURE__ */ wp.element.createElement("div", null, /* @__PURE__ */ wp.element.createElement(
  "h3", null, adapter.name), /* @__PURE__ */ wp.element.createElement("p", null, adapter.version ? `v${adapter.version}` :
  __("Adapter available", "configops"), " ", /* @__PURE__ */ wp.element.createElement("span", { "aria-hidden": "true" },
  "\xB7"), " ", __("tested", "configops"), " ", adapter.testedVersion))), /* @__PURE__ */ wp.element.createElement(PluginState,
  { adapter }), /* @__PURE__ */ wp.element.createElement("div", { className: "configops-support-quick", "aria-label": __(
  "Support summary", "configops") }, adapter.capabilities.slice(0, 4).map((capability) => /* @__PURE__ */ wp.element.createElement(
  "span", { key: capability.id, className: `is-${versionUntested ? "partial" : capability.level}`, title: `${capability.
  label}: ${versionUntested ? __("Not tested on this version", "configops") : levelLabel(capability.level, __)}` }, /* @__PURE__ */ wp.
  element.createElement("span", { className: "screen-reader-text" }, capability.label, ": "), versionUntested || capability.
  level === "partial" ? "\u25D0" : capability.level === "full" ? "\u25CF" : "\u25CB"))), /* @__PURE__ */ wp.element.createElement(
  "span", { className: "configops-chevron", "aria-hidden": "true" })), /* @__PURE__ */ wp.element.createElement("div", {
  className: "configops-support-detail" }, /* @__PURE__ */ wp.element.createElement("section", null, /* @__PURE__ */ wp.
  element.createElement("h4", null, __("What works today", "configops")), /* @__PURE__ */ wp.element.createElement("div",
  { className: "configops-support-capabilities" }, adapter.capabilities.map((capability) => /* @__PURE__ */ wp.element.createElement(
  Capability, { capability, versionUntested, key: capability.id })))), /* @__PURE__ */ wp.element.createElement("div", {
  className: "configops-support-notes" }, /* @__PURE__ */ wp.element.createElement("section", null, /* @__PURE__ */ wp.element.
  createElement("h4", null, __("Understood", "configops")), /* @__PURE__ */ wp.element.createElement("ul", null, adapter.
  coverage.map((item) => /* @__PURE__ */ wp.element.createElement("li", { key: item }, item)))), /* @__PURE__ */ wp.element.
  createElement("section", null, /* @__PURE__ */ wp.element.createElement("h4", null, __("Known limits", "configops")), /* @__PURE__ */ wp.
  element.createElement("ul", null, adapter.limitations.map((item) => /* @__PURE__ */ wp.element.createElement("li", { key: item },
  item))))), /* @__PURE__ */ wp.element.createElement("footer", null, /* @__PURE__ */ wp.element.createElement("span", null,
  __("Adapter schema", "configops"), " ", /* @__PURE__ */ wp.element.createElement("code", null, "v", adapter.schemaVersion)),
  adapter.sourceUrl && /* @__PURE__ */ wp.element.createElement("a", { href: adapter.sourceUrl, target: "_blank", rel: "\
noreferrer", "aria-label": __("Review source contract (opens in a new tab)", "configops") }, __("Review source contract",
  "configops"), " ", /* @__PURE__ */ wp.element.createElement("span", { "aria-hidden": "true" }, "\u2197")))));
}, "AdapterRow");
function SupportMatrix() {
  const { __ } = window.wp.i18n;
  const state = useConfigOpsState();
  const adapters = state.adapters || [];
  return /* @__PURE__ */ wp.element.createElement(wp.element.Fragment, null, /* @__PURE__ */ wp.element.createElement("h\
eader", { className: "configops-support-intro" }, /* @__PURE__ */ wp.element.createElement("span", { className: "configo\
ps-eyebrow" }, __("Compatibility contract", "configops")), /* @__PURE__ */ wp.element.createElement("h2", null, __("Know\
 what ConfigOps understands.", "configops")), /* @__PURE__ */ wp.element.createElement("p", null, __("Each adapter state\
s what it can explain, hide, and undo. Open a plugin for the exact boundaries\u2014no blanket \u201Ccompatible\u201D badge.",
  "configops"))), /* @__PURE__ */ wp.element.createElement("section", { className: "configops-support-legend", "aria-lab\
el": __("Support level legend", "configops") }, /* @__PURE__ */ wp.element.createElement("span", null, /* @__PURE__ */ wp.
  element.createElement("i", { className: "is-full", "aria-hidden": "true" }, "\u25CF"), " ", __("Supported", "configops")),
  /* @__PURE__ */ wp.element.createElement("span", null, /* @__PURE__ */ wp.element.createElement("i", { className: "is-\
partial", "aria-hidden": "true" }, "\u25D0"), " ", __("With limits", "configops")), /* @__PURE__ */ wp.element.createElement(
  "span", null, /* @__PURE__ */ wp.element.createElement("i", { className: "is-planned", "aria-hidden": "true" }, "\u25CB"),
  " ", __("Not available yet", "configops")), /* @__PURE__ */ wp.element.createElement(Hint, { label: __("How should I r\
ead this?", "configops"), align: "end" }, __("Support is pinned to tested plugin versions. ConfigOps still records unfam\
iliar values, but labels them for review instead of guessing.", "configops"))), /* @__PURE__ */ wp.element.createElement(
  "div", { className: "configops-support-ledger" }, /* @__PURE__ */ wp.element.createElement("div", { className: "config\
ops-support-head", "aria-hidden": "true" }, /* @__PURE__ */ wp.element.createElement("span", null, __("Plugin", "configo\
ps")), /* @__PURE__ */ wp.element.createElement("span", null, __("This website", "configops")), /* @__PURE__ */ wp.element.
  createElement("span", null, __("Coverage", "configops")), /* @__PURE__ */ wp.element.createElement("span", null)), adapters.
  map((adapter) => /* @__PURE__ */ wp.element.createElement(AdapterRow, { adapter, key: adapter.id }))));
}
__name(SupportMatrix, "SupportMatrix");
export {
  SupportMatrix as default
};
