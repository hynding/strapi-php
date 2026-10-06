# Porting notes

Each package's `README.md` carries its own ported / stubbed / skipped tables and the
deviations from upstream. This folder is for cross-cutting notes.

- [`../AGENTS.md`](../AGENTS.md) — naming conventions and code style.
- [`../VERSIONING.md`](../VERSIONING.md) — how releases track Strapi's weekly releases.
- [`../parity.json`](../parity.json) — upstream file → PHP file map; regenerate with
  `php scripts/parity-map.php --upstream=<strapi checkout>`.

## Runtime model

Strapi (Node) boots once and keeps registries, metadata, cron, webhooks and the event hub
in memory. The PHP port keeps that model: `Strapi\Core\Strapi::load()` builds everything,
`public/index.php` boots once per FrankenPHP worker and reuses it across requests, and
under PHP-FPM it boots per request (acceptable for development; a compiled-container cache
for FPM is on the roadmap). Cron under FPM runs via `php bin/strapi cron:run` from system
cron; under FrankenPHP `cron:run --loop` keeps a scheduler alive.

## Next milestones

1. `strapi/admin` server: admin users, roles, permissions, API tokens, auth routes the
   upstream admin bundle calls on boot (`/admin/init`, `/admin/project-settings`, ...).
2. `strapi/content-manager`, `strapi/content-type-builder`, `strapi/upload` server halves —
   the first version worth tagging `5.56.0-beta.1`.
3. `strapi/plugin-users-permissions`, `strapi/plugin-i18n`, then `data-transfer` and `graphql`.
