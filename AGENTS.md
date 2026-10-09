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

## Repository map

- `packages/` — one Composer package per upstream package (see `packages/*/*/README.md`).
- `examples/getstarted` — the upstream example project, PHP edition.
- `examples/plugins/announcements` — a third-party plugin that runs on both Strapi and strapi-php from
  one JSON spec (generated types, `.ts`/`.php` parity check, shared conformance fixtures).
- `tests/api` — upstream's Jest HTTP suite, run against this backend.
- `scripts/check-package-versions.php` — all packages share one version.
- `scripts/strapi-release-watch.php` — polls npm for a new upstream version.
- `scripts/parity-map.php` — maps upstream server files to ours (`parity.json`).
- `docs/` — porting notes per package.
