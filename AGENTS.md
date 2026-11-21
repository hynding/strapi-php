# strapi-php — conventions for contributors and agents

A PHP 8.3+ port of [Strapi](https://github.com/strapi/strapi). The version of this
repository **is** the Strapi version it mirrors (see `VERSIONING.md`). The React admin
panel is not ported: a project installs the upstream `@strapi/admin` npm bundle and this
backend serves it.

## Layout mirrors upstream, file for file

```
packages/core/<name>/            ↔ packages/core/<name>/
  composer.json                  ↔ package.json          (server half)
  package.json                   ↔ package.json          (admin half, JS, unchanged)
  src/<same path>.php            ↔ src/<same path>.ts
  server/src/<same path>.php     ↔ server/src/<same path>.ts  (feature packages)
  admin/src/...                  ↔ admin/src/...          (JS, copied as-is)
```

Rules:

1. **Same path, same name.** `packages/core/core/src/middlewares/cors.ts` becomes
   `packages/core/core/src/middlewares/cors.php`. Keep kebab-case file names. Composer
   autoloads every package by **classmap** (`"classmap": ["src/"]`), so the file name does
   not have to match the class name. Run `composer dump-autoload` after adding files.
2. **One class per file.** Class name = StudlyCase of the file name; namespace = package
   namespace + StudlyCase of each directory. `src/middlewares/cors.php` holds
   `Strapi\Core\Middlewares\Cors`. `src/services/document-service/index.php` holds
   `Strapi\Core\Services\DocumentService\DocumentService` (an `index.*` file takes the
   directory's name).
3. **Factory files become invokable classes.** Upstream exports functions such as
   `export const cors = (config, { strapi }) => middleware`. In PHP the file defines a
   `final class Cors` with `__invoke(array $config, Strapi $strapi)` returning the
   middleware. Lifecycle names stay: `register()`, `bootstrap()`, `destroy()`.
4. **Barrel `index.ts` files are not ported** unless they carry logic. PHP has autoloading.
5. **`@strapi/types` is `strapi/types`:** interfaces, enums, `@phpstan-type` aliases and
   nothing executable. Every other package depends on it; it depends on nothing.
6. **Keep upstream's option names and wire formats.** `config/*.php` keys, REST query
   syntax (`filters[title][$eq]`), response envelopes (`{ data, meta }`), error bodies
   (`{ data: null, error: { status, name, message, details } }`), `schema.json`,
   database table and column naming, JWT claims. The upstream admin bundle and
   `tests/api` are the compatibility oracle.
7. **Port, don't substitute, for semantics the admin depends on:** `qs` parsing,
   `convert-query-params`, `traverse`/`sanitize`/`validate` visitors, the permission
   engine (CASL + sift), `fractional-indexing`, `@vercel/stega`.
8. **Dependencies:** PSR-7/15/17 via `nyholm/psr7`, routing via `nikic/fast-route`,
   database via `doctrine/dbal`, logging via `monolog/monolog`, CLI via `symfony/console`.
   Prefer a small amount of our own code over a large framework.
9. **One published package.** The root composer.json is `hynding/strapi-php`, which `replace`s
   every `strapi/*` package and carries their requirements, classmaps and binaries. Those keys
   are generated: after adding a package, a dependency or an autoload path to any
   `packages/*/*/composer.json`, run `composer bundle:fix` (CI runs `composer bundle:check`).
   Code that looks a package up at runtime goes through `GetEnabledPlugins::installedPackages()`,
   which sees the `strapi/*` packages inside the bundle.

## Code style

- `declare(strict_types=1);` in every file. PHP 8.3 features are welcome
  (readonly classes, enums, typed constants, `json_validate`).
- PSR-12 via `php-cs-fixer` (`composer lint`), PHPStan level 8 (`composer analyse`).
- Tests: PHPUnit 11 in each package's `tests/` directory, mirroring upstream
  `__tests__` files (`__tests__/cors.test.ts` → `tests/CorsTest.php`).
- Exceptions extend `Strapi\Utils\Errors\ApplicationError` and carry the same `name`,
  `status` and `details` fields as upstream `@strapi/utils` errors.
- Controllers receive a `Strapi\Types\Core\Context` (Koa-style: `$ctx->request`,
  `$ctx->params`, `$ctx->query`, `$ctx->state`, `$ctx->body`, `$ctx->status`).
- Everything is synchronous. Where upstream awaits a promise, call it.

## Before calling work done

- PHPUnit passes for the affected packages and the full suite (`composer test`). Move
  `examples/getstarted/.env` aside first: CI has none. As root, tests that need an unwritable
  directory skip. Install `php-cgi` so `PerRequestSapiTest` runs instead of skipping.
- PHPStan is clean (`composer analyse`, level 8, `src/` of every package; tests are not analysed)
  and `composer lint` is clean for the files you changed (CI does not run it).
- `composer bundle:check` and `composer version:check` pass (CI runs both).
- For runtime changes, run upstream's API suite (`/api-tests`, ~35 minutes for all of it) and
  compare with the previous run. The reference is 3,358 of 3,403; docs/README.md "Open work"
  lists every expected failure by cause.
- Update the docs your change makes wrong: the package's `README.md`, the root README's status
  table, docs/README.md ("Runtime model", "Status", "Open work").

## Runtime facts that bite

- `NODE_ENV` defaults to `development`, which never settles: every boot runs the whole bootstrap
  under `.tmp/bootstrap.lock`. Deployments, and tests of production behaviour, set
  `NODE_ENV=production`. A settled boot reads `.tmp/bootstrap.settled`; after changing the
  database by hand, delete it (docs/README.md, "Runtime model").
- Production caches compiled metadata and routes in `.strapi/cache/` (`Utils\BootCache`), only
  where opcache keeps scripts in shared memory, so the CLI builds them every time.
- PHP 8.3's function JIT (`opcache.jit=1235`, setup-php's default on CI) segfaults on some fresh
  boots. Tests that spawn per-request PHP pass `-d opcache.jit=disable`; do the same in new ones.
- One codebase serves two models: FrankenPHP worker mode boots once and keeps state, PHP-FPM /
  CGI / shared hosting boot per request. State that upstream keeps in memory between requests
  needs a per-request equivalent: rate limits (`.tmp/rate-limit/`), cron runs (`.tmp/cron`).
- nginx configs must not use `$uri/` in `try_files`: `public/admin/` holds the admin's static
  assets, and `/admin/` would answer 403 instead of reaching `index.php`.

## Repository map

- `packages/` — one Composer package per upstream package (see `packages/*/*/README.md`):
  `core/`, `cli/` (create-strapi-app, published on its own repository by `split.yml`),
  `plugins/`, `providers/`, `generators/`, `utils/` (logger, upgrade, api-tests, phpstan-config).
  Enterprise-licensed packages (`content-releases`, `review-workflows`) are empty placeholders.
- `examples/getstarted` — the upstream example project, PHP edition; most integration tests boot it.
- `examples/plugins/announcements` — a third-party plugin that runs on both Strapi and strapi-php from
  one JSON spec (generated types, `.ts`/`.php` parity check, shared conformance fixtures).
- `examples/plugins/runtime-sync` — the dual-runtime plugin that keeps one project runnable on both
  runtimes on one database (`strapi-runtime-sync status / generate / db:check / handoff`, migration-log
  reconciliation, boot markers, an admin page); its engine is a `.js`/`.php` pair tested against shared goldens.
- `tests/api` — upstream's Jest HTTP suite, run against this backend (`cd tests/api && npm test`;
  `tests/api/scripts/setup.sh` fetches the upstream checkout and FrankenPHP).
