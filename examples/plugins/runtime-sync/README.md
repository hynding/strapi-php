# runtime-sync: one Strapi project, two runtimes

A plugin for **Strapi** (Node) and **strapi-php** that keeps one project runnable on both, sharing
one database, so an application can move from one runtime to the other and back. It is a
dual-runtime plugin itself, built the way [`../announcements`](../announcements) is: one directory,
two manifests (`package.json` + `composer.json`), JSON specs both servers read as-is, and a `.js`
↔ `.php` twin for every hand-written file (`npm run check` fails otherwise).

What a dual-runtime project is: the two runtimes read the **same** `src/**/schema.json`,
components, `src/admin`, `public/`, `.env`, `.sql` migrations and admin build, and each reads its
own copy of everything that is code — `config/*.js` ↔ `config/*.php`, `src/index.js` ↔
`src/index.php`, `src/api/*/controllers/*.js` ↔ `.php`, … (`spec/facets.json` is the full list).
The database schema, table names and hashes are identical on both ([strapi/database](../../../packages/core/database/README.md)),
so one database serves whichever runtime is up.

## Tools

The same CLI on both sides: `npx strapi-runtime-sync` (`bin/strapi-runtime-sync.js`) and
`php vendor/bin/strapi-runtime-sync`. Same commands, same words, same JSON (`--json`).

| Command | What it does |
| --- | --- |
| `status` | Which runtimes the project is set up for and at which Strapi version (lockstep check), every file pair and the twins that are missing, shared files, manifests (`package.json` dependencies and scripts, `composer.json`, `bin/strapi`, `public/index.php`), `.env` keys, the plugins each runtime enables, the admin build, and a list of problems. |
| `generate [--to node\|php\|both] [--ts] [--force] [--dry-run]` | Writes the files the other runtime lacks. Config files that are untouched create-strapi-app templates get the other runtime's template; other config files are **translated**: the Node side is evaluated with a recording `env` (`engine/bin/evaluate-config.js`), the PHP side parsed with nikic/php-parser (`engine/bin/evaluate-config.php`), both into one intermediate form that either emitter prints — `env('PORT', 1337)` ↔ `$env->int('PORT', 1337)`, `path.join(__dirname, '..', …)` ↔ `dirname(__DIR__) . '/…'`, secrets never copied. `createCoreController('api::x.x')` one-liners and data files (`routes/*.js` as `{ routes: [...] }`) are translated too; lifecycles, policies, middlewares, extensions, migrations and customised factories become **stubs** of the right shape with the original attached for a hand port (policies deny and migrations throw until ported). Manifests are merged: `composer.json`, `bin/strapi` and `public/index.php` for PHP; `@strapi/plugin-users-permissions`, the database driver and the `strapi` scripts for Node (`build:php` / `start:node` when the plain name is taken); `.gitignore` lines. Nothing existing is overwritten without `--force`. |
| `db:check [--runtime node\|php]` | Boots the runtime **up to the schema sync** and stops there: the plugin's `beforeSync` hook computes this runtime's schema hash, compares it with the one stored in `strapi_database_schema`, runs the three-way diff (tables added / updated / removed), lists pending migrations, and aborts the boot before anything is written. `--runtime` with the other runtime runs that runtime's CLI. Exit 1 when a boot would alter the database. |
| `handoff --to node\|php` | Go / no-go for serving the project from that runtime: runtime set up, no missing twins, manifests, lockstep versions, `.env`, dependencies installed, plugins matching, admin build, `db:check`. Prints the run commands when ready. |

```sh
# a Node project, going PHP
npm install strapi-plugin-runtime-sync && composer require strapi-plugin/runtime-sync
npx strapi-runtime-sync status
npx strapi-runtime-sync generate --to php     # config/*.php, src/**/*.php, composer.json, bin/strapi, public/index.php
composer install
php vendor/bin/strapi-runtime-sync handoff --to php
php bin/strapi start                          # same .env, same database, same build/

# a strapi-php project, going Node
php vendor/bin/strapi-runtime-sync generate --to node
npm install
npx strapi-runtime-sync handoff --to node
npx strapi start
```

The plugin must be enabled on both runtimes (it is, once both packages are installed: a Composer
package with `extra.strapi.kind = "plugin"` and an npm package with `strapi.kind = "plugin"` are
picked up automatically; `config/plugins.*` can also `resolve` the directory).

## What the server half does at every boot

- **Migration log reconciliation.** Node Strapi logs `2024.01.01T00.00.00.add.js` in
  `strapi_migrations`; strapi-php logs `….php`. Before either runner looks, the plugin logs the
  twin of every migration the other runtime already ran, so it never runs twice
  (`config.reconcileMigrations`, default `true`; strapi-php's own runner also treats the twins as
  one). Prefer `.sql` migrations in a dual project: one file, one name, both runtimes.
