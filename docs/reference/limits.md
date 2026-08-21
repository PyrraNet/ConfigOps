---
title: Known limits
description: Capabilities ConfigOps 0.4.3 deliberately does not claim.
---

# Known limits

Version 0.4.3 is deliberately narrower than a configuration-management platform.

## Not shipped

- whole-network-change undo, network option deletion undo, authority or plugin-lifecycle restoration, and derived network-counter undo;
- cross-site aggregation, cross-site bulk actions, or continuing one site capture across `switch_to_blog()` transitions;
- remote apply, cross-site synchronization, or fleet control;
- generic rollback for plugin custom tables or direct SQL;
- content, media-file, theme-file, or filesystem deployment;
- database snapshots or transactional rollback;
- reversal of email, webhooks, API calls, cache purges, or other external side effects;
- generic semantic understanding for every WordPress plugin;
- cloud storage, team accounts, approvals, drift monitoring, Policies, or Release Packs.

ConfigOps pins site observations to their originating WordPress site. If unsupported cross-site code calls `switch_to_blog()` and then writes configuration, ConfigOps ignores that value instead of attaching it to the wrong site's history. Any already-running site capture is marked incomplete and cannot use whole-change undo. Supported Network Options API changes use a separate network scope and never join a site's capture. Named Network Change Sessions group network-owned evidence only and deliberately do not enable whole-session undo.

## Evidence limits

Source attribution is bounded provenance, not proof of causality. Browser field correlation explains likely intent but cannot authorize a write. Secret detection is conservative but cannot guarantee discovery of every unusually named credential.

Typed values that are too large, too deep, unsupported, or unsafe to reconstruct lose undo eligibility. This is an intentional trade: ConfigOps would rather preserve a warning than persist dangerous data or manufacture a rollback.

The opt-in generic array experiment is structural, not semantic. It applies only to unclaimed associative `wp_options` updates whose complete patch agrees with both stored snapshots. It does not infer plugin intent, traverse integer-keyed parent arrays, patch list indexes, cross a redacted value, or make custom-table writes restorable. It is site-local and does not extend the stricter Network Options undo contract.

## Undo limits

Undo compensates the option state ConfigOps observed. WordPress hooks triggered by that compensating write can execute again, and third-party side effects may not be reversible. A successful audit record means the guarded option write completed; it does not prove the entire system returned to an earlier moment.

## Adapter limits

WP Mail SMTP Free 4.9.0 and Yoast SEO Free 28.2 are exact tested contracts. A newer or older release may retain useful generic option evidence, but adapter-dependent semantics and patches are disabled until verified. The generic array experiment never overrides an adapter ownership or version boundary.

Read [Support contracts](/reference/support) for the positive contract and [Failure model](/security/failure-model) for behavior outside it.
