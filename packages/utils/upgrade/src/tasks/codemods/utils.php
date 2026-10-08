<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tasks\Codemods;

use Strapi\Upgrade\Modules\Project\Project;
use Strapi\Upgrade\Modules\Project\Utils as ProjectUtils;
use Strapi\Upgrade\Modules\Version\NodeSemver\Range as SemverRange;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer as NodeSemVer;
use Strapi\Upgrade\Modules\Version\Range;
use Strapi\Upgrade\Modules\Version\Types as VersionTypes;
use Strapi\Upgrade\Tasks\Upgrade\Upgrade;

/** Port of packages/utils/upgrade/src/tasks/codemods/utils.ts. */
final class Utils
{
    public static function resolvePath(?string $cwd = null): string
    {
        return Upgrade::resolve($cwd);
    }

    public static function getRangeFromTarget(NodeSemVer $currentVersion, NodeSemVer|string $target): SemverRange
    {
        if ($target instanceof NodeSemVer) {
            return Range::rangeFactory($target->raw);
        }

        ['major' => $major, 'minor' => $minor, 'patch' => $patch] = ['major' => $currentVersion->major, 'minor' => $currentVersion->minor, 'patch' => $currentVersion->patch];

        return match ($target) {
            VersionTypes::LATEST => throw new \RuntimeException("Can't use <latest> to create a codemods range: not implemented"),
            VersionTypes::MAJOR => Range::rangeFactory("{$major}"),
            VersionTypes::MINOR => Range::rangeFactory("{$major}.{$minor}"),
            VersionTypes::PATCH => Range::rangeFactory("{$major}.{$minor}.{$patch}"),
            default => throw new \RuntimeException("Invalid target set: {$target}"),
        };
    }

    public static function findRangeFromTarget(Project $project, NodeSemVer|SemverRange|string $target): SemverRange
    {
        // If a range is manually defined, use it
        if ($target instanceof SemverRange) {
            return $target;
        }

        // If the current project is a Strapi application
        // Get the range from the given target
        if (ProjectUtils::isApplicationProject($project)) {
            return self::getRangeFromTarget($project->strapiVersion, $target);
        }

        // Else, if the project is a Strapi plugin or anything else
        // Set the range to match any version
        return Range::rangeFactory('*');
    }
}
