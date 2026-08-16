import {
  __name,
  hydrateReview,
  loadMoreMutations,
  restoreMutation,
  restoreSession,
  useConfigOpsState
} from "./chunk-QIY7J6RF.js";

// ui/components/Hint.jsx
function Hint({ label, children, align = "start", trigger = null }) {
  const id = window.wp.element.useId();
  const hasTextTrigger = typeof trigger === "string" && trigger.length > 0;
  return /* @__PURE__ */ wp.element.createElement("span", { className: `configops-hint configops-hint--${align}` }, /* @__PURE__ */ wp.
  element.createElement(
    "button",
    {
      className: `configops-hint-trigger ${hasTextTrigger ? "is-text" : "is-icon"}`,
      type: "button",
      "aria-label": label,
      "aria-describedby": id
    },
    hasTextTrigger ? trigger : /* @__PURE__ */ wp.element.createElement("span", { "aria-hidden": "true" }, "i")
  ), /* @__PURE__ */ wp.element.createElement("span", { id, className: "configops-tooltip", role: "tooltip" }, children));
}
__name(Hint, "Hint");

// ui/format.js
var formatValue = /* @__PURE__ */ __name((value, emptyLabel = "Empty") => {
  if (typeof value === "boolean") {
    return value ? "On (true)" : "Off (false)";
  }
  if (value === null || value === "") {
    return emptyLabel;
  }
  if (typeof value === "string") {
    if (value === "[not set]" || value === "\u2022\u2022\u2022\u2022\u2022\u2022\u2022\u2022" || value.startsWith("[unsu\
pported")) {
      return value;
    }
    return JSON.stringify(value);
  }
  if (typeof value === "object") {
    return JSON.stringify(value, null, 2);
  }
  return String(value);
}, "formatValue");

