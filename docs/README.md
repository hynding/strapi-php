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
under PHP-FPM (and FrankenPHP's classic mode, `php_server` without `worker`) it boots per
request. Cron under FPM runs via `php bin/strapi cron:run` from
system cron; under FrankenPHP `cron:run --loop` keeps a scheduler alive.

What keeps a per-request boot (PHP-FPM, shared hosting) cheap and safe:

- **The steady state is read-only and concurrent.** Bootstrap writes to the database (schema
  sync, migrations, default roles, permission syncs), so it runs under a lock
  (`.tmp/bootstrap.lock`). Once a bootstrap has completed, `.tmp/bootstrap.settled` records a
  hash of its inputs (Strapi version, installed packages and the time of the last
  `composer install`, config, content types, components, models, `database/migrations`, and the
  size and time of every file under `src/` and `config/`) and the database's latest schema row.
  A boot with the same inputs on the same database skips the lock: its writes would change
  nothing, and `CoreStore::set` skips updates that change nothing. A settled boot writes no row.
  A new deploy, a config change or another database takes the lock again once. Development never
  settles: code changes there in ways these inputs do not see (a package under development, the
  body of a closure in config), so every boot runs the whole bootstrap.
- **GraphQL builds on first use in production.** With artifacts off (`generateArtifacts`, off
  outside development) the schema and the Apollo server are built on the first request to the
  GraphQL endpoint; development keeps upstream's eager build.
- **A settled boot skips the re-syncs.** `Strapi::bootstrapSettled()` tells bootstrap code that
  a completed bootstrap already ran for the same inputs on the same database (upstream syncs once
  per process start). The admin's super admin permissions reset and permissions cleanup, and the
  content-manager's configuration syncs, are skipped then: they read every permission and
  configuration to find nothing to change. An orphaned permission (a role deleted by hand) waits
  for the next unsettled boot, as it waits for a restart upstream.
- **Rate limits count across requests.** koa2-ratelimit's memory store lives as long as the
  process: the CLI and FrankenPHP worker mode keep it, but PHP-FPM, CGI, mod_php, LiteSpeed,
  the built-in server and FrankenPHP's classic mode start each request afresh, where it never
  limited (six bad admin logins in a row all answered 400). Those count in `.tmp/rate-limit/`
  instead (`FileStore`, one file per key under `flock`, spread over 256 subdirectories), so the
  sixth answers 429. One server only, like the memory store. Where `.tmp/rate-limit` cannot be
  written, Strapi warns and the limits are not enforced, rather than failing every login.
- **Cron catches up.** `cron:run` from the system crontab is a new process every time; with the
  last runs in memory it looked back one minute, so a crontab every 5 or 15 minutes (shared hosts
  often allow no less) missed most jobs, two calls in a minute ran a job twice and a one-shot job
  ran at every call. `cron:run` keeps each job's last run in `.tmp/cron` (`Cron::persistState()`):
  a job runs once if its schedule matched since its last run (missed runs coalesce), and a job
  still running in another call is skipped (`flock`).
- **Logs go to PHP's error log, once.** Under PHP-FPM, CGI, mod_php and LiteSpeed stdout is no
  console: `/dev/null`, or under CGI the response itself (the log lines came before the headers,
  so a CGI server failed every request). Without configured `transports`, Strapi logs there
  through `error_log()` (`strapi.WARNING: message`), which shared hosts keep per account. And
  where every request boots, a settled boot's startup and shutdown messages repeat what the
  bootstrap that settled logged: `Utils\BootLogBuffer` holds them and drops them (errors log at
  once), so a settled request logs only the request line. The trade-off: a warning a settled
  boot finds in the database (`Some users don't have any role`) is dropped too. The boot that
  settled logged it, and the next unsettled boot (a deploy, a config change) logs it again.
- **Apache.** `public/.htaccess` (example app and both create-strapi-app templates) routes
  every request that is not a file to `index.php`, passes the `Authorization` header (Apache
  drops it for PHP-FPM, FastCGI and CGI: every admin and API token answered 401), never runs
  PHP under `uploads/` (uploads keep their extension: an uploaded `.php` file ran, also as
  `/uploads/x.php/anything`), and refuses `.user.ini`. `public/uploads/.htaccess` denies the same
  files by name where the parent rules are not read first. Checked on
  Apache 2.4 with PHP-FPM; `HttpServer::requestFromGlobals()` also restores the header from
  `REDIRECT_HTTP_AUTHORIZATION` and `PHP_AUTH_*`.
- **Tested per request.** Upstream's suite cannot run under PHP-FPM (its bridge keeps spies and
  callbacks in the worker between requests), so `PerRequestLaneTest` (core) serves the example
  app from PHP's built-in server in production, one boot per request: admin auth, API tokens,
  the content API and GraphQL on settled boots, rate limits across requests, and one boot's logs
  only. `PerRequestSapiTest` does the same through `php-cgi` (error log, CGI response) when it is
  installed.

