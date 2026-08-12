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

ConfigOps turns an explicit settings workflow into an inspectable capture session. The technical preview records mutations made through the WordPress Options API, groups them by request, attributes likely source files, masks probable secrets before persistence, and offers conflict-checked compensating undo. Its admin interface uses performance-budgeted React islands over a capability-gated local REST API; PHP remains the configuration authority.

Current scope is deliberately narrow: local capture, review, and restore. ConfigOps is not a staging, backup, content migration, database sync, or generic activity-log plugin.

== Installation ==

1. Upload the `configops` directory to `/wp-content/plugins/`.
2. Activate ConfigOps through the Plugins screen.
3. Open ConfigOps, name a capture, and start recording.
4. Change a WordPress or plugin setting, then stop and review the capture.

== Frequently Asked Questions ==

= Does ConfigOps capture custom plugin tables? =

Not generically. ConfigOps records a value-free warning when it observes a direct database write, but understanding or undoing a custom table safely requires an explicit adapter.

= Are secrets stored in the mutation history? =

Probable secret fields and options are replaced with a redaction marker before persistence. Supported adapters may undo neighboring non-secret fields while preserving the current credential; ConfigOps never reconstructs a redacted secret.

= Is rollback guaranteed? =

No. ConfigOps restores captured Options API values after a conflict check. Side effects in files, caches, remote services, or custom tables may remain.

= How long is local history kept? =

Completed and interrupted captures are kept for 30 days by default. Cleanup is bounded and never selects an active capture. Developers may change the window with the `configops_retention_days` filter.

== Changelog ==

= 0.1.0 =

* Technical preview with explicit captures, recursive diffs, source attribution, secret redaction, pinned adapters, audited undo, and bounded history retention.
