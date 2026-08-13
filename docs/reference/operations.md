---
title: Operations
description: Production posture, storage, retention, health checks, and incident response for ConfigOps.
---

# Operations

ConfigOps is suitable for production observation only when it sits inside ordinary WordPress operational controls: tested backups, least privilege, database health monitoring, and a staging path for risky settings.

## Recommended production posture

- Keep active captures short and name one operator task.
- Avoid upgrades, imports, bulk jobs, and deployments during recording.
- Give `configops_rollback` to fewer people than `configops_view`.
- Treat mail, authentication, URL, indexing, cache, and integration settings as staging-first changes.
- Monitor WordPress/PHP logs and database write health.
- Verify behavior after undo; do not rely on a success badge alone.

ConfigOps observes all site option writes while the capture is active. High traffic is not itself a problem, but a long capture creates more unrelated evidence and more ambiguity. Capture duration and operational scope matter more than visitor count.

## Local storage

The recorder uses dedicated WordPress tables for capture sessions, mutations, value-free write signals, and restore audit runs. Table names receive the site’s configured WordPress prefix. Evidence remains in the site database.

The REST interface is local to WordPress, capability-gated, and returns private `no-store` responses. There is no ConfigOps account, cloud collector, or remote control plane in 0.2.0.

## Retention

History retention runs daily and removes completed or interrupted capture evidence older than 30 days by default. Change the period only through site code:

```php
add_filter(
	'configops_retention_days',
	static fn (): int => 14
);
```

The value is bounded by the plugin. A failed dependent delete preserves the capture instead of leaving a falsely clean partial removal.

## Health checks

After installation or an incident, verify:

1. ConfigOps loads for an authorized administrator.
2. A small test capture can start, stop, and reach **completed**.
3. A known setting appears with the expected actor and request path.
4. A harmless conflict test refuses undo after the setting is changed again.
5. WordPress and PHP logs contain no ConfigOps warnings, notices, or deprecations.
6. The daily `configops_history_retention` event is scheduled.

## Incident response

If a restore fails or compensation fails:

1. Stop further ConfigOps and native settings writes to the affected option.
2. Record the capture ID, mutation ID, UTC time, and value-free failure code.
3. Verify current state in the owning plugin and, if appropriate, WP-CLI.
4. Check PHP, WordPress, and database logs without copying credentials into a ticket.
5. Recover through the owning plugin or a tested backup when the intended value cannot be proven.

Never paste raw database option values into a public issue. Use the private security contact in `SECURITY.md` when evidence could expose credentials or site internals.