// ui/islands/ReviewLedger.jsx
var fieldKindLabel = /* @__PURE__ */ __name((kind, referenceType, __) => {
  switch (kind) {
    case "portable":
      return __("Reusable", "configops");
    case "environment":
      return __("Check per website", "configops");
    case "secret":
      return __("Secret", "configops");
    case "reference":
      return referenceType === "media" ? __("Media", "configops") : referenceType === "content" ? __("Content", "configo\
ps") : referenceType === "user" ? __("User", "configops") : __("Website link", "configops");
    case "runtime":
      return __("Technical", "configops");
    case "unsupported":
      return __("Outside scope", "configops");
    default:
      return __("Needs review", "configops");
  }
}, "fieldKindLabel");
var formatFileSize = /* @__PURE__ */ __name((bytes) => {
  if (!Number.isFinite(bytes) || bytes < 0) return "";
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 102.4) / 10} KB`;
  return `${Math.round(bytes / 1024 / 102.4) / 10} MB`;
}, "formatFileSize");
var MediaReferenceValue = /* @__PURE__ */ __name(({ dataLabel, snapshot }) => {
  const { __, sprintf } = window.wp.i18n;
  const id = Number(snapshot?.id || 0);
  const status = snapshot?.current_status || snapshot?.status || (id > 0 ? "missing" : "unset");
  if (id <= 0 || status === "unset") {
    return /* @__PURE__ */ wp.element.createElement("div", { className: "configops-reference-value is-unset", "data-labe\
l": dataLabel }, /* @__PURE__ */ wp.element.createElement("span", null, __("Not set", "configops")));
  }
  const missing = status === "missing";
  const attachmentLabel = sprintf(__("Attachment #%d", "configops"), id);
  const name = snapshot.title || snapshot.filename || attachmentLabel;
  const metadata = [
    snapshot.mime,
    Number.isFinite(snapshot.width) && Number.isFinite(snapshot.height) ? `${snapshot.width} \xD7 ${snapshot.height} px` :
    "",
    formatFileSize(snapshot.filesize)
  ].filter(Boolean);
  return /* @__PURE__ */ wp.element.createElement("div", { className: `configops-reference-value ${missing ? "is-missing" :
  ""}`, "data-label": dataLabel }, /* @__PURE__ */ wp.element.createElement("div", { className: "configops-reference-mar\
k", "aria-hidden": "true" }, snapshot.preview_url ? /* @__PURE__ */ wp.element.createElement("img", { src: snapshot.preview_url,
  alt: "", loading: "lazy", decoding: "async" }) : /* @__PURE__ */ wp.element.createElement("span", null, missing ? "\xD7" :
  __("File", "configops"))), /* @__PURE__ */ wp.element.createElement("div", { className: "configops-reference-identity" },
  /* @__PURE__ */ wp.element.createElement("strong", null, name), snapshot.title && snapshot.filename && /* @__PURE__ */ wp.
  element.createElement("span", null, snapshot.filename), metadata.length > 0 && /* @__PURE__ */ wp.element.createElement(
  "span", null, metadata.join(" \xB7 ")), /* @__PURE__ */ wp.element.createElement("span", { className: "configops-refer\
ence-id" }, attachmentLabel, missing && /* @__PURE__ */ wp.element.createElement("em", null, __("Missing", "configops")))));
}, "MediaReferenceValue");
var ContentReferenceValue = /* @__PURE__ */ __name(({ dataLabel, snapshot }) => {
  const { __, sprintf } = window.wp.i18n;
  const id = Number(snapshot?.id || 0);
  const status = snapshot?.current_status || snapshot?.status || (id > 0 ? "missing" : "unset");
  if (id <= 0 || status === "unset") {
    return /* @__PURE__ */ wp.element.createElement("div", { className: "configops-reference-value is-unset", "data-labe\
l": dataLabel }, /* @__PURE__ */ wp.element.createElement("span", null, __("Not set", "configops")));
  }
  const missing = status === "missing";
  const contentLabel = sprintf(__("Content #%d", "configops"), id);
  const name = snapshot.title || contentLabel;
  const typeLabel = snapshot.type_label || snapshot.post_type || __("Content", "configops");
  const metadata = [typeLabel, snapshot.post_status].filter(Boolean).join(" \xB7 ");
  return /* @__PURE__ */ wp.element.createElement("div", { className: `configops-reference-value ${missing ? "is-missing" :
  ""}`, "data-label": dataLabel }, /* @__PURE__ */ wp.element.createElement("div", { className: "configops-reference-mar\
k configops-content-mark", "aria-hidden": "true" }, /* @__PURE__ */ wp.element.createElement("span", null, missing ? "\xD7" :
  typeLabel)), /* @__PURE__ */ wp.element.createElement("div", { className: "configops-reference-identity" }, /* @__PURE__ */ wp.
  element.createElement("strong", null, name), metadata && /* @__PURE__ */ wp.element.createElement("span", null, metadata),
  /* @__PURE__ */ wp.element.createElement("span", { className: "configops-reference-id" }, contentLabel, missing && /* @__PURE__ */ wp.
  element.createElement("em", null, __("Missing", "configops")))));
}, "ContentReferenceValue");
var UserReferenceValue = /* @__PURE__ */ __name(({ dataLabel, snapshot }) => {
  const { __, sprintf } = window.wp.i18n;
  const id = Number(snapshot?.id || 0);
  const status = snapshot?.current_status || snapshot?.status || (id > 0 ? "missing" : "unset");
  if (id <= 0 || status === "unset") {
    return /* @__PURE__ */ wp.element.createElement("div", { className: "configops-reference-value is-unset", "data-labe\
l": dataLabel }, /* @__PURE__ */ wp.element.createElement("span", null, __("Not set", "configops")));
  }
  const missing = status === "missing";
  const userLabel = sprintf(__("User #%d", "configops"), id);
  return /* @__PURE__ */ wp.element.createElement("div", { className: `configops-reference-value ${missing ? "is-missing" :
  ""}`, "data-label": dataLabel }, /* @__PURE__ */ wp.element.createElement("div", { className: "configops-reference-mar\
k", "aria-hidden": "true" }, /* @__PURE__ */ wp.element.createElement("span", null, missing ? "\xD7" : __("User", "confi\
gops"))), /* @__PURE__ */ wp.element.createElement("div", { className: "configops-reference-identity" }, /* @__PURE__ */ wp.
  element.createElement("strong", null, snapshot.display_name || userLabel), /* @__PURE__ */ wp.element.createElement("s\
pan", { className: "configops-reference-id" }, userLabel, missing && /* @__PURE__ */ wp.element.createElement("em", null,
  __("Missing", "configops")))));
}, "UserReferenceValue");
var DiffValue = /* @__PURE__ */ __name(({ change, side, label }) => {
  const { __ } = window.wp.i18n;
  const reference = change[`${side}_reference`];
  if (change.reference_type === "media" && reference) {
    return /* @__PURE__ */ wp.element.createElement(MediaReferenceValue, { dataLabel: label, snapshot: reference });
  }
  if (change.reference_type === "content" && reference) {
    return /* @__PURE__ */ wp.element.createElement(ContentReferenceValue, { dataLabel: label, snapshot: reference });
  }
  if (change.reference_type === "user" && reference) {
    return /* @__PURE__ */ wp.element.createElement(UserReferenceValue, { dataLabel: label, snapshot: reference });
  }
  const hasValue = Object.hasOwn(change, side);
  const value = hasValue ? change[side] : void 0;
  const empty = hasValue && (value === null || value === "");
  return /* @__PURE__ */ wp.element.createElement("pre", { className: empty ? "is-empty" : "", "data-label": label }, hasValue ?
  formatValue(value, __("Empty", "configops")) : "\u2014");
}, "DiffValue");
var hasMissingRestoreReference = /* @__PURE__ */ __name((changes) => changes.some((change) => ["remove", "replace"].includes(
change.op) && Number(change.before_reference?.id || 0) > 0 && change.before_reference?.current_status === "missing"), "h\
asMissingRestoreReference");
var MutationRow = window.wp.element.memo(/* @__PURE__ */ __name(function MutationRow2({ mutation, canRestore, busy, filter }) {
  const { __ } = window.wp.i18n;
  const sourceLabel = mutation.source.file || mutation.source.type;
  const sourceOwner = mutation.adapter?.name || mutation.source.component || __("WordPress", "configops");
  const [open, setOpen] = window.wp.element.useState(filter !== "noise");
  const restoreDescriptionId = `configops-restore-${mutation.id}`;
  const operationLabels = {
    add: __("Added", "configops"),
    update: __("Updated", "configops"),
    delete: __("Deleted", "configops")
  };
  const operationLabel = operationLabels[mutation.type] || mutation.type;
  const visibleCount = mutation.diff.length;
  const visibleLabel = filter === "noise" ? visibleCount === 1 ? __("1 technical change", "configops") : `${visibleCount}\
 ${__("technical changes", "configops")}` : visibleCount === 1 ? __("1 setting", "configops") : `${visibleCount} ${__("s\
ettings", "configops")}`;
  const patchRestore = mutation.restoreMode === "patch";
  const observedFields = [...new Set(
    mutation.diff.map((change) => change.intent?.field_name).filter(Boolean)
  )];
  const undoSucceeded = mutation.lastRestore?.status === "succeeded";
  const undoUncertain = ["running", "compensation_failed"].includes(mutation.lastRestore?.status);
  const missingRestoreReference = hasMissingRestoreReference(mutation.diff);
  const showReviewActions = filter !== "noise";
  const undoUnavailableExplanation = !showReviewActions ? "" : missingRestoreReference ? __("The earlier referenced item\
 no longer exists on this website. ConfigOps will not restore a broken local reference.", "configops") : !mutation.restorable &&
  !mutation.redacted ? __("The adapter marks this as technical, unsupported, or outside its tested version range. Config\
Ops keeps the evidence but will not guess during rollback.", "configops") : "";
  const canUndo = canRestore && mutation.restorable && !missingRestoreReference && !undoSucceeded && !undoUncertain && showReviewActions;
  const undoLabel = patchRestore ? !mutation.redacted ? __("Undo this change", "configops") : mutation.changeCounts.safeUndo ===
  1 ? __("Undo 1 safe setting", "configops") : `${__("Undo", "configops")} ${mutation.changeCounts.safeUndo} ${__("safe \
settings", "configops")}` : __("Undo this setting", "configops");
  return /* @__PURE__ */ wp.element.createElement(
    "details",
    {
      className: `configops-mutation ${mutation.classification === "derived" ? "is-derived" : ""}`,
      open,
      onToggle: (event) => setOpen(event.currentTarget.open)
    },
    /* @__PURE__ */ wp.element.createElement("summary", null, /* @__PURE__ */ wp.element.createElement("span", { className: `\
