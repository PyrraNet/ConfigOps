---
title: Undo safely
description: The conflict checks, patch rules, audit trail, and compensation limits behind ConfigOps undo.
---

# Undo safely

ConfigOps performs compensating writes. It does not rewind the database or claim to reverse side effects it did not observe.

## Before selecting Undo

1. Read every change in the request group.
2. Confirm the site should return to the observed baseline.
3. Check that no one intentionally changed the same setting afterward.
4. Keep a tested backup for consequential configuration.
5. Prefer an individual field or option over whole-save or whole-session undo when that is the true intent.

## Conflict check

The current option must still match the observed **after** state at the scope ConfigOps intends to restore. If it differs, ConfigOps refuses to write. This prevents stale evidence from silently overwriting newer work.

Three restore modes can appear:

- **Full option:** the complete typed before/after values are safely encoded and the entire current option matches.
- **Field patch:** a tested adapter can restore supported non-secret paths while preserving an existing hidden credential or unrelated field.
- **Experimental generic array patch:** when a site administrator explicitly enables the experiment under **Plugin support**, an unclaimed associative `wp_options` array can restore only its captured paths while preserving unrelated later keys.

Adapter patches remain schema-bound. The generic experiment does not guess field meaning: it verifies every patch entry against both encoded snapshots, requires the current target paths to match the captured after-state, and refuses roots, integer-keyed parent arrays, list-index edits, secrets, truncated diffs, malformed evidence, adapter-owned options, and derived state. If any target path conflicts, it writes nothing.

For a generic patch, current state is evaluated path by path:

| Current state | Result |
| --- | --- |
| A captured target still equals its recorded after-state | That target remains eligible for reversal |
| Any captured target differs or has the wrong structure | The complete generic patch is refused without writing |
| An unrelated sibling was changed or added later | The sibling is retained in the current array |
| One stored patch entry disagrees with either typed snapshot | Smart undo is unavailable for the mutation |

This protects later work in the same option without pretending to understand the owning plugin. A successful structural write still cannot reverse email, webhooks, cache purges, remote API calls, or another side effect caused by the original save.

## Local references

Some settings point to local media, pages, or users. ConfigOps stores bounded identity evidence—not file contents, post bodies, or account data. Before undo, it verifies that the referenced target still exists and remains usable. Missing or trashed targets block the write.

## Serialization and audit

Restore operations acquire a local operation lock. Every attempt writes a value-free audit record before its first configuration write, then records success, failure, compensation, or compensation failure.

For a multi-option change, ConfigOps applies eligible writes in sequence. If a later write fails, it attempts to compensate earlier writes back to the state seen before the restore began. Compensation can also fail; that condition is recorded and requires manual investigation.

## After undo

- Reload the affected plugin or WordPress settings screen.
- Verify the visible behavior, not just the ConfigOps status.
- Check relevant health signals such as outbound mail, indexing, URLs, or cache behavior.
- Investigate any failed or compensated audit entry before another attempt.

::: danger Not a backup restore
ConfigOps cannot reverse email already sent, external API calls, cache purges, filesystem writes, custom-table changes, or plugin side effects that occurred outside the observed Options API mutation.
:::

See [Failure model](/security/failure-model) for the full fail-closed matrix.
