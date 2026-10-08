<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tasks\Upgrade;

/**
 * Port of packages/utils/upgrade/src/tasks/upgrade/types.ts.
 *
 * PHP-only: `npmPackage`, the source of upgrade targets (default: `StrapiPackage`, Packagist ∩
 * npm), injectable for tests and offline use.
 *
 * @phpstan-type UpgradeOptions array{logger: \Strapi\Upgrade\Modules\Logger\Logger, confirm?: (\Closure(string): bool)|null, cwd?: string|null, dry?: bool, target: string|\Strapi\Upgrade\Modules\Version\NodeSemver\SemVer, codemodsTarget?: \Strapi\Upgrade\Modules\Version\NodeSemver\SemVer|null, npmPackage?: \Strapi\Upgrade\Modules\Npm\PackageInterface|null}
 */
final class Types
{
}
