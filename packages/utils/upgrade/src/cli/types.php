<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Cli;

/**
 * Port of packages/utils/upgrade/src/cli/types.ts.
 *
 * @phpstan-type CLIUpgradeOptions array{debug: bool, silent: bool, projectPath?: string|null, yes?: bool, dry: bool}
 * @phpstan-type CLIUpgradeToOptions array{debug: bool, silent: bool, projectPath?: string|null, yes?: bool, dry: bool, codemodsTarget?: \Strapi\Upgrade\Modules\Version\NodeSemver\SemVer|null}
 * @phpstan-type CLIRunCodemodsOptions array{debug: bool, silent: bool, projectPath?: string|null, range?: \Strapi\Upgrade\Modules\Version\NodeSemver\Range|null, dry: bool}
 * @phpstan-type CLIListCodemodsOptions array{debug: bool, silent: bool, projectPath?: string|null, range?: \Strapi\Upgrade\Modules\Version\NodeSemver\Range|null}
 * @phpstan-type UpgradeCommandOptions array{debug: bool, silent: bool, projectPath?: string|null, yes?: bool, dry: bool, target: string|\Strapi\Upgrade\Modules\Version\NodeSemver\SemVer, codemodsTarget?: \Strapi\Upgrade\Modules\Version\NodeSemver\SemVer|null}
 * @phpstan-type RunCodemodsCommandOptions array{debug: bool, silent: bool, projectPath?: string|null, range?: \Strapi\Upgrade\Modules\Version\NodeSemver\Range|null, dry: bool, uid: string|null}
 */
final class Types
{
}
