---
description: Port an upstream Strapi release (the VERSIONING.md release loop), end to end
argument-hint: <upstream version, e.g. 5.57.0>
---

Port upstream Strapi release **$ARGUMENTS** to strapi-php. VERSIONING.md is the policy;
AGENTS.md (CLAUDE.md) has the file-for-file rules. Work on a branch `port/$ARGUMENTS`.

1. **Upstream checkout.** Use `../strapi` if it exists, otherwise `tests/api/.upstream`
   (`tests/api/scripts/setup.sh` clones it). Fetch tags and check out `v$ARGUMENTS`. Read the
   upstream release notes / compare view for v<previous>...v$ARGUMENTS to know what moved.

2. **What changed.** Regenerate the parity map against the new tag:
   `php scripts/parity-map.php --upstream=<checkout> --tag=v$ARGUMENTS --diff=parity.json --out=parity.json`.
   List the files flagged `changed-upstream` and `added-upstream` (skip `ee` ones and the
   `notApplicable` list). Group them by package and show me the list before porting if it is
   longer than ~40 files.

3. **Port.** For each file, port the upstream change into the mirrored `.php` file (same path,
   same name; see /port-file for the rules). Port the matching `__tests__` changes to PHPUnit.
   New dependencies go in the package's composer.json, then `composer bundle:fix`.

4. **Version.** Full parity: `php scripts/check-package-versions.php --set $ARGUMENTS`. Anything
   left: `--set $ARGUMENTS-beta.1` and note what is missing in `docs/README.md`
   ("Open work"). Bump the `@strapi/*` pins that follow Strapi's version to $ARGUMENTS
   (VERSIONING.md rule 2): `@strapi/admin`, `@strapi/strapi`, `@strapi/plugin-*`,
   `@strapi/typescript-utils`, `@strapi/utils` (tests/api). Leave the ones with their own
   numbering (`@strapi/design-system`, `@strapi/icons`, `@strapi/sdk-plugin`). Find them with
   `grep -rn '"@strapi/' --include=package.json . | grep -v node_modules`; create-strapi-app
   derives its pins from the package version, nothing to edit there.

5. **Check.** `composer test`, `composer analyse`, `composer bundle:check`, `composer version:check`,
   then the upstream API suite at the new tag: point `STRAPI_UPSTREAM` at the checkout and run
   `cd tests/api && npm test -- --name v$ARGUMENTS`. Compare with the previous run (the runner
   does it) and explain every test that no longer passes.

6. **Wrap up.** Update the status numbers in README.md, commit, push, open a PR titled
   "Port Strapi $ARGUMENTS". Remind me to tag `v$ARGUMENTS` (or the beta) after merging; the tag
   is what publishes `hynding/strapi-php` and `hynding/create-strapi-app`.
