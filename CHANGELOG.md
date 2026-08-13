# Changelog

ConfigOps follows semantic versioning. While the major version is `0`, adapter contracts and stored evidence may still evolve between minor releases; migrations must always fail closed.

## 0.1.0 — 2026-08-12

The first technical preview, created by pyrra.

### Shipped

- Explicit local capture sessions with request grouping and source attribution.
- Same-request, same-owner option-write coalescing from the original baseline to the final state, including removal of complete reverts.
- Typed nested diffs, conservative noise separation, and redaction before persistence.
- Scalar normalization that suppresses storage-only `null` ↔ empty-string and canonical integer ↔ string churn while preserving structural and meaningful typed value changes.
- Conflict-checked field and session undo with compensation and value-free audit records.
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
