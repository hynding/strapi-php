# strapi/upgrade

Upgrade tool and codemods for strapi-php projects (port of `@strapi/upgrade`)

| | |
| --- | --- |
| Upstream | [`@strapi/upgrade`](https://github.com/strapi/strapi/tree/develop/packages/utils/upgrade) |
| Namespace | `Strapi\Upgrade\` |
| Status | `ported` |
| Version | tracks Strapi `5.56.0` |

Files mirror upstream one for one (`src/<same path>.php`); see `CLAUDE.md` at the
repository root for the naming rules. The CLI is `bin/strapi-upgrade`, the counterpart of
`npx @strapi/upgrade`.

## Usage

```sh
php vendor/bin/strapi-upgrade --help       # installed with hynding/strapi-php
strapi-upgrade --help                      # or `composer global require hynding/strapi-php`, like npx
strapi-upgrade minor --dry                  # simulate
strapi-upgrade to 5.57.0 -p path/to/app     # a specific version
strapi-upgrade codemods ls
strapi-upgrade codemods run 5.0.0-entity-service-document-service-code
```

The tool ships inside `hynding/strapi-php` (the package that `replace`s every `strapi/*` one), so
every project has `vendor/bin/strapi-upgrade`.

Upstream's commands and options, unchanged:

| Command | Options |
| --- | --- |
| `latest`, `major`, `minor`, `patch` | `-p, --project-path`, `-n, --dry`, `-d, --debug`, `-s, --silent`, `-y, --yes` |
| `to <target>` | the same, plus `-c, --codemods-target <x.y.z>` |
| `codemods run [uid]` | `-p`, `-n`, `-d`, `-s`, `-r, --range <range>` |
| `codemods ls` | `-p`, `-d`, `-s`, `-r` |

Upstream's `strapi` CLI has no `upgrade` command (the upgrade tool is only `npx @strapi/upgrade`),
so `bin/strapi` doesn't get one either.

## What an upgrade does in a strapi-php project

Same four steps as upstream: (1) requirements — on a `major` upgrade, the next major must exist
and the project must be on the latest release of its current major; always (optional) git
installed / a repository / a clean working tree; (2) the codemods between the current version and
the target; (3) the dependencies; (4) install, then the offer to clear `node_modules/.strapi/vite`.
Before that, ranged Strapi dependencies are pinned (with confirmation; in memory under `--dry`),
and `latest` asks before crossing a major.

What differs is what a version is (VERSIONING.md):

- **Current version**: `composer.json`'s `hynding/strapi-php` requirement (`5.56.0`,
  `5.56.0-beta.1`, `5.56.0.1`), or the installed one (`vendor/composer/installed.json`) when it is a
  constraint. Upstream reads `package.json`'s `@strapi/strapi`.
- **Targets**: the `hynding/strapi-php` versions on Packagist (Composer v2 `p2` metadata) whose
  upstream release (`5.57.0-beta.1` → `5.57.0`) is published as `@strapi/strapi` on npm. Release
  types (`minor`…) pick stable versions only — `x.y.z` and `x.y.z.N` — as upstream picks `x.y.z`;
  `to` reaches pre-releases. The Packagist repository is `PACKAGIST_URL`, else the first `composer`
  repository in the project's composer.json, else repo.packagist.org (`file://` works: a mirror,
  or a fake one for testing); npm's is `NPM_REGISTRY_URL`, else the package manager's
  configuration, else registry.npmjs.org, as upstream. `HTTP_PROXY`/`HTTPS_PROXY` are honoured.
- **Dependencies, in lockstep**: composer.json `hynding/strapi-php` (and any `strapi/*`) requirements on the current version move
  to the target; package.json `@strapi/*` dependencies on the current *upstream* release move to
  the target's (unchanged for `5.56.0-beta.1` → `5.56.0`). A pre-release target lowers
  `minimum-stability` to match, with `prefer-stable`.
- **Install**: `composer update "strapi/*" --with-all-dependencies` (`COMPOSER_BINARY` respected),
  then `npm|yarn|pnpm install` (lockfile detection, `Strapi\Utils\PackageManager`).

Pinning: `strapi/*` requirements that aren't exact versions are pinned to the declared floor
(`^5.56` → `5.56.0`; upstream's rule), `@strapi/*` ones to its upstream release. In composer.json
`5.56.0-beta.1` and `5.56.0.1` count as pinned.

## Codemods

Codemods are PHP files in `resources/codemods/<version>/<name>.{code,json}.php` that return a
transform (upstream: `.ts` files with a default export); uids, names and versions are upstream's.
A project's files are routed to three runners:

| Files | Runner |
| --- | --- |
| `.json` (package.json, composer.json, src/**/*.json) | `JSONRunner`: the PHP JSON codemods, `json()` API = lodash `get/has/set/merge/remove` |
| `.php` (src, config, public) | `CodeRunner`: the PHP code codemods, on nikic/php-parser with its format-preserving printer (jscodeshift's outcomes: ok / nochange / skip / error) |
| `.js .mjs .ts .jsx .tsx` (the admin customizations in `src/admin`, …) | `UpstreamRunner`: upstream's own codemod, `npx @strapi/upgrade@<upstream version> codemods run <uid>`, on a temporary copy (so `--dry` still reports); without Node.js the files are skipped with a warning. `STRAPI_UPGRADE_UPSTREAM_COMMAND` overrides the command. |

Every upstream codemod and its PHP status:

| Upstream codemod | Status |
| --- | --- |
| 5.0.0 `comment-out-lifecycle-files.code` | **ported** — comments out `content-types/<name>/lifecycles.php` (the `<?php` tag stays), same header |
| 5.0.0 `dependency-remove-strapi-plugin-i18n.json` | **ported** — package.json |
| 5.0.0 `dependency-upgrade-react-and-react-dom.json` | **ported** — package.json (the admin's peer dependencies) |
| 5.0.0 `dependency-upgrade-react-router-dom.json` | **ported** — package.json |
| 5.0.0 `dependency-upgrade-styled-components.json` | **ported** — package.json |
| 5.0.0 `deprecate-helper-plugin.code` | **not applicable to PHP sources** (admin React code); JS/TS files get upstream's codemod via npx |
| 5.0.0 `entity-service-document-service.code` | **ported** — `$strapi->entityService()->findOne($uid, $id, [...])` → `$strapi->documents($uid)->findOne(['documentId' => '__TODO__', ...])`, `publicationState` → `status`, through variables and spreads like upstream; receivers `$strapi`, `$this->strapi`, `strapi()` |
| 5.0.0 `s3-keys-wrapped-in-credentials.code` | **ported** — config/plugins.php (`return [...]`, `fn () => [...]` or a closure) |
| 5.0.0 `sqlite3-to-better-sqlite3.json` | **not applicable** — a strapi-php project's database driver is PDO, not an npm package; no file |
| 5.0.0 `strapi-public-interface.code` | **not applicable to PHP sources** (v4 `strapi()` JS factory); JS/TS files via npx |
| 5.0.0 `use-uid-for-config-namespace.code` | **ported** — `$strapi->config()->get|has|set('plugin.x')` → `'plugin::x'` (not `api.rest`/`api.responses`) |
| 5.0.0 `utils-public-interface.code` | **not applicable to PHP sources** (v4 flat `@strapi/utils` helpers, never in strapi/utils); JS/TS files via npx |
| 5.1.0 `dependency-better-sqlite3.json` | **not applicable** — same reason as sqlite3-to-better-sqlite3; no file |

Not-applicable `code` codemods keep a file returning `null` so `codemods ls` lists them and the
upstream runner still applies them to JS/TS files. `resources/examples` and `resources/utils`
(jscodeshift helpers) are not ported: they serve upstream's JS codemods only.

Since a strapi-php project starts at 5.56, the 5.0.0/5.1.0 codemods never run during an upgrade
(the range is current → target); they run with `codemods run <uid>` or `--range`.

## Deviations from upstream

- **semver**: the npm `semver` package is ported (`src/modules/version/node-semver/`, strict mode
  — the tool never uses `loose`/`includePrerelease`), plus VERSIONING.md's optional fourth number
  (`5.56.0.1`, sorts after `5.56.0`) in versions and comparators, not in `^`/`~`/x-ranges.
- **CLI**: Symfony Console instead of commander. `codemods run|ls` are registered as
  `codemods:run|ls` and the upstream spelling is mapped onto them; Symfony's global options are
  reduced to `-h`/`-V` so that `-n, --dry` and `-s, --silent` keep upstream's meaning; prompts are
  Symfony questions (non-interactive: confirmations answer no, codemod selection takes all).
- **Logs**: `Completed in 1.234s` (upstream prints `…1.234sms` after an upgrade and a bare
  millisecond count after `codemods run`).
- **s3 codemod**: config/plugins.php is located from the project root (upstream uses
  `process.cwd()`), and unrelated entries next to the keys are kept (upstream's filter drops
  non-identifier keys).
- **JSON files**: empty objects stay `{}` and the file's indentation is kept (upstream always
  writes two spaces; composer.json uses four).
- **Package manager** helpers live in strapi/utils (`packages/core/utils/src/package-manager.php`,
  port of `@strapi/utils`'s `packageManager`); `preferred-pm` is ported for its on-disk rules.
- **Seams for tests** (upstream mocks modules with Jest): injectable clock (`Timer`), output
  streams (`Logger`), fetch / exec / package-manager detection (`Npm\Package`), codemod runner
  factory and installer (`Upgrader`), `npmPackage` (upgrade task) and `codemodRunnerFactory`
  (codemods task).

## Tests

`tests/` ports every upstream `__tests__` file (codemod, codemod-repository, file-scanner, json,
logger, npm, project, strapi-dependencies, requirement, runner code/json/json-transform, timer,
upgrader, version range/semver, the codemods task, the pin-versions prompt) and adds the semver
port, Packagist sources, the upstream runner (with a fake `npx`), every bundled codemod, the
upgrade task end to end and the CLI: 186 tests.
