# Changelog

ConfigOps follows semantic versioning. While the major version is `0`, adapter contracts and stored evidence may still evolve between minor releases; migrations must always fail closed.

## Unreleased

## 0.5.1 — 2026-08-24

The agent-readable, human-authorized maintenance release.

### Added

- Eight site-scoped native WordPress Abilities for state, capture discovery, bounded mutation evidence, named capture control, and read-only mutation restore planning.
- A machine-readable JSON `wp configops` vocabulary that executes the same registered abilities with explicit WordPress capability checks.
- Versioned response envelopes, immutable scope metadata, bounded JSON Schemas, behavioral annotations, and machine-readable failure contracts for automation clients.
- Read-only restore planning that reuses the real restore engine's site, active-capture, reference, filter, autoload, current-state, adapter, and Smart Undo validations without changing WordPress state.

### Hardened

- WP-CLI automatic observations now work when the optional `--user` global argument is omitted, recording actor ID `0` for the shell-authorized command instead of silently dropping its evidence.
- Site and network restores now fail before writing when `pre_*` filters short-circuit the target's database read or a path-relevant missing-row default virtualizes it; value-free restore audits record `filtered_option_value` or `filtered_network_option_value`. Normal post-read transforms such as Yoast's `option_wpseo` remain conflict-checked instead of being refused solely because the hook is registered.
- Network activation no longer synchronously traverses every site when WordPress classifies the network as large. Existing sites provision idempotently on their next request, while network deactivation interrupts open site evidence with a bounded network-scoped update and lazy pointer reconciliation.

### Verified against

- Dedicated WP-CLI-without-user, virtual option filter, and forced large-network lifecycle regression contracts in the supported WordPress/PHP matrix.

## 0.5.0 — 2026-08-24

The smart undo beyond adapters release.

### Shipped

- Named Network Change Sessions in Network Admin, backed by the network-owned atomic active-session pointer and the same verified finalization contract as site sessions.
- Scope-aware capture controls, Network Admin recording status, intent correlation, and REST start/stop commands without enabling unsafe whole-network-change undo.
- Multisite integration coverage for concurrent start refusal, named-session write ownership, finalization, pointer cleanup, and unauthorized capture commands.
- An explicitly opt-in **Smart undo for ordinary settings arrays** experiment under Plugin support. For unclaimed associative site options, ConfigOps verifies the complete diff against both typed snapshots, checks every target path against current state, reverses additions, removals, and replacements together, and preserves unrelated later keys.

### Hardened

- Generic array patches fail closed for roots, integer-keyed parent arrays, list-index edits, secrets, redacted or oversized evidence, truncated or overlapping paths, adapter-owned options, malformed snapshots, autoload drift, and any target key that changed again. Current parent shapes and current adapter ownership are rechecked immediately before eligibility or apply, so historical string keys cannot alias later list indexes and newly registered adapters cannot be bypassed.
- Successful field patches now verify both the stored value and its autoload mode. A synchronous autoload rewrite fails as compensated instead of producing a false successful audit.
- Named-session pointer release is now compare-and-delete in site and network option scopes. A finishing request cannot delete a newer owner's pointer, and a start that loses ownership cannot resurrect or strand its session row.
- Site and network retention now share their scope's restore mutex, so cleanup cannot remove mutation or audit evidence while an undo operation is reading it.
- Internal lock and pointer compare-and-swap statements no longer create unmanaged-write evidence through the SQL sentry.

### Verified against

- 903 unit/fuzz assertions, 46 adversarial assertions, 272 real WordPress integration assertions, 51 exact real-plugin adapter assertions, and 154 network-active Multisite assertions.
- 77.39% tracked-production and 80.27% trust-boundary line coverage, plus the PHP 8.2–8.5 compatibility scan, UI budget, documentation build, release archive, and dependency advisory gates.

## 0.4.3 — 2026-08-19

