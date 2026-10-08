<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tasks\Upgrade\Requirements;

use Strapi\Upgrade\Modules\Npm\Package as NpmPackage;
use Strapi\Upgrade\Modules\Requirement\Requirement;
use Strapi\Upgrade\Modules\Version\Semver;

/** Port of packages/utils/upgrade/src/tasks/upgrade/requirements/major.ts. */
final class Major
{
    public static function REQUIRE_AVAILABLE_NEXT_MAJOR(): Requirement
    {
        return Requirement::requirementFactory('REQUIRE_AVAILABLE_NEXT_MAJOR', static function (array $context): void {
            $currentMajor = $context['project']->strapiVersion->major;
            $targetedMajor = $context['target']->major;

            if ($targetedMajor === $currentMajor) {
                throw new \RuntimeException("You're already on the latest major version (v{$currentMajor})");
            }
        });
    }

    public static function REQUIRE_LATEST_FOR_CURRENT_MAJOR(): Requirement
    {
        return Requirement::requirementFactory('REQUIRE_LATEST_FOR_CURRENT_MAJOR', static function (array $context): void {
            $currentMajor = $context['project']->strapiVersion->major;

            $invalidVersions = [];
            foreach ($context['npmVersionsMatches'] as $match) {
                $version = NpmPackage::versionOf($match);
                if (Semver::semVerFactory($version)->major === $currentMajor) {
                    $invalidVersions[] = $version;
                }
            }

            if ($invalidVersions !== []) {
                $count = count($invalidVersions);
                $last = $invalidVersions[$count - 1];

                throw new \RuntimeException("Doing a major upgrade requires to be on the latest v{$currentMajor} version, but found {$count} versions between the current one and {$context['target']}. Please upgrade to {$last} and try again.");
            }
        });
    }
}
