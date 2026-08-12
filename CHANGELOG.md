# Changelog

ConfigOps follows semantic versioning. While the major version is `0`, adapter contracts and stored evidence may still evolve between minor releases; migrations must always fail closed.

## 0.1.0 — 2026-08-12

The first technical preview, created by pyrra.

### Shipped

- Explicit local capture sessions with request grouping and source attribution.
- Typed nested diffs, conservative noise separation, and redaction before persistence.
- Conflict-checked field and session undo with compensation and value-free audit records.
- Exact adapter contracts for WP Mail SMTP Free 4.9.0 and Yoast SEO Free 28.2.
- Race-aware capture finalization, integrity recovery, verified schema upgrades, and bounded retention.
- Local-only capture storage with suggested WordPress privacy-policy disclosure text.
- A responsive, keyboard-accessible React review interface within a strict bundle budget.

### Deliberate boundaries

- Options API configuration only; custom-table writes remain value-free warnings without an adapter.
- Local capture, review, and undo only. Release Packs, remote apply, Policies, and Drift are not shipped.
- Undo is compensating rather than a claim of transactional rollback across unknown plugin side effects.
- WordPress 7.0+, PHP 8.3+, and single-site WordPress only for this preview.

### Verified against

- WordPress 7.0 and latest, PHP 8.3–8.5.
- MySQL 8.4 and MariaDB 11.4.
- WP Mail SMTP Free 4.9.0 and Yoast SEO Free 28.2 through their real settings screens.
