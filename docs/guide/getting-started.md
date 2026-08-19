---
title: Get started
description: Install ConfigOps 0.4.2 and review an automatically observed WordPress or Network Settings change.
---

# Get started

ConfigOps automatically observes configuration mutations made by authorized administrators. Start with a small, reversible settings change so the first review is easy to verify against the screen you just used. Evidence stays local to WordPress.

## Requirements

| Contract | Supported in 0.4.2 |
| --- | --- |
| WordPress | 7.0 or newer, single-site or network-active Multisite |
| PHP | 8.2, 8.3, 8.4, or 8.5 |
| Database | WordPress-supported MySQL or MariaDB; release CI exercises MySQL 8.4 and MariaDB 11.4 |
| Access | Site users need the relevant ConfigOps capability; Network Admin evidence requires `manage_network_options` |
| Browser | A current browser with JavaScript enabled for the review interface |

::: warning Scope
ConfigOps is a local configuration evidence layer. It is not a database backup, a deployment system, or a transactional rollback engine. Keep a tested site backup and rehearse consequential changes in staging.
:::

## Install

To inspect the workflow before installing anything, open the [disposable ConfigOps live demo](https://playground.wordpress.net/?blueprint-url=https%3A%2F%2Fplugins.svn.wordpress.org%2Fconfigops%2Fassets%2Fblueprints%2Fblueprint.json). It starts with ConfigOps and WP Mail SMTP active, then guides one sender-email save through Evidence, Review, and Undo.

1. Obtain `configops-0.4.2.zip` from a trusted release channel.
2. In WordPress, open **Plugins → Add Plugin → Upload Plugin**.
3. Select the archive, install it, and activate **ConfigOps**.
4. Open **ConfigOps** in the WordPress admin menu.

Activation creates the local evidence tables and grants the versioned ConfigOps capabilities to the administrator role. It does not create an account or send evidence to pyrra.

On Multisite, use **Network Admin → Plugins** to network-activate ConfigOps. Existing and newly created sites receive isolated site evidence state. Super administrators can open **Network Admin → ConfigOps** for the separate network-wide ledger.

## Observe one settings save

1. Open the intended WordPress or plugin settings screen.
2. Make one small settings change and save it.
3. Use the ConfigOps evidence card to select **Review** or, when the complete save is safe, **Undo**.
4. Inspect the likely decision, technical writes, protected secrets, provenance, and undo eligibility.

Automatic observations are request-local: concurrent admin requests do not share an observation boundary. For a planned task that spans several requests, start a named **Change Session** in ConfigOps, perform only that task, then stop and review it.

Network Settings changes use a stricter boundary. ConfigOps records supported Network Options API mutations automatically, and complete additions or updates may expose mutation-level undo. Network deletes and whole-change undo remain unavailable.

## What success looks like

A completed change shows:

- the request groups that persisted option mutations;
- human-readable fields when a tested adapter knows them;
- probable credentials as removed, never as plaintext history;
- technical or unknown writes separated from the decision set;
- an undo control only where the stored evidence and current site state permit it.

Continue with [Observe a change](/guide/first-capture) for the exact automatic and named-session boundaries, or jump to [Read the evidence](/guide/read-change) to understand a review.
