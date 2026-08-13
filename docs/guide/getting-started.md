---
title: Get started
description: Install ConfigOps 0.2.0 and record a bounded WordPress settings task.
---

# Get started

ConfigOps records the option changes caused while one explicit capture is active. Start with a small, reversible settings task so the first review is easy to verify against the screen you just used.

## Requirements

| Contract | Supported in 0.2.0 |
| --- | --- |
| WordPress | 7.0 or newer, single-site |
| PHP | 8.2, 8.3, 8.4, or 8.5 |
| Database | WordPress-supported MySQL or MariaDB; release CI exercises MySQL 8.4 and MariaDB 11.4 |
| Access | A user with the ConfigOps view and capture capabilities; administrators receive them on activation |
| Browser | A current browser with JavaScript enabled for the review interface |

::: warning Technical preview
ConfigOps is a local configuration recorder. It is not a database backup, a deployment system, or a transactional rollback engine. Keep a tested site backup and rehearse consequential changes in staging.
:::

## Install

1. Obtain `configops-0.2.0.zip` from a trusted release channel.
2. In WordPress, open **Plugins → Add Plugin → Upload Plugin**.
3. Select the archive, install it, and activate **ConfigOps**.
4. Open **ConfigOps** in the WordPress admin menu.

Activation creates the local evidence tables and grants the versioned ConfigOps capabilities to the administrator role. It does not create an account or send evidence to pyrra.

## Record one bounded task

1. Give the capture a concrete name, such as `Change transactional sender`.
2. Select **Start recording**.
3. Open the intended WordPress or plugin settings screen and make only that change.
4. Return to ConfigOps and select **Stop recording**.
5. Review the recorded request, redacted entries, technical changes, and undo eligibility.

A narrow capture is easier to explain and safer to undo. Do not leave recording active across unrelated admin work, automated maintenance, or a deployment.

## What success looks like

A completed capture shows:

- the request groups that persisted option mutations;
- human-readable fields when a tested adapter knows them;
- probable credentials as removed, never as plaintext history;
- technical or unknown writes separated from the decision set;
- an undo control only where the stored evidence and current site state permit it.

Continue with [Record a task](/guide/first-capture) for the exact capture boundary, or jump to [Read the evidence](/guide/read-change) to understand a review.