- **Boot markers.** After the sync, the boot is recorded in the core store
  (`plugin_runtime-sync_boots`: runtime, Strapi version, engine, time, schema hash, plugins,
  content-type count), one entry per runtime (`config.recordBoots`, default `true`).
- **Preflight.** With `STRAPI_RUNTIME_SYNC_PREFLIGHT=1` the boot aborts in `beforeSync` with the
  report (`PreflightAbort`); that is what `db:check` runs.
- **Admin route** `GET /runtime-sync/status` (admin auth) and an **admin page** ("Runtime sync"):
  the runtime serving the panel, the schema status of this boot, both runtimes' last boots and
  whether they agree (version, schema hash, plugins), and the project's file pairs and problems.

## Layout

```
package.json / composer.json   two manifests, one plugin name (runtime-sync), versions in lockstep
spec/facets.json               SPEC: the pairing rules (paired, shared, unsupported globs; manifests)
spec/templates/{node,php}/     the create-strapi-app project files of each runtime (upstream vanilla-js
                               and vanilla, strapi-php's vanilla): recognised, and written for the other side
engine/*.js ↔ engine/*.php     the engine, module for module: glob, spec, files, project, scan, source,
                               ir, templates, migrations, evaluate-config, emit, generate, status,
                               report, cli
engine/bin/evaluate-config.js  reads a Node config into the IR (used by both CLIs)
engine/bin/evaluate-config.php reads a PHP config into the IR (used by both CLIs)
server/src/*.js ↔ *.php        register (the hooks), services/preflight, services/status,
                               controllers/status, routes/admin.json, config/default.json
admin/src/                     shared React admin page
bin/strapi-runtime-sync.js     npm bin;  bin/strapi-runtime-sync  Composer bin
conformance/                   fixtures both runtimes answer identically; run-strapi.mjs for real Strapi
tests/php/                     PHPUnit: engine (shared fixtures + goldens), conformance, server
tests/node/                    node --test: the same fixtures and goldens
tests/fixtures/                engine cases, three sample projects (node-js, php, dual), expected output
generator/check.mjs            npm run check: .js/.php parity, route handlers, manifests
```

## Workflow

| Change | Do |
| --- | --- |
| A pairing rule or a manifest rule | Edit `spec/facets.json`. Both engines read it. |
| Engine behaviour | Edit the `.js` and `.php` twins. Run the PHP suite with `UPDATE_FIXTURES=1` to rewrite the goldens, then `npm test` must pass on them unchanged: the two engines are byte-identical by construction. |
| A route | Edit `server/src/routes/admin.json`; `npm run check` fails until both controllers implement it. |
| Release | Bump `version` in both manifests to the Strapi version (VERSIONING.md). |

```sh
npm install
npm run check                # parity, route handlers, manifests
npm test                     # engine against the shared goldens (needs php on the PATH)
npm run build                # strapi-plugin build: dist/admin + dist/server
npm run test:conformance     # fixtures + server checks on real Strapi (needs build)
cd ../../.. && vendor/bin/phpunit --testsuite example-plugins   # fixtures, server and engine on strapi-php
```

## Limits and things to know

- **Translation is for data.** Config files are code; the translator follows them as far as
  literal data, `env` calls, string concatenation and the project root go. An `env` value used in
  a condition, a comparison or a lookup (`connections[client]`) is reported (`Note:` in the
  header, `review before running` on the CLI) and the generated value must be checked.
  `config/database.*` is the usual case; the template version is recognised and swapped.
- **Stubs are not ports.** Lifecycles, policies, middlewares, plugin extensions, JS migrations
  and customised core factories need a hand port; the stub carries the original. Local plugins
  (`src/plugins/*`) are reported, not generated.
- **Plugins must match.** strapi-php enables its bundled plugins (graphql, documentation, sentry,
  color-picker, users-permissions) unless `config/plugins.php` disables them; Node enables what
  `package.json` installs. A plugin with content types enabled on one side only makes each boot
  alter the shared database — `status` warns, `db:check` shows the tables.
- **Versions in lockstep.** `package.json`'s `@strapi/strapi` and `composer.json`'s
  `hynding/strapi-php` must mirror the same Strapi release (VERSIONING.md); the admin build is
  the same `build/` (`dist/build` for a TypeScript project) for both.
- **Only one cron.** Both runtimes run `config/cron-tasks`; enable cron on the runtime that
  serves traffic (`server.cron.enabled`).
- **Uploads.** `public/uploads` is shared when both runtimes run from the same directory; use a
  provider (S3, Cloudinary) otherwise.
- TypeScript config files are evaluated with `node --experimental-strip-types` (Node 22.6+).
