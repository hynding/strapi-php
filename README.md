# strapi-php

A PHP 8.3+ port of [Strapi](https://github.com/strapi/strapi) that tracks upstream
release for release. Version **5.56.0** mirrors Strapi 5.56.0: same package layout, same
file names, same REST and admin API, same database schema. The React admin panel is not
rewritten — a project installs the upstream `@strapi/admin` npm bundle and this backend
serves it.

## Install

strapi-php is one Composer package, `hynding/strapi-php`. It holds every package in this
repository and `replace`s their `strapi/*` names, so a plugin that requires `strapi/core` is
satisfied by it:

```sh
composer create-project hynding/create-strapi-app my-project   # a new project (vanilla or --example)
composer require hynding/strapi-php                            # or add it to an existing one
```

During a beta add `--stability=beta` to `create-project` (see `VERSIONING.md`).

## Status

The package column is each part's name inside `hynding/strapi-php` (the directory under
`packages/` it lives in mirrors upstream's).

| Area | Package | State |
| --- | --- | --- |
| Shared contracts | `strapi/types` | ported |
| Utilities (errors, `qs`, query-param conversion, sanitize/validate, pagination) | `strapi/utils` | ported, 622 tests |
| Logger (Monolog) | `strapi/logger` | ported |
| Permission engine (CASL + sift semantics) | `strapi/permissions` | ported, 88 tests |
| Database (Doctrine DBAL): metadata, schema sync, query builder, entity manager, migrations | `strapi/database` | ported, 166 tests; schema and hash byte-identical to Node |
| Runtime: container, registries, loaders, PSR-7 server, core API, document service, entity validator | `strapi/core` | ported (MCP server with content-manager/upload tools; AI providers stubbed); MCP API 140/144 |
| CLI: `strapi start / develop / build / console / routes:list / *:list / report / cron:run / migrations:run` | `strapi/strapi` | ported |
| Admin API: users, roles, permissions, sessions, API/admin/transfer tokens, webhooks, project settings | `strapi/admin` | ported (non-EE); 313/333 |
| Content Manager | `strapi/content-manager` | ported (history/preview are EE-licensed, not ported); 760/781 |
| Content-Type Builder | `strapi/content-type-builder` | ported; 62/63 |
| Upload + local, AWS S3, Cloudinary providers (GD instead of sharp; no vendor SDKs) | `strapi/upload`, `strapi/provider-upload-*` | ported; 182/193 |
| Email + sendmail, nodemailer, Amazon SES, Mailgun, SendGrid providers | `strapi/email`, `strapi/provider-email-*` | ported |
| Internationalization | `strapi/i18n` | ported; 56/64 |
| Users & Permissions: end users, roles, JWT / refresh sessions, OAuth providers | `strapi/plugin-users-permissions` | ported; 125/125 |
| GraphQL (webonyx/graphql-php; nexus, Apollo Server 4 behaviour ported) | `strapi/plugin-graphql` | ported; 119/122 |
| Data transfer: `strapi export / import / transfer` (archives byte-compatible with Node Strapi; remote transfers through `strapi transfer:serve`) | `strapi/data-transfer` | ported; 3/3 (+ admin push security 3/3) |
| OpenAPI generator and the documentation plugin (Swagger UI) | `strapi/openapi`, `strapi/plugin-documentation` | ported; documents identical to Node's for the same app |
| `strapi generate`, sentry, color-picker | `strapi/generators`, `strapi/plugin-sentry`, `strapi/plugin-color-picker` | ported |
| Project generator: `composer create-project hynding/create-strapi-app my-project` (same prompts and flags as `npx create-strapi-app`, vanilla and example templates in PHP, fresh `.env` secrets, `composer install` + npm install of the pinned admin bundle) | `hynding/create-strapi-app` (published on its own) | ported (Strapi Cloud login not ported); 44 tests |
| Upgrade tool `strapi-upgrade` (upstream's `npx @strapi/upgrade` commands; composer.json and package.json upgraded in lockstep; PHP codemods, upstream's JS codemods via npx) | `strapi/upgrade` | ported; 186 tests |
| content-releases, review-workflows | | not ported: the whole packages are Enterprise-licensed |

`parity.json` lists every upstream server file and whether it is ported. `php
scripts/parity-map.php` regenerates it.

The numbers are upstream's own Jest API suite (`tests/api`, see its README) run unmodified
against the PHP app, one package directory at a time, each test file on a fresh database:
3,183 of 3,389 across all directories (`cd tests/api && npm test`), `tests/api/core/strapi` (core's REST, document
service, relations, validation) at 1372/1497. What still fails is mostly Enterprise
features and tests that replace functions inside the server from the Jest process, which
can't cross into PHP.

## Try it

```sh
git clone https://github.com/hynding/strapi-php && cd strapi-php
composer install
cp examples/getstarted/.env.example examples/getstarted/.env
php examples/getstarted/bin/strapi routes:list
php examples/getstarted/bin/strapi start          # http://localhost:1337/api/articles
```

Like upstream, the content API is closed by default: grant the Public role access in the
admin panel (Settings → Users & Permissions → Roles), or call it with an API token
(Settings → API Tokens), e.g. `curl -H "Authorization: Bearer <token>" localhost:1337/api/articles`.

`strapi start` uses PHP's built-in server for development. For production, point
PHP-FPM or [FrankenPHP](https://frankenphp.dev) (worker mode, `public/index.php` boots
once per worker) at `examples/getstarted/public`.

To serve the admin panel, install the pinned upstream bundle and build it:

```sh
cd examples/getstarted && npm install && php bin/strapi build   # needs Node 20+
```

## Docker

`docker-compose.dev.yml` runs `examples/getstarted` on [FrankenPHP](https://frankenphp.dev)
in worker mode (Strapi boots once per worker, PHP files are watched and workers restart on
change) with the repository bind-mounted, plus optional database services:

```sh
docker compose -f docker-compose.dev.yml up                                         # SQLite, http://localhost:1337
DATABASE_CLIENT=postgres docker compose -f docker-compose.dev.yml --profile postgres up
DATABASE_CLIENT=mysql    docker compose -f docker-compose.dev.yml --profile mysql up
DATABASE_CLIENT=mariadb  docker compose -f docker-compose.dev.yml --profile mariadb up

docker compose -f docker-compose.dev.yml exec app composer test
docker compose -f docker-compose.dev.yml exec app php examples/getstarted/bin/strapi routes:list
docker compose -f docker-compose.dev.yml exec app sh -c 'cd examples/getstarted && npm install && php bin/strapi build'
```

The container runs `composer install` on first start, creates the example's `.env` from
`.env.example` when missing, points `DATABASE_HOST`/`DATABASE_PORT` at the chosen service
and waits for it. The image (`docker/Dockerfile`) is PHP 8.3 with `pdo_mysql`, `pdo_pgsql`,
`intl`, `gd`, `zip`, Composer, and Node 20 for the admin build (`--build-arg WITH_NODE=0`
to skip). `docker-compose.test.yml` mirrors upstream's and only starts Postgres and MySQL
for running the test suites against a real server.

## Layout

```
packages/core/*        ↔ strapi/strapi packages/core/*      (each a strapi/* package; published together)
packages/plugins/*     ↔ packages/plugins/*
packages/providers/*   ↔ packages/providers/*
examples/getstarted    ↔ examples/getstarted (PHP edition)
tests/php              integration suite: boots the example, hits the content API over PSR-7
scripts/               version sync, parity map, upstream release watch, offline dev manifest
```

Every PHP file sits at the path of the TypeScript file it ports, with `.php` for `.ts`
(`packages/core/core/src/middlewares/cors.ts` → `.../cors.php`). See `AGENTS.md` for the
naming rules and `VERSIONING.md` for how releases track upstream.

## Development

```sh
composer test            # phpunit, all suites
composer analyse         # phpstan level 8
composer version:check   # every package at the same version
composer bundle:check    # root composer.json (hynding/strapi-php) in sync with packages/*
composer parity          # regenerate parity.json (needs ../strapi checkout)
```

If Packagist is unreachable but GitHub is (sandboxes), `php scripts/dev-offline-manifest.php`
writes `composer.dev.json` that resolves every dependency from its GitHub mirror; run Composer
with `COMPOSER=composer.dev.json`.

## Licence

MIT, as upstream's Community Edition. Nothing under an upstream `ee/` directory is ported.
