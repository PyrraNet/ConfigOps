---
title: Support contracts
description: Exact WordPress, PHP, database, Multisite, adapter, and verified key undo support for ConfigOps 0.5.1.
---

# Support contracts

Support means a tested contract, not a best-effort badge. Version 0.5.1 fails its release checks if its runtime metadata, Multisite boundaries, adapter fixtures, generic array policy, public Playground flow, compatibility scan, browser flows, or coverage boundaries drift.

## Runtime matrix

| Component | Supported contract | Release evidence |
| --- | --- | --- |
| WordPress | 7.0–7.1 | WordPress 7.0, 7.1 RC4, and latest stable in CI |
| PHP | 8.2–8.5 | Parser, unit, hostile-input, and WordPress integration paths across the matrix |
| Database | WordPress-supported MySQL/MariaDB | Native MySQL 8.4 and MariaDB 11.4 integration lanes |
| Browser UI | Current JavaScript-capable admin browser | Real Chromium site and Network Admin settings, review, and undo flows |
| Site model | Single-site, network-active Multisite, and Multi-Network isolation | Isolated site ledgers plus a separate Network Admin ledger, exercised by 167 Multisite assertions |

PHP 8.2 is the oldest supported branch. A lifecycle check expires that claim after upstream security support ends on 2026-12-31 instead of silently keeping an unsafe floor.

## Multisite matrix

| Scope | Evidence | Undo | Deliberate limit |
| --- | --- | --- | --- |
| Individual site | Site-local Options API evidence and named Change Sessions | Safe field, mutation, or complete-change undo where proven | No cross-site reads or restores |
| Network Admin | Automatic observations and named Change Sessions for Network Options API evidence | Complete additions and updates, one mutation at a time | Deletes and whole-change undo are unavailable |
| Network lifecycle | Eager activation for bounded networks; lazy per-site provisioning plus bounded evidence interruption for large networks; new-site initialization, retention, migration, site deletion, deactivation, and uninstall | Not applicable | No cross-site aggregation or operator bulk actions |
| Multi-Network boundary | Actual site-to-network ownership after internal blog switches; foreign Network Options writes fail closed | Not applicable | No cross-network aggregation or commands |

Network undo excludes authority and plugin-lifecycle state plus derived counters. Network-owned locks, audits, and retention state are isolated from every site's option state. A write explicitly targeting another network still succeeds through WordPress, but ConfigOps does not record its value in the owning request's ledger and marks any affected open capture incomplete.

## Adapter matrix

| Integration | Tested release | Observe | Explain | Secrets | Undo |
| --- | ---: | :---: | :---: | :---: | :---: |
| WordPress Core | 7.0–7.1 | Supported | Field map + local references | Redacted | With limits |
| WP Mail SMTP Free | 4.7–4.9 | Supported | Version-line schema | Removed before persistence | Full or safe field patch |
| Yoast SEO Free | 28.1–28.3 | Supported | Version-line schema | Removed before persistence | With field and reference limits |
| WooCommerce | 10.3, 10.7, 10.9, 11.0 | Core Options API settings + feature flags | Version-line Settings API audit | BACS accounts removed | With storage and reference limits |
| Plugins without an adapter | Detected caller or Settings API owner | Options API evidence | Source basis, captured version, and readable leaf keys; semantics unverified | Conservative heuristic | Exact full-value undo; opt-in experimental key patch for verified associative arrays |

Versions outside an adapter’s tested range keep generic evidence. Adapter-dependent explanations, field patches, and automatic undo fail closed until the contract is verified.

For a regular plugin without an adapter, ConfigOps stores the bounded source slug and the plugin version observed through its source or active main file when available. Review groups use a readable source name, nested paths use their exact humanized leaf key, and Technical evidence retains the raw slug and file location. If Core performs the final write for an option registered during the same request through `register_setting()`, ConfigOps stores that registration as a separate source basis. A direct plugin, must-use plugin, or theme caller always wins; `unregister_setting()` removes the request-local ownership immediately, malformed hook events are ignored, and the registration map has a fixed 1,000-option ceiling with independent trace accounting. The label is presentation only: classification and restore eligibility do not gain plugin semantics from it.

For plugin adapters, an active version line means a version the official WordPress.org statistics API exposes as its own bucket rather than folding into `other`. The 2026-08-24 snapshot contains WP Mail SMTP 4.7/4.8/4.9, Yoast 28.1/28.2/28.3, and WooCommerce 10.3/10.7/10.9/11.0. Each line installs a real public release in CI, changes a setting through the plugin API, checks the stored component version and field meaning, undoes it, and compares the adapter with that release’s published settings surface. A field present in the plugin’s option map, registered defaults, or Settings API fails the contract when ConfigOps still treats it as unknown. A separate live policy job fails when WordPress.org exposes a line without a ConfigOps contract. The API does not reveal which versions make up `other`; ConfigOps records that limitation instead of calling those installations supported.

The WooCommerce contract covers the core General, Products, Inventory, Accounts & Privacy, Shipping display, Tax display, Advanced page/endpoint, Email, bundled offline-payment, Point of Sale receipt, performance, REST cache, and feature-toggle Options API settings exposed across its tested lines. HPOS and Cost of Goods switches are named but deliberately non-restorable because reversing one option does not reverse a datastore migration or content changes. BACS account records are redacted. Orders, products, customers, coupons, tax-rate tables, shipping zones and methods, webhooks, API keys, extension gateways, analytics tables, and scheduled jobs remain outside undo.

The generic array experiment is available only for site-owned `wp_options` evidence and is disabled by default. A user with `manage_options` can change the experiment setting under **ConfigOps → Support contracts**; attempting the resulting mutation undo still requires `configops_rollback`. It does not apply to Network Options or bypass an installed adapter's ownership and version boundary.

## What “with limits” means

- The current target must still match the observed result.
- The complete observation must be proven complete for whole-change undo.
- Secret, derived, oversized, and unsupported evidence is not restorable.
- Adapter schema and plugin version must still match.
- Local references must still exist and remain usable.
- The target option read path must not be virtualized by an Options API filter.
- Only Options API changes are generically restorable; custom tables need an explicit future adapter.
- Experimental generic array patches carry no semantic plugin knowledge and deliberately refuse integer-keyed parents, list-index surgery, and ambiguous structures.

The exact adapter field families and normalization rules are documented in [Adapter contracts](/adapters).
