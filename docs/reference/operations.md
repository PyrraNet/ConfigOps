---
title: Operations
description: Production posture, storage, retention, health checks, and incident response for ConfigOps.
---

# Operations

ConfigOps is suitable for production observation only when it sits inside ordinary WordPress operational controls: tested backups, least privilege, database health monitoring, and a staging path for risky settings.

## Recommended production posture

- Let automatic request-local observations cover ordinary settings saves.
- Keep named Change Sessions short and scoped to one operator task.
- Avoid upgrades, imports, bulk jobs, and deployments during a named Change Session.
- Give `configops_rollback` to fewer people than `configops_view`.
- Treat mail, authentication, URL, indexing, cache, and integration settings as staging-first changes.
- Monitor WordPress/PHP logs and database write health.
- Verify behavior after undo; do not rely on a success badge alone.

Automatic observation is limited to authorized administrative, REST, and WP-CLI contexts and begins lazily on the first Options API mutation. Named Change Sessions observe site option writes across their active window. High traffic is not itself a problem, but a long named session creates more unrelated evidence and more ambiguity. Duration and operational scope matter more than visitor count.

## Local storage

ConfigOps uses dedicated WordPress tables for observation sessions, mutations, value-free write signals, and restore audit runs. The internal table identifiers retain `capture` for schema compatibility. Table names receive the site’s configured WordPress prefix. Evidence remains in the site database.

The REST interface is local to WordPress, capability-gated, and returns private `no-store` responses. There is no ConfigOps account, cloud collector, or remote control plane in 0.3.1.

## Retention

History retention runs daily and removes completed or interrupted observation evidence older than 30 days by default. Change the period only through site code:

```php
add_filter(
	'configops_retention_days',
	static fn (): int => 14
);
```

The value is bounded by the plugin. A failed dependent delete preserves the observation instead of leaving a falsely clean partial removal.

## Health checks

After installation or an incident, verify:

1. ConfigOps loads for an authorized administrator.
2. A small settings save creates one completed automatic change and an evidence card.
3. A named Change Session can start, stop, and reach **completed**.
4. A known setting appears with the expected actor and request path.
5. A harmless conflict test refuses undo after the setting is changed again.
6. WordPress and PHP logs contain no ConfigOps warnings, notices, or deprecations.
7. The daily `configops_history_retention` event is scheduled.

## Incident response

If a restore fails or compensation fails:

1. Stop further ConfigOps and native settings writes to the affected option.
2. Record the change ID, mutation ID, UTC time, and value-free failure code.
3. Verify current state in the owning plugin and, if appropriate, WP-CLI.
4. Check PHP, WordPress, and database logs without copying credentials into a ticket.
5. Recover through the owning plugin or a tested backup when the intended value cannot be proven.

Never paste raw database option values into a public issue. Use the private security contact in `SECURITY.md` when evidence could expose credentials or site internals.
