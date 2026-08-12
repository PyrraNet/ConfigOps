# Adapter contracts

An adapter adds meaning to captured evidence. It does not automatically gain permission to deploy or verify a plugin.

The current `ConfigAdapter` contract owns four recorder concerns:

- identify the plugin options it owns;
- classify a captured nested diff;
- name and explain known JSON Pointer paths;
- redact plugin-specific secrets before persistence.

Two optional, capability-sized interfaces keep exceptions out of the observer: `ChangeAwareAdapter` may classify a field using the complete save diff, while `DatabaseWriteAwareAdapter` may suppress exact-version plugin housekeeping writes that have been proven non-configurational. Unknown writes remain visible.

Apply and verification will use separate capability interfaces when those engines ship. This keeps “we understand this setting” distinct from “we can safely change this plugin on another site.”

## Compatibility rules

Every adapter manifest declares an exact tested version range, schema version, supported capabilities, coverage, and limitations. Captures persist the adapter ID, schema version, and installed plugin version. A version outside the tested range keeps its evidence but disables generic restore; an old schema is never silently reinterpreted by a newer field map.

Capability levels are deliberately small:

- `full` — covered by the pinned real-plugin contract;
- `partial` — usable with the stated boundary;
- `planned` — product direction, not a shipped feature.

## Adding an adapter

1. Extend `AbstractOptionAdapter` and declare fields against both the option name and JSON Pointer.
2. Fail closed for credentials, content data, unsupported storage, and unknown versions.
3. Classify cache, migration, counters, and timestamps as runtime only when source evidence supports it.
4. Append the adapter with the `configops_adapters` filter; do not patch the capture observer.
5. Add pure schema checks, an exact-release WordPress Playground contract, and a browser flow through the plugin’s real settings screen.
6. Bump the adapter schema version whenever an existing path changes meaning.

The in-product Plugin support view is generated from these manifests, so documentation and runtime claims cannot drift into separate marketing tables.

Duplicate adapter IDs are rejected. If multiple adapters claim the same option, ConfigOps unions their secret detection but disables interpretation and restore for that mutation until ownership is unambiguous.

The shipped 4.9.0 WP Mail SMTP and 28.2 Yoast SEO browser contracts perform the same job a user does: start recording, change a visible setting, save, stop, review, undo, and verify the original state in the plugin UI. They also assert that generated provider defaults, indexing tables, scheduler locks, and user-preference writes do not masquerade as intended settings. Both field-aware contracts use adapter schema 2; schema 1 history remains evidence but is never silently reclassified.
