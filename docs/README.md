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

## Status

Every non-Enterprise upstream package is ported (see the root README's table and
`parity.json`). Upstream's API suite, run with `cd tests/api && npm test` (tests/api/README.md):
3,183 of 3,389 tests pass (93.9%) at 5.56.0.

## Open work

Ordered by tests unlocked per effort. Counts are from a full `npm test` run; rerun it before
starting and after finishing (`/api-tests` in Claude Code).

**Release**
- Tag `v5.56.0-beta.1`, then submit `hynding/strapi-php` and `hynding/strapi-php-create-strapi-app`
  on packagist.org with their GitHub hooks (README "Install"; split.yml fills the second repo).
  Delete this item once done.

**API suite failures (206), by cause**
1. **A check upstream doesn't have (60 tests).** `Repository::publish()/unpublish()/discardDraft()`
   throw `Cannot <action> a document without a documentId`
   (packages/core/core/src/services/document-service/repository.php); upstream 5.56.0 has no such
   check, and `core/strapi/document-service/validation/validation.test.api.ts` calls them without
   one. Port upstream's behaviour exactly.
2. **Jest functions sent to PHP (52, preview included).** The tests hand the server a JS function: custom admin
   permission conditions (`admin-permissions-custom-conditions`, 9), event/lifecycle listeners
   (`document-service/events`, 9), Zod schemas built in the test (`api/validate/*`,
   `api/sanitize/sanitize-input`, 22), document-service middlewares, repair hooks,
   GraphQL custom resolvers, the preview URL handler. The bridge already calls back into the
   test for lifecycle subscribers and remote objects (`Strapi\ApiTests\Callback`,
   `RemoteObject`); a general "function argument → callback" would cover most of these.
3. **Enterprise features (29), not ported:** content history (18), audit logs (`i18n`, `mcp`; 11).
   Preview is Enterprise too (its 2 tests fail earlier, on a JS function). Leave unless the
   licensing decision changes.
4. **Raw knex through the bridge (9).** Tests call `strapi.db.connection(table).where(...)`,
   `.raw()`, `.schema` on what is a Doctrine DBAL connection here (`cleanup-after-delete`,
   `polymorphic`, `unidirectional-relations`, `delete-morph-join-order`, `schema-inspector`,
   `v4-self-ref-compat`). Needs a small knex-shaped facade in packages/utils/api-tests.
5. **Suites that don't load (3):** `core/database/knex-utils` (requires `@strapi/database`),
   `core/strapi/api/openapi-extra-params`, `plugins/graphql/cors`: missing modules in the Jest
   environment; map them in tests/api like `@strapi/data-transfer`.
6. **Real behaviour differences (~55):** `core/database/transactions` (10, nested
   transactions/rollback), `admin-api-token-crud` (7), `upload-signing` (7, starts with a
   harness `ReferenceError`), document-service publish/delete/discard/clone cases (~15),
   upload concurrency and URL-import size limit (4), session manager (2), and single cases in
   webhooks, permissions snapshot, populate `arrayLimit`, lifecycles, i18n role locales.
7. **Flaky, not bugs:** `content-manager/api/basic-pagination` creates its rows with parallel
   requests, so their order varies; `content-type-builder/schema` occasionally hits a socket
   hang-up in a full run and passes alone.

**Other gaps**
- create-strapi-app: `--dbclient sqlite` without `--dbfile` writes an empty `DATABASE_FILENAME`
  and SQLite cannot open it (upstream does the same; the templates' `config/database.php` could
  treat an empty value as `.tmp/data.db`).
- A JSON `{}` is read back as `{}` only for content `json` attributes (`api::` content types,
  components). Internal JSON columns (admin permissions, history versions...) still read it as `[]`.
- v4 → v5 data migrations run as no-ops under their upstream names:
  `5.0.0-discard-drafts`, `serialize-json-columns`, database internal migrations `5.0.0-01…06`.
  Needed only to upgrade a Strapi v4 database.
- Strapi Cloud login in create-strapi-app (`--skip-cloud` is always on).
- PHP-FPM boots Strapi per request; a compiled-container cache would make FPM viable in production
  (FrankenPHP worker mode is the recommended setup).

## Fixed gaps

- Request bodies: JSON `{}` and `[]` used to both decode to a PHP `[]`, so validation could not
  tell an object from an array (components). A `{}` is now kept as a `Strapi\Utils\EmptyObject`
  for the readers that ask for it. See [`empty-json-objects.md`](empty-json-objects.md).
- Yup array validation on `min`/`max` component sets: profiled, and it scales linearly
  (entity validator, 1280 repeatable items with nested components ≈ 0.55 s, 2560 ≈ 1.1 s).
  Each request in the `*-min-max` component suites takes under 100 ms. `max_execution_time = 0`
  in tests/api is there for worker startup (php/strapi.ini), not for validation.
