<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="assets/brand/configops-wordmark-dark.svg">
    <img src="assets/brand/configops-wordmark-light.svg" width="360" alt="ConfigOps">
  </picture>
</p>

<p align="center"><strong>WordPress configuration, treated as reviewable state.</strong></p>

<p align="center"><code>Capture → Changes → Packs → Plans → Policies → Drift</code></p>

ConfigOps records what WordPress actually changes when a setting is saved. It groups mutations by request, renders typed nested diffs, identifies likely noise and preserves a conflict-checked path back.

## The workflow

1. Start a named capture.
2. Change settings in WordPress as usual.
3. Review the resulting option diffs and their source.
4. Restore supported values only when the target has not changed again.

ConfigOps is not a staging tool, database migration, backup system or generic activity log.

## Current state

This repository is an early technical preview of the local recorder:

- explicit capture sessions and request correlation;
- Options API mutations with nested JSON Pointer diffs;
- value-free signals for direct database writes, without storing raw SQL;
- source attribution, conservative noise classification and secret redaction;
- individual and session restore with conflict checks;
- a performance-budgeted React review interface inside WordPress.

Release Packs, remote deployment, Policies and Drift are the product direction—not shipped features.

## Development

Requires Node.js 22+. WordPress and PHP run locally through WordPress Playground.

```bash
npm install
npm test
npm run dev
```

The recorder is PHP because it must observe the WordPress hook lifecycle directly. The interface uses independently loaded React islands, with React supplied by WordPress rather than bundled again.

Architecture decisions live in [docs/architecture.md](docs/architecture.md) and [docs/frontend.md](docs/frontend.md).

## License

GPL-2.0-or-later.