configops-mutation-kind configops-mutation-kind--${mutation.type}` }, operationLabel), /* @__PURE__ */ wp.element.createElement(
    "span", { className: "configops-option" }, /* @__PURE__ */ wp.element.createElement("strong", null, mutation.adapter?.
    name || mutation.displayName || mutation.optionName), /* @__PURE__ */ wp.element.createElement("span", null, sourceOwner)),
    /* @__PURE__ */ wp.element.createElement("span", { className: `configops-badge configops-badge--${filter === "noise" ?
    "derived" : mutation.classification}` }, visibleLabel), /* @__PURE__ */ wp.element.createElement("span", { className: "\
configops-chevron", "aria-hidden": "true" })),
    /* @__PURE__ */ wp.element.createElement("div", { className: "configops-mutation-body" }, mutation.redacted && /* @__PURE__ */ wp.
    element.createElement("p", { className: "configops-secret-note" }, /* @__PURE__ */ wp.element.createElement("span", {
    "aria-hidden": "true" }, "\u25CF"), " ", patchRestore ? __("A secret changed and was removed before storage. ConfigO\
ps can undo the other supported settings without reading or replacing that secret.", "configops") : __("A secret changed\
 and was removed before storage. ConfigOps cannot reconstruct it for undo.", "configops")), /* @__PURE__ */ wp.element.createElement(
    "div", { className: "configops-diff-table", role: "table", "aria-label": __("Setting changes", "configops") }, mutation.
    diff.map((change, index) => /* @__PURE__ */ wp.element.createElement("div", { className: "configops-diff-row", role: "\
row", key: `${change.path || "/"}-${change.op || ""}-${index}` }, /* @__PURE__ */ wp.element.createElement("div", { className: "\
configops-diff-field", role: "rowheader" }, /* @__PURE__ */ wp.element.createElement("span", { className: "configops-fie\
ld-context" }, change.group || __("Setting", "configops")), /* @__PURE__ */ wp.element.createElement("div", null, /* @__PURE__ */ wp.
    element.createElement("strong", null, change.label || change.path || "/")), change.kind && /* @__PURE__ */ wp.element.
    createElement("span", { className: "configops-field-kind" }, fieldKindLabel(change.kind, change.reference_type, __)),
    change.intent && /* @__PURE__ */ wp.element.createElement("span", { className: "configops-field-intent" }, change.intent.
    confidence === "high" ? __("Observed field", "configops") : __("Likely observed field", "configops"))), /* @__PURE__ */ wp.
    element.createElement("div", { className: "configops-diff-value is-before", role: "cell" }, /* @__PURE__ */ wp.element.
    createElement("span", { className: "configops-value-label" }, __("Before", "configops")), /* @__PURE__ */ wp.element.
    createElement(DiffValue, { change, side: "before", label: __("Before", "configops") })), /* @__PURE__ */ wp.element.
    createElement("span", { className: "configops-diff-direction", "aria-hidden": "true" }, "\u2192"), /* @__PURE__ */ wp.
    element.createElement("div", { className: "configops-diff-value is-after", role: "cell" }, /* @__PURE__ */ wp.element.
    createElement("span", { className: "configops-value-label" }, __("Now", "configops")), /* @__PURE__ */ wp.element.createElement(
    DiffValue, { change, side: "after", label: __("Now", "configops") })), change.explanation && /* @__PURE__ */ wp.element.
    createElement("details", { className: "configops-field-evidence", role: "cell" }, /* @__PURE__ */ wp.element.createElement(
    "summary", null, __("About this field", "configops")), /* @__PURE__ */ wp.element.createElement("div", null, /* @__PURE__ */ wp.
    element.createElement("p", null, change.explanation)))))), /* @__PURE__ */ wp.element.createElement("footer", { className: "\
configops-mutation-footer" }, /* @__PURE__ */ wp.element.createElement("details", { className: "configops-technical-evid\
ence" }, /* @__PURE__ */ wp.element.createElement("summary", null, __("Technical evidence", "configops")), /* @__PURE__ */ wp.
    element.createElement("dl", null, /* @__PURE__ */ wp.element.createElement("div", null, /* @__PURE__ */ wp.element.createElement(
    "dt", null, __("Option", "configops")), /* @__PURE__ */ wp.element.createElement("dd", null, /* @__PURE__ */ wp.element.
    createElement("code", null, mutation.optionName))), /* @__PURE__ */ wp.element.createElement("div", null, /* @__PURE__ */ wp.
    element.createElement("dt", null, __("Changed through", "configops")), /* @__PURE__ */ wp.element.createElement("dd",
    null, sourceOwner)), /* @__PURE__ */ wp.element.createElement("div", null, /* @__PURE__ */ wp.element.createElement(
    "dt", null, __("Source", "configops")), /* @__PURE__ */ wp.element.createElement("dd", null, /* @__PURE__ */ wp.element.
    createElement("code", null, sourceLabel, mutation.source.line > 0 ? `:${mutation.source.line}` : ""))), mutation.adapter?.
    componentVersion && /* @__PURE__ */ wp.element.createElement("div", null, /* @__PURE__ */ wp.element.createElement("\
dt", null, __("Version", "configops")), /* @__PURE__ */ wp.element.createElement("dd", null, /* @__PURE__ */ wp.element.
    createElement("code", null, mutation.adapter.componentVersion))), observedFields.length > 0 && /* @__PURE__ */ wp.element.
    createElement("div", null, /* @__PURE__ */ wp.element.createElement("dt", null, __("Observed form fields", "configop\
s")), /* @__PURE__ */ wp.element.createElement("dd", { className: "configops-evidence-paths" }, observedFields.map((field) => /* @__PURE__ */ wp.
    element.createElement("code", { key: field }, field)))), /* @__PURE__ */ wp.element.createElement("div", null, /* @__PURE__ */ wp.
    element.createElement("dt", null, __("Fields", "configops")), /* @__PURE__ */ wp.element.createElement("dd", { className: "\
configops-evidence-paths" }, mutation.diff.map((change, index) => /* @__PURE__ */ wp.element.createElement("code", { key: `${change.
    path || "/"}-${index}` }, change.path || "/")))), /* @__PURE__ */ wp.element.createElement("div", null, /* @__PURE__ */ wp.
    element.createElement("dt", null, __("Why it is here", "configops")), /* @__PURE__ */ wp.element.createElement("dd",
    null, mutation.classificationReason)))), /* @__PURE__ */ wp.element.createElement("div", { className: "configops-mut\
ation-action" }, undoSucceeded && /* @__PURE__ */ wp.element.createElement("span", { className: "configops-restore-state\
 is-succeeded" }, /* @__PURE__ */ wp.element.createElement("strong", null, __("Undone", "configops")), /* @__PURE__ */ wp.
    element.createElement("span", null, mutation.lastRestore.actorName, " \xB7 ", mutation.lastRestore.finishedAtLabel)),
    undoUncertain && /* @__PURE__ */ wp.element.createElement("span", { className: "configops-undo-unavailable" }, /* @__PURE__ */ wp.
    element.createElement("strong", null, __("Undo needs inspection", "configops")), /* @__PURE__ */ wp.element.createElement(
    "span", null, __("Check the current plugin setting before continuing.", "configops"))), undoUnavailableExplanation &&
    /* @__PURE__ */ wp.element.createElement("span", { className: "configops-undo-unavailable" }, /* @__PURE__ */ wp.element.
    createElement("strong", null, __("Undo unavailable", "configops")), /* @__PURE__ */ wp.element.createElement("span",
    null, undoUnavailableExplanation)), canUndo && /* @__PURE__ */ wp.element.createElement("span", { className: "config\
ops-undo-ready" }, /* @__PURE__ */ wp.element.createElement("span", { id: restoreDescriptionId }, __("Current value is c\
hecked first.", "configops")), /* @__PURE__ */ wp.element.createElement(
      "button",
      {
        className: "button button-small configops-undo-button",
        type: "button",
        disabled: busy,
        "aria-describedby": restoreDescriptionId,
        onClick: () => {
          const question = patchRestore ? __("Undo only the supported, non-secret settings shown here? ConfigOps will pr\
eserve protected and technical values and stop if a visible setting changed again.", "configops") : __("Undo this settin\
g? ConfigOps will stop if it has changed again since the capture.", "configops");
          if (window.confirm(question)) {
            restoreMutation(mutation.id);
          }
        }
      },
      busy ? __("Undoing\u2026", "configops") : undoLabel
    )))))
  );
}, "MutationRow"));
var DatabaseWriteSignal = window.wp.element.memo(/* @__PURE__ */ __name(function DatabaseWriteSignal2({ signal }) {
  const { __, sprintf } = window.wp.i18n;
  const sourceLabel = signal.source.file || signal.source.type;
  const operationLabel = `${signal.operation} ${signal.table}`;
  return /* @__PURE__ */ wp.element.createElement("article", { className: "configops-write-signal" }, /* @__PURE__ */ wp.
  element.createElement("header", null, /* @__PURE__ */ wp.element.createElement("span", { className: "configops-sql-mar\
k", "aria-hidden": "true" }, "!"), /* @__PURE__ */ wp.element.createElement("div", { className: "configops-write-identit\
y" }, /* @__PURE__ */ wp.element.createElement("strong", null, __("Database change outside standard settings", "configop\
s")), /* @__PURE__ */ wp.element.createElement("code", null, operationLabel)), signal.occurrenceCount > 1 && /* @__PURE__ */ wp.
  element.createElement("strong", { "aria-label": sprintf(__("%d occurrences", "configops"), signal.occurrenceCount) }, "\
\xD7", signal.occurrenceCount)), /* @__PURE__ */ wp.element.createElement("p", null, __("No value was stored, so automat\
ic undo is unavailable.", "configops")), /* @__PURE__ */ wp.element.createElement("details", null, /* @__PURE__ */ wp.element.
  createElement("summary", null, __("Technical evidence", "configops")), /* @__PURE__ */ wp.element.createElement("div",
  null, /* @__PURE__ */ wp.element.createElement("span", null, signal.source.component || signal.source.type), /* @__PURE__ */ wp.
  element.createElement("code", null, sourceLabel, signal.source.line > 0 ? `:${signal.source.line}` : ""))));
}, "DatabaseWriteSignal"));
var RequestGroup = window.wp.element.memo(/* @__PURE__ */ __name(function RequestGroup2({ group, canRestore, pending, filter }) {
  const { __, sprintf } = window.wp.i18n;
  const screenLabels = {
    options: __("Saved WordPress settings", "configops"),
    "options-general": __("General settings", "configops")
  };
  const title = group.title || screenLabels[group.head.adminScreen] || group.head.adminScreen || group.head.requestUri ||
  __("Background request", "configops");
  const writeSignals = group.writeSignals || [];
  const unmanagedWriteCount = writeSignals.reduce((total, signal) => total + signal.occurrenceCount, 0);
  const visibleChangeCount = group.mutations.reduce((total, mutation) => total + mutation.diff.length, 0);
  const intent = group.intent;
  const intentLabels = Array.isArray(intent?.labels) ? intent.labels.filter(Boolean) : [];
  const intentStatement = intentLabels.length === 1 ? sprintf(__("Changed \u201C%s\u201D", "configops"), intentLabels[0]) :
  intentLabels.length > 1 ? sprintf(__("Changed fields: %s", "configops"), intentLabels.join(" \xB7 ")) : intent?.action ||
  __("Changed admin field", "configops");
  const intentEvidence = intent?.confidence === "high" ? sprintf(__("Matched %1$d of %2$d saved settings directly", "con\
figops"), intent.matchedFields, visibleChangeCount) : sprintf(__("Matched %1$d of %2$d saved settings by option scope", "\
configops"), intent?.matchedFields || 0, visibleChangeCount);
  return /* @__PURE__ */ wp.element.createElement("section", { className: "configops-request-group" }, /* @__PURE__ */ wp.
  element.createElement("header", { className: "configops-request-header" }, /* @__PURE__ */ wp.element.createElement("d\
iv", null, /* @__PURE__ */ wp.element.createElement("div", null, /* @__PURE__ */ wp.element.createElement("span", { className: "\
configops-request-index" }, sprintf(__("Save action %s", "configops"), group.index)), /* @__PURE__ */ wp.element.createElement(
  "h3", null, title), /* @__PURE__ */ wp.element.createElement("p", null, visibleChangeCount === 1 ? __("1 visible chang\
e", "configops") : sprintf(__("%d visible changes", "configops"), visibleChangeCount), unmanagedWriteCount > 0 && /* @__PURE__ */ wp.
  element.createElement(wp.element.Fragment, null, " ", /* @__PURE__ */ wp.element.createElement("span", { "aria-hidden": "\
true" }, "\xB7"), " ", sprintf(__("%d outside API", "configops"), unmanagedWriteCount)), " ", /* @__PURE__ */ wp.element.
  createElement("span", { "aria-hidden": "true" }, "\xB7"), " ", /* @__PURE__ */ wp.element.createElement("time", { dateTime: group.
  head.occurredAt }, group.head.timeLabel)), intent && /* @__PURE__ */ wp.element.createElement("div", { className: "con\
figops-intent-summary" }, /* @__PURE__ */ wp.element.createElement("span", { className: "configops-intent-mark", "aria-h\
idden": "true" }, "\u21B3"), /* @__PURE__ */ wp.element.createElement("div", null, /* @__PURE__ */ wp.element.createElement(
  "span", null, __("Observed intent", "configops")), /* @__PURE__ */ wp.element.createElement("strong", null, intentStatement),
  /* @__PURE__ */ wp.element.createElement("em", null, intentEvidence))))), /* @__PURE__ */ wp.element.createElement("de\
tails", { className: "configops-request-evidence" }, /* @__PURE__ */ wp.element.createElement("summary", null, __("Reque\
st details", "configops")), /* @__PURE__ */ wp.element.createElement("div", null, /* @__PURE__ */ wp.element.createElement(
  "code", null, group.head.method), /* @__PURE__ */ wp.element.createElement("code", null, group.head.requestUri)))), /* @__PURE__ */ wp.
  element.createElement("div", { className: "configops-mutation-list" }, writeSignals.map((signal) => /* @__PURE__ */ wp.
  element.createElement(DatabaseWriteSignal, { key: signal.id, signal })), group.mutations.map((mutation) => /* @__PURE__ */ wp.
  element.createElement(
    MutationRow,
    {
      key: mutation.id,
      mutation,
      canRestore,
      busy: pending === `restore-mutation-${mutation.id}`,
      filter
    }
  ))));
}, "RequestGroup"));
var ReviewFilter = window.wp.element.memo(/* @__PURE__ */ __name(function ReviewFilter2({ active, count, description, label,
onSelect }) {
  return /* @__PURE__ */ wp.element.createElement(
    "button",
    {
      className: active ? "is-active" : "",
      type: "button",
      "aria-pressed": active,
      "aria-controls": "configops-change-list",
      onClick: onSelect
    },
    /* @__PURE__ */ wp.element.createElement("span", null, label),
    /* @__PURE__ */ wp.element.createElement("strong", null, count),
    /* @__PURE__ */ wp.element.createElement("span", { className: "screen-reader-text" }, description)
  );
}, "ReviewFilter"));
function ReviewLedger() {
  const { __ } = window.wp.i18n;
  const state = useConfigOpsState();
  const selected = state.selected;
  const review = state.review;
  const selectedStatus = selected?.status === "active" ? { className: "is-live", label: __("Recording", "configops") } :
  ["interrupted", "stopping"].includes(selected?.status) ? { className: "is-incomplete", label: __("Interrupted", "confi\
gops") } : { className: "is-recorded", label: selected?.mode === "automatic" ? __("Observed", "configops") : __("Recorde\
d", "configops") };
  const sessionUndo = review.summary.lastSessionRestore;
  const sessionUndoSucceeded = sessionUndo?.status === "succeeded";
  const sessionUndoUncertain = ["running", "compensation_failed"].includes(sessionUndo?.status);
  const canRestore = !state.active && state.capabilities.rollback && !sessionUndoSucceeded && !sessionUndoUncertain;
  const visibleMissingRestoreReference = review.groups.some((group) => group.mutations.some((mutation) => hasMissingRestoreReference(
  mutation.diff)));
  const canRestoreSession = canRestore && review.summary.total > 0 && review.summary.allRestorable;
  const [filter, setFilter] = window.wp.element.useState("review");
  const filteredGroups = window.wp.element.useMemo(() => {
    const selectChanges = /* @__PURE__ */ __name((mutation) => mutation.diff.filter((change) => {
      const technical = mutation.classification === "derived" || change.kind === "runtime";
      return filter === "all" || filter === "review" && !technical || filter === "noise" && technical;
    }), "selectChanges");
    return review.groups.map((group) => {
      const mutations = group.mutations.map((mutation) => ({ ...mutation, diff: selectChanges(mutation) })).filter((mutation) => mutation.
      diff.length > 0);
      return {
        ...group,
        mutations,
        intent: filter === "noise" ? null : group.intent,
        writeSignals: filter === "noise" ? [] : group.writeSignals || []
      };
    }).filter((group) => group.mutations.length > 0 || group.writeSignals.length > 0).map((group, index) => ({ ...group,
    index: String(index + 1).padStart(2, "0") }));
  }, [filter, review.groups]);
  window.wp.element.useEffect(() => {
    if (review.deferred) {
      hydrateReview();
    }
  }, [selected?.id, review.deferred, state.ui.pending]);
  window.wp.element.useEffect(() => {
    setFilter("review");
  }, [selected?.id]);
  window.wp.element.useEffect(() => {
    const visibleMutations = filteredGroups.reduce((total, group) => total + group.mutations.length, 0);
    if (filter === "review" && !review.deferred && visibleMutations === 0 && review.pageInfo.hasNext && !state.ui.pending) {
      loadMoreMutations();
    }
  }, [filter, filteredGroups, review.deferred, review.pageInfo.hasNext, state.ui.pending]);
  if (review.deferred) {
    return /* @__PURE__ */ wp.element.createElement("div", { className: "configops-island-placeholder configops-island-p\
laceholder--review", "aria-label": __("Loading capture review", "configops") }, /* @__PURE__ */ wp.element.createElement(
    "span", null), /* @__PURE__ */ wp.element.createElement("span", null), /* @__PURE__ */ wp.element.createElement("spa\
n", null));
  }
  if (!selected) {
    return /* @__PURE__ */ wp.element.createElement("section", { className: "configops-empty-state" }, /* @__PURE__ */ wp.
    element.createElement("h2", null, __("No capture selected", "configops")), /* @__PURE__ */ wp.element.createElement(
    "p", null, __("Save a setting or choose an existing change.", "configops")));
  }
  return /* @__PURE__ */ wp.element.createElement(wp.element.Fragment, null, /* @__PURE__ */ wp.element.createElement("h\
eader", { className: "configops-review-header" }, /* @__PURE__ */ wp.element.createElement("div", { className: "configop\
s-review-heading" }, /* @__PURE__ */ wp.element.createElement("div", { className: "configops-capture-reference" }, /* @__PURE__ */ wp.
  element.createElement("span", { className: selectedStatus.className }, selectedStatus.label), /* @__PURE__ */ wp.element.
  createElement("span", null, selected.mode === "automatic" ? __("Automatic change", "configops") : __("Change session",
  "configops"), " ", /* @__PURE__ */ wp.element.createElement("code", null, "#", selected.id))), /* @__PURE__ */ wp.element.
  createElement("h2", null, selected.name), /* @__PURE__ */ wp.element.createElement("p", null, selected.actorName, /* @__PURE__ */ wp.
  element.createElement("span", { "aria-hidden": "true" }, " \xB7 "), /* @__PURE__ */ wp.element.createElement("time", {
  dateTime: selected.startedAt }, selected.startedDisplay))), /* @__PURE__ */ wp.element.createElement("div", { className: "\
configops-capture-action" }, sessionUndoSucceeded && /* @__PURE__ */ wp.element.createElement("span", { className: "conf\
igops-restore-state configops-restore-state--session is-succeeded" }, /* @__PURE__ */ wp.element.createElement("strong",
  null, __("Capture undone", "configops")), /* @__PURE__ */ wp.element.createElement("span", null, sessionUndo.actorName,
  " \xB7 ", sessionUndo.finishedAtLabel)), sessionUndoUncertain && /* @__PURE__ */ wp.element.createElement("span", { className: "\
configops-restore-state configops-restore-state--session is-uncertain" }, /* @__PURE__ */ wp.element.createElement("stro\
ng", null, __("Undo needs inspection", "configops")), /* @__PURE__ */ wp.element.createElement("span", null, __("Check t\
he current settings before continuing.", "configops"))), canRestoreSession && !visibleMissingRestoreReference && /* @__PURE__ */ wp.
  element.createElement(wp.element.Fragment, null, /* @__PURE__ */ wp.element.createElement("span", { className: "config\
ops-capture-undo-ready" }, /* @__PURE__ */ wp.element.createElement("strong", null, __("Capture undo ready", "configops")),
  /* @__PURE__ */ wp.element.createElement("span", null, __("Current values are checked first.", "configops"))), /* @__PURE__ */ wp.
  element.createElement(
    "button",
    {
      className: "button",
      type: "button",
      disabled: Boolean(state.ui.pending),
      onClick: () => {
        if (window.confirm(__("Undo every safe setting in this capture? ConfigOps will stop before making changes if any\
thing changed again.", "configops"))) {
          restoreSession(selected.id);
        }
      }
    },
    state.ui.pending === `restore-session-${selected.id}` ? __("Undoing\u2026", "configops") : __("Undo capture", "confi\
gops")
  )), canRestoreSession && visibleMissingRestoreReference && /* @__PURE__ */ wp.element.createElement("span", { className: "\
configops-undo-unavailable" }, /* @__PURE__ */ wp.element.createElement("strong", null, __("Capture undo unavailable", "\
configops")), /* @__PURE__ */ wp.element.createElement("span", null, __("A previous referenced item is missing. Review s\
ettings individually.", "configops"))))), review.summary.captureErrors > 0 && /* @__PURE__ */ wp.element.createElement("\
section", { className: "configops-integrity-warning", role: "alert", "aria-labelledby": "configops-integrity-title" }, /* @__PURE__ */ wp.
  element.createElement("span", { className: "configops-integrity-mark", "aria-hidden": "true" }, "!"), /* @__PURE__ */ wp.
  element.createElement("div", null, /* @__PURE__ */ wp.element.createElement("h3", { id: "configops-integrity-title" },
  __("Capture incomplete", "configops")), /* @__PURE__ */ wp.element.createElement("p", null, __("WordPress saved the se\
tting, but ConfigOps could not record every piece of evidence. Review the visible changes carefully; whole-capture undo \
is disabled.", "configops"))), /* @__PURE__ */ wp.element.createElement("strong", null, review.summary.captureErrors), /* @__PURE__ */ wp.
  element.createElement(Hint, { label: __("What can I do?", "configops"), align: "end" }, __("You can still inspect the \
evidence and undo supported settings individually. Start a new capture and repeat the save before turning these changes \
into a release.", "configops"))), /* @__PURE__ */ wp.element.createElement("div", { className: "configops-review-toolbar" },
  /* @__PURE__ */ wp.element.createElement("div", { className: "configops-review-toolbar-main" }, /* @__PURE__ */ wp.element.
  createElement("span", { className: "configops-toolbar-label" }, __("Show", "configops")), /* @__PURE__ */ wp.element.createElement(
  "div", { className: "configops-review-filters", role: "group", "aria-label": __("Filter changes", "configops") }, /* @__PURE__ */ wp.
  element.createElement(
    ReviewFilter,
    {
      active: filter === "review",
      count: review.summary.needsReview + review.summary.unmanagedWrites,
      description: __("Settings worth reading. Technical cache and maintenance values are left out.", "configops"),
      label: __("Review", "configops"),
      onSelect: () => setFilter("review")
    }
  ), /* @__PURE__ */ wp.element.createElement(
    ReviewFilter,
    {
      active: filter === "noise",
      count: review.summary.derived,
      description: __("Cache, migration, timestamp, and maintenance values generated by WordPress or a plugin.", "config\
ops"),
      label: __("Technical", "configops"),
      onSelect: () => setFilter("noise")
    }
  ), /* @__PURE__ */ wp.element.createElement(
    ReviewFilter,
    {
      active: filter === "all",
      count: review.summary.total + review.summary.unmanagedWrites,
      description: __("Every recorded Options API mutation plus any unmanaged database write signal.", "configops"),
      label: __("All", "configops"),
      onSelect: () => setFilter("all")
    }
  ))), /* @__PURE__ */ wp.element.createElement("div", { className: "configops-review-safety" }, review.summary.individuallyUndone >
  0 && /* @__PURE__ */ wp.element.createElement(Hint, { label: __("Why is capture undo unavailable?", "configops"), align: "\
end", trigger: `${review.summary.individuallyUndone} ${__("already undone", "configops")}` }, __("At least one setting w\
as already undone individually. The original whole-capture target no longer exists as one safe operation.", "configops")),
  review.summary.captureErrors > 0 && /* @__PURE__ */ wp.element.createElement(Hint, { label: __("Why is this capture in\
complete?", "configops"), align: "end", trigger: `${review.summary.captureErrors} ${__("missed", "configops")}` }, __("A\
t least one observation failed after WordPress processed a settings change. ConfigOps kept the host save running, marked\
 the evidence incomplete, and disabled whole-capture undo.", "configops")), review.summary.unmanagedWrites > 0 && /* @__PURE__ */ wp.
  element.createElement(Hint, { label: __("What is an unmanaged write?", "configops"), align: "end", trigger: `${review.
  summary.unmanagedWrites} ${__("unmanaged DB", "configops")}` }, __("A plugin wrote outside WordPress settings. ConfigO\
ps kept no query or values, so undoing the whole capture is disabled.", "configops")), review.summary.redacted > 0 && /* @__PURE__ */ wp.
  element.createElement(Hint, { label: __("What was redacted?", "configops"), align: "end", trigger: `${review.summary.redacted}\
 ${__("redacted", "configops")}` }, __("Only secrets that actually changed are counted here. Their raw values were remov\
ed before ConfigOps stored the capture.", "configops")), review.summary.total > 0 && !review.summary.allRestorable && review.
  summary.captureErrors === 0 && /* @__PURE__ */ wp.element.createElement(Hint, { label: __("Why can\u2019t I undo the whole \
capture?", "configops"), align: "end", trigger: __("Capture undo limited", "configops") }, __("At least one recorded cha\
nge cannot be reconstructed safely. Supported changes can still be undone individually.", "configops")))), review.groups.
  length === 0 && /* @__PURE__ */ wp.element.createElement("section", { className: "configops-empty-state configops-empt\
y-state--compact" }, /* @__PURE__ */ wp.element.createElement("h3", null, __("No changes", "configops")), /* @__PURE__ */ wp.
  element.createElement("p", null, selected.status === "active" ? __("Change a setting while recording.", "configops") :
  __("This capture contains no supported mutation or unmanaged database write signal.", "configops"))), review.groups.length >
  0 && filteredGroups.length === 0 && /* @__PURE__ */ wp.element.createElement("section", { className: "configops-empty-\
state configops-empty-state--compact" }, /* @__PURE__ */ wp.element.createElement("h3", null, __("Nothing in this filter",
  "configops")), /* @__PURE__ */ wp.element.createElement("p", null, __("Choose another change filter to continue the re\
view.", "configops"))), /* @__PURE__ */ wp.element.createElement("div", { id: "configops-change-list", "aria-live": "pol\
ite" }, filteredGroups.map((group) => /* @__PURE__ */ wp.element.createElement(RequestGroup, { key: `${group.requestId}-${filter}`,
  group, canRestore, pending: state.ui.pending, filter }))), review.pageInfo.hasNext && /* @__PURE__ */ wp.element.createElement(
  "div", { className: "configops-load-more" }, /* @__PURE__ */ wp.element.createElement("button", { className: "button",
  type: "button", disabled: Boolean(state.ui.pending), onClick: loadMoreMutations }, state.ui.pending === "load-more" ? __(
  "Loading\u2026", "configops") : __("Load more changes", "configops"))));
}
__name(ReviewLedger, "ReviewLedger");
export {
  ReviewLedger as default
};
