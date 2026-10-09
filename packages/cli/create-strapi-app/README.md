# strapi/create-strapi-app

Generate a new Strapi application, PHP edition (port of `create-strapi-app`).

| | |
| --- | --- |
| Upstream | [`create-strapi-app`](https://github.com/strapi/strapi/tree/develop/packages/cli/create-strapi-app) |
| Namespace | `Strapi\CreateStrapiApp\` |
| Status | `ported` (Strapi Cloud login not ported) |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`src/<same path>.php`, `templates/<name>`); see `AGENTS.md`
at the repository root for the naming rules.

## Usage

```sh
composer create-project hynding/create-strapi-app my-project         # add --stability=beta during a beta
CREATE_STRAPI_APP_ARGS="--quickstart --dbclient=postgres ..." \
  composer create-project hynding/create-strapi-app my-project -n    # flags for the create-project run

composer global require hynding/create-strapi-app
create-strapi-app my-project --quickstart                            # same flags as npx create-strapi-app
```

`composer create-project` unpacks this package into `my-project` and runs
`bin/create-strapi-app --in-place` (`post-create-project-cmd`): the project is generated in a
temporary directory, the bootstrapper's files are removed (`vendor/` is kept and reconciled by the
following `composer install`) and the project is moved in. Composer cannot forward flags to that
script, hence `CREATE_STRAPI_APP_ARGS`. Without a terminal (CI, `-n`) nothing is prompted, like
`--non-interactive`. `hynding/create-strapi` is the alias package (upstream's `create-strapi`; kept for parity,
not published).

This is the one strapi-php package published on its own: `composer create-project` uses a
package's root as the new project, so the generator cannot live inside `hynding/strapi-php`.

The generated project (both templates):

- `composer.json` requiring `hynding/strapi-php` (every strapi-php package in one: the CLI, the
  admin API, the internal plugins, users-permissions, the providers, as upstream's
  `@strapi/strapi` npm package brings in), plus `ext-pdo_<client>` for the chosen database. Versions follow `VERSIONING.md`: a stable
  `5.56.0` is required as `^5.56`; a pre-release is required exactly with
  `"minimum-stability": "beta", "prefer-stable": true`. Scripts `develop`, `start`, `build`,
  `console`, `strapi` (`composer develop`...), `process-timeout: 0`.
- `package.json` pinning `@strapi/admin`, `@strapi/strapi` and `@strapi/plugin-users-permissions`
  to the upstream release (`5.56.0` for `5.56.0-beta.1`, rule 2) plus react / react-dom /
  react-router-dom / styled-components, and the `strapi` key (`uuid`, `installId`, `template`).
- `.env` with fresh secrets (APP_KEYS ×4, API_TOKEN_SALT, ADMIN_JWT_SECRET, JWT_SECRET,
  TRANSFER_TOKEN_SALT, ENCRYPTION_KEY: 16 random bytes in base64 each, upstream's template) and the
  database connection; `.gitignore` (upstream's plus `vendor`).
- `config/*.php`, `src/index.php`, `bin/strapi`, `public/index.php` (strapi/strapi's front
  controller: PHP-FPM, built-in server, FrankenPHP worker mode), `src/admin/*.example.*` (the
  admin customisation files, TypeScript as upstream), favicon, robots.txt.
- `--example`: the blog content types (`src/api/{article,author,category,global,about}`), shared
  components, `data/` and `scripts/seed.php` (`composer seed:example`), run after installing.

## Ported

| Upstream | PHP | Notes |
| --- | --- | --- |
| `bin/index.js` | `bin/create-strapi-app` | |
| `src/index.ts` | `src/index.php` (`CreateStrapiApp`) | commander → Symfony `SingleCommandApplication`; same arguments, options and validations |
| `src/create-strapi.ts` | `src/create-strapi.php` | also writes `composer.json`; install = `composer install` then the package manager; seed/run with `php` |
| `src/prompts.ts` | `src/prompts.php` | inquirer → QuestionHelper |
| `src/types.ts` | `src/types.php` | `@phpstan-type` shapes; `Scope` is an array |
| `src/utils/check-install-path.ts` | `src/utils/check-install-path.php` | |
| `src/utils/check-requirements.ts` | `src/utils/check-requirements.php` | PHP ≥ 8.3 is the hard requirement; an unsupported or missing Node is a warning (only the admin build needs it) |
| `src/utils/database.ts` | `src/utils/database.php` | Node drivers → `ext-pdo_*` Composer requirements |
| `src/utils/dot-env.ts` | `src/utils/dot-env.php` | same template and secret generation |
| `src/utils/engines.ts` | `src/utils/engines.php` | |
| `src/utils/get-package-manager-args.ts` | `src/utils/get-package-manager-args.php` | execa → injectable runner |
| `src/utils/git.ts`, `gitignore.ts`, `install-id.ts`, `logger.ts`, `package-json.ts`, `pnpm-config.ts`, `template.ts` | same paths | `sort-package-json`, lodash `kebabCase`/`mergeWith`, `semver` (`src/utils/semver.php`) and tar (`PharData`) ported locally |
| `src/utils/usage.ts` | `src/utils/usage.php` | no-op: no analytics are sent (like strapi/core's telemetry) |
| `templates/vanilla`, `templates/example` | same paths, PHP edition | `-js` variants not ported: there is one server language |
| `src/__tests__/templates-database.test.ts` | `tests/TemplatesDatabaseTest.php` | |
| `src/utils/__tests__/get-package-manager-args.test.ts` | `tests/Utils/GetPackageManagerArgsTest.php` | |

PHP-only: `src/utils/composer-json.php`, `src/utils/semver.php`, `src/utils/fatal-error.php`
(`logger.fatal()` throws it instead of `process.exit(1)`; it extends `\RuntimeException` because,
like upstream, this package depends on no Strapi runtime package).

## Not ported

- `src/cloud.ts` (Strapi Cloud login / growth SSO trial `license.txt`) and
  `src/utils/parse-to-chalk.ts` (only formats the Cloud API's intro text): they need
  `@strapi/cloud-cli` and the Strapi Cloud API, which host Node projects. `--skip-cloud` is
  accepted and always in effect; the `deploy` script is not in the templates.
- `--ts` / `--js` (and `--typescript` / `--javascript`) are accepted and ignored with a warning;
  `--template` with either is still refused, as upstream.
- The `upgrade` / `upgrade:dry` npm scripts (`npx @strapi/upgrade`): strapi-php's upgrade tool is
  `strapi/upgrade` (`strapi-upgrade`).

## Deviations

- Official templates (`--template <name>`) are looked up in `hynding/strapi-php`
  (`templates/<name>`) instead of `strapi/strapi`, whose templates are Node projects. A template
  must contain `composer.json` (upstream: `package.json`).
- A GitHub URL template (`https://github.com/o/r/tree/branch/path`) installs; upstream throws
  "Invalid GitHub template URL" after downloading it (missing `return`).
- `.gitignore` is written with the other files, before installing (upstream: after).
- The example seed passes `status: 'published'`: upstream's `publishedAt: Date.now()` is dropped by
  the document service, which leaves the example content as drafts the public API cannot read.