The WordPress 7.1 compatibility release.

### Shipped

- A WordPress Core adapter contract covering final 7.0 and 7.1 versions while continuing to fail closed for untested 7.2 releases.
- Permanent WordPress 7.1 RC4 test lanes for the full PHP behavior suite, exact WP Mail SMTP and Yoast contracts, real browser save/review/undo flows, and native MySQL and MariaDB.
- WordPress.org, plugin-header, project, and operator-documentation metadata that consistently advertises the verified 7.1 ceiling.

### Verified against

- The official WordPress 7.1 RC4 package on PHP 8.2, 8.3, 8.4, and 8.5 test paths.
- Single-site settings observation, exact Core/plugin adapter behavior, secret protection, review, conflict-checked undo, and browser workflows.
- Network activation, isolated per-site evidence, Network Admin evidence and mutation undo, lifecycle, retention, migration, and uninstall boundaries.
- Native MySQL 8.4 and MariaDB 11.4 release lanes, the deterministic release archive, and WordPress Plugin Check.

## 0.4.2 — 2026-08-19

The guided WordPress Playground preview release.

### Shipped

- A public WordPress.org Blueprint that installs and activates ConfigOps plus the exact supported WP Mail SMTP 4.9.0 release, signs in, and lands directly on the sender settings screen.
- A small in-product demo instruction and a prepared settings baseline that make one sender-email edit produce one focused Evidence Card and one reviewable decision.
- Preview-only suppression for known first-load setup writes, so WordPress and WP Mail SMTP initialization cannot masquerade as the visitor's change.
- A browser release gate that exercises the complete public path: guided landing, normal settings save, Evidence Card, exact review, guarded field undo, and baseline verification.

### Verified against

- The current official Playground Blueprint schema and WordPress.org's 100 KiB preview-asset limit.
- A fresh six-worker WordPress Playground instance using WordPress latest, PHP 8.3, ConfigOps from WordPress.org, and WP Mail SMTP Free 4.9.0.
- The deterministic release archive, release metadata contract, WordPress Plugin Check, and complete ConfigOps test suite.

## 0.4.1 — 2026-08-19

The WordPress.org project-link visibility patch.

### Shipped

- Visible links to the ConfigOps website and documentation near the top of the WordPress.org plugin description.
- The existing plugin homepage metadata remains `https://configops.pyrra.net/`; runtime behavior and the 0.4 Multisite support contract are unchanged.

### Verified against

- The deterministic release archive, release metadata contract, WordPress Plugin Check, and complete ConfigOps test suite.

## 0.4.0 — 2026-08-19

The Multisite evidence and guarded Network Admin undo release.

### Shipped

- A network-active, site-local Multisite lifecycle for existing and newly initialized sites, including per-site capabilities, schema migration, and retention scheduling.
- Network-wide deactivation that interrupts open evidence and clears scheduled retention without crossing site boundaries.
- Site-deletion and uninstall cleanup for site-scoped shared rows, retained legacy tables, local options, transient cache entries, capabilities, and cron events.
- Automatic observation of Network Options API changes made by authorized Network Admin, REST, and WP-CLI requests.
- A capability-gated Network Admin evidence ledger with an explicit network scope, independently paged REST resources, and mutation-level undo for complete additions and updates.
- Conflict-checked Network Options undo with an atomic expiring network lock, append-first value-free audit records, post-write verification, and compensating recovery.
- Network-owned state, interruption, 30-day retention, and uninstall cleanup isolated from every site's options and evidence rows.
- A real network-activation contract covering 132 Multisite observation, isolation, undo, compensation, audit, locking, REST, retention, migration, lifecycle, deletion, and uninstall assertions, also included in release coverage evidence.
- A Chromium Network Settings save-review-undo contract at desktop and mobile widths.

### Deliberate boundaries

