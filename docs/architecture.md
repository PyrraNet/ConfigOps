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
| Value semantics | Type-preserving codec and JSON Pointer diff | Adapter schemas and semantic references |
| Noise | Conservative built-in rules | Versioned adapter normalization |
| Secrets | Redact before persistence; block restore | Secret references and target-local resolution |
| Rollback | Conflict-checked compensating restore | Adapter-declared safety and verification |
| Storage | Dedicated session, mutation, and write-signal tables | Packs, runs, snapshots, and drift tables when used |
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

## Hardening decisions

- **Capture ownership is atomic.** The active-session option is acquired with `add_option()`, so two concurrent start requests cannot silently replace one another. Stale pointers self-heal.
- **Absence is not memoized.** Positive active-session lookups are cached, but another integration may start or stop a capture later in the same request.
- **Conflicts are semantic.** Associative key order is ignored; list order, typed array keys, scalar types, option existence, and autoload mode remain significant. Semantically empty writes are not persisted as noise.
- **Restore is serialized and compensating.** Token-owned, expiring locks prevent overlapping restore requests. Session restore preflights the entire plan, rechecks each value immediately before writing, then restores distinct options in reverse last-mutation order. If a later step fails, earlier steps are reapplied to their captured result where possible.
- **Work is budgeted.** Value nodes, persisted payload size, diff operations, and backtraces have explicit upper bounds and disclose truncation or unsupported values.
- **First paint cannot inherit history size.** The server bootstrap excludes mutation diffs. The ledger fetches cursor pages only near the viewport, with both row and encoded-response budgets.
- **Large sessions are not loaded whole.** Admin review is paged and restore planning selects only required columns in bounded batches. It retains only the first and last state per option and refuses plans above 1,000 options or 64 MiB.
- **Hot reads have matching indexes.** Session review, stop-time recounts, and keyset restore iteration share a `(session_id, id)` index instead of degrading into table scans as history grows.
- **Internal operations are invisible.** Schema, lock, capability, and flash-notice options never appear in captures.
- **Error reporting is also isolated.** Even a third-party listener that throws during `configops_capture_error` cannot escape into the settings request being observed.
- **Adapter seams exist before the registry.** Secret detection and mutation classification use small interfaces so later adapters replace heuristics rather than patching the observer.
- **Direct writes fail visibly, not magically.** During an active capture, the SQL Sentry recognizes common write statements, ignores ConfigOps-owned tables and Options API duplicates, and stores only operation, table, count, provenance, and safe request metadata. Raw SQL and values never enter persistence. Fifty unique signals per request form a hard ceiling; repeated signals collapse by source.
- **Unknown effects limit rollback.** Any unmanaged database write disables full-session restore in the review contract. Individually supported Options API mutations remain conflict-checkable and restorable.

## Admin direction

The interface is a forensic change ledger: dense enough for professional review, calm enough to scan under incident pressure. Request groups form the primary rhythm; mutation rows reveal evidence progressively. Only the implemented Capture surface appears in navigation. Future Packs, Plans, Policies, and Drift do not masquerade as inactive product tiles. The design avoids equal-card dashboards and decorative infrastructure diagrams.

Three directions were considered:

1. a WordPress-native settings table, rejected because it hides request causality;
2. a pull-request imitation, useful for diffs but too code-host-specific as the whole product identity;
3. a forensic ledger with request chapters and nested evidence, selected because provenance and uncertainty are the actual product material.

The visual direction remains server-shell plus forensic instruments: a compact product bar and capture command render before only Capture, Sessions, and Review become interactive. This preserves the ledger rather than replacing it with framework-shaped cards. The company brand is expressed through a scoped token mapping and the supplied SVG wordmark; Paper carries the ledger, Ink carries evidence headers, and Brand Blue marks the command or selection without a webfont or visual runtime. Explanations stay attached to specialist terms and restore actions through accessible hover/focus help instead of lengthening every row.

## Trust harness

The deliberately hostile fixture plugin now exercises simple and nested options, typed WordPress IDs, secret redaction, transients, synchronous side effects, a versioned schema migration, AJAX metadata, direct SQL writes, and a neighboring plugin slug that shares the `configops` prefix. The integration contract proves that generic capture understands Options API mutations, reports direct writes only as value-free unmanaged signals, does not duplicate normal Options API saves, and never presents an unsupported rollback as complete.

## Next boundary

Iteration 0 now needs captures from one Core screen and two real design-partner plugins. Those runs must produce understandable request groups, correct nested diffs, useful ownership, and honest noise treatment before Release Packs begin. The next work is real-plugin evidence and classifier fixtures—not fleet UI.
