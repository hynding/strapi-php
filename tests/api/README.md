# tests/api: upstream's API suite against strapi-php

Upstream Strapi's Jest HTTP suite (`tests/api` in strapi/strapi) is the compatibility oracle
(AGENTS.md rule 6). This directory runs those test files and upstream's `api-tests` helpers
**unmodified** against the PHP test app.

```sh
tests/api/scripts/setup.sh          # upstream checkout at the tracked tag (.upstream/), FrankenPHP (.bin/), npm ci
cd tests/api
npm run jest -- .upstream/tests/api/core/admin              # a directory or a file
npm run jest -- .upstream/tests/api/core/admin -t 'login'   # Jest filters work as usual
```

Environment: `STRAPI_UPSTREAM` (use an existing checkout), `FRANKENPHP_BIN` (an existing
binary), `STRAPI_API_TESTS_TMP` (scratch dir, default `.tmp/`; give concurrent runs separate ones).

## How it works

- `app/` is create-strapi-app's vanilla template, PHP edition. Each run copies it to `.tmp/app`
  (the content-type builder writes schemas into `src/`).
- `createStrapiInstance()` (lib/strapi.js, swapped in for upstream's) starts that app under
  FrankenPHP worker mode with **one** worker, so state set through `strapi.*` lives in the process
  serving the HTTP requests.
- Upstream tests reach into the instance (`await strapi.db.query(uid).findMany()`,
  `strapi.config.set()`, `strapi.service('admin::user').create()`). `strapi` is a proxy
  (lib/bridge.js) that records such an expression and posts it to `POST /__api-tests/rpc`, where
  `Strapi\ApiTests\Bridge` (packages/utils/api-tests) replays it on the live instance. PHP
  exceptions come back as JS errors with `name`, `status`, `details`.
- `lib/global-setup.js` copies upstream's helpers to `.tmp/api-tests` with a few asserted patches
  (synchronous in-process reads that must be awaited across the process boundary).

- `php/strapi.ini` (added to `PHP_INI_SCAN_DIR` by lib/server.js) turns off PHP's own multipart
  parsing, which keeps only the last of a repeated field: `strapi::body` parses `php://input` and
  keeps them all, as koa-body does (several `files`, one `fileInfo` per file).

Logs: each server writes `.tmp/app/.tmp/frankenphp-<port>.log`.