- Network undo is intentionally mutation-only: deletions remain review-only because WordPress exposes them after the previous value is gone; authority, plugin-lifecycle, and derived counter state require dedicated commands; and named network sessions plus whole-capture undo remain disabled.
- Per-site ledgers remain site-local. Cross-site aggregation, bulk operations, fleet control, Packs, Policies, and Drift are not part of the 0.4 boundary.

### Verified against

- WordPress 7.0 and latest on PHP 8.2 through PHP 8.5.
- 254 WordPress integration assertions, 132 Multisite assertions, and 51 exact adapter assertions.
- Native MySQL 8.4 and MariaDB 11.4 integration lanes plus real desktop and mobile browser flows.
- 76.43% tracked-production and 78.11% trust-boundary line coverage.

## 0.3.1 — 2026-08-18

The delivery and maintainability hardening release.

### Shipped

- Lossless automatic-evidence delivery across admin navigation: reading remains non-destructive, the browser acknowledges only evidence it rendered, and worker-local option-cache misses are invalidated before polling.
- One transport-neutral command service for capture start, stop, mutation undo, and whole-session undo across wp-admin and REST.
- Shared built-in adapter construction, runtime compatibility resolution, request evidence metadata, and database batch iteration in place of repeated implementations.
- Reusable browser-test interception and overflow assertions plus one bounded CI HTTP readiness helper for all browser lanes.
- Public plugin metadata at `https://configops.pyrra.net/` and versioned documentation at `https://configops.pyrra.net/docs/`.

### Verified against

- WordPress 7.0 and latest on PHP 8.2 and PHP 8.3 release lanes.
- 232 WordPress integration assertions, 51 exact adapter assertions, and the complete responsive settings, evidence, review, support, and undo browser flow.
- Release metadata, deterministic packaging, runtime policy, UI budget, PHP parsing, hostile inputs, and strict duplicate-code scanning.

## 0.3.0 — 2026-08-16

The automatic evidence and undo release.

### Shipped

- Request-local automatic observations that begin lazily on the first supported Options API mutation in an authorized admin, REST, or WP-CLI request.
- Immediate, value-free evidence feedback with write, likely-decision, technical, and protected-secret counts plus direct Review and whole-save Undo actions when the complete change is still safe.
- Named Change Sessions retained as the explicit multi-request mode for planned maintenance, support cases, and investigations.
- A versioned capture-mode schema and lifecycle that keeps automatic observations isolated from named sessions, discards technical-only automatic noise, fails incomplete evidence closed, and removes pending feedback on uninstall.
- Intent correlation that can bind bounded field names and labels to either a named session or the automatic observation created by the same save request without reading configuration values.
- The WordPress Change Intelligence positioning, “ConfigOps – Undo Settings Changes” directory identity, rewritten listing, and a focused three-screenshot proof sequence.

### Deliberate boundaries

- Automatic recording remains limited to supported local Options API evidence in authorized administrative contexts; anonymous front-end traffic is not treated as a settings change.
- ConfigOps remains an evidence and compensating-undo layer, not an activity log, backup, code rollback tool, or generic custom-table transaction engine.
- Branded report exports, Save Autopsies, the WordPress Change Index, a public demo, and AI-agent provenance remain later product and media milestones rather than claims of this release.

### Verified against

- WordPress 7.0 and latest on PHP 8.2, 8.3, 8.4, and 8.5.
- WP Mail SMTP Free 4.9.0 and Yoast SEO Free 28.2 through real save, explain, secret-protection, and undo browser flows at both ends of the PHP range.
- The official WordPress readme validator and Plugin Check with no release-blocking findings.
- 77.64% tracked-production and 79.24% trust-boundary line coverage with all 54 tracked production files in the final release-candidate denominator.

## 0.2.0 — 2026-08-13

The trust and compatibility release.

### Shipped

