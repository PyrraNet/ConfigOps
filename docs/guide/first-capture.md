---
title: Record a task
description: Understand exactly what an active ConfigOps capture observes and what it excludes.
---

# Record a task

A capture is a named time boundary around ordinary WordPress administration. ConfigOps observes Options API mutations while that boundary is active and groups persisted evidence by request.

## Choose the boundary first

Good capture names describe one operator intent:

- `Set WP Mail SMTP sender identity`
- `Disable Yoast author archives`
- `Change WordPress discussion defaults`

Names such as `Site work` or `Fix settings` make later review harder because they do not provide a useful expectation to compare with the evidence.

## During recording

Use the target settings screen normally. A single save can legitimately cause several option writes: the intended setting, a cache marker, a migration flag, or another plugin side effect. ConfigOps keeps these writes visible instead of pretending every mutation was your decision.

The recorder captures server-side persistence. Browser observations can add the names and visible labels of touched fields, but those observations:

- contain no field values;
- expire quickly and are bounded in size;
- explain likely intent only;
- never turn an unknown field into adapter-trusted evidence;
- never expand undo permission.

## Requests and background work

Each server request receives a local request ID. Consecutive writes by the same owner to the same option within that request are collapsed from the original baseline to the final result; a complete revert disappears from the final decision set.

An active capture is site-wide, not confined to one browser tab. Another administrator or a background request can write options during the same window. Source and actor evidence help review those writes, but correlation is not certainty. Keep the window short and inspect unexpected request groups before undoing anything.

Cron and known runtime state are normally classified as technical noise. Unknown direct custom-table writes can produce value-free warnings, but ConfigOps does not read or store their SQL or values.

## Stop is part of the safety contract

Stopping moves the capture through a finalization boundary while summaries are verified. A clean result becomes **completed**. Deactivation, late evidence, or a failed finalization leaves a durable **interrupted** or incomplete state.

An incomplete capture can still be useful for investigation. It cannot be presented as a complete settings decision, and whole-capture undo stays unavailable.

## Production checklist

- Verify that a restorable backup exists before consequential work.
- Prefer staging for mail, authentication, URL, caching, or indexing changes.
- Record one operator task at a time.
- Avoid plugin updates and deployments inside the capture window.
- Stop immediately after the settings save finishes.
- Treat an unexpected actor, request path, or unmanaged-write signal as a reason to investigate.

Next: [Read the evidence](/guide/read-change).
