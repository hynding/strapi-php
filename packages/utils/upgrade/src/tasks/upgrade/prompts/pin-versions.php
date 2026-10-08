<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tasks\Upgrade\Prompts;

use Strapi\Upgrade\Modules\Error\AbortedError;
use Strapi\Upgrade\Modules\Format\Formats as F;
use Strapi\Upgrade\Modules\Json\File;
use Strapi\Upgrade\Modules\Project\Project;
use Strapi\Upgrade\Modules\Project\StrapiDependencies;
use Strapi\Upgrade\Modules\Project\Utils;
use Strapi\Upgrade\Modules\Upgrader\Constants as UpgraderConstants;

/**
 * Port of packages/utils/upgrade/src/tasks/upgrade/prompts/pin-versions.ts: detects ranged
 * Strapi dependencies and offers to pin them before upgrading.
 *
 * The upgrade tool matches and bumps only exact pins. Ranges (e.g. ^5.56) make the current
 * version ambiguous and can cause false "already up-to-date" errors. For a strapi-php project
 * both manifests are pinned: `strapi/*` in composer.json to the project's version, `@strapi/*`
 * in package.json to the upstream release it mirrors.
 *
 * @phpstan-import-type UpgradeOptions from \Strapi\Upgrade\Tasks\Upgrade\Types
 */
final class PinVersions
{
    /** @param UpgradeOptions $options */
    public static function pinVersions(Project $project, array $options): void
    {
        Utils::assertAppProject($project);

        ['require' => $require, 'require-dev' => $requireDev] = StrapiDependencies::getComposerDependencyRecords($project->composerJSON ?? []);
        ['dependencies' => $dependencies, 'devDependencies' => $devDependencies] = StrapiDependencies::getPackageDependencyRecords($project->packageJSON);

        $unpinnedComposer = StrapiDependencies::findUnpinnedComposerStrapiDependencies($require, $requireDev);
        $unpinnedNpm = StrapiDependencies::findUnpinnedStrapiDependencies($dependencies, $devDependencies);

        if ($unpinnedComposer === [] && $unpinnedNpm === []) {
            return;
        }

        $pinTarget = StrapiDependencies::getStrapiPinTargetVersion($project);
        $pinVersion = $pinTarget->raw;
        $npmPinVersion = UpgraderConstants::upstreamVersion($pinVersion);

        $unpinnedList = implode(', ', array_map(
            static fn (array $d): string => "{$d['name']} (" . F::highlight($d['declaredVersion']) . ')',
            [...$unpinnedComposer, ...$unpinnedNpm]
        ));

        $fPins = F::version($pinTarget) . ($npmPinVersion !== $pinVersion ? ' / ' . F::version($npmPinVersion) : '');

        $options['logger']->warn(implode(' ', [
            'Found strapi/* or @strapi/* dependencies using version ranges instead of pinned versions:',
            $unpinnedList,
            "(will pin to {$fPins} before upgrading)",
        ]));

        $confirmed = isset($options['confirm']) ? ($options['confirm'])("Pin all strapi/* and @strapi/* dependencies to {$fPins} before upgrading?") : true;

        if (!$confirmed) {
            throw new AbortedError();
        }

        $updatedComposerJSON = $unpinnedComposer !== [] && $project->composerJSON !== null
            ? StrapiDependencies::pinComposerStrapiDependencies($project->composerJSON, $pinVersion, $unpinnedComposer)
            : null;
        $updatedPackageJSON = $unpinnedNpm !== [] ? StrapiDependencies::pinStrapiDependencies($project->packageJSON, $npmPinVersion, $unpinnedNpm) : null;

        if ($options['dry'] ?? false) {
            if ($updatedPackageJSON !== null) {
                $project->applyPackageJSON($updatedPackageJSON);
            }
            if ($updatedComposerJSON !== null) {
                $project->applyComposerJSON($updatedComposerJSON);
            }
            $options['logger']->warn('Pinned versions in memory only (dry mode).');

            return;
        }

        if ($updatedComposerJSON !== null) {
            File::saveJSON($project->composerJSONPath, $updatedComposerJSON);
        }
        if ($updatedPackageJSON !== null) {
            File::saveJSON($project->packageJSONPath, $updatedPackageJSON);
        }
        $project->refresh();
    }
}
