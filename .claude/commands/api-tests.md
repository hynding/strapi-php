---
description: Run upstream's Jest API suite against strapi-php and explain what changed
argument-hint: "[directories or files, e.g. core/admin plugins/i18n] (default: all)"
---

Run upstream Strapi's API suite (tests/api/README.md) and report.

1. If `tests/api/.upstream` and `tests/api/.bin/frankenphp` are missing and `STRAPI_UPSTREAM` /
   `FRANKENPHP_BIN` are unset, run `tests/api/scripts/setup.sh` first.
2. `cd tests/api && npm test -- $ARGUMENTS` (directories or files relative to upstream's
   `tests/api`; no argument runs every non-Enterprise directory, about 35 minutes with 2 lanes).
   Run it in the background and wait for it; don't run two at once on a small machine.
3. Read the summary it prints (passed / (passed + failed) per directory, and the tests that
   changed since the previous run). For details: `node scripts/summary.js <run> --failures`.
   Logs: `.results/<run>/<dir>.log`; FrankenPHP's: `.tmp/runs/<run>/<dir>/app/.tmp/frankenphp-*.log`.
4. For every test that **no longer passes**, rerun its file alone
   (`npm test -- <file> --name <run>-recheck`). Known flaky ones are listed in docs/README.md
   ("Open work"): `basic-pagination` (rows created in parallel) and an occasional socket hang-up in
   `content-type-builder/schema`. Anything else is a regression: find the cause.
5. Report: the per-directory table, regressions with their cause, newly passing tests. If the
   numbers changed for good, update the status table and totals in README.md.

Never use `pkill -f <pattern>` to stop stray servers: the pattern matches your own shell.
Kill by PID (`pgrep -af '[f]rankenphp'`).
