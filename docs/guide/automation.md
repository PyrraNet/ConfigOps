---
title: Automation & agents
description: Read ConfigOps evidence, control named captures, and validate restores through WordPress Abilities or JSON WP-CLI commands.
---

# Automation & agents

**Agent-ready. Human-authorized.** A script or language-model agent can inspect recorded changes, start or stop a named site capture, and validate one mutation restore. It cannot apply that restore. The canonical contract is the native [WordPress Abilities API](https://developer.wordpress.org/apis/abilities-api/); WP-CLI calls the same registered abilities. ConfigOps contains no model, outbound model connection, generic option writer, or separate service.

## Available abilities

| Ability | Capability | Effect |
| --- | --- | --- |
| `configops/get-state` | `configops_view` | Value-free current scope and active-capture state |
| `configops/list-captures` | `configops_view` | Bounded capture summaries |
| `configops/get-capture` | `configops_view` | One capture and aggregate counts |
| `configops/list-mutations` | `configops_view` | One bounded page of presented evidence |
| `configops/inspect-mutation` | `configops_view` | One presented mutation and its redacted diff |
| `configops/plan-restore` | `configops_plan` | Read-only restore validation |
| `configops/start-capture` | `configops_capture` | Start a named site Change Session |
| `configops/stop-capture` | `configops_capture` | Finalize the active named site Change Session |

Every successful operation returns a versioned envelope with `schemaVersion`, `ok`, `requestId`, an immutable site/network scope, and `data`. Ability inputs reject undeclared properties and have bounded IDs, cursors, names, and page sizes. Read-only, destructive, and idempotent annotations describe each operation for compatible clients.

Mutation reads can contain configuration evidence. Probable secrets are still removed before storage, but an authorized caller may receive other before/after values. Connect an external agent only when its data-handling policy is appropriate for the site.

## WP-CLI

Use an explicit WordPress service user. The shell remains the authority for ordinary WP-CLI observation without `--user`, but ConfigOps operations require a WordPress identity so capabilities and attribution remain unambiguous.

```bash
wp --user=configops-agent configops state
wp --user=configops-agent configops captures list --limit=20
wp --user=configops-agent configops capture get --id=42
wp --user=configops-agent configops mutations list --capture=42 --after=0 --limit=25
wp --user=configops-agent configops mutation inspect --id=842
```

Named capture control uses the same domain commands as wp-admin and REST:

```bash
wp --user=configops-agent configops capture start --name="Plugin update"
# Run the intended WordPress operation.
wp --user=configops-agent configops capture stop
```

Successful commands write one JSON object to standard output. Failures use a non-zero exit and put the JSON error object after WP-CLI's standard `Error:` prefix on the error channel. Agents should use `schemaVersion`, IDs, error codes, and `retryable`; localized messages are for operators, not control flow.

## Read-only restore planning

Planning repeats the real mutation-restore preconditions without writing:

```bash
wp --user=configops-agent configops restore plan --mutation=842
```

It validates the owning site, absence of an active named capture, mutation eligibility, adapter/runtime contract, protected-field policy, local references, unfiltered Options API reads, autoload state, current value, and verified generic-array target paths where applicable. A successful response includes a state fingerprint and `requiresConfirmation: true`, but also `applySupported: false`.

A successful plan is evidence, not a lock. State may change immediately afterwards. The future apply boundary must therefore revalidate the complete plan immediately before writing, require an explicit short-lived confirmation, and remain idempotent across client retries. Until that contract ships, restores stay in the existing human-authorized wp-admin and ConfigOps REST flows.

## MCP clients

Abilities marked for authenticated REST discovery can be translated into MCP tools by a compatible adapter. The official [WordPress MCP Adapter introduction](https://developer.wordpress.org/news/2026/02/from-abilities-to-ai-agents-introducing-the-wordpress-mcp-adapter/) describes local WP-CLI STDIO and remote HTTP arrangements.

Prefer local STDIO when the agent runs beside the WordPress installation. For remote access, use HTTPS, a dedicated least-privilege WordPress user, and a revocable [Application Password](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/). Do not give the agent `configops_rollback`, `configops_apply`, or broader administrator permissions merely to read evidence or control captures.

## Current boundaries

- The automation vocabulary is site-scoped. Network Admin evidence and network restore are not exposed as abilities.
- There is no arbitrary `get_option`, `set_option`, SQL, plugin-install, or code-execution ability.
- Restore planning supports one mutation at a time and never writes.
- Restore apply, plan tokens, idempotency keys, and agent provenance are intentionally reserved for the next boundary.
- ConfigOps does not choose a task, call a model, or send evidence anywhere. A connected client initiates every request under its WordPress user.
