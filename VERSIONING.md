# Versioning

The version of every `strapi/*` package in this repository **is the Strapi version it
mirrors**. They are published together as one Composer package, `hynding/strapi-php` (the
root composer.json, which `replace`s each `strapi/*` package at `self.version`), so a release
is one version of that package. Upstream uses Lerna fixed versioning (one number for every
`@strapi/*` package); `scripts/check-package-versions.php` enforces the same here, with
`packages/core/strapi/composer.json` as the canonical source.

| Situation | Tag | Users write |
| --- | --- | --- |
| Strapi 5.56.0 fully ported | `v5.56.0` | `"hynding/strapi-php": "^5.56"` |
| Strapi 5.57.0 released, port in progress | `v5.57.0-beta.1`, `-beta.2`, … | `"hynding/strapi-php": "5.57.0-beta.1"` plus `"minimum-stability": "beta", "prefer-stable": true` |
| PHP-only fix, no upstream counterpart | `v5.56.0.1`, `v5.56.0.2` | still `^5.56` — Composer sorts four-part versions after `5.56.0` |
| Strapi 6.0.0 | `v6.0.0-alpha.1` → `v6.0.0` | `^6.0` |

Rules:

1. Never skip an upstream number. If 5.57.0 cannot be fully ported before 5.58.0 ships,
   publish `5.57.0-beta.N` with a parity note and move on; `parity.json` records what is
   missing.
2. A project pins `@strapi/admin` and every plugin's admin package in `package.json` to the
   upstream release its `hynding/strapi-php` version mirrors: the version without PHP's
   pre-release or fourth number (`5.56.0-beta.1` and `5.56.0.1` both pin `5.56.0`).
   `bin/strapi build` refuses to run when the two manifests disagree.
3. During a beta, the project needs `"minimum-stability": "beta"` (with `"prefer-stable": true`):
   a plugin's `"strapi/core": "^5.57"` is a range, and Composer only lets a range match the
   beta that `hynding/strapi-php` replaces it with when the project's minimum stability allows
   betas. create-strapi-app sets both for a pre-release.
4. A given PHP `5.x.y` serves the admin bundle `@strapi/admin@5.x.y`, exposes the same REST
   and admin API routes, reads and writes the same database schema, and accepts the same
   `schema.json`, `config/*` options and data-transfer archives as Strapi `5.x.y`.

## Release loop

Upstream ships weekly (Wednesdays). `.github/workflows/release-watch.yml` polls the npm
registry daily and opens a `Parity: X.Y.Z` issue with the upstream diff link and a
checklist. The maintainer then:

```sh
git -C ../strapi fetch --tags && git -C ../strapi checkout vX.Y.Z
php scripts/parity-map.php --upstream=../strapi --tag=vX.Y.Z --diff=parity.json --out=parity.json
# port the files listed as changed-upstream / added-upstream
php scripts/check-package-versions.php --set X.Y.Z-beta.1   # or X.Y.Z at full parity
composer test && composer analyse
git tag vX.Y.Z-beta.1 && git push --tags             # Packagist picks up hynding/strapi-php;
                                                     # split.yml publishes hynding/create-strapi-app
```
