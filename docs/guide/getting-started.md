---
title: Get started
description: Install ConfigOps 0.3.0 and review an automatically recorded WordPress settings change.
---

# Get started

ConfigOps automatically records configuration mutations made by authorized administrators. Start with a small, reversible settings change so the first review is easy to verify against the screen you just used. Evidence stays local to WordPress.

## Requirements

| Contract | Supported in 0.3.0 |
| --- | --- |
| WordPress | 7.0 or newer, single-site |
| PHP | 8.2, 8.3, 8.4, or 8.5 |
| Database | WordPress-supported MySQL or MariaDB; release CI exercises MySQL 8.4 and MariaDB 11.4 |
| Access | A user with the ConfigOps view and capture capabilities; administrators receive them on activation |
| Browser | A current browser with JavaScript enabled for the review interface |

::: warning Scope
ConfigOps is a local configuration recorder. It is not a database backup, a deployment system, or a transactional rollback engine. Keep a tested site backup and rehearse consequential changes in staging.
:::

## Install

1. Obtain `configops-0.3.0.zip` from a trusted release channel.
2. In WordPress, open **Plugins → Add Plugin → Upload Plugin**.
3. Select the archive, install it, and activate **ConfigOps**.
4. Open **ConfigOps** in the WordPress admin menu.

Activation creates the local evidence tables and grants the versioned ConfigOps capabilities to the administrator role. It does not create an account or send evidence to pyrra.

## Record one settings save

1. Open the intended WordPress or plugin settings screen.
2. Make one small settings change and save it.
3. Use the ConfigOps evidence card to select **Review** or, when the complete save is safe, **Undo**.
4. Inspect the likely decision, technical writes, protected secrets, provenance, and undo eligibility.

Automatic observations are request-local: concurrent admin requests do not share a recording window. For a planned task that spans several requests, start a named **Change Session** in ConfigOps, perform only that task, then stop and review it.

## What success looks like

A completed change shows:

- the request groups that persisted option mutations;
- human-readable fields when a tested adapter knows them;
- probable credentials as removed, never as plaintext history;
- technical or unknown writes separated from the decision set;
- an undo control only where the stored evidence and current site state permit it.

Continue with [Record a change](/guide/first-capture) for the exact automatic and named-session boundaries, or jump to [Read the evidence](/guide/read-change) to understand a review.
