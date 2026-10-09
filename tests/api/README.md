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

## Whole-suite runs

```sh
npm test                                   # every directory except the Enterprise ones, 2 lanes (~35 min)
npm test -- core/admin plugins/i18n        # some directories or files (relative to upstream's tests/api)
npm test -- --name before-fix --lanes 3    # name the run (default: a timestamp)
npm test -- --compare before-fix           # compare with that run (default: the previous one)
node scripts/summary.js <run> --failures   # every failing test of a run, grouped by file
node scripts/summary.js <run> --compare <other>
```

`scripts/run.js` gives each directory its own Jest process and scratch dir, so lanes never share
an app or a database, and each test file still starts from a fresh database. Reports and logs go
to `.results/<run>/` (`<dir>.json` is Jest's JSON report, `<dir>.log` its output); the latest
run's apps, with their FrankenPHP logs, stay in `.tmp/runs/<run>/`. The summary counts
passed / (passed + failed), leaving out upstream's skipped and todo tests, as the README's status
table does.

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

- `app/config/plugins.php` gives the email plugin nodemailer's `jsonTransport` (messages are built,
  nothing is sent). Upstream keeps the template's sendmail, which delivers in the background while
  Node keeps serving (and the suites mock `send`); here delivery is synchronous in the one worker
  and remote MX hosts time out in sandboxes (`POST /admin/forgot-password` would hang).

- `jest.spyOn(<remote service>, 'method')` (e.g. `strapi.plugin('email').service('email')`)
  works across the process boundary: the bridge replaces the registered service in the worker by
  a `Strapi\ApiTests\Spy` whose spied method calls the jest mock back over HTTP (a callback
  server in the test process, lib/bridge.js); `mockRestore()` puts the service back. Assigning a
  mock to any other remote object stays local to the test process, as before.

- `strapi.plugin(name).config(path)` is answered synchronously (a blocking request), like
  `strapi.config.get()`: upstream reads it synchronously (`expect(strapi.plugin('graphql').config('maxLimit')).toBe(...)`).

- The worker gets `STRAPI_GRAPHQL_V4_COMPATIBILITY_MODE=true` (unless set), as upstream's runner
  (`tests/scripts/run-api-tests.js`) gives it to the whole suite.

- Remote data transfer (`ws://…/admin/transfer/runner/{push,pull}`) needs a process that keeps the
  WebSocket open, which the FrankenPHP worker cannot: `strapi.server.httpServer.address()` is a
  small TCP proxy (lib/transfer-proxy.js) that pipes every connection to the worker, except one
  opening with `GET /admin/transfer/runner/`, which goes to a `strapi transfer:serve` sidecar of the
  same app (same env and database), started on first use and stopped with the instance. That is
  the reverse-proxy routing a deployment uses (packages/core/data-transfer/README.md). The `ws`
  client the tests import is a devDependency here.

- `@strapi/data-transfer` (imported by `core/data-transfer`) is lib/data-transfer.js: its local
  Strapi providers run in the worker (`Strapi\ApiTests\DataTransfer`), one bridge call per stage.

- A few more synchronous or callback APIs cross the bridge: `strapi.db.metadata.get(uid)` and
  `strapi.dirs` (in `Core.StrapiDirectories`' shape) are answered synchronously;
  `strapi.db.lifecycles.subscribe({ afterCreate: jest.fn() })` subscribes callbacks that run in the
  test process (`Strapi\ApiTests\Callback`) and returns a working unsubscribe function; an object
  with methods assigned to a remote property (`strapi.plugin('upload').provider = { ...provider,
  uploadStream(file) {} }`) replaces it in the worker by a `Strapi\ApiTests\RemoteObject` whose
  methods call back into the test (changes they make to an argument, like `file.url`, are copied
  back), until the original is assigned again.

Logs: each server writes `.tmp/app/.tmp/frankenphp-<port>.log` (the transfer sidecar
`transfer-serve-<port>.log`).

- `strapi.ai.mcp.registerTool(definition)` from a test's `register`/`bootstrap` callback (synchronous
  and not awaited upstream) is sent at once: the Zod schemas cross as JSON Schema 2020-12 and are
  rebuilt as PHP Zod (`Strapi\ApiTests\McpDefinition`), the handler stays in the test process and
  is called back with the JSON params. `tests/api/core/mcp` needs `ajv` and `ajv-formats`
  (devDependencies, as upstream's root `package.json` pins them).
