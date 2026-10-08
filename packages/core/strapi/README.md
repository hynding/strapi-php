# strapi/strapi

Strapi CLI and entrypoint: `bin/strapi start|develop|build|console|...` and the `public/index.php`
front controller template (port of `@strapi/strapi`).

| | |
| --- | --- |
| Upstream | [`@strapi/strapi`](https://github.com/strapi/strapi/tree/develop/packages/core/strapi) |
| Namespace | `Strapi\Cli\` |
| Status | `foundation` |
| Version | tracks Strapi `5.56.0` — this `composer.json` is the canonical version of the monorepo (see `VERSIONING.md`) |

Files mirror upstream one for one (`src/<same path>.php`); see `AGENTS.md` at the repository root
for the naming rules. commander becomes symfony/console; the Node admin build toolchain (Vite) is
driven through symfony/process.

## Usage

```sh
composer require strapi/strapi            # pulls strapi/core, database, utils...
php vendor/bin/strapi routes:list         # or a project-local bin/strapi delegating to Strapi\Cli\Cli\Cli::run($root)
php bin/strapi start                      # built-in web server on server.host:server.port
php bin/strapi build                      # admin panel with the upstream Node toolchain
```

```php
// public/index.php (generated from templates/index.php)
$strapi = \Strapi\Cli\Strapi::createStrapi(['appDir' => dirname(__DIR__)])->load();
$server = $strapi->server()->mount();
// FPM: one request per execution; FrankenPHP: `frankenphp_handle_request()` loop around the same handler
HttpServer::emit($server->handle(HttpServer::requestFromGlobals()));
```

## Commands

| Command | Upstream | Notes |
| --- | --- | --- |
| `start` | `start.ts` | `createStrapi({appDir, distDir}).start()` — PHP's built-in server runs `public/index.php` (`HttpServer`). A production deployment serves that file with FPM or FrankenPHP instead. |
| `develop` (`dev`) | `develop.ts` | Checks the admin dependencies, builds the admin once (`--no-build-admin` skips), loads and starts the server. No cluster/worker restart (PHP re-reads the sources per request) and no Vite middleware mode: `--watch-admin` is accepted and ignored. |
| `build` | `build.ts` | `EnsureAdminDependencies` (VERSIONING.md check: `package.json` must pin `@strapi/admin` and `@strapi/strapi` to this package's version, installed copy must match), `CreateBuildContext`, `StaticFiles::writeStaticClientFiles()` (`.strapi/client/app.js`, `index.html`), `Vite\Build` (writes `.strapi/client/vite.config.mjs` and runs `node_modules/.bin/vite build`). `--bundler webpack` is refused. |
| `console` | `console.ts` | readline/eval REPL with `$strapi` (and `$app`) loaded; expressions are echoed as JSON. |
| `version` | `version.ts` | Prints `Strapi\Cli\Strapi::version()`. |
| `routes:list` | `routes/list.ts` | Table with Method, Path and — PHP addition — Handler, Policies, Auth. |
| `openapi:generate [-o <path>]` | `openapi/index.ts`, `openapi/generate.ts` | Upstream's `openapi generate`: writes the content-API OpenAPI 3.1 document (`strapi/openapi`) to `specification.json` (or `-o`). |
| `content-types:list` | `content-types/list.ts` | Registered content type uids. |
| `generate` | `generate.ts` | `strapi/generators`' `Generators::runCLI()` (plop's CLI upstream) with symfony/console's question helper: `strapi generate [generator] [answers...]`, plop's bypass (positional answers, `--name=value` after `--`), `-n` takes defaults. Adds a `plugin` generator (upstream: `npx @strapi/sdk-plugin init`). See packages/generators/generators/README.md. |
| `export` | `export/{command,action,validate-dir-format}.ts` | Same flags (`-f/--file`, `--format tar\|dir`, `--no-encrypt`, `-k/--key`, `--no-compress`, `--only`, `--exclude`, `--exclude-content-types`, `--only-content-types`, `--throttle`, `--verbose`), prompts and messages; writes the same `.tar[.gz][.enc]` archives (or unpacked directory) as upstream, readable by `npx @strapi/strapi import` and vice versa. |
| `import` | `import/{command,action}.ts` | Same flags (`-f/--file`, `-k/--key`, `--force`, `--only`, `--exclude`, content-type filters, `--throttle`, `--verbose`), the destructive-operation confirmation, schema-diff and assets-backup prompts. Reads upstream archives (any compression/encryption combination) and unpacked directories. |
| `transfer` | `transfer/{command,action}.ts` | Same flags (`--from`/`--from-token`, `--to`/`--to-token`, `--force`, `--checksums`, filters, `--throttle`, `--verbose`), prompts and progress; speaks upstream's WebSocket protocol, so it pushes to and pulls from Node Strapi 5.56 as well as PHP apps. |
| `transfer:serve` | — | PHP addition: serves `/admin/transfer/runner/{push,pull}` (`--host`, `--port`, default `127.0.0.1:1338`) for remote transfers, since FPM/FrankenPHP cannot keep a WebSocket open. Route those paths to it from the reverse proxy. See packages/core/data-transfer/README.md. |
| `configuration:dump` (`config:dump`) | `configuration/dump.ts` | `plugin_*` core-store entries as JSON (`-f`, `-p`). |
| `configuration:restore` (`config:restore`) | `configuration/restore.ts` | `replace` / `merge` / `keep` importers (`-f`, `-s`). |
| `admin:create-user`, `admin:reset-user-password` | `admin/*.ts` | **Stubs**: options are validated like upstream (email, password rules) then the command fails explaining the admin package is not ported. |
| `telemetry:enable`, `telemetry:disable` | `telemetry/*.ts` | No-ops writing `extra.strapi.telemetryDisabled` into `composer.json`; nothing is ever sent. |
| `cron:run` | — | PHP addition: runs the due cron tasks once (crontab under FPM), `--loop` polls, `--list` prints the jobs. |
| `migrations:run` | — | PHP addition: loads the app (which runs pending migrations) and confirms the state. |

Every command accepts `--debug` / `--silent` like upstream; `runAction` refuses to run outside a
Strapi project (a `composer.json` requiring `strapi/strapi` or `strapi/core`).

## Ported files

| Upstream | PHP | Public API |
| --- | --- | --- |
| `index.ts` | `src/index.php` | `Strapi::createStrapi($options)`, `compileStrapi($options)`, `version()`. |
| `cli/index.ts` | `src/cli/index.php` | `Cli::createCLI($argv, $cwd): Application`, `runCLI($cwd, $argv, $output): int`, `run($cwd): int`. |
| `cli/types.ts` | `src/cli/types.php` | `CliContext` (`cwd`, `logger`). |
| `cli/commands/index.ts` | `src/cli/commands/index.php` | `Commands::all()`: the command factories; `StrapiCommand` base class (`configure()` + `action()`). |
| `cli/commands/*.ts` | `src/cli/commands/*.php` | One class per command (see the table above). |
| `cli/utils/helpers.ts` | `src/cli/utils/helpers.php` | `Helpers::readableBytes()`, `readableTime()`, `exitWith()`, `isStrapiProject()`, `assertCwdContainsStrapiProject()`, `runAction()`. |
| `cli/utils/data-transfer.ts` | `src/cli/utils/data-transfer.php` | `DataTransfer`: `exitMessageText()`, `getDefaultExportName()`, `buildTransferTable()`, `abortTransfer()`, `setSignalHandler()` (pcntl), `createStrapiInstance()`, the filter helpers (`parseFilterOptions()`, `normalizeTransferFilterOptions()`, `validate*()`, `createEntityFilter()`, `createLinkFilter()`, `buildTransferTransforms()`, `parseRestoreFromOptions()`, `logTransferFilterSummary()`), `formatDiagnostic()` (console + `<operation>_<ms>.log`), `loadersFactory()`, `progressText()`, `getDiffHandler()`, `getAssetsBackupHandler()`, telemetry payload. |
| `cli/utils/commander.ts`, `cli/utils/data-transfer-loader.ts` | `src/cli/utils/commander.php`, `src/cli/utils/progress-loader.php`, `src/cli/utils/progress-loaders.php` | `Commander` (option parsers: `parseList`, `parseInteger`, `parseURL`, `promptEncryptionKey`, `getCommanderConfirmMessage`, `forceOption`), the per-stage progress loaders (ora's spinners upstream). |
| `cli/commands/{export,import,transfer}/*.ts` | `src/cli/commands/{export,import,transfer}/*.php` | `Command` (symfony/console definition) and `Action` per command; the actions take their factories (`createStrapiInstance`, provider and engine factories) through a `$deps` array, which the tests replace as upstream's tests mock the modules. |
| — | `src/cli/utils/exit-error.php` | `ExitError`: thrown where upstream calls `process.exit(code)` deep in a command; the command prints the message(s) and returns the code. |
| `cli/utils/logger.ts` | `src/cli/utils/logger.php` | `Logger::createLogger(['silent', 'debug', 'timestamp'])`: `debug/info/log/success/warn/error`, `warnings()`, `errors()`, `spinner()` (`Spinner`: `start/succeed/fail`). |
| `node/create-build-context.ts` | `src/node/create-build-context.php` | `CreateBuildContext::createBuildContext(['cwd', 'logger', 'strapi'?, 'options'])`: admin/server URLs, `STRAPI_ADMIN_*` env, plugins with an admin part, customisations, `.strapi/client` runtime dir, browserslist target. |
| `node/staticFiles.ts` | `src/node/static-files.php` | `StaticFiles::getEntryModule()`, `getDocumentHTML()` (the `DefaultDocument` markup), `decorateHTMLWithAutoGeneratedWarning()`, `writeStaticClientFiles()`. |
| `node/build.ts`, `node/develop.ts` | `src/node/build.php`, `src/node/develop.php` | `Build::build($options)`, `Develop::develop($options)`. |
| `node/vite/config.ts`, `node/vite/build.ts` | `src/node/vite/config.php`, `src/node/vite/build.php` | `Config::resolveProductionConfig($ctx)` (the Vite config as an ES module: same `define`, `optimizeDeps.include`, `resolve.dedupe`/`alias`, the `strapi/server/build-files` plugin; a user `src/admin/vite.config.*` is applied), `Build::build($ctx)`. |
| `node/core/plugins.ts` | `src/node/core/plugins.php` | `Plugins::getEnabledPlugins()`, `getMapOfPluginsWithAdmin()`, `getModule()`. |
| `node/core/env.ts`, `files.ts`, `errors.ts`, `timer.ts`, `admin-customisations.ts`, `ensure-admin-dependencies.ts` | `src/node/core/*.php` | `Env`, `Files`, `Errors::handleUnexpectedError()`, `Timer`, `AdminCustomisations::loadUserAppFile()`, `EnsureAdminDependencies::handleAdminDependencies()` / `assertVersionsMatch()`, `MissingAdminPeerDepsError`. |
| `bin/strapi.js` | `bin/strapi` | The Composer binary. |
| — | `templates/index.php` | The `public/index.php` front controller (FPM + FrankenPHP worker mode + built-in server static files). |

## Skipped

`admin:delete-user`, `admin:active-user`, `admin:block-user`, `admin:list-users` (admin package),
`components:list`, `controllers:list`, `hooks:list`, `middlewares:list`, `policies:list`,
`services:list`, `content-types:rename-field`, `templates:generate` (deprecated upstream),
`ts:generate-types`, `report`,
`enterprise`, the cloud commands; `node/webpack/*` (deprecated bundler), `node/vite/watch.ts` and
`node/webpack/watch.ts` (HMR middleware mode), `node/core/monorepo.ts`, `aliases.ts`,
`linked-packages.ts`, `scan-roots.ts`, `admin-vite-*` (resolved inside the generated Vite config
with `require.resolve`), `dependencies.ts` (replaced by the simpler `EnsureAdminDependencies`),
`pkg.ts`, `tsconfig.ts`, `telemetry.ts`, `get-inquirer.ts`,
`admin.ts` / `admin-test.ts` (React entry points: the admin bundle is `@strapi/admin`'s).

## Tests

`tests/Cli/CliTest.php` (command registration, deprecations, `version`, project assertion,
`routes:list`, `content-types:list`, `cron:run`, `migrations:run`, `configuration:dump|restore`,
the admin stubs), `tests/Cli/GenerateTest.php` (`generate`), `tests/Cli/HelpersTest.php`, `tests/Node/StaticFilesTest.php` (port of
`staticFiles.test.ts`), `tests/Node/BuildContextTest.php` (port of `create-build-context.test.ts`
plus the VERSIONING.md check). The commands run against `examples/getstarted` on an in-memory
SQLite database. `tests/Cli/Utils/DataTransferTest.php` ports `utils/__tests__/{normalize-transfer-filter-options,parse-restore-from-options,validate-content-type-transfer-options,content-type-transfer-filters,log-transfer-filter-summary}.test.ts`;
`tests/Cli/Commands/{Export,Import,Transfer}Test.php` port the `export`, `import` and `transfer`
action tests against packages/core/data-transfer's fixture app.
