=== ConfigOps – Settings History, Diff & Rollback ===
Contributors: pyrra
Tags: settings, history, rollback, configuration, developer
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Record WordPress configuration changes, inspect precise nested diffs, and restore only supported values.

== Description ==

ConfigOps shows what WordPress actually changed when a setting was saved.

Start a named capture, use the WordPress admin normally, then stop. ConfigOps groups writes by request, renders nested changes with plain-language labels, separates likely plugin housekeeping, and attributes the code path that caused the change where possible.

= Honest by default =

Probable credentials are removed before mutation history is stored. Undo checks that the current value still matches the capture before writing anything. An interrupted or incomplete capture loses whole-capture undo instead of pretending its evidence is safe.

Direct writes to custom plugin tables are recorded as value-free warnings. ConfigOps does not store raw SQL and does not claim it can reverse data it does not understand.

All evidence remains in the website database. ConfigOps does not send capture data to pyrra or another external service. Suggested disclosure text is added to WordPress's privacy-policy guide.

= Tested plugin contracts =

The first release includes pinned adapters for:

* WP Mail SMTP Free 4.9.0
* Yoast SEO Free 28.2

The Plugin support screen states exactly which capabilities are supported, limited, or not available. An untested plugin version keeps its capture evidence but disables automatic undo.

= Current scope =

Version 0.1 is a local technical preview for capture, review, and conflict-checked restore. It is not a staging plugin, backup, content migration, database synchronization, fleet manager, or generic activity log.

== Installation ==

1. Upload the ConfigOps ZIP through Plugins > Add Plugin > Upload Plugin.
2. Activate ConfigOps.
3. Open ConfigOps, name a capture, and start recording.
4. Change one WordPress or plugin setting as usual.
5. Stop the capture and review the result before using any undo action.

== Frequently Asked Questions ==

= Does ConfigOps capture custom plugin tables? =

Not generically. ConfigOps records a value-free warning when it observes a direct database write, but understanding or reversing a custom table requires an explicit adapter.

= Are secrets stored in the mutation history? =

Probable secret fields and options are replaced before persistence. Supported adapters may undo neighboring non-secret fields while preserving the current credential. ConfigOps never reconstructs a redacted secret.

= Is rollback guaranteed? =

No. ConfigOps restores supported Options API values after a conflict check. Side effects in files, caches, remote services, or custom tables may remain. The interface states when undo is limited or unavailable.

= What happens when capture storage fails? =

The host settings request is allowed to finish. ConfigOps marks the recording incomplete through a value-free emergency marker and disables whole-capture undo after storage recovers.

= How long is local history kept? =

Completed and interrupted captures are kept for 30 days by default. Cleanup is bounded and never selects an active capture. Developers may change the window with the `configops_retention_days` filter.

= How do I report a security issue? =

Email felix@pyrra.net. Do not post credentials, configuration values, database exports, or customer data in a public support thread.

== Screenshots ==

1. A real WP Mail SMTP capture separates the settings a person changed from technical writes and removes the SMTP password before storage.
2. A Yoast SEO capture explains a nested XML sitemap change as a clear on-to-off decision.
3. Plugin support is published as an explicit capability contract with tested versions and known limits.
4. Incomplete evidence is shown prominently and disables whole-capture undo instead of presenting an unsafe action.

== Changelog ==

= 0.1.0 =

* First technical preview by pyrra.
* Explicit local captures with request grouping and source attribution.
* Typed nested diffs, conservative noise classification, and secret redaction before persistence.
* Conflict-checked field and session undo with value-free audit records and compensating recovery.
* Exact adapter contracts for WP Mail SMTP Free 4.9.0 and Yoast SEO Free 28.2.
* Fail-closed capture finalization, schema recovery, bounded 30-day retention, and integrity warnings.
* Performance-budgeted React review interface with responsive and keyboard-tested states.
