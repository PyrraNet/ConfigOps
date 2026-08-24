=== ConfigOps – Agent-Ready Settings Undo ===
Contributors: pyrra
Tags: settings, rollback, wp-cli, automation, ai
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 0.5.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Record WordPress settings writes, inspect redacted diffs, and undo values that still match. Agents can read evidence and validate restore plans.

== Description ==

= The undo button WordPress forgot =

WordPress shows you the settings form. ConfigOps shows you what the save actually changed.

= Agent-ready. Human-authorized. =

WordPress Abilities and machine-readable JSON WP-CLI commands let an authorized tool list recorded changes, inspect redacted diffs, control named Change Sessions, and run the restore checks without writing. Compatible MCP adapters can expose the same abilities as agent tools.

Automation gets evidence and a plan. Humans retain write authority. ConfigOps exposes no generic option writer, raw SQL tool, plugin installer, or automated restore apply. The current agent boundary can produce a conflict-checked restore plan, but a human remains responsible for the actual undo.

Visit the [ConfigOps website](https://configops.pyrra.net/) or read the [documentation](https://configops.pyrra.net/docs/).

Change a supported WordPress or plugin setting as usual. ConfigOps automatically opens an isolated observation for that save, groups the resulting writes, and reduces repeated writes to the same option into the original-to-final change.

A compact evidence card states how many values WordPress wrote, separates likely decisions from housekeeping, and links to the stored diff. Whole-save Undo appears only when every recorded value is restorable and still passes the conflict check.

Version 0.5 adds opt-in verified key undo for ordinary associative plugin settings even when ConfigOps has no dedicated adapter. It restores only snapshot-verified keys that still match and preserves unrelated later changes.

One action. The hidden writes behind it. A clear diff. A conflict-checked undo.

= What a settings save actually wrote =

ConfigOps is not a generic activity log. It does more than report that somebody clicked Save: it records the supported Options API writes caused by the request, separates likely decisions from plugin housekeeping, and attributes the responsible component and code path where possible.

ConfigOps is not a backup. It restores only supported setting values that still match the recorded state, so a later legitimate change is not silently overwritten.

ConfigOps is not plugin-version rollback. It works with configuration values, not plugin or theme code.

= What ConfigOps records and checks =

* Agent-ready discovery through the native WordPress Abilities API.
* Machine-readable JSON `wp configops` commands designed for scripts and language-model tool use.
* Read-only restore planning that checks scope, conflicts, references, autoload state, filtered option reads, adapters, and verified generic-array paths without writing.
* Automatic local evidence for authorized settings changes made through WordPress admin, REST, and WP-CLI requests.
* Plain-language nested diffs that turn option arrays into recognizable settings.
* An immediate link to the recorded diff and whole-save Undo only when every value passes the restore policy.
* Named Change Sessions for planned maintenance, support cases, and investigations that span several requests.
* Provenance for the user, request, component, and code path where ConfigOps can determine it.
* Secret redaction before mutation history is stored.
* Conflict checks before every restore.
* Isolated site evidence across network-active WordPress Multisite installations.
* Multi-Network boundaries tied to each site's real network ownership, including fail-closed foreign-network writes and lifecycle switches.
* A separate Network Admin ledger for Network Options changes.
* Named Network Change Sessions for planned work that spans several Network Admin requests.
* Mutation-level undo for complete Network Options additions and updates.
* Optional experimental undo for verified keys in ordinary, unclaimed associative wp_options arrays.

= Safety before convenience =

Probable credentials are removed before mutation history is stored. Undo first checks that the current value still matches the observed value. An interrupted or incomplete observation loses whole-save undo instead of pretending its evidence is safe.

Direct writes to custom plugin tables are recorded as value-free warnings. ConfigOps does not store raw SQL and does not claim it can reverse data it does not understand.

Site icons, site logos, and supported Yoast logo and social-image settings show the referenced attachment name, file type, dimensions, thumbnail, and missing state. Yoast publisher-policy, content-ignore, and LLMs.txt page IDs show bounded page identity instead of a bare database ID. ConfigOps never copies or deletes referenced media or content.

All evidence remains in the website database. ConfigOps does not send observation data to pyrra or another external service. Suggested disclosure text is added to WordPress's privacy-policy guide.

= Tested component contracts =

The current release includes pinned adapters for:

* WordPress Core 7.0–7.1
* WP Mail SMTP Free 4.7–4.9
* Yoast SEO Free 28.1–28.3
* WooCommerce 10.3, 10.7, 10.9, and 11.0 core settings

These plugin ranges cover every version line that the official WordPress.org usage API exposed separately on 2026-08-24. CI rechecks that list and fails when a newly visible line has no real-plugin contract. WordPress.org combines the remaining installations under "other" without disclosing their versions, so that bucket is not advertised as verified support.

The **Support contracts** screen lists each tested plugin version, mapped settings family, refused operation, and undo level. The WooCommerce contract covers core Options API settings; orders, products, tax-rate tables, shipping zones, webhooks, extension gateways, and scheduled jobs are not rolled back. An untested component version keeps its observed evidence but disables automatic undo.

= Multisite in version 0.5 =

On a network-active installation, each site keeps its own isolated evidence lifecycle. On Multi-Network installations, ConfigOps derives the network from the actual site record after every internal context switch, refuses lifecycle work that crosses networks, and excludes foreign Network Options writes from the current network ledger. An affected open capture is marked incomplete instead of silently appearing trustworthy. Network Admin receives a separate network-wide ledger for supported Network Options API changes, with guarded mutation-level undo for complete additions and updates.

Network option deletes remain review-only because WordPress reports them after the previous value is gone. Named Network Change Sessions can group network-owned evidence, but whole-network-change undo, cross-site aggregation, and bulk operations are not available.

Version 0.5 is a local single-site and Multisite undo and evidence layer for supported WordPress settings. It is not a staging plugin, backup, content migration, database synchronization, fleet manager, or generic activity log.

== Installation ==

1. Upload the ConfigOps ZIP through Plugins > Add Plugin > Upload Plugin.
2. Activate ConfigOps.
3. Change one WordPress or plugin setting as usual.
4. Use the ConfigOps evidence card to review the hidden writes or undo a save whose recorded values all pass the restore policy.
5. For a multi-request task, open ConfigOps and start a named Change Session.

For Multisite, network-activate ConfigOps to initialize and maintain isolated site ledgers. Super administrators can review supported Network Options changes from **Network Admin → ConfigOps**.

== Frequently Asked Questions ==

= Does ConfigOps observe custom plugin tables? =

Not generically. ConfigOps records a value-free warning when it observes a direct database write, but understanding or reversing a custom table requires an explicit adapter.

= Is ConfigOps an activity log, backup, or plugin rollback tool? =

No. An activity log records events, a backup restores a broad site state, and plugin rollback replaces code. ConfigOps explains the supported setting writes behind a save and restores only values that still pass its safety checks.

= What does ConfigOps record automatically? =

ConfigOps observes supported Options API mutations in authorized WordPress admin, REST, and WP-CLI requests. It does not record anonymous front-end traffic as settings evidence. Named Change Sessions are available when you want to group several requests into one investigation.

WP-CLI's optional --user parameter is not required for observation. A shell-authorized command without a WordPress user is recorded with actor ID 0. If another plugin virtualizes a site or network target through an Options API read filter, ConfigOps refuses undo before writing instead of treating the virtual value as stored database state.

= Does ConfigOps support WordPress Multisite? =

Yes. Version 0.5 supports network activation, isolated per-site evidence, lifecycle and retention across sites, Multi-Network ownership boundaries, and a separate Network Admin ledger for supported Network Options changes. Named Network Change Sessions can group a planned task, and complete network additions and updates can be undone one mutation at a time. Network deletes, whole-network-change undo, cross-site aggregation, and bulk actions are not supported.

= Can automation tools operate ConfigOps? =

Yes. ConfigOps is agent-ready through site-scoped native WordPress Abilities and machine-readable JSON WP-CLI commands for authenticated state, capture and mutation reads, named capture control, and read-only restore planning. The same discoverable abilities can be exposed by compatible MCP adapters.

Use a dedicated least-privilege WordPress service user. Mutation evidence may contain non-secret configuration values, even though probable credentials are removed before storage. Automated restore apply and generic option-writing tools are not available.

Example commands:

`wp --user=configops-agent configops state`

`wp --user=configops-agent configops captures list --limit=20`

`wp --user=configops-agent configops restore plan --mutation=842`

= Can ConfigOps undo an array without a plugin adapter? =

Complete generic Options API values already use exact current-value checks. Site administrators can additionally enable **Verified key undo for plugin arrays** under Support contracts. For unclaimed associative wp_options updates, it cross-checks the complete patch against both typed snapshots, reverses only captured target keys, and preserves unrelated later keys. Current adapter ownership and current parent shapes are checked again before apply. It refuses secrets, root replacements, integer-keyed parent arrays, list-index edits, redacted or truncated evidence, malformed or overlapping paths, autoload drift, adapter-owned options, and any target key that changed again.

= Are secrets stored in the mutation history? =

Probable secret fields and options are replaced before persistence. Supported adapters may undo neighboring non-secret fields while preserving the current credential. ConfigOps never reconstructs a redacted secret.

= Is rollback guaranteed? =

No. ConfigOps restores supported Options API values after a conflict check. Side effects in files, caches, remote services, or custom tables may remain. The interface states when undo is limited or unavailable.

If earlier referenced media or content has since been deleted or moved to the trash, ConfigOps refuses to restore its local ID.

= What happens when observation storage fails? =

The host settings request is allowed to finish. ConfigOps marks the observation incomplete through a value-free emergency marker and disables whole-save undo after storage recovers.

= How long is local history kept? =

While ConfigOps is active, completed and interrupted observations are kept for 30 days by default. Cleanup is bounded and never selects an active Change Session. Developers may change the window with the `configops_retention_days` filter. Uninstalling ConfigOps removes its observation history, installation options, scheduled cleanup, and capabilities.

= How do I report a security issue? =

Email felix@pyrra.net. Do not post credentials, configuration values, database exports, or customer data in a public support thread.

== Upgrade Notice ==

= 0.5.1 =

Adds agent-readable WordPress Abilities, JSON WP-CLI commands, and read-only restore planning. Restore apply remains a human action in wp-admin.

= 0.5.0 =

Verified key undo beyond dedicated adapters is available as an opt-in site setting under ConfigOps → Support contracts. It remains off by default; test the owning plugin's save and undo path in staging before enabling it in production.

== Screenshots ==

1. Save a supported setting normally and ConfigOps shows the recorded writes, likely decision, housekeeping, **Review writes**, and conflict-checked **Undo save**.
2. See the supported WP Mail SMTP decisions behind one save while the changed SMTP password is removed before storage.
3. See one Yoast SEO toggle as one understandable XML sitemaps decision with its conflict-checked Undo action.
4. Review a real Network Settings change in Network Admin with explicit network-wide scope, add/update undo boundaries, and guarded mutation undo.
5. Enable verified key undo for ordinary plugin settings arrays without a dedicated adapter and review the exact structures it refuses.

== Changelog ==

= 0.5.1 =

* Adds eight site-scoped WordPress Abilities for state, evidence discovery, named Change Sessions, and read-only restore planning.
* Adds machine-readable JSON `wp configops` commands with the same capability checks and bounded response contracts.
* Keeps restore apply human-authorized: automation can inspect evidence and validate a plan but cannot write settings.
* Records WP-CLI observations without `--user` as shell-authorized actor ID 0 instead of silently dropping them.
* Refuses site and network undo before writing when a `pre_*` filter or path-relevant missing-row default virtualizes the target, without falsely blocking normal post-read transforms such as Yoast's `option_wpseo`.
* Avoids synchronous all-site traversal on large-network activation and provisions existing sites lazily.

= 0.5.0 =

* Adds opt-in verified key undo for ordinary plugin settings arrays without requiring a dedicated ConfigOps adapter.
* Preserves unrelated later keys, rechecks current structure and adapter ownership, and refuses the complete patch when snapshots, target paths, secrets, autoload state, or ownership fail the restore policy.
* Adds named Network Change Sessions for planned Network Admin work without enabling unsafe whole-network-change undo.
* Makes named-session pointer release owner-conditional across site and network scopes and prevents activation races from resurrecting or orphaning sessions.
* Serializes site and network retention with restore so cleanup cannot remove evidence from an in-flight undo.

= 0.4.3 =

* Adds a tested WordPress 7.1 Core compatibility contract for single-site and Multisite settings evidence, review, and guarded undo.
* Extends the Core adapter through WordPress 7.1 while continuing to fail closed for untested WordPress 7.2 releases.
* Adds permanent WordPress 7.1 release-candidate lanes across PHP, exact adapters, browser flows, MySQL, and MariaDB.

= 0.4.2 =

* Adds a guided WordPress Playground preview that opens WP Mail SMTP with ConfigOps active and ready to observe one sender-email change.
* Adds an end-to-end release gate for the public demo's direct landing, focused Evidence Card, Review, and conflict-checked Undo flow.
* Keeps runtime behavior and the version 0.4 Multisite support contract unchanged.

= 0.4.1 =

* Adds visible links to the ConfigOps website and documentation on the WordPress.org plugin page.
* Keeps the runtime behavior and Multisite support contract unchanged from 0.4.0.

= 0.4.0 =

* Adds network activation with isolated evidence, capabilities, retention, migration, site deletion, deactivation, and uninstall lifecycle across WordPress Multisite.
* Adds automatic Network Options evidence in a dedicated Network Admin ledger.
* Adds conflict-checked, mutation-level undo for complete Network Options additions and updates, with locking, audit records, verification, and compensation.
* Keeps network deletes, lifecycle and authority state, named network sessions, whole-change undo, cross-site aggregation, and bulk operations explicitly unavailable.
* Includes the navigation-safe evidence delivery and maintainability hardening developed after 0.3.0.

= 0.3.1 =

* Prevents automatic evidence cards from being lost when a settings save navigates between admin requests.
* Consolidates shared command, adapter, request-evidence, pagination, browser-test, and CI workflows.
* Moves the public plugin website to configops.pyrra.net and documentation to configops.pyrra.net/docs/.

= 0.3.0 =

* Records authorized settings saves automatically in isolated request-local observations.
* Shows immediate evidence with write, decision, technical, and protected-secret counts plus direct review and conflict-checked undo actions.
* Keeps named Change Sessions as the focused mode for planned multi-request work.
* Preserves automatic observations and named Change Sessions as distinct modes through the schema, review, retention, and uninstall lifecycle.
* Repositions ConfigOps as the undo and evidence layer for WordPress settings with a new directory title, description, and artwork.

= 0.2.0 =

* Supports PHP 8.2 through 8.5 with full minimum-runtime and endpoint browser contracts.
* Enforces 70% production and 75% trust-boundary PHP line coverage in CI.
* Adds hostile-input, warning/deprecation, dependency-advisory, and runtime-lifecycle gates.
* Builds a byte-reproducible release archive before WordPress Plugin Check.
* Publishes a complete operator, safety, support, and development documentation site through GitHub Pages.

= 0.1.0 =

* First technical preview by pyrra.
* Explicit local captures with request grouping and source attribution.
* Local intent correlation matches touched admin-field names and labels to saved option paths without reading field values or expanding undo permissions.
* Same-request option-write chains collapse to their original and final state; complete same-owner reverts disappear from review.
* Typed nested diffs, conservative noise classification, and secret redaction before persistence.
* A WordPress Core 7.0 settings contract for General, Writing, Reading, Discussion, Media, and Permalink settings.
* Conflict-checked field and session undo with value-free audit records and compensating recovery.
* Exact deep-field contracts for every bundled WP Mail SMTP Free 4.9.0 mailer and the Yoast SEO Free 28.2 feature, crawl, schema, search, social, and LLMs.txt families.
* Media and content identity review for WordPress site icons/logos and supported Yoast image/page fields, including missing-target undo protection.
* Fail-closed capture finalization, schema recovery, bounded 30-day retention, and integrity warnings.
* Performance-budgeted React review interface with responsive and keyboard-tested states.
