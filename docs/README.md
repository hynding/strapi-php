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

1. ~~`strapi/admin` server~~ and ~~`strapi/content-manager`, `strapi/content-type-builder`,
   `strapi/upload` server halves~~: done (milestone 1); see the status table in the root
   README for the upstream API suite results.
2. `strapi/i18n` and `strapi/plugin-users-permissions` (most remaining API-suite
   failures in core and the content manager need them), `strapi/email` + providers, the other
   upload providers. Then tag `5.56.0-beta.1`.
3. `data-transfer`, `graphql`, `documentation` + `openapi`, `content-releases`, `generators`,
   `create-strapi-app`, `upgrade`, `sentry`, `color-picker`.

Fixed gaps:
- Request bodies: JSON `{}` and `[]` used to both decode to a PHP `[]`, so validation could not
  tell an object from an array (components). A `{}` is now kept as a `Strapi\Utils\EmptyObject`
  for the readers that ask for it. See [`empty-json-objects.md`](empty-json-objects.md).
- Yup array validation on `min`/`max` component sets: profiled, and it scales linearly
  (entity validator, 1280 repeatable items with nested components ≈ 0.55 s, 2560 ≈ 1.1 s).
  Each request in the `*-min-max` component suites takes under 100 ms. `max_execution_time = 0`
  in tests/api is there for worker startup (php/strapi.ini), not for validation.

Known gaps worth their own fix:
- A JSON `{}` is read back as `{}` only for content `json` attributes (`api::` content types,
  components). Internal JSON columns (admin permissions, history versions...) still read it as `[]`.
