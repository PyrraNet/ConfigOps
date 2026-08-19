# Adapter contracts

An adapter adds meaning to observed evidence. It does not automatically gain permission to deploy or verify WordPress or a plugin.

The current `ConfigAdapter` contract owns four observation concerns:

- identify the component options it owns;
- classify an observed nested diff;
- name and explain known JSON Pointer paths;
- redact component-specific secrets before persistence.

Fields with `kind: reference` may also name a bounded `referenceType`. The shipped `media` resolver snapshots attachment ID, title, filename, MIME type, dimensions, and file size when available. The `content` resolver snapshots post ID, title, post type, and status; the `user` resolver stores only user ID and display name. Review adds only current availability plus media thumbnails; URLs, file contents, post bodies, excerpts, email addresses, logins, roles, and user metadata are not added. ConfigOps never creates or deletes the referenced object. Site identity media, all pinned Yoast social-image families, publisher-policy pages, analysis ignore lists, LLMs.txt page selections, and the represented-person selector use these contracts.

Two optional, capability-sized interfaces keep exceptions out of the observer: `ChangeAwareAdapter` may classify a field using the complete save diff, while `DatabaseWriteAwareAdapter` may suppress exact-version plugin housekeeping writes that have been proven non-configurational. Unknown writes remain visible.

Apply and verification will use separate capability interfaces when those engines ship. This keeps “we understand this setting” distinct from “we can safely change this plugin on another site.”

## Compatibility rules

Every adapter manifest declares a component type, exact tested version range, schema version, supported capabilities, coverage, and limitations. Observations persist the adapter ID, schema version, and installed component version. A version outside the tested range keeps its evidence but disables generic restore; an old schema is never silently reinterpreted by a newer field map.

Capability levels are deliberately small:

- `full` — covered by the pinned real-plugin contract;
- `partial` — usable with the stated boundary;
- `planned` — product direction, not a shipped feature.

## Adding an adapter

1. Extend `AbstractOptionAdapter` and declare fields against both the option name and JSON Pointer.
2. Fail closed for credentials, content data, unsupported storage, and unknown versions.
3. Classify cache, migration, counters, and timestamps as runtime only when source evidence supports it.
4. Append the adapter with the `configops_adapters` filter; do not patch the core observer.
5. Add pure schema checks, an exact-release WordPress Playground contract, and a browser flow through the plugin’s real settings screen.
6. Bump the adapter schema version whenever an existing path changes meaning.

Adding a reference resolver to an existing reference path does not change its field meaning, so it does not by itself require a schema bump. A resolver must fail in isolation and local undo must reject a previously observed target that no longer resolves.

The in-product Plugin support view is generated from these manifests, so documentation and runtime claims cannot drift into separate marketing tables.

Duplicate adapter IDs are rejected. If multiple adapters claim the same option, ConfigOps unions their secret detection but disables interpretation and restore for that mutation until ownership is unambiguous.

The shipped WordPress 7.0 Core adapter contract covers the standard single-site settings screens and local page/media references. Network Options evidence uses the generic, stricter network mutation contract and is not adapter-mapped in 0.4.1. The 4.9.0 WP Mail SMTP and 28.2 Yoast SEO browser contracts exercise bounded named Change Sessions: change a visible setting, save, stop the session, review, undo, and verify the original state in the plugin UI. A separate Core browser flow verifies automatic evidence feedback after an ordinary settings save. The lower-level exact-release contract also exercises Core settings, provider routing, less-obvious credentials, dynamic social images, LLMs.txt pages, and missing-reference refusal. Generated Core state, provider defaults, indexing tables, scheduler locks, and user-preference writes must not masquerade as intended settings. The plugin deep-field contracts use adapter schema 3; schema 2 history remains evidence but is never silently reclassified.
