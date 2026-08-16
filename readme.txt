=== ConfigOps – Undo Settings Changes ===
Contributors: pyrra
Tags: settings, configuration, rollback, history, developer tools
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

See what a settings save changed and reverse only the values that are still safe to restore.

== Description ==

= The undo button WordPress forgot =

WordPress shows you the settings form. ConfigOps shows you what the save actually changed.

Change a supported WordPress or plugin setting as usual. ConfigOps automatically opens an isolated observation for that save, groups the resulting writes, and reduces repeated writes to the same option into the original-to-final change.

A compact evidence card then tells you how many values WordPress wrote, links directly to an understandable review, and offers whole-save Undo only when the complete change is still safe to restore.

One action. The hidden writes behind it. A clear diff. A conflict-checked undo.

= WordPress Change Intelligence =

ConfigOps is not a generic activity log. It does more than report that somebody clicked Save: it records the supported Options API writes caused by the request, separates likely decisions from plugin housekeeping, and attributes the responsible component and code path where possible.

ConfigOps is not a backup. It restores only supported setting values that still match the recorded state, so a later legitimate change is not silently overwritten.

ConfigOps is not plugin-version rollback. It works with configuration values, not plugin or theme code.

= What you get =

* Automatic local evidence for authorized settings changes made through WordPress admin, REST, and WP-CLI requests.
* Plain-language nested diffs that turn option arrays into recognizable settings.
* Immediate Review and safe Undo feedback after a settings save.
* Named Change Sessions for planned maintenance, support cases, and investigations that span several requests.
* Provenance for the user, request, component, and code path where ConfigOps can determine it.
* Secret redaction before mutation history is stored.
* Conflict checks before every restore.

= Safety before convenience =

Probable credentials are removed before mutation history is stored. Undo first checks that the current value still matches the captured value. An interrupted or incomplete observation loses whole-save undo instead of pretending its evidence is safe.

Direct writes to custom plugin tables are recorded as value-free warnings. ConfigOps does not store raw SQL and does not claim it can reverse data it does not understand.

Site icons, site logos, and supported Yoast logo and social-image settings show the referenced attachment name, file type, dimensions, thumbnail, and missing state. Yoast publisher-policy, content-ignore, and LLMs.txt page IDs show bounded page identity instead of a bare database ID. ConfigOps never copies or deletes referenced media or content.

All evidence remains in the website database. ConfigOps does not send capture data to pyrra or another external service. Suggested disclosure text is added to WordPress's privacy-policy guide.

= Tested component contracts =

The current release includes pinned adapters for:

* WordPress Core 7.0
* WP Mail SMTP Free 4.9.0
* Yoast SEO Free 28.2

The Plugin support screen states exactly which WordPress and plugin capabilities are supported, limited, or not available. An untested component version keeps its capture evidence but disables automatic undo.

= Version 0.3 scope =

Version 0.3 is a local, single-site undo and evidence layer for supported WordPress settings. It is not a staging plugin, backup, content migration, database synchronization, fleet manager, or generic activity log.

== Installation ==

1. Upload the ConfigOps ZIP through Plugins > Add Plugin > Upload Plugin.
2. Activate ConfigOps.
3. Change one WordPress or plugin setting as usual.
4. Use the ConfigOps evidence card to review the hidden writes or undo a fully safe save.
5. For a multi-request task, open ConfigOps and start a named Change Session.

== Frequently Asked Questions ==

= Does ConfigOps capture custom plugin tables? =

Not generically. ConfigOps records a value-free warning when it observes a direct database write, but understanding or reversing a custom table requires an explicit adapter.

= Is ConfigOps an activity log, backup, or plugin rollback tool? =

No. An activity log records events, a backup restores a broad site state, and plugin rollback replaces code. ConfigOps explains the supported setting writes behind a save and restores only values that still pass its safety checks.

= What does ConfigOps record automatically? =

ConfigOps observes supported Options API mutations in authorized WordPress admin, REST, and WP-CLI requests. It does not record anonymous front-end traffic as settings evidence. Named Change Sessions are available when you want to group several requests into one investigation.

= Are secrets stored in the mutation history? =

Probable secret fields and options are replaced before persistence. Supported adapters may undo neighboring non-secret fields while preserving the current credential. ConfigOps never reconstructs a redacted secret.

= Is rollback guaranteed? =

No. ConfigOps restores supported Options API values after a conflict check. Side effects in files, caches, remote services, or custom tables may remain. The interface states when undo is limited or unavailable.

If earlier referenced media or content has since been deleted or moved to the trash, ConfigOps refuses to restore its local ID.

= What happens when capture storage fails? =

The host settings request is allowed to finish. ConfigOps marks the recording incomplete through a value-free emergency marker and disables whole-capture undo after storage recovers.

= How long is local history kept? =

While ConfigOps is active, completed and interrupted captures are kept for 30 days by default. Cleanup is bounded and never selects an active capture. Developers may change the window with the `configops_retention_days` filter. Uninstalling ConfigOps removes its capture history, installation options, scheduled cleanup, and capabilities.

= How do I report a security issue? =

Email felix@pyrra.net. Do not post credentials, configuration values, database exports, or customer data in a public support thread.

== Screenshots ==

1. One WP Mail SMTP save becomes a clear before-and-after review while the changed SMTP password is removed before storage.
2. One Yoast SEO toggle becomes one understandable XML sitemaps decision with its safe undo action.
3. WordPress Core, WP Mail SMTP, and Yoast SEO support are published as explicit capability contracts with tested versions and known limits.

== Changelog ==

= 0.3.0 =

* Records authorized settings saves automatically in isolated request-local observations.
* Shows immediate evidence with write, decision, technical, and protected-secret counts plus direct Review and safe Undo actions.
* Keeps named Change Sessions as the focused mode for planned multi-request work.
* Preserves automatic and named evidence as distinct modes through the schema, review, retention, and uninstall lifecycle.
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