- `tests/php` — the example app's integration tests and `smoke.php` (CI boots it on SQLite and MySQL).
- `scripts/check-package-versions.php` — all packages share one version.
- `scripts/sync-root-composer.php` — generates the root composer.json (`bundle:check` / `bundle:fix`).
- `scripts/dev-offline-manifest.php` — `composer.dev.json` resolving dependencies from GitHub mirrors.
- `scripts/strapi-release-watch.php` — polls npm for a new upstream version.
- `scripts/parity-map.php` — maps upstream server files to ours (`parity.json`).
- `docs/` — cross-cutting notes: `docs/README.md` (runtime model, status, "Open work": the to-do
  list, ordered), `porting-feature-packages.md`, `empty-json-objects.md`. Per-package notes live in
  each package's README.
- `.github/workflows/` — `ci.yml`, `release-watch.yml` (opens a `Parity: X.Y.Z` issue per upstream
  release), and on `v*` tags `split.yml` (create-strapi-app) and `release-bundle.yml` (attaches
  `strapi-php-starter-<tag>.zip`, a bundled starter for shared hosting).
- `docker/`, `docker-compose.dev.yml` — FrankenPHP worker-mode dev environment (README "Docker").
- `.claude/commands/` — Claude Code commands: `/upstream-release <version>` (the VERSIONING.md
  loop), `/api-tests [dirs]` (run upstream's suite, explain changes), `/port-file <upstream path>`.
  `.claude/settings.json` pre-approves the routine composer/phpunit/test commands.
