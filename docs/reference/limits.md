---
title: Known limits
description: Capabilities ConfigOps 0.7.0 deliberately does not claim.
---

# Known limits

Version 0.7.0 begins a local configuration-management workflow while keeping its transport and execution boundary deliberately narrow.

## Not shipped

- whole-network-change undo, network option deletion undo, authority or plugin-lifecycle restoration, and derived network-counter undo;
- cross-site aggregation, cross-site bulk actions, or continuing one site capture across `switch_to_blog()` transitions;
- remote apply, cross-site synchronization, or fleet control; Pack files are imported by an operator on one destination at a time;
- generic rollback for plugin custom tables or direct SQL;
- content, media-file, theme-file, or filesystem deployment;
- database snapshots or transactional rollback;
- reversal of email, webhooks, API calls, cache purges, or other external side effects;
- generic semantic understanding for every WordPress plugin;
- cloud storage, team accounts, approvals, public Pack discovery/signing, variable substitution, Pack stacking, drift monitoring, or Policies.

Private Configuration Packs are shipped. They transport only safe, complete, adapter-backed site option desired states and deliberately exclude old values, autoload metadata, protected or partial options, Network Options, and custom tables. A portability warning does not transform a URL, path, email, environment value, or local reference. See [Configuration Packs](/guide/configuration-packs).

ConfigOps pins site observations to their originating WordPress site. If unsupported cross-site code calls `switch_to_blog()` and then writes configuration, ConfigOps ignores that value instead of attaching it to the wrong site's history. Any already-running site capture is marked incomplete and cannot use whole-change undo. Supported Network Options API changes use a separate network scope and never join a site's capture. Named Network Change Sessions group network-owned evidence only and deliberately do not enable whole-session undo.

## Evidence limits

Source attribution is bounded provenance, not proof of causality. Browser field correlation explains likely intent but cannot authorize a write. Secret detection is conservative but cannot guarantee discovery of every unusually named credential.

Typed values that are too large, too deep, unsupported, or unsafe to reconstruct lose undo eligibility. This is an intentional trade: ConfigOps would rather preserve a warning than persist dangerous data or manufacture a rollback.

The opt-in generic array experiment is structural, not semantic. It applies only to unclaimed associative `wp_options` updates whose complete patch agrees with both stored snapshots. It does not infer plugin intent, traverse integer-keyed parent arrays, patch list indexes, cross a redacted value, or make custom-table writes restorable. It is site-local and does not extend the stricter Network Options undo contract.

Settings API registration proves that one component registered an exact option in the current request; it does not prove which form field the operator intended to change or what a nested value means. ConfigOps therefore presents the owner and registration source but leaves classification and undo policy on the same adapterless path.

## Undo limits

Undo compensates the option state ConfigOps observed. WordPress hooks triggered by that compensating write can execute again, and third-party side effects may not be reversible. A successful audit record means the guarded option write completed; it does not prove the entire system returned to an earlier moment.

ConfigOps refuses an undo when `pre_option_*` or global `pre_option` short-circuits a site target's database read. A `default_option_*` hook is blocked only while the row is actually missing. The equivalent `pre_site_option_*`, global `pre_site_option`, and path-relevant `default_site_option_*` network hooks receive the same treatment. Normal post-read `option_*` and `site_option_*` transformations remain subject to the captured/current-state checks; their mere registration does not disable a supported plugin such as Yoast. Remove or bypass an owning read short-circuit in a controlled maintenance path, or restore through the owning plugin; ConfigOps does not guess which hidden representation is authoritative.

## Adapter limits

WP Mail SMTP Free 4.7–4.9, Yoast SEO Free 28.1–28.3, and WooCommerce 10.3/10.7/10.9/11.0 are tested version-line contracts. These are the lines WordPress.org exposed separately from its undisclosed `other` bucket on 2026-08-24; CI rejects a newly visible line until a real-plugin contract exists. A version outside those ranges may retain useful generic evidence, but adapter-dependent semantics and patches are disabled. The generic array experiment never overrides adapter ownership or a version boundary.

WooCommerce support stops at mapped core Options API settings. Custom-table tax rates, shipping zones and methods, orders, products, customers, webhooks, extension gateways, analytics data, and scheduled work are not reconstructed. Proven inbox, scheduler, note, and empty-selector initialization writes stay technical; an unfamiliar WooCommerce table write remains visible and limits whole-capture undo.

Read [Support contracts](/reference/support) for the positive contract and [Failure model](/security/failure-model) for behavior outside it.
