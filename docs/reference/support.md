---
title: Support contracts
description: Exact WordPress, PHP, database, and plugin-adapter support for ConfigOps 0.2.0.
---

# Support contracts

Support means a tested contract, not a best-effort badge. Version 0.2.0 fails its release checks if its runtime metadata, adapter fixtures, compatibility scan, browser flows, or coverage boundaries drift.

## Runtime matrix

| Component | Supported contract | Release evidence |
| --- | --- | --- |
| WordPress | 7.0 or newer, single-site | WordPress 7.0 and latest in CI |
| PHP | 8.2–8.5 | Parser, unit, hostile-input, and WordPress integration paths across the matrix |
| Database | WordPress-supported MySQL/MariaDB | Native MySQL 8.4 and MariaDB 11.4 integration lanes |
| Browser UI | Current JavaScript-capable admin browser | Real Chromium settings, review, and undo flows |
| Site model | Single-site only | Multisite is outside the 0.2 contract |

PHP 8.2 is the oldest supported branch. A lifecycle check expires that claim after upstream security support ends on 2026-12-31 instead of silently keeping an unsafe floor.

## Adapter matrix

| Integration | Tested release | Capture | Explain | Secrets | Undo |
| --- | ---: | :---: | :---: | :---: | :---: |
| WordPress Core | 7.0 | Supported | Field map + local references | Redacted | With limits |
| WP Mail SMTP Free | 4.9.0 | Supported | Exact schema | Removed before persistence | Full or safe field patch |
| Yoast SEO Free | 28.2 | Supported | Exact schema | Removed before persistence | With field and reference limits |
| Unknown plugins | — | Options API evidence | Needs review | Conservative heuristic | Only when generic evidence is fully safe |

Versions outside an adapter’s tested range keep generic evidence. Adapter-dependent explanations, field patches, and automatic undo fail closed until the contract is verified.

## What “with limits” means

- The current target must still match the recorded result.
- The complete capture must be proven complete for whole-capture undo.
- Secret, derived, oversized, and unsupported evidence is not restorable.
- Adapter schema and plugin version must still match.
- Local references must still exist and remain usable.
- Only Options API changes are generically restorable; custom tables need an explicit future adapter.

The exact adapter field families and normalization rules are documented in [Adapter contracts](/adapters).
