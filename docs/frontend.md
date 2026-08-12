# Frontend boundary: React islands, not a WordPress SPA

Status: accepted
Date: 2026-08-12

## Decision

ConfigOps uses three independent React roots inside a minimal PHP shell:

1. **Capture controls** hydrate immediately because starting and stopping are the primary actions.
2. **Sessions** hydrate when the browser is idle.
3. **Review ledger** imports only near the viewport, then requests its first mutation connection.

The shell includes real headings, product orientation, bounded placeholders, an initial capture/session snapshot, and a minimal no-script stop action. It does not serialize diff history. This keeps first paint independent from capture size and still avoids a request waterfall for the primary control.

React, React DOM, API fetching, and translations are WordPress-owned externals. ConfigOps bundles only its application code. No component framework, client router, normalized entity cache, CSS-in-JS runtime, or duplicate React build ships with the plugin.

## Brand layer

The admin UI consumes the company design system as a scoped CSS layer instead of importing the public-site stylesheet. Its Avenir Next system stack, Ink/Paper surfaces, Brand Blue action edge, control radii, spacing, focus treatment, and state colors map directly to the supplied tokens without leaking into WordPress. The original light wordmark ships as a single cached SVG; no webfont, icon library, theme runtime, or branding JavaScript is loaded.

The visual model is an evidence ledger rather than a dashboard. A compact sticky product bar leads directly into the bounded capture command; capture history and request-grouped evidence form one continuous work surface. Light surfaces carry review work, dark fields are reserved for active recording and typed diff headers, and Brand Blue marks the primary command or current selection. The system remains gradient-free.

Specialist explanations are local, not permanent prose. Capture scope, request grouping, review/noise semantics, redaction, classification reasons, and rollback limits are attached to their terms or actions as hover and keyboard-focus tooltips with accessible descriptions. On narrow screens the session rail becomes a contained horizontal scroller so history does not push the selected review below a long list or create page-level overflow.

## Why not Astro inside wp-admin

Astro's island model is correct; its page runtime boundary is not. A dynamic authenticated WordPress admin screen is already server-routed and rendered by PHP. Adding Astro as another page server or pre-rendering authority would create two routing, authentication, and deployment systems for no useful runtime reduction.

ConfigOps adopts the valuable mechanics directly: static shell, explicit hydration, independent priorities, dynamic imports, and zero JavaScript for non-interactive structure. Astro remains a good candidate for the public website, documentation, and static reports.

## Why local REST and later GraphQL

The local Agent has a small command vocabulary and predictable connection reads. WordPress REST provides same-origin nonce authentication and direct capability callbacks without another query engine. Endpoints call the same PHP domain services as server fallbacks.

GraphQL belongs later at the optional control-plane boundary, where a fleet screen composes sites, policies, drift, releases, and secrets. That schema must read asynchronously materialized fleet state; one dashboard query must never fan out synchronously to hundreds of WordPress Agents. Apply and rollback remain explicit audited commands even if a GraphQL façade is eventually added.

## Performance invariants

- critical ConfigOps loader: maximum 3 KiB gzip;
- all ConfigOps-owned JavaScript: maximum 24 KiB gzip;
- scoped admin CSS: maximum 8 KiB gzip and no gradients;
- packaged wordmark: maximum 16 KiB gzip;
- no diff data in the PHP bootstrap;
- mutation pages: maximum 25 rows and approximately 512 KiB encoded payload;
- display diff per mutation: maximum 256 KiB;
- cursor continuation instead of growing SQL offsets;
- no frontend polling while the page is idle;
- native controls, native `details`, and native browser scheduling before custom abstractions.

`npm test` rebuilds the islands and fails if the JavaScript, CSS, or wordmark budgets are exceeded.
