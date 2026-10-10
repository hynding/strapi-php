---
description: Port one upstream TypeScript file (and its test) to strapi-php
argument-hint: <upstream path, e.g. packages/core/core/src/middlewares/cors.ts>
---

Port upstream's **$ARGUMENTS** following AGENTS.md (CLAUDE.md) exactly.

1. Read the upstream file (in `../strapi` or `tests/api/.upstream`) and its `__tests__` file.
   Check `parity.json` for its entry: if it is `ee`, stop and tell me (Enterprise code is not
   ported); if `notApplicable`, tell me the recorded reason.
2. **Target path**: the same path with `.php` (`src/a/b-c.ts` → `src/a/b-c.php`). Class name =
   StudlyCase of the file name, namespace = package namespace + StudlyCase directories; an
   `index.ts` takes its directory's name. Exported factories become invokable `final` classes;
   feature-package services/controllers follow docs/porting-feature-packages.md.
3. **Semantics over syntax**: keep upstream's option names, wire formats, error names/statuses
   (`Strapi\Utils\Errors\*`), and behaviour the admin bundle depends on. Promises are plain calls.
   Read neighbouring ported files first and reuse their helpers (`Strapi\Utils\*`, Yup/Zod ports)
   instead of writing new ones.
4. **Test**: port the upstream `__tests__` cases to PHPUnit in the package's `tests/`
   (`__tests__/cors.test.ts` → `tests/CorsTest.php`). Jest mocks become small test doubles.
5. **Wire up**: `composer dump-autoload`; if you added a dependency or autoload path, `composer bundle:fix`.
   Run the package's tests, `composer analyse` (PHPStan level 8), and
   `php scripts/parity-map.php` to confirm the file now counts as ported.
6. Tell me anything you could not port faithfully, and why, in one short list.
