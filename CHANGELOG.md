# Changelog

ConfigOps follows semantic versioning. While the major version is `0`, adapter contracts and stored evidence may still evolve between minor releases; migrations must always fail closed.

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
- 77.69% tracked-production and 79.20% trust-boundary line coverage with all 54 tracked production files in the final release-candidate denominator.

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
