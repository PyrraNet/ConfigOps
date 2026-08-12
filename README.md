<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/brand/configops-wordmark-dark.svg">
    <img src="assets/brand/configops-wordmark-light.svg" width="320" alt="ConfigOps">
  </picture>
</p>

<p align="center"><strong>Know what WordPress changed. Undo only what ConfigOps can prove.</strong></p>

<p align="center">
  v0.1.0
  &nbsp;·&nbsp; Local recorder
  &nbsp;·&nbsp; No account required
</p>

<br>

<p align="center">
  <img src=".wordpress-org/screenshot-1.png" width="1120" alt="ConfigOps reviewing real WP Mail SMTP setting changes while protecting the SMTP password">
</p>

<p align="center"><sub>An actual WP Mail SMTP capture. The password is removed before storage; seven supported settings remain undoable.</sub></p>

## One settings save, explained

Start a named capture, use WordPress normally, then stop. ConfigOps groups the writes caused by each request and separates the settings you chose from plugin housekeeping, secrets, and changes it cannot safely interpret.

```diff
WP Mail SMTP → Sender email
- admin@localhost.test
+ noreply@agency.example

WP Mail SMTP → SMTP password
- [removed before storage]
+ [removed before storage]
```

Every undo is checked against the current value first. If the website changed again, ConfigOps refuses to overwrite it. If capture evidence is incomplete, whole-capture undo is disabled rather than presented as safe.

## Support is a contract

| Integration | Tested release | Capture | Explain | Secrets | Undo |
|---|---:|:---:|:---:|:---:|:---:|
| WordPress Options API | WordPress 7.0 | Supported | Nested diff | Redacted | Conflict-checked |
| WP Mail SMTP Free | 4.9.0 | Supported | Supported | Removed | With limits |
| Yoast SEO Free | 28.2 | Supported | With limits | Removed | With limits |
| Unknown plugins | — | Options API only | Needs review | Conservative | Only when proven safe |

The exact field coverage and limitations are available inside **ConfigOps → Plugin support**. Versions outside a tested adapter range keep their evidence, but automatic undo is disabled.

## First capture

1. Install ConfigOps from WordPress.org once it is listed, or use a release ZIP provided by pyrra.
2. In WordPress, open **Plugins → Add Plugin**. Use **Upload Plugin** when installing a ZIP.
3. Activate ConfigOps and open **ConfigOps** in the admin menu.
4. Name the capture, record one settings change, then stop and review it.

Requires WordPress 7.0 or newer and PHP 8.3 or newer.

> **Technical preview:** v0.1 is a local, single-site configuration recorder—not a backup or a promise of transactional rollback. It does not deploy content, synchronize databases, manage fleets, or generically understand custom plugin tables.

## Safety model

- probable credentials are redacted before mutation history is written;
- capture evidence stays in the website database and is not sent to pyrra or another service;
- direct custom-table writes produce value-free warnings, never stored SQL;
- restore operations are serialized, audited, conflict-checked, and compensated when possible;
- interrupted, incomplete, or version-uncertain evidence fails closed;
- completed local history is retained for 30 days by default.

Release Packs, Plans, Policies, and Drift are the direction after the recorder earns trust.

## Development

```bash
npm install
npm test
npm run dev
```

The recorder is PHP because it observes the WordPress hook lifecycle. The review interface is made of route-specific React islands, with React supplied by WordPress. ConfigOps-owned JavaScript is held below a 24 KiB gzip release budget and shipped in human-readable form.

Architecture decisions: [capture and storage](docs/architecture.md) · [React islands](docs/frontend.md) · [adapter contracts](docs/adapters.md)

Created by **pyrra**. Licensed under [GPL-2.0-or-later](LICENSE).