- PHP 8.2–8.5 runtime support, with the complete parser, deterministic fuzz, hostile-input, and WordPress integration path exercised on every supported branch.
- Exact WP Mail SMTP Free 4.9.0 and Yoast SEO Free 28.2 adapter and browser save/review/undo contracts at both PHP 8.2 and PHP 8.5.
- A hard 70% tracked-production line-coverage gate plus a separate 75% aggregate gate for access, API, capture, persistence, locking, retention, reference, release, and restore boundaries.
- Adversarial contracts for malformed intent evidence, oversized and deeply nested values, serialized-looking payloads, forged redaction nodes, secret-key variants, and JSON Pointer collisions.
- Product-code warnings, notices, and deprecations promoted to test failures across the supported PHP matrix.
- Locked PHPCompatibilityWP analysis plus Composer and npm vulnerability audits; the corrected PHPCS 3.13.6 toolchain excludes CVE-2026-67434.
- A PHP lifecycle gate that expires the PHP 8.2 support claim after upstream security support ends on 2026-12-31.
- Byte-reproducible release archives: two independent builds must produce the same SHA-256 before WordPress Plugin Check runs.
- A versioned VitePress documentation site for capture, review, undo, security boundaries, support contracts, operations, architecture, testing, and adapter development, deployable through GitHub Pages.

### Deliberate boundaries

- The compatibility expansion stops at PHP 8.2; PHP 8.1 and older are already outside upstream security support.
- Coverage floors are release signals, not proof that defects are absent.
- The public documentation describes only the shipped local recorder. Fleet control, generic custom-table rollback, remote apply, Policies, and Drift remain out of scope.

### Verified against

- WordPress 7.0 and latest on PHP 8.2, 8.3, 8.4, and 8.5.
- MySQL 8.4 and MariaDB 11.4.
- WP Mail SMTP Free 4.9.0 and Yoast SEO Free 28.2 through their real settings screens on PHP 8.2 and PHP 8.5.
- 77.30% tracked-production and 79.02% trust-boundary line coverage at release-candidate time.

## 0.1.0 — 2026-08-12

The first technical preview, created by pyrra.

### Shipped

- Explicit local capture sessions with request grouping and source attribution.
- Local, value-free intent correlation that matches touched wp-admin fields to persisted option paths and explains the match without changing restore authority.
- Same-request, same-owner option-write coalescing from the original baseline to the final state, including removal of complete reverts.
- Typed nested diffs, conservative noise separation, and redaction before persistence.
- Scalar normalization that suppresses storage-only `null` ↔ empty-string and canonical integer ↔ string churn while preserving structural and meaningful typed value changes.
- Conflict-checked field and session undo with compensation and value-free audit records.
- A WordPress Core 7.0 contract for common single-site settings, stable scalar normalization, and local page/media references.
- Exact deep-field contracts for every bundled WP Mail SMTP Free 4.9.0 mailer and the Yoast SEO Free 28.2 feature, crawl, schema, search, social, and LLMs.txt families.
- Race-aware capture finalization, integrity recovery, verified schema upgrades, and bounded retention.
- Local-only capture storage with suggested WordPress privacy-policy disclosure text.
- A responsive, keyboard-accessible React review interface within a strict bundle budget.
- Bounded media and content-reference evidence for WordPress site identity plus supported Yoast image, policy, ignore-list, and LLMs.txt fields, with missing-target protection during undo.

### Deliberate boundaries

- Options API configuration only; custom-table writes remain value-free warnings without an adapter.
- Local capture, review, and undo only. Release Packs, remote apply, Policies, and Drift are not shipped.
- Undo is compensating rather than a claim of transactional rollback across unknown plugin side effects.
- WordPress 7.0+, PHP 8.3+, and single-site WordPress only for this preview.

### Verified against

- WordPress 7.0 and latest, PHP 8.3–8.5.
- MySQL 8.4 and MariaDB 11.4.
- WP Mail SMTP Free 4.9.0 and Yoast SEO Free 28.2 through their real settings screens.
