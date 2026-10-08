<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Runner\Upstream;

/**
 * PHP-only.
 *
 * `command` is the upstream tool's command prefix, run with `codemods run <uid> --project-path
 * <dir>` appended; it defaults to `npx --yes @strapi/upgrade@<upstream version of this package>`.
 * `strapiVersion` is the `@strapi/strapi` version written to the temporary project.
 *
 * @phpstan-type UpstreamRunnerConfiguration array{dry?: bool, cwd: string, strapiVersion: string, command?: list<string>|null, timeout?: float|null}
 */
final class Types
{
    /** `Report['stats']` key set when the upstream tool could not run (no Node.js) */
    public const STAT_UNAVAILABLE = 'upstream-unavailable';
}
