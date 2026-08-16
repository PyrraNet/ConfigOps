---
title: Secrets & privacy
description: What ConfigOps stores locally, removes before persistence, and exposes through capabilities.
---

# Secrets & privacy

ConfigOps is local by design. Capture evidence is stored in the WordPress database and is not sent to pyrra or another ConfigOps service.

## Data that can be stored

- observation mode, optional capture name, status, timestamps, and initiating user ID;
- request ID, actor ID, method, path, admin screen, and bounded source attribution;
- typed before/after option evidence and nested diffs where safe;
- classifications, adapter IDs, schema versions, and undo eligibility;
- value-free direct-write warnings and restore audit records;
- bounded identity for supported local media, content, and user references.

## Secret handling

Tested adapter schemas and conservative key-name heuristics identify probable credentials. Redaction happens before mutation history is written. A redacted node carries only the fact that protected data changed; it cannot reconstruct the original value and is never eligible for undo.

Opaque or structurally unsafe values can also become non-restorable. This favors losing rollback capability over retaining a value ConfigOps cannot safely interpret.

No heuristic can guarantee that an unusually named secret is detected. Plugin authors should provide explicit adapter schemas, and operators should restrict who can view local evidence.

## Browser intent evidence

The admin observer can correlate a settings save with bounded field names and visible labels. It does not read field values. The short-lived local cookie is limited in bytes, field count, nesting depth, and age. It binds to a named session ID or remains unbound until the same save request lazily creates its automatic observation; malformed or ambiguous evidence is ignored.

Intent evidence may improve a label for review. It cannot change classification, adapter trust, or undo authority.

## Access control

Version 0.3.0 uses separate WordPress capabilities for its active recorder surface:

| Capability | Grants |
| --- | --- |
| `configops_view` | Read ConfigOps state and capture evidence |
| `configops_capture` | Start and stop captures |
| `configops_rollback` | Attempt mutation or whole-capture undo |

These are the capabilities used by the shipped recorder REST routes. The versioned capability set also reserves names for future product boundaries, but 0.3.0 exposes no recorder endpoint through them. Administrators receive the set on activation. Sites with custom roles should grant only the minimum active capabilities needed. REST responses are capability-gated, private, and marked `no-store`.

## Retention and removal

Completed and interrupted captures are removed after 30 days by default while ConfigOps is active. Site developers can change the period with the `configops_retention_days` filter. Cleanup is bounded and preserves history when dependent evidence cannot be removed safely.

Uninstalling ConfigOps removes its capture tables, installation options, scheduled cleanup, and capabilities. Deactivation does not erase history; it closes an active capture as interrupted.

## Suggested privacy disclosure

ConfigOps registers suggested WordPress privacy-policy text describing its local configuration evidence, user attribution, default retention, and lack of external transmission. Review that text against your organization’s access, backup, and retention policies before publishing it.
