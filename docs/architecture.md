# Architecture decision: Recorder first

Status: accepted for Iteration 0
Date: 2026-08-12

## Decision

ConfigOps starts as a native WordPress plugin whose core is PHP 8.3. The first product boundary is one local WordPress site, one explicit capture session, Options API mutations, semantic nested diffs, provenance, and compensating restore.

JavaScript is the interaction layer and may later observe labels, field names, tabs, and client-side requests, but only the PHP observer can assert that WordPress actually persisted a mutation. The wp-admin interface uses code-split React islands over a capability-gated REST boundary. There is no Node service, monolithic SPA, cloud account, or remote control plane in the recorder.

## Why PHP is the right primary language

`update_option()` and its hooks execute inside PHP. Capturing old and new typed values, the current actor, request metadata, and the responsible call path is both more precise and cheaper in that same process. Reconstructing this from browser requests or database polling would lose internal writes, invent correlations, and complicate deployment.

PHP 8.3 is a deliberate product constraint, not an accidental use of new syntax. WordPress 7.0 recommends PHP 8.3, and the intended early users are technical agencies that should not run configuration-control software on an end-of-life runtime. We can revisit the floor only with real design-partner hosting data.

## Boundaries

| Concern | Iteration 0 authority | Later extension |
| --- | --- | --- |
| Persisted mutation | WordPress Options API hooks; value-free signal for unmanaged writes | Adapter-owned custom tables and APIs |
| Human intent | Explicit session name and request context | Admin field and fetch/REST correlation |
| Value semantics | Type-preserving codec, JSON Pointer diff, versioned field schemas, and bounded local media references | Cross-site semantic resolution and release transforms |
| Noise | Conservative built-in rules plus pinned WP Mail SMTP and Yoast contracts | Registry fixtures and adapter normalization |
| Secrets | Redact before persistence; preserve during field-level undo | Secret references and target-local resolution |
| Rollback | Conflict-checked full or adapter-backed field undo | Adapter-declared safety and verification |
| Storage | Dedicated session, mutation, write-signal, and restore-run tables | Packs, deployment runs, snapshots, and drift tables when used |
| Local UI transport | Explicit REST resources and commands | Keep domain services independent from transport |
| Fleet read model | Not present | GraphQL over asynchronously materialized fleet state |

## Domain invariants

1. A `ConfigMutation` is an observation, never an approved release change.
2. Unknown stays unknown; heuristics never impersonate adapter certainty.
3. Secret plaintext never enters mutation persistence or exported diagnostics.
4. Capture failure cannot fail the host settings request.
5. Restore refuses a target whose current value no longer matches the captured result.
6. List order is meaningful; associative key order is not.
7. ConfigOps does not claim transactional rollback for effects it did not observe.
8. An unmanaged write signal is not a `ConfigMutation`: it proves only bounded write intent and never fabricates values, semantic paths, or rollback support.
9. Incomplete evidence is a durable product state: it disables whole-capture undo and cannot be presented as a clean recording.
10. Every undo attempt creates a value-free audit record before its first configuration write.
11. A local media reference stores identity evidence, never file contents; undo refuses an attachment that no longer exists.

## Hardening decisions

