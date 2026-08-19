---
title: Support contracts
description: Exact WordPress, PHP, database, Multisite, and plugin-adapter support for ConfigOps 0.4.2.
---

# Support contracts

Support means a tested contract, not a best-effort badge. Version 0.4.2 fails its release checks if its runtime metadata, Multisite boundaries, adapter fixtures, public Playground flow, compatibility scan, browser flows, or coverage boundaries drift.

## Runtime matrix

| Component | Supported contract | Release evidence |
| --- | --- | --- |
| WordPress | 7.0 or newer | WordPress 7.0 and latest in CI |
| PHP | 8.2–8.5 | Parser, unit, hostile-input, and WordPress integration paths across the matrix |
| Database | WordPress-supported MySQL/MariaDB | Native MySQL 8.4 and MariaDB 11.4 integration lanes |
| Browser UI | Current JavaScript-capable admin browser | Real Chromium site and Network Admin settings, review, and undo flows |
| Site model | Single-site and network-active Multisite | Isolated site ledgers plus a separate Network Admin ledger, exercised by 132 Multisite assertions |

PHP 8.2 is the oldest supported branch. A lifecycle check expires that claim after upstream security support ends on 2026-12-31 instead of silently keeping an unsafe floor.

## Multisite matrix

| Scope | Evidence | Undo | Deliberate limit |
| --- | --- | --- | --- |
| Individual site | Site-local Options API evidence and named Change Sessions | Safe field, mutation, or complete-change undo where proven | No cross-site reads or restores |
| Network Admin | Network Options API evidence in a network-wide ledger | Complete additions and updates, one mutation at a time | Deletes, named network sessions, and whole-change undo are unavailable |
| Network lifecycle | Activation, new-site initialization, retention, migration, site deletion, deactivation, and uninstall | Not applicable | No cross-site aggregation or bulk operations |

Network undo excludes authority and plugin-lifecycle state plus derived counters. Network-owned locks, audits, and retention state are isolated from every site's option state.

## Adapter matrix

| Integration | Tested release | Observe | Explain | Secrets | Undo |
| --- | ---: | :---: | :---: | :---: | :---: |
| WordPress Core | 7.0 | Supported | Field map + local references | Redacted | With limits |
| WP Mail SMTP Free | 4.9.0 | Supported | Exact schema | Removed before persistence | Full or safe field patch |
| Yoast SEO Free | 28.2 | Supported | Exact schema | Removed before persistence | With field and reference limits |
| Unknown plugins | — | Options API evidence | Needs review | Conservative heuristic | Only when generic evidence is fully safe |

Versions outside an adapter’s tested range keep generic evidence. Adapter-dependent explanations, field patches, and automatic undo fail closed until the contract is verified.

## What “with limits” means

- The current target must still match the observed result.
- The complete observation must be proven complete for whole-change undo.
- Secret, derived, oversized, and unsupported evidence is not restorable.
- Adapter schema and plugin version must still match.
- Local references must still exist and remain usable.
- Only Options API changes are generically restorable; custom tables need an explicit future adapter.

The exact adapter field families and normalization rules are documented in [Adapter contracts](/adapters).
