<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tasks\Upgrade\Prompts;

use Strapi\Upgrade\Modules\Error\AbortedError;
use Strapi\Upgrade\Modules\Format\Formats as F;
use Strapi\Upgrade\Modules\Npm\Package as NpmPackage;
use Strapi\Upgrade\Modules\Upgrader\Upgrader;
use Strapi\Upgrade\Modules\Version\Range;
use Strapi\Upgrade\Modules\Version\Semver;
use Strapi\Upgrade\Modules\Version\Types as VersionTypes;

/**
 * Port of packages/utils/upgrade/src/tasks/upgrade/prompts/latest.ts: when using the latest tag,
 * checks if an upgrade involves a major bump, warning and asking for user confirmation before
 * proceeding.
 *
 * @phpstan-import-type UpgradeOptions from \Strapi\Upgrade\Tasks\Upgrade\Types
 */
final class Latest
{
    /** @param UpgradeOptions $options */
    public static function latest(Upgrader $upgrader, array $options): void
    {
        // Exit if the upgrade target isn't the latest tag
        if ($options['target'] !== VersionTypes::LATEST) {
            return;
        }

        // Retrieve utilities from the upgrader instance
        $npmPackage = $upgrader->getNPMPackage();
        $target = $upgrader->getTarget();
        $current = $upgrader->getProject()->strapiVersion;

        // Pre-formatted strings used in logs
        $fTargetMajor = F::highlight("v{$target->major}");
        $fCurrentMajor = F::highlight("v{$current->major}");

        $fTarget = F::version($target);
        $fCurrent = F::version($current);

        // Handle potential major upgrade, warns, and asks for confirmation to proceed
        if ($target->major > $current->major) {
            $options['logger']->warn('Detected a major upgrade for the "' . F::highlight(VersionTypes::LATEST) . "\" tag: {$fCurrent} > {$fTarget}");

            // Find the latest release in between the current one and the next major
            $releases = $npmPackage->findVersionsInRange(Range::rangeFactory(">{$current->raw} <{$target->major}"));
            $newerPackageRelease = $releases === [] ? null : $releases[count($releases) - 1];

            // If the project isn't on the latest version for the current major, emit a warning
            if ($newerPackageRelease !== null) {
                $fLatest = F::version(Semver::semVerFactory(NpmPackage::versionOf($newerPackageRelease)));
                $options['logger']->warn("It's recommended to first upgrade to the latest version of {$fCurrentMajor} ({$fLatest}) before upgrading to {$fTargetMajor}.");
            }

            $proceedAnyway = $upgrader->confirm("I know what I'm doing. Proceed anyway!");

            if (!$proceedAnyway) {
                throw new AbortedError();
            }
        }
    }
}