- **Capture ownership is atomic.** The active-session option is acquired with `add_option()`, so two concurrent start requests cannot silently replace one another. Stale pointers self-heal.
- **Capture completion is verified.** Stop-time mutation and unmanaged-write summaries must be readable before a session can become completed. Storage failure leaves the capture active for a safe retry; deactivation closes it as interrupted and permanently incomplete.
- **Capture finalization is explicit.** Stop first moves the session through an atomic `stopping` state. Evidence that finishes after that boundary marks the capture incomplete; an abandoned stop self-recovers to interrupted after five minutes so it cannot strand the recorder or masquerade as complete.
- **Absence is not memoized.** Positive active-session lookups are cached, but another integration may start or stop a capture later in the same request.
- **Conflicts are semantic.** Associative key order is ignored; list order, typed array keys, scalar types, option existence, and autoload mode remain significant. Semantically empty writes are not persisted as noise.
- **Media references stay local and bounded.** Site icon, site-logo, theme custom-logo, and explicit Yoast image-ID paths retain attachment identity alongside the raw local ID. Review resolves a current thumbnail on demand. Capture does not hash or copy the file, and undo never creates or deletes attachments.
- **Restore is serialized and compensating.** Token-owned, expiring locks prevent overlapping restore requests. Session restore preflights the entire plan, rechecks each value immediately before writing, then restores distinct options in reverse last-mutation order. If a later step fails, earlier steps are reapplied to their captured result where possible.
- **Restore is auditable before it is mutable.** A dedicated append-first run records actor, target scope, outcome, restored option count, and bounded failure code. It deliberately contains no option name, value, SQL, stack trace, or raw error message. Successful, refused, compensated, and compensation-failed attempts remain distinct.
- **Work is budgeted.** Value nodes, persisted payload size, diff operations, and backtraces have explicit upper bounds and disclose truncation or unsupported values.
- **First paint cannot inherit history size.** The server bootstrap excludes mutation diffs. The ledger fetches cursor pages only near the viewport, with both row and encoded-response budgets.
- **Large sessions are not loaded whole.** Admin review is paged and restore planning selects only required columns in bounded batches. It retains only the first and last state per option and refuses plans above 1,000 options or 64 MiB.
- **Retention is bounded and resumable.** Daily cleanup removes at most 1,000 captures after 30 days, never selects active or unfinished captures, marks each batch as deleting before child evidence is touched, and can safely resume after an interrupted cleanup without exposing a half-deleted review.
- **Hot reads have matching indexes.** Session review, stop-time recounts, and keyset restore iteration share a `(session_id, id)` index instead of degrading into table scans as history grows.
- **Internal operations are invisible.** Schema, lock, capability, and flash-notice options never appear in captures.
- **Error reporting is also isolated.** Even a third-party listener that throws during `configops_capture_error` cannot escape into the settings request being observed. If a ConfigOps table fails while WordPress still saves the host setting, a bounded, value-free emergency marker in `wp_options` makes the session incomplete after storage recovers; unresolved markers produce a persistent administrator warning.
- **Schema upgrades fail safe.** Upgrades are serialized, required tables and columns are verified before the version advances, and a failed normal boot disables ConfigOps with an administrator notice without taking WordPress down.
- **Opaque credentials fail closed.** Adapter and heuristic detection covers nested PHP values plus recognizable JSON, malformed JSON, XML-like documents, DSNs, authorization headers, and private keys before persistence. Structured strings deeper than the inspection budget are redacted rather than partially trusted.
- **Adapters are capability-scoped.** Capture ownership, field meaning, secret detection, and rollback eligibility form the current contract. Apply and verification do not appear on the interface until those engines exist, so recorder support cannot be mistaken for deployment support.
- **Adapter meaning is pinned at capture time.** Mutations retain adapter ID, schema version, and installed component version. Historical fields are enriched only when the matching schema is still available; newer adapters cannot silently reinterpret old evidence.
- **Derived state stays out of rollback.** Cache, migration, tracking, version, and other adapter-declared runtime values remain visible under Technical. When they share an option with real settings, undo patches only the adapter-backed settings instead of reconstructing the whole option.
- **Protected options are patched, never reconstructed.** When a supported option also contains a secret, ConfigOps checks and reverses only adapter-backed non-secret paths against the current value. Credentials and plugin housekeeping remain byte-for-byte under the owning plugin’s control.
- **Direct writes fail visibly, not magically.** During an active capture, the SQL Sentry recognizes common write statements, ignores ConfigOps-owned tables and Options API duplicates, and stores only operation, table, count, provenance, and safe request metadata. Raw SQL and values never enter persistence. Fifty unique signals per request form a hard ceiling; repeated signals collapse by source.
- **Uncorrelated core cron stays out of an admin task.** Anonymous `/wp-cron.php` writes are not attributed to an explicit operator capture. Synchronous plugin side effects in the user’s Save request remain visible; future async correlation requires an adapter-owned job token instead of timing guesses.
- **Unknown effects limit rollback.** Any unmanaged database write disables full-session restore in the review contract. Individually supported Options API mutations remain conflict-checkable and restorable.

## Admin direction

The interface is a forensic change ledger: dense enough for professional review, calm enough to scan under incident pressure. Request groups form the primary rhythm; mutation rows reveal evidence progressively. Only the implemented Capture surface appears in navigation. Future Packs, Plans, Policies, and Drift do not masquerade as inactive product tiles. The design avoids equal-card dashboards and decorative infrastructure diagrams.

Three directions were considered:

1. a WordPress-native settings table, rejected because it hides request causality;
2. a pull-request imitation, useful for diffs but too code-host-specific as the whole product identity;
3. a forensic ledger with request chapters and nested evidence, selected because provenance and uncertainty are the actual product material.

The visual direction remains server-shell plus forensic instruments: a compact product bar and capture command render before only Capture, Sessions, and Review become interactive. This preserves the ledger rather than replacing it with framework-shaped cards. The company brand is expressed through a scoped token mapping and the supplied SVG wordmark; Paper carries the ledger, Ink carries evidence headers, and Brand Blue marks the command or selection without a webfont or visual runtime. Explanations stay attached to specialist terms and restore actions through accessible hover/focus help instead of lengthening every row.

## Trust harness

The deliberately hostile fixture plugin now exercises simple and nested options, typed WordPress IDs, secret redaction, transients, synchronous side effects, a versioned schema migration, AJAX metadata, direct SQL writes, and a neighboring plugin slug that shares the `configops` prefix. The integration contract also captures real attachment identities, renders their current availability, restores an existing reference, and refuses a deleted one before writing. Separate browser contracts install exact public releases of WP Mail SMTP and Yoast, operate their real settings screens, review the resulting capture at desktop and mobile widths, undo the safe fields, and verify the result back in each plugin.

## Next boundary

Iteration 0 now runs exact-release contract checks against WP Mail SMTP Free 4.9.0 and Yoast SEO Free 28.2. The next boundary is repeated design-partner evidence across real settings screens, followed by reviewed mutations becoming Release Changes—not fleet UI.
