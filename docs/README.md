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
3,351 of 3,403 tests pass (98.5%) at 5.56.0 (3,183 of 3,389 before the 2026-10 fixes below; three
suites that could not load now count).

## Open work

Ordered by tests unlocked per effort. Counts are from a full `npm test` run; rerun it before
starting and after finishing (`/api-tests` in Claude Code).

**Release**
- Tag `v5.56.0-beta.1`, then submit `hynding/strapi-php` and `hynding/strapi-php-create-strapi-app`
  on packagist.org with their GitHub hooks (README "Install"; split.yml fills the second repo).
  Delete this item once done.

**API suite failures (52), by cause**
1. **Test-process state the bridge cannot share (17).** The bridge now carries functions,
   zod schemas, transaction callbacks, synchronous calls from inside callbacks and knex
   (tests/api/README.md). What is left:
   - `admin-api-token-crud` (7) assigns `jest.fn()` to
     `strapi.contentAPI.permissions.providers.action.keys`: a method of a provider that is not a
     registered service, so `Strapi\ApiTests\Spy` cannot stand in for it (it sits in the readonly
     `Permissions::$providers` and in the permission engine).
   - `api/validate/validate-query` (2) and `validate-with-exported-zod` (2):
     `contentAPI.applyExtraParamsToRoutes([route])` mutates the test's route object in place and is
     not awaited; `Object.keys(strapi.apis)` enumerates a registry synchronously.
   - `upload/admin/file-upload-and-url-import` size limit (2): `withMockedFetch` swaps
     `globalThis.fetch` in the test process; the worker's `strapi.fetch` makes the real request.
     The fetch service could call back into the test while a mock is installed.
   - `document-service/clone` "non existing document" (1) expects `documentId: undefined` inside a
     result (JSON has no `undefined`); `dp/basic-no-dp` (1) expects `strapi.documents(uid).publish`
     to be `undefined` for a type without draft & publish.
   - `admin-webhooks` (1) sets `process.env.NODE_ENV` in the test process;
     `content-type-builder/content-structure` (1) spies on `strapi.log.error` at runtime (only the
     warnings logged while bootstrapping reach a `strapi.log` spy).
2. **A synchronous runtime (2):** `upload-concurrency` counts the provider uploads running at once;
   here they run one after the other.
3. **Enterprise features (32), not ported:** content history (18), audit logs (`i18n` 7,
   `mcp` 4), preview (2; its URL handler now reaches the worker, the feature itself is
   Enterprise) and the Content Releases actions in the admin permissions snapshot
   (`admin-permission`, 1). Leave unless the licensing decision changes.
4. **Flaky, not bugs (1 in the last run):** `content-manager/api/basic-pagination` creates its rows
   with parallel requests, so their order varies; `content-type-builder/schema` occasionally hits a
   socket hang-up in a full run and passes alone.

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

- Found while working through the API suite failures (2026-10):
  - Document-service events never reached `strapi.eventHub` listeners or webhooks: a nested
    transaction's `onCommit` callbacks were dropped (`TransactionContext` copied the parent's
    callback list where upstream shares it).
  - A transaction callback that rolled the connection back itself failed on the automatic commit
    (upstream's `isTransactorComplete` port).
  - `publish()` / `unpublish()` / `discardDraft()` rejected a missing `documentId`; upstream looks
    it up as `''` (no row), as every document-service method now does.
  - `clone()` replaced the original data instead of deep-merging the submitted data over it, and
    dropped relations sent as empty `{ connect: [], disconnect: [] }` (the duplicate form).
  - Extra route params (`addQueryParams` / `addInputParams`) ignored Zod schemas' `safeParse`.
  - A populate list longer than 100 was rejected even with a higher `strapi::query` `arrayLimit`.
  - A config warning was a fatal error under FrankenPHP or FPM (`STDERR` exists only in the CLI).

- Request bodies: JSON `{}` and `[]` used to both decode to a PHP `[]`, so validation could not
  tell an object from an array (components). A `{}` is now kept as a `Strapi\Utils\EmptyObject`
  for the readers that ask for it. See [`empty-json-objects.md`](empty-json-objects.md).
- Yup array validation on `min`/`max` component sets: profiled, and it scales linearly
  (entity validator, 1280 repeatable items with nested components ≈ 0.55 s, 2560 ≈ 1.1 s).
  Each request in the `*-min-max` component suites takes under 100 ms. `max_execution_time = 0`
  in tests/api is there for worker startup (php/strapi.ini), not for validation.
