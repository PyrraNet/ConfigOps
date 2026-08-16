---
title: Record a change
description: Understand automatic observations, named Change Sessions, and what each boundary excludes.
---

# Record a change

Automatic recording is the default. On the first Options API mutation in an authorized administrative request, ConfigOps opens one request-local observation, records the resulting configuration writes, verifies its summary at shutdown, and closes it. A save that contains only classified technical noise is discarded instead of becoming operator history.

The completed observation appears in Change History and in a short-lived evidence card for the administrator who caused it. The card offers whole-save Undo only when every recorded setting is reconstructable, no unmanaged write was observed, the evidence is complete, and no prior undo blocks the plan.

## Use a Change Session for a wider task

A named Change Session is an explicit, sitewide time boundary around ordinary WordPress administration. Use one when planned maintenance, a support case, or an investigation spans several requests. ConfigOps observes Options API mutations while that boundary is active and groups persisted evidence by request.

## Name the wider boundary

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

An automatic observation belongs to one request. A named Change Session is site-wide and not confined to one browser tab. Another administrator or a background request can write options during that wider window. Source and actor evidence help review those writes, but correlation is not certainty. Keep named sessions short and inspect unexpected request groups before undoing anything.

Cron and known runtime state are normally classified as technical noise. Unknown direct custom-table writes can produce value-free warnings, but ConfigOps does not read or store their SQL or values.

## Stop is part of the safety contract

Automatic observations finalize at request shutdown. Stopping a named session moves it through the same verified finalization boundary. A clean result becomes **completed**. Deactivation, late evidence, or a failed finalization leaves a durable **interrupted** or incomplete state.

An incomplete capture can still be useful for investigation. It cannot be presented as a complete settings decision, and whole-capture undo stays unavailable.

## Production checklist

- Verify that a restorable backup exists before consequential work.
- Prefer staging for mail, authentication, URL, caching, or indexing changes.
- Record one operator task at a time.
- Avoid plugin updates and deployments inside the capture window.
- Stop immediately after the settings save finishes.
- Treat an unexpected actor, request path, or unmanaged-write signal as a reason to investigate.

Next: [Read the evidence](/guide/read-change).
