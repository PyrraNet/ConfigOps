# Test and coverage evidence

ConfigOps treats coverage as a release floor, not as a safety claim. CI fails when the merged PHP line coverage for tracked production files under `src/**/*.php` is below 70%. Access control, API, observation, persistence, locking, retention, Multisite scoping, references, release, and restore code has a separate 75% aggregate floor, so well-tested presentation code cannot hide weak trust boundaries.

Run the same isolated gate locally:

```bash
npm run test:coverage
```

The command builds a pinned WordPress 7.0 / PHP 8.3 container with Xdebug, starts a temporary MariaDB 11.4 database, installs the exact supported WP Mail SMTP 4.9.0 and Yoast SEO 28.2 releases, and then merges five independent fragments:

- unit and deterministic fuzz checks;
- adversarial input and hostile value-shape checks;
- 272 WordPress observation, persistence, concurrency, privacy, retention, and restore integration assertions, including generic array eligibility, add/remove/replace reversal, sibling preservation, target conflicts, current-parent type safety, current adapter ownership, malformed evidence, list refusal, post-write autoload compensation, REST authorization, pointer ownership races, and restore/retention exclusion;
- real adapter-contract checks;
- network-active Multisite and Multi-Network observation, mutation undo, audit, locking, REST authorization, scope isolation, retention, lifecycle, migration, site-deletion, and uninstall checks.

The source manifest comes from Git-tracked PHP files. This keeps unrelated local work out of a published metric, while ensuring every production file joins the gate as soon as it is committed. The collector loads all production declarations with Xdebug's unused- and dead-code analysis enabled, so files or branches a test never reaches remain uncovered instead of silently disappearing.

Generated evidence is written to the ignored `coverage/` directory:

- `summary.json` contains the total and per-file results;
- `lcov.info` is suitable for line-oriented coverage tools;
- `clover.xml` is suitable for CI and quality dashboards;
- `raw/*.json` preserves the independently collected fragments.

The database and WordPress volume are destroyed after the run unless `CONFIGOPS_COVERAGE_KEEP=1` is set for debugging.

Every PHP behavioral suite installs a production error trap before ConfigOps loads. Warnings, notices, and deprecations originating in the plugin bootstrap, `src/`, or templates become hard failures on every tested runtime instead of being buried in a log.

## Runtime and platform matrix

PHP 8.2 is the oldest supported runtime. The full parser, unit/fuzz, hostile-input, and WordPress integration suites run on PHP 8.2, 8.3, 8.4, and 8.5. Exact WP Mail SMTP and Yoast contracts plus their browser save/review/undo flows run at both ends of that range. Native MySQL and MariaDB jobs split the minimum and maximum PHP versions, while the Xdebug evidence remains pinned to PHP 8.3 for reproducibility. A locked PHPCompatibilityWP scan independently inspects every PHP file for 8.2–8.5 syntax and API hazards. Composer and npm advisory audits reject known high-impact vulnerabilities in test and build tooling.

A separate real-Multisite contract network-activates ConfigOps, creates multiple WordPress sites, and creates a second network where the plugin is inactive. On every supported PHP/WordPress matrix entry, it proves that new sites in the active network receive their local schema marker, capabilities, and retention schedule while the inactive sibling network remains untouched; switched-site identity follows the site's actual network record; mismatched lifecycle work is refused and unwound; foreign Network Options writes succeed without leaking values into origin evidence; and an affected origin capture becomes incomplete exactly once. Normal site-local repositories persist independent captures with the same option name in shared tables, reject cross-site reads and restore attempts, and preserve their network/blog identities. The suite also exercises real Network Options API add, update, and delete calls; atomic named-network-session start, concurrent refusal, write ownership, verified stop, and authorization; mutation-level addition and update undo; compensated post-write failure; delete and plugin-lifecycle refusal; current-value conflicts; stale and active network locks; scoped value-free audits; reserved blog-ID-zero storage; isolated network state; finalization; retention; deactivation; and uninstall cleanup. It verifies collision-safe, idempotent migration from schema v9, removal of shared and legacy evidence across stale former network identities when a site is deleted, and complete per-site plus shared-storage cleanup on uninstall. Run its 154 assertions locally with `npm run test:multisite`.

The `npm run test:network-visual` browser contract targets a running network-active Playground at `http://configops.test`. CI runs it on WordPress 7.1 RC4. It saves the real Network Title field, hydrates that mutation through the Network Admin REST boundary, verifies the named network-capture controls and the absence of whole-session undo, confirms the network-specific conflict warning, undoes the mutation, verifies the original title back in WordPress, and rejects runtime errors and page-level overflow at desktop and 390 px widths. The current merged Xdebug gate covers 77.24% of tracked production lines and 80.07% of trust-boundary lines.

PHP 8.1 and older are deliberately not advertised: they no longer receive upstream security fixes. A lifecycle gate expires the PHP 8.2 support claim after 2026-12-31 and forces the minimum to be reviewed and retested. The release archive is built twice in CI and both SHA-256 digests must match before Plugin Check sees it.

## What the number does not prove

Coverage is a minimum executable-line signal. It does not prove the absence of defects, validate an unknown third-party plugin, or replace production observability, backups, staging, and a rollback plan. Riskier paths therefore also have behavioral contracts for schema failures, interrupted observations, concurrent finalization, secret redaction, forged payloads, compensation after partial restore failure, direct SQL warnings, native MySQL/MariaDB behavior, exact plugin screens, and browser-level undo flows.

The real-plugin browser flow also sends a concurrent anonymous frontend burst and rejects any HTTP failure or ConfigOps asset/bootstrap leakage. This checks that the admin observer remains silent on public pages; it is intentionally not presented as a substitute for application-specific production load testing.

## Documentation gate

The documentation has its own production browser contract:

```bash
npm run test:docs
```

VitePress first renders every page with dead-link checks and the GitHub Pages base path. Playwright then opens all 23 published routes in a 1440 px light profile and a 390 px dark, reduced-motion profile. The gate rejects HTTP failures, browser console or page errors, missing or duplicate primary headings, broken images, and document-level horizontal overflow. Wide evidence and support tables may scroll inside their own bounded region; the page itself may not pan sideways.
