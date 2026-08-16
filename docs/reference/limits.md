---
title: Known limits
description: Capabilities ConfigOps 0.3.0 deliberately does not claim.
---

# Known limits

Version 0.3.0 is deliberately narrower than a configuration-management platform.

## Not shipped

- multisite capture or network-wide administration;
- remote apply, cross-site synchronization, or fleet control;
- generic rollback for plugin custom tables or direct SQL;
- content, media-file, theme-file, or filesystem deployment;
- database snapshots or transactional rollback;
- reversal of email, webhooks, API calls, cache purges, or other external side effects;
- generic semantic understanding for every WordPress plugin;
- cloud storage, team accounts, approvals, drift monitoring, Policies, or Release Packs.

## Evidence limits

Source attribution is bounded provenance, not proof of causality. Browser field correlation explains likely intent but cannot authorize a write. Secret detection is conservative but cannot guarantee discovery of every unusually named credential.

Typed values that are too large, too deep, unsupported, or unsafe to reconstruct lose undo eligibility. This is an intentional trade: ConfigOps would rather preserve a warning than persist dangerous data or manufacture a rollback.

## Undo limits

Undo compensates the option state ConfigOps observed. WordPress hooks triggered by that compensating write can execute again, and third-party side effects may not be reversible. A successful audit record means the guarded option write completed; it does not prove the entire system returned to an earlier moment.

## Adapter limits

WP Mail SMTP Free 4.9.0 and Yoast SEO Free 28.2 are exact tested contracts. A newer or older release may retain useful generic option evidence, but adapter-dependent semantics and undo are disabled until verified.

Read [Support contracts](/reference/support) for the positive contract and [Failure model](/security/failure-model) for behavior outside it.
