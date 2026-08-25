---
title: Read the evidence
description: Interpret requests, classifications, diffs, intent clues, and undo eligibility.
---

# Read the evidence

The review is an evidence ledger, not a claim that every write was intentional. Read it from the observation boundary inward: status, request group, option mutation, then individual field diff.

## Start with observation status

| Status | Meaning | Whole-change undo |
| --- | --- | --- |
| Completed | Stop-time summaries were persisted and verified | Possible only when every decision mutation is fully restorable |
| Active | A named Change Session is still open | Unavailable |
| Stopping | Finalization is in progress or recovering | Unavailable |
| Interrupted / incomplete | The evidence boundary could not be proven complete | Unavailable |

## Read the request group

A request group shows the actor, HTTP method, path, WordPress admin screen when available, and source attribution. A plugin-owned mutation uses a readable form of the source slug and includes the version captured at save time when WordPress can resolve it; the raw slug and code path remain in Technical evidence. **Changed through** means the plugin or theme was present in the causal write stack. **Setting registered by** means the component registered that exact option through the WordPress Settings API and Core performed the final write; its file and line identify the registration call. These are provenance signals. They can reveal that a save also triggered another plugin, but they do not prove human intent by themselves.

## Read the classification

| Classification | What it says | Typical consequence |
| --- | --- | --- |
| Setting | A tested adapter recognizes the option or field | Human label and adapter rules may permit bounded undo |
| Secret | The value or path was treated as sensitive | Plaintext is removed before persistence; secret change is not undoable |
| Derived / technical | Cache, lock, status, rewrite, cron, or migration-like state | Shown separately and excluded from the settings decision |
| Unknown | A persisted option change exists without an exact field contract | Evidence remains visible; undo is conservative |

When the site-local generic array experiment is enabled, an eligible Unknown mutation is labeled **Experimental** and its action reads **Undo verified keys**. That label means the complete structural patch passed the snapshot policy; it does not mean ConfigOps understands the plugin or the consequences of its save.

## Read the diff

ConfigOps stores typed nested differences, including meaningful changes in lists and associative values. JSON Pointer paths identify nested fields. Storage-only scalar churn can be normalized, while list order remains meaningful. Without an adapter, the review turns an exact leaf key such as `/mail/retry` into **Retry** and labels it as a plugin, must-use plugin, theme, or WordPress setting according to its captured source. That makes the evidence readable; it does not claim to know the field's behavior.

An adapter can add:

- a human label and settings group;
- an explanation of the field semantics;
- portability or local-reference rules;
- a tested plugin-version range;
- field-level undo when a hidden credential must remain untouched.

If an admin-field observation is matched, the review may show its field name, label, screen, and match reason. This is an explanatory clue only. The persisted mutation and adapter contract remain authoritative.

## Read undo eligibility last

An **Undo** control means ConfigOps has enough evidence to attempt a conflict-checked compensation now. It does not mean the change is consequence-free. Before any write, ConfigOps rechecks the current option value, adapter schema, local references, lock state, and restore history.

No control is itself evidence. Common reasons include:

- a secret or oversized value was removed from restorable history;
- the adapter version is outside its tested range;
- only part of an unknown option can be explained;
- the generic array experiment is off, the option has an adapter owner, or its structure is ambiguous;
- the current value no longer matches the observed result;
- a referenced page, media item, or user is now missing;
- the observation is incomplete;
- the same target already has a relevant restore in progress or completed.

Next: [Undo safely](/guide/undo-safely).
