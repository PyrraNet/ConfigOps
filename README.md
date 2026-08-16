<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/brand/configops-wordmark-dark.svg">
    <img src="assets/brand/configops-wordmark-light.svg" width="320" alt="ConfigOps">
  </picture>
</p>

<p align="center"><strong>The undo layer for WordPress settings.</strong></p>

<p align="center">
  v0.3.0
  &nbsp;·&nbsp; Automatic observation
  &nbsp;·&nbsp; No account required
</p>

<p align="center">
  <a href="https://pyrranet.github.io/ConfigOps/">Read the operations &amp; safety docs</a>
</p>

<br>

<p align="center">
  <img src=".wordpress-org/screenshot-1.png" width="1120" alt="ConfigOps automatically showing observed writes, one likely decision, housekeeping, Review, and Undo after a normal WordPress settings save">
</p>

<p align="center"><sub>Save normally. ConfigOps appears with the evidence: observed writes, the likely decision, housekeeping, Review, and safe Undo.</sub></p>

## The undo button WordPress forgot

WordPress shows you the settings form. ConfigOps shows you what the save actually changed.

Change a supported WordPress or plugin setting as usual. ConfigOps opens one isolated observation for that request, groups its writes, collapses repeated same-owner writes to one option into the original-to-final decision, and separates the settings you chose from plugin housekeeping, secrets, and changes it cannot safely interpret. The resulting evidence card links to Review and offers whole-save Undo only when the complete change is safe.

**One action → hidden writes → a clear diff → conflict-checked undo.**

Named Change Sessions remain the focused mode for planned maintenance, support cases, and investigations that span several requests.

```diff
WP Mail SMTP → Sender email
- admin@localhost.test
+ noreply@agency.example

WP Mail SMTP → SMTP password
- [removed before storage]
+ [removed before storage]
```

Every undo is checked against the current value first. If the website changed again, ConfigOps refuses to overwrite it. If observation evidence is incomplete, whole-save undo is disabled rather than presented as safe.

## Support is a contract

| Integration | Tested release | Observe | Explain | Secrets | Undo |
|---|---:|:---:|:---:|:---:|:---:|
| WordPress Core | WordPress 7.0 | Supported | Field map + local references | Redacted | With limits |
| WP Mail SMTP Free | 4.9.0 | Supported | Supported | Removed | With limits |
| Yoast SEO Free | 28.2 | Supported | Supported | Removed | With limits |
| Unknown plugins | — | Options API only | Needs review | Conservative | Only when proven safe |

The exact field coverage and limitations are available inside **ConfigOps → Plugin support**. Versions outside a tested adapter range keep their evidence, but automatic undo is disabled.

## First change

1. Install ConfigOps from WordPress.org, or use a release ZIP provided by pyrra.
2. In WordPress, open **Plugins → Add Plugin**. Use **Upload Plugin** when installing a ZIP.
3. Activate ConfigOps, change one WordPress or plugin setting, and save it.
4. Use the ConfigOps evidence card to review the writes or undo a fully safe save. Open **ConfigOps** to start a named Change Session for wider work.

Requires WordPress 7.0 or newer and PHP 8.2 or newer. PHP 8.2 is the oldest supported runtime; production sites should prefer a newer actively supported PHP branch.

> **Version 0.3 scope:** ConfigOps is a local, single-site undo and evidence layer for supported settings—not a backup or a promise of transactional rollback. It does not deploy content, synchronize databases, manage fleets, or generically understand custom plugin tables.

## Safety model

- probable credentials are redacted before mutation history is written;
- touched admin fields are correlated locally using names and visible labels only; the observer never reads their configuration values and its evidence never expands undo permissions;
- observation evidence stays in the website database and is not sent to pyrra or another service;
- direct custom-table writes produce value-free warnings, never stored SQL;
- site icons, site logos, every supported Yoast social-image ID, publisher-policy page, content-ignore entry, LLMs.txt page, and represented-person selector retain bounded local identity; referenced objects are never copied or deleted;
- restore operations are serialized, audited, conflict-checked, and compensated when possible;
- interrupted, incomplete, or version-uncertain evidence fails closed;
- while ConfigOps is active, completed local history is retained for 30 days by default;
- uninstalling ConfigOps removes its observation history, installation options, scheduled cleanup, and capabilities.

Release Packs, Plans, Policies, and Drift are the direction after the recorder earns trust.

## Development

For a persistent local WordPress installation with MariaDB, WP-CLI, Query Monitor,
Xdebug, Mailpit, and the supported WP Mail SMTP and Yoast versions:

```bash
npm ci
npm run wp:start
```

Open `http://localhost:8888/wp-admin/` and sign in with `admin` / `password`.
The repository is mounted directly as the active `configops` plugin, so PHP changes
are available immediately. Rebuild UI changes with `npm run build:ui`.

Useful commands:

```bash
npm run test:adversarial            # Hostile cookies, payloads, secrets, and value shapes
npm run test:minimum                # Full PHP path on the oldest supported PHP 8.2 runtime
npm run test:coverage               # Fails below 70% overall or 75% trust-boundary line coverage
npm run test:docs                   # Build and browser-check every documentation route
npm run wp:cli -- plugin list       # Run WP-CLI in the container
npm run wp:debug-log                # Follow wp-content/debug.log
npm run wp:logs                     # Follow Apache/PHP container output
npm run wp:stop                     # Keep data, stop containers
npm run wp:down                     # Keep data, remove containers
npm run wp:reset                    # Delete the local database/site and recreate it
```

Xdebug listens on port `9003` and uses the server path
`/var/www/html/wp-content/plugins/configops`. It starts only when an IDE/browser
debug trigger is present. Start the IDE listener and add `?XDEBUG_TRIGGER=1` to a
request to debug it. Copy `.env.example` to `.env` to change host ports or set
`XDEBUG_MODE=off`. MariaDB is reachable on `127.0.0.1:3307` with database/user/
password `wordpress` / `configops` / `configops`.

Captured development mail is available at `http://localhost:8025`. To exercise the
WP Mail SMTP adapter, select its **Other SMTP** mailer and use host `mailpit`, port
`1025`, no encryption, and no authentication.

For a disposable, in-memory WordPress Playground instead:

```bash
npm install
npm test
npm run dev
```

The recorder is PHP because it observes the WordPress hook lifecycle. The review interface is made of route-specific React islands, with React supplied by WordPress. ConfigOps-owned JavaScript is held below a 24 KiB gzip release budget and shipped in human-readable form.

Documentation: [operations & safety](https://pyrranet.github.io/ConfigOps/) · [observation and storage](docs/architecture.md) · [test and coverage evidence](docs/testing.md) · [React islands](docs/frontend.md) · [adapter contracts](docs/adapters.md)

Created by **pyrra**. Licensed under [GPL-2.0-or-later](LICENSE).
