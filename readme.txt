=== ConfigOps – Settings History, Diff & Rollback ===
Contributors: felixhans
Tags: settings, history, rollback, configuration, developer
Requires at least: 7.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Record WordPress configuration changes, inspect precise nested diffs, and safely restore supported values.

== Description ==

ConfigOps turns an explicit settings workflow into an inspectable capture session. The technical spike records mutations made through the WordPress Options API, groups them by request, attributes likely source files, masks probable secrets before persistence, and offers conflict-checked compensating restore operations. Its admin interface uses performance-budgeted React islands over a capability-gated local REST API; PHP remains the configuration authority.

Current scope is deliberately narrow: local capture, review, and restore. ConfigOps is not a staging, backup, content migration, database sync, or generic activity-log plugin.

== Installation ==

1. Upload the `configops` directory to `/wp-content/plugins/`.
2. Activate ConfigOps through the Plugins screen.
3. Open ConfigOps, name a capture, and start recording.
4. Change a WordPress or plugin setting, then stop and review the capture.

== Frequently Asked Questions ==

= Does ConfigOps capture custom plugin tables? =

Not generically. This technical spike observes writes through the WordPress Options API. Custom tables will require explicit adapters later.

= Are secrets stored in the mutation history? =

Probable secret fields and options are replaced with a redaction marker before persistence. A redacted mutation cannot be restored automatically.

= Is rollback guaranteed? =

No. ConfigOps restores captured Options API values after a conflict check. Side effects in files, caches, remote services, or custom tables may remain.

== Changelog ==

= 0.1.0 =

* Initial technical spike for explicit capture sessions, recursive diffs, source attribution, noise classification, and restore.
