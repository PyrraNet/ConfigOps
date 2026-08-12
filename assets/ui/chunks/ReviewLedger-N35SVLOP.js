import {
  Hint
} from "./chunk-JEVHJMKH.js";
import {
  __name,
  hydrateReview,
  loadMoreMutations,
  restoreMutation,
  restoreSession,
  useConfigOpsState
} from "./chunk-TVQASTIY.js";

// ui/format.js
var formatValue = /* @__PURE__ */ __name((value) => {
  if (typeof value === "boolean") {
    return value ? "On (true)" : "Off (false)";
  }
  if (value === null) {
    return "null";
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
var fieldKindLabel = /* @__PURE__ */ __name((kind, __) => {
  switch (kind) {
    case "portable":
      return __("Reusable", "configops");
    case "environment":
      return __("Check per website", "configops");
    case "secret":
      return __("Secret", "configops");
    case "reference":
      return __("Website link", "configops");
    case "runtime":
      return __("Technical", "configops");
    case "unsupported":
      return __("Outside scope", "configops");
    default:
      return __("Needs review", "configops");
  }
}, "fieldKindLabel");
var MutationRow = window.wp.element.memo(/* @__PURE__ */ __name(function MutationRow2({ mutation, canRestore, busy, filter }) {
  const { __ } = window.wp.i18n;
  const sourceLabel = mutation.source.file || mutation.source.type;
  const sourceOwner = mutation.adapter?.name || mutation.source.component || __("WordPress", "configops");
  const [open, setOpen] = window.wp.element.useState(filter !== "noise");
  const classificationDescriptionId = `configops-classification-${mutation.id}`;
  const restoreDescriptionId = `configops-restore-${mutation.id}`;
  const operationLabels = {
    add: __("Added option", "configops"),
    added: __("Added option", "configops"),
    update: __("Updated option", "configops"),
    updated: __("Updated option", "configops"),
    delete: __("Deleted option", "configops"),
    deleted: __("Deleted option", "configops")
  };
  const operationLabel = operationLabels[mutation.type] || mutation.type;
  const visibleCount = mutation.diff.length;
  const visibleLabel = filter === "noise" ? visibleCount === 1 ? __("1 technical change", "configops") : `${visibleCount}\
 ${__("technical changes", "configops")}` : visibleCount === 1 ? __("1 setting", "configops") : `${visibleCount} ${__("s\
ettings", "configops")}`;
  const patchRestore = mutation.restoreMode === "patch";
  const undoSucceeded = mutation.lastRestore?.status === "succeeded";
  const undoUncertain = ["running", "compensation_failed"].includes(mutation.lastRestore?.status);
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
    /* @__PURE__ */ wp.element.createElement("summary", { "aria-describedby": classificationDescriptionId }, /* @__PURE__ */ wp.
    element.createElement("span", { className: `configops-op configops-op--${mutation.type}`, title: operationLabel, "ar\
ia-hidden": "true" }, mutation.type.slice(0, 1).toUpperCase()), /* @__PURE__ */ wp.element.createElement("span", { className: "\
screen-reader-text" }, operationLabel), /* @__PURE__ */ wp.element.createElement("span", { className: "configops-option" },
    /* @__PURE__ */ wp.element.createElement("strong", null, mutation.adapter?.name || mutation.displayName || mutation.
    optionName), /* @__PURE__ */ wp.element.createElement("span", null, /* @__PURE__ */ wp.element.createElement("code",
    null, mutation.optionName), mutation.adapter?.componentVersion ? ` \xB7 v${mutation.adapter.componentVersion}` : "")),
    /* @__PURE__ */ wp.element.createElement("span", { className: `configops-badge configops-badge--${filter === "noise" ?
    "derived" : mutation.classification}`, "data-tooltip": mutation.classificationReason }, visibleLabel), /* @__PURE__ */ wp.
    element.createElement("span", { id: classificationDescriptionId, className: "screen-reader-text" }, mutation.classificationReason),
    /* @__PURE__ */ wp.element.createElement("span", { className: "configops-chevron", "aria-hidden": "true" })),
    /* @__PURE__ */ wp.element.createElement("div", { className: "configops-mutation-body" }, mutation.redacted && /* @__PURE__ */ wp.
    element.createElement("p", { className: "configops-secret-note" }, /* @__PURE__ */ wp.element.createElement("span", {
    "aria-hidden": "true" }, "\u25CF"), " ", patchRestore ? __("A secret changed and was removed before storage. ConfigO\
ps can undo the other supported settings without reading or replacing that secret.", "configops") : __("A secret changed\
 and was removed before storage. ConfigOps cannot reconstruct it for undo.", "configops")), /* @__PURE__ */ wp.element.createElement(
    "div", { className: "configops-diff-table", role: "table", "aria-label": __("Nested value changes", "configops") }, /* @__PURE__ */ wp.
    element.createElement("div", { className: "configops-diff-row configops-diff-head", role: "row" }, /* @__PURE__ */ wp.
    element.createElement("span", { role: "columnheader" }, __("Setting", "configops")), /* @__PURE__ */ wp.element.createElement(
    "span", { role: "columnheader" }, __("Before", "configops")), /* @__PURE__ */ wp.element.createElement("span", { role: "\
columnheader" }, __("After", "configops"))), mutation.diff.map((change, index) => /* @__PURE__ */ wp.element.createElement(
    "div", { className: "configops-diff-row", role: "row", key: `${change.path || "/"}-${change.op || ""}-${index}` }, /* @__PURE__ */ wp.
    element.createElement("div", { className: "configops-diff-field", role: "cell" }, /* @__PURE__ */ wp.element.createElement(
    "div", null, /* @__PURE__ */ wp.element.createElement("strong", null, change.label || change.path || "/"), change.explanation &&
    /* @__PURE__ */ wp.element.createElement(Hint, { label: __("About this setting", "configops") }, change.explanation)),
    change.group && /* @__PURE__ */ wp.element.createElement("span", null, change.group, change.kind ? ` \xB7 ${fieldKindLabel(
    change.kind, __)}` : ""), change.label && /* @__PURE__ */ wp.element.createElement("code", null, change.path || "/")),
    /* @__PURE__ */ wp.element.createElement("pre", { role: "cell", "data-label": __("Before", "configops") }, Object.hasOwn(
    change, "before") ? formatValue(change.before) : "\u2014"), /* @__PURE__ */ wp.element.createElement("pre", { role: "\
cell", "data-label": __("After", "configops") }, Object.hasOwn(change, "after") ? formatValue(change.after) : "\u2014")))),
    /* @__PURE__ */ wp.element.createElement("footer", { className: "configops-provenance" }, /* @__PURE__ */ wp.element.
    createElement("div", null, /* @__PURE__ */ wp.element.createElement("span", null, __("Changed through", "configops")),
    /* @__PURE__ */ wp.element.createElement("strong", null, sourceOwner), /* @__PURE__ */ wp.element.createElement("cod\
e", null, sourceLabel, mutation.source.line > 0 ? `:${mutation.source.line}` : "")), undoSucceeded && /* @__PURE__ */ wp.
    element.createElement("span", { className: "configops-restore-state is-succeeded" }, /* @__PURE__ */ wp.element.createElement(
    "strong", null, __("Undone", "configops")), /* @__PURE__ */ wp.element.createElement("span", null, mutation.lastRestore.
    actorName, " \xB7 ", mutation.lastRestore.finishedAtLabel)), undoUncertain && /* @__PURE__ */ wp.element.createElement(
    Hint, { label: __("Previous undo needs inspection", "configops"), align: "end", trigger: __("Inspect undo", "configo\
ps") }, __("A previous undo and its compensation did not both complete. Inspect the current plugin setting before attemp\
ting another change.", "configops")), !mutation.restorable && !mutation.redacted && filter !== "noise" && /* @__PURE__ */ wp.
    element.createElement(Hint, { label: __("Why can\u2019t this be undone?", "configops"), align: "end", trigger: __("U\
ndo unavailable", "configops") }, __("The adapter marks this as technical, unsupported, or outside its tested version ra\
nge. ConfigOps keeps the evidence but will not guess during rollback.", "configops")), canRestore && mutation.restorable &&
    !undoSucceeded && !undoUncertain && filter !== "noise" && /* @__PURE__ */ wp.element.createElement("span", { className: "\
configops-action-hint" }, /* @__PURE__ */ wp.element.createElement(
      "button",
      {
        className: "button button-small",
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
    ), /* @__PURE__ */ wp.element.createElement("span", { id: restoreDescriptionId, className: "configops-action-tooltip",
    role: "tooltip" }, patchRestore ? __("Only adapter-backed fields are reversed. Existing secrets, plugin housekeeping\
, files, and custom tables stay untouched.", "configops") : __("ConfigOps first checks that the setting still has the va\
lue shown here. Files and custom database tables are not part of this undo.", "configops")))))
  );
}, "MutationRow"));
var DatabaseWriteSignal = window.wp.element.memo(/* @__PURE__ */ __name(function DatabaseWriteSignal2({ signal }) {
  const { __, sprintf } = window.wp.i18n;
  const sourceLabel = signal.source.file || signal.source.type;
  const operationLabel = `${signal.operation} ${signal.table}`;
  return /* @__PURE__ */ wp.element.createElement("article", { className: "configops-write-signal" }, /* @__PURE__ */ wp.
  element.createElement("header", null, /* @__PURE__ */ wp.element.createElement("span", { className: "configops-sql-mar\
k", "aria-hidden": "true" }, "SQL"), /* @__PURE__ */ wp.element.createElement("div", { className: "configops-write-ident\
ity" }, /* @__PURE__ */ wp.element.createElement("code", null, operationLabel), /* @__PURE__ */ wp.element.createElement(
  "span", null, signal.source.component || signal.source.type)), signal.occurrenceCount > 1 && /* @__PURE__ */ wp.element.
  createElement("strong", { "aria-label": sprintf(__("%d occurrences", "configops"), signal.occurrenceCount) }, "\xD7", signal.
  occurrenceCount), /* @__PURE__ */ wp.element.createElement(Hint, { label: __("Why is there no comparison or undo?", "c\
onfigops"), align: "end", trigger: __("Outside standard settings", "configops") }, __("This plugin wrote directly to the\
 database. ConfigOps kept no query or value; understanding and undoing it safely requires a dedicated adapter.", "config\
ops"))), /* @__PURE__ */ wp.element.createElement("footer", null, /* @__PURE__ */ wp.element.createElement("span", null,
  __("Database write seen \xB7 No value stored \xB7 No automatic undo", "configops")), /* @__PURE__ */ wp.element.createElement(
  "code", null, sourceLabel, signal.source.line > 0 ? `:${signal.source.line}` : "")));
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
  return /* @__PURE__ */ wp.element.createElement("section", { className: "configops-request-group" }, /* @__PURE__ */ wp.
  element.createElement("header", { className: "configops-request-header" }, /* @__PURE__ */ wp.element.createElement("d\
iv", null, /* @__PURE__ */ wp.element.createElement("span", { className: "configops-request-index" }, group.index), /* @__PURE__ */ wp.
  element.createElement("div", null, /* @__PURE__ */ wp.element.createElement("div", { className: "configops-request-tit\
le" }, /* @__PURE__ */ wp.element.createElement("h3", null, title), /* @__PURE__ */ wp.element.createElement(Hint, { label: __(
  "Why are these changes grouped?", "configops") }, __("These changes happened after the same Save action, so ConfigOps \
keeps them together.", "configops"))), /* @__PURE__ */ wp.element.createElement("p", null, /* @__PURE__ */ wp.element.createElement(
  "code", null, group.head.method), " ", group.head.requestUri, " ", /* @__PURE__ */ wp.element.createElement("span", { "\
aria-hidden": "true" }, "\xB7"), " ", sprintf(__("%d visible changes", "configops"), visibleChangeCount), unmanagedWriteCount >
  0 && /* @__PURE__ */ wp.element.createElement(wp.element.Fragment, null, " ", /* @__PURE__ */ wp.element.createElement(
  "span", { "aria-hidden": "true" }, "\xB7"), " ", sprintf(__("%d unmanaged DB writes", "configops"), unmanagedWriteCount))))),
  /* @__PURE__ */ wp.element.createElement("time", { dateTime: group.head.occurredAt }, group.head.timeLabel)), /* @__PURE__ */ wp.
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
      "data-tooltip": description,
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
gops") } : { className: "is-recorded", label: __("Recorded", "configops") };
  const sessionUndo = review.summary.lastSessionRestore;
  const sessionUndoSucceeded = sessionUndo?.status === "succeeded";
  const sessionUndoUncertain = ["running", "compensation_failed"].includes(sessionUndo?.status);
  const canRestore = !state.active && state.capabilities.rollback && !sessionUndoSucceeded && !sessionUndoUncertain;
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
    "p", null, __("Record changes or choose an existing capture.", "configops")));
  }
  return /* @__PURE__ */ wp.element.createElement(wp.element.Fragment, null, /* @__PURE__ */ wp.element.createElement("h\
eader", { className: "configops-review-header" }, /* @__PURE__ */ wp.element.createElement("div", null, /* @__PURE__ */ wp.
  element.createElement("div", { className: "configops-capture-reference" }, /* @__PURE__ */ wp.element.createElement("s\
pan", null, __("Capture", "configops"), " ", /* @__PURE__ */ wp.element.createElement("code", null, "#", selected.id)), /* @__PURE__ */ wp.
  element.createElement("span", { className: selectedStatus.className }, selectedStatus.label)), /* @__PURE__ */ wp.element.
  createElement("div", { className: "configops-review-title" }, /* @__PURE__ */ wp.element.createElement("h2", null, selected.
  name)), /* @__PURE__ */ wp.element.createElement("p", null, selected.actorName, /* @__PURE__ */ wp.element.createElement(
  "span", { "aria-hidden": "true" }, " \xB7 "), /* @__PURE__ */ wp.element.createElement("time", { dateTime: selected.startedAt },
  selected.startedDisplay))), sessionUndoSucceeded && /* @__PURE__ */ wp.element.createElement("span", { className: "con\
figops-restore-state configops-restore-state--session is-succeeded" }, /* @__PURE__ */ wp.element.createElement("strong",
  null, __("Capture undone", "configops")), /* @__PURE__ */ wp.element.createElement("span", null, sessionUndo.actorName,
  " \xB7 ", sessionUndo.finishedAtLabel)), sessionUndoUncertain && /* @__PURE__ */ wp.element.createElement("span", { className: "\
configops-restore-state configops-restore-state--session is-uncertain" }, /* @__PURE__ */ wp.element.createElement("stro\
ng", null, __("Undo needs inspection", "configops")), /* @__PURE__ */ wp.element.createElement("span", null, __("Check t\
he current settings before continuing.", "configops"))), canRestore && review.summary.total > 0 && review.summary.allRestorable &&
  !sessionUndoSucceeded && !sessionUndoUncertain && /* @__PURE__ */ wp.element.createElement(
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
    state.ui.pending === `restore-session-${selected.id}` ? __("Undoing\u2026", "configops") : __("Undo this capture", "\
configops")
  )), review.summary.captureErrors > 0 && /* @__PURE__ */ wp.element.createElement("section", { className: "configops-in\
tegrity-warning", role: "alert", "aria-labelledby": "configops-integrity-title" }, /* @__PURE__ */ wp.element.createElement(
  "span", { className: "configops-integrity-mark", "aria-hidden": "true" }, "!"), /* @__PURE__ */ wp.element.createElement(
  "div", null, /* @__PURE__ */ wp.element.createElement("h3", { id: "configops-integrity-title" }, __("Capture incomplet\
e", "configops")), /* @__PURE__ */ wp.element.createElement("p", null, __("WordPress saved the setting, but ConfigOps co\
uld not record every piece of evidence. Review the visible changes carefully; whole-capture undo is disabled.", "configo\
ps"))), /* @__PURE__ */ wp.element.createElement("strong", null, review.summary.captureErrors), /* @__PURE__ */ wp.element.
  createElement(Hint, { label: __("What can I do?", "configops"), align: "end" }, __("You can still inspect the evidence\
 and undo supported settings individually. Start a new capture and repeat the save before turning these changes into a r\
elease.", "configops"))), /* @__PURE__ */ wp.element.createElement("div", { className: "configops-review-toolbar" }, /* @__PURE__ */ wp.
  element.createElement("div", { className: "configops-review-filters", role: "group", "aria-label": __("Filter changes",
  "configops") }, /* @__PURE__ */ wp.element.createElement(
    ReviewFilter,
    {
      active: filter === "all",
      count: review.summary.total + review.summary.unmanagedWrites,
      description: __("Every recorded Options API mutation plus any unmanaged database write signal.", "configops"),
      label: __("All", "configops"),
      onSelect: () => setFilter("all")
    }
  ), /* @__PURE__ */ wp.element.createElement(
    ReviewFilter,
    {
      active: filter === "review",
      count: review.summary.needsReview + review.summary.unmanagedWrites,
      description: __("Settings worth reading. Technical cache and maintenance values are left out.", "configops"),
      label: __("Settings", "configops"),
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
  )), /* @__PURE__ */ wp.element.createElement("div", { className: "configops-review-safety" }, review.summary.individuallyUndone >
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
ed before ConfigOps stored the capture.", "configops")), review.summary.total > 0 && /* @__PURE__ */ wp.element.createElement(
  Hint, { label: review.summary.allRestorable ? __("How safe is undo?", "configops") : __("Why can\u2019t I undo the whole ca\
pture?", "configops"), align: "end", trigger: review.summary.allRestorable ? __("Undo checked", "configops") : __("Captu\
re undo limited", "configops") }, review.summary.allRestorable ? __("ConfigOps will undo only when the current value sti\
ll matches this capture. Files and custom tables remain outside generic rollback.", "configops") : review.summary.captureErrors >
  0 ? __("The recording is incomplete, so ConfigOps cannot prove a whole-capture undo is safe. Supported visible changes\
 can still be undone individually below.", "configops") : __("At least one recorded change cannot be reconstructed safel\
y, so whole-capture undo stays off. Supported changes can still be undone individually below.", "configops")))), review.
  groups.length === 0 && /* @__PURE__ */ wp.element.createElement("section", { className: "configops-empty-state configo\
ps-empty-state--compact" }, /* @__PURE__ */ wp.element.createElement("h3", null, __("No changes", "configops")), /* @__PURE__ */ wp.
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
