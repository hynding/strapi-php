# announcements: one plugin, two backends

A small third-party plugin that runs on **Strapi** (Node) and **strapi-php** from the same
directory. It proves out the approach for keeping a dual plugin in sync:

1. **The spec is data both runtimes read as-is.** Content types, routes and config defaults are
   JSON in the places Strapi expects them. The TS server `import`s them and the PHP server
   `json_decode`s them, so nothing is translated and nothing can drift.
2. **Types are generated from the spec.** `generator/generate.mjs` (plain Node, no dependencies)
   emits TS interfaces and PHP readonly classes and enums, plus the list of route handlers each
   controller must implement.
3. **Hand-written logic is small, mirrored, and checked twice.** Every server `.ts` has a `.php`
   twin at the same path (`npm run check` fails otherwise). The same JSON fixtures run against
   real Strapi and against strapi-php.
4. **The admin is shared.** `admin/` is one React codebase. strapi-php serves the upstream admin
   bundle, so it is built once and never ported.

## Layout

```
package.json              npm manifest (strapi.kind = plugin); exports strapi-admin / strapi-server
composer.json             Composer manifest (extra.strapi.kind = plugin, server = server/src/index.php)

server/src/
  content-types/announcement/schema.json   SPEC, read by both
  routes/content-api.json, admin.json      SPEC, read by both
  config/default.json                      SPEC, read by both
  generated/                               GENERATED: content-types.ts, route-handlers.ts,
                                           announcement.php, announcement-level.php, route-handlers.php
  index.ts            ↔ index.php            module assembly
  config/index.ts     ↔ config/index.php     config validator
  routes/index.ts     ↔ routes/index.php     loads the route JSON
  content-types/index.ts ↔ content-types/index.php
  controllers/announcement.ts ↔ controllers/announcement.php
  services/announcement.ts    ↔ services/announcement.php

admin/src/                 shared React admin (menu link + summary page); admin/src/generated/ is GENERATED
conformance/fixtures/      request → expected response, backend-agnostic
conformance/run-strapi.mjs runs the fixtures on real Strapi (from devDependencies)
tests/php/ConformanceTest.php  runs the same fixtures on strapi-php (part of the root `composer test`)
generator/generate.mjs     codegen + parity/manifest checks
```

## What the plugin does

- Content type `plugin::announcements.announcement`: `title`, `body`, `level` (`info` | `warning` |
  `critical`), `active`.
- `GET /api/announcements/active?limit=n` (public) returns active announcements, most severe
  first, then newest first, capped by config `maxActive` (default 3). Severity is the order of
  `level.enum` in `schema.json`, so even the ordering rule lives in the spec. An invalid `limit`
  is a 400 `ValidationError`.
- `GET /announcements/summary` (admin) returns counts per level. The admin page shows them.

## Workflow

| Change | Do |
| --- | --- |
| Add or alter an attribute | Edit `schema.json`, then `npm run generate`. Both servers pick it up. |
| Add a route | Edit `routes/*.json`, then `npm run generate`. TS fails to compile (`satisfies ControllerContract`) and `ConformanceTest::testEveryRouteHandlerIsImplemented` fails until both controllers implement it. |
| Change behavior | Edit the `.ts` and `.php` twins, and add a fixture under `conformance/fixtures/`. |
| Release | Bump `version` in **both** manifests, to the Strapi version, as in the rest of this repo. `npm run check` refuses a mismatch. |

```sh
npm install
npm run generate            # rewrite generated files
npm run check               # CI: generated files current, .ts/.php parity, manifests in lockstep
npm run typecheck
npm run build               # strapi-plugin build: dist/admin + dist/server
npm run test:conformance    # fixtures on Strapi (needs build)
cd ../../.. && vendor/bin/phpunit --testsuite example-plugins   # fixtures on strapi-php
```

Fixture rules (both runners): each fixture empties and seeds its content types through the
document service. Then each step compares status and body. Objects match as subsets unless
they list `"$keys"`, arrays must have the same length, and scalars must be strictly equal. A
fixture that `requires` something a backend lacks is skipped there with a reason.

## Status

| | Strapi 5.56.0 | strapi-php |
| --- | --- | --- |
| Content API fixtures (4) | pass | pass |
| Anonymous admin route → 401 | pass | pass |
| Authenticated admin summary | pass | skipped: admin auth is not ported yet |
| Admin bundle | builds with `@strapi/sdk-plugin` | served from the same upstream bundle |

Limits of this proof of concept:

- The generator models scalar and enum attributes only. Relations, components, media and
  dynamic zones come out as `unknown`/`mixed`.
- The generator lives in this example. Once a second plugin needs it, it belongs in its own
  package (for example a `strapi-plugin-sync` bin) that both plugins depend on.