- **Hot spots found with a profiler** (excimer, `php -d extension=excimer`), each a no-op for
  the result: a settled boot skips `schema->sync()` (the settled check already matched the
  database's latest schema row and the inputs it is computed from); `Strings::transliterate()`
  returns ASCII as is and builds its ICU transliterator once (it built one per call, ~0.1 ms, for
  every snake/kebab/camel case: ~30 ms of a boot); the core routes of a content type share their
  document, data and query-parameter schemas instead of building each five times; the admin's
  action schema is built once and `registerMany()` validates a list once instead of once more per
  action; the documentation plugin caches the parsed users-permissions YAML in `.tmp/cache`.

On `examples/getstarted` (SQLite, opcache), a settled production boot went from ~620 ms to
~65 ms, 1,024 to 795 included files, 24 to 10 MB. What remains is mostly routing (~18 ms) and
the plugins' remaining bootstraps; a boot cache (resolved config, schemas, routes) would take
most of the rest.

## Status

Every non-Enterprise upstream package is ported (see the root README's table and
`parity.json`). Upstream's API suite, run with `cd tests/api && npm test` (tests/api/README.md):
3,358 of 3,403 tests pass (98.7%) at 5.56.0 (3,183 of 3,389 before the 2026-10 fixes below; three
suites that could not load now count).

## Open work

Ordered by tests unlocked per effort. Counts are from a full `npm test` run; rerun it before
starting and after finishing (`/api-tests` in Claude Code).

**Release**
- Tag `v5.56.0-beta.1`, then submit `hynding/strapi-php` and `hynding/strapi-php-create-strapi-app`
  on packagist.org with their GitHub hooks (README "Install"; split.yml fills the second repo).
  Delete this item once done.

**API suite failures (45), by cause**
1. **Test-process state the bridge cannot share (8).** The bridge now carries functions,
   zod schemas, transaction callbacks, synchronous calls from inside callbacks, knex, jest mocks
   on non-service objects and `withMockedFetch` (tests/api/README.md). What is left:
   - `api/validate/validate-query` (2) and `validate-with-exported-zod` (2):
     `contentAPI.applyExtraParamsToRoutes([route])` mutates the test's route object in place and is
     not awaited; `Object.keys(strapi.apis)` enumerates a registry synchronously.
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
4. **Flaky, not bugs (3 in the last run):** `content-manager/api/basic-pagination` (2) creates its
   rows with parallel requests, so their order varies; `content-type-builder/schema` (1)
   occasionally hits a socket hang-up in a full run and passes alone.

**Other gaps**
- A JSON `{}` is read back as `{}` only for content `json` attributes (`api::` content types,
  components). Internal JSON columns (admin permissions, history versions...) still read it as `[]`.
- v4 → v5 data migrations run as no-ops under their upstream names:
  `5.0.0-discard-drafts`, `serialize-json-columns`, database internal migrations `5.0.0-01…06`.
  Needed only to upgrade a Strapi v4 database.
- Strapi Cloud login in create-strapi-app (`--skip-cloud` is always on).
- PHP-FPM and shared hosting. A per-request boot is now read-only, lock-free once settled and
  ~65 ms on `examples/getstarted` ("Runtime model"); FrankenPHP worker mode stays the fast path.
  Still to do, in order:
  - a boot cache for production (`.strapi/cache/*.php`, `var_export`ed and served from opcache):
    resolved config, plugin list, API and component schemas, database metadata, the FastRoute
    dispatcher; keyed on `composer.lock`, `src/**` and `config/**`;
  - AI metadata jobs (`POST /upload/ai-metadata-jobs`) run in a
    shutdown function, after `destroy()` and before the response is finished; unreachable without
    an Enterprise licence (no AI metadata provider), so left as is until AI is;
  - admin assets are served by PHP (`build/` is outside `public/`), a boot each: serve them
    statically on Apache too; a release bundle with `vendor/` and `build/` for hosts without
    Composer or Node; a requirements check (extensions, writable `.tmp`);

## Moving a project between the runtimes

`examples/plugins/runtime-sync` is the plugin for that: `strapi-runtime-sync status` lists what a
project has for each runtime, `generate` writes the other runtime's files (config translated through
a shared intermediate form, core-factory one-liners and data files translated, the rest stubbed with
the original attached), `db:check` boots up to the schema sync and reports what a boot would alter
without writing, `handoff --to <runtime>` is the go / no-go. Its server half reconciles the migration
log between the two spellings of a migration and records each boot in the core store, so the admin
page shows whether both runtimes agree on the schema. Found and fixed while building it:

- `Schema\Storage::hashSchema()` sorted tables with `strcmp` where upstream uses `localeCompare`
  (ICU: `_` before digits before letters), so the same schema could hash differently on the two
  runtimes and each switch re-synced. Now ICU order (ext-intl, with an ASCII fallback), with
  Node-generated vectors in `packages/core/database/tests/Schema/StorageTest.php`.
- A user migration run by Node (`name.js`) ran again on PHP (`name.php`) on a shared database:
  `Migrations\Runner` now treats the runtimes' spellings as one migration.
- `scripts/dev-offline-manifest.php` failed on a root composer.json without `repositories` and lacked
  the `symfony/mailer`, `webonyx/graphql-php` and `phpstan/phpstan` mirrors.
- The announcements conformance test skipped its admin fixture as "admin auth not ported"; it runs it now.

## Fixed gaps

- create-strapi-app: `--dbclient sqlite` without `--dbfile` writes an empty `DATABASE_FILENAME`
  (as upstream does), which joined onto the project root made a path SQLite cannot open. The
  templates' `config/database.php` now treat an empty value as `.tmp/data.db`.
- The upload plugin's URL import fetched with its own stream-wrapper code; it now goes through
  `strapi.fetch()->open()` like upstream's `strapi.fetch`, so `Fetch::intercept()` (tests) and the
  proxy settings apply to it.

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
