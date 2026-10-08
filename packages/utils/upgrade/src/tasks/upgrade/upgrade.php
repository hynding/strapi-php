<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tasks\Upgrade;

use Strapi\Upgrade\Modules\Format\Formats as F;
use Strapi\Upgrade\Modules\Packagist\StrapiPackage;
use Strapi\Upgrade\Modules\Project\Project;
use Strapi\Upgrade\Modules\Project\Utils;
use Strapi\Upgrade\Modules\Timer\Timer;
use Strapi\Upgrade\Modules\Upgrader\Upgrader;
use Strapi\Upgrade\Modules\Version\Types as VersionTypes;
use Strapi\Upgrade\Tasks\Upgrade\Prompts\Latest;
use Strapi\Upgrade\Tasks\Upgrade\Prompts\PinVersions;
use Strapi\Upgrade\Tasks\Upgrade\Requirements\Common;
use Strapi\Upgrade\Tasks\Upgrade\Requirements\Major;

/**
 * Port of packages/utils/upgrade/src/tasks/upgrade/upgrade.ts.
 *
 * @phpstan-import-type UpgradeOptions from Types
 */
final class Upgrade
{
    /** @param UpgradeOptions $options */
    public static function upgrade(array $options): void
    {
        $timer = Timer::timerFactory();
        $logger = $options['logger'];
        $codemodsTarget = $options['codemodsTarget'] ?? null;

        // Resolves the correct working directory based on the given input
        $cwd = self::resolve($options['cwd'] ?? null);

        $project = Project::projectFactory($cwd);

        $logger->debug(F::projectDetails($project));

        if (!Utils::isApplicationProject($project)) {
            throw new \RuntimeException("The \"{$options['target']}\" upgrade can only be run on a Strapi project; for plugins, please use \"codemods\".");
        }

        $logger->debug('Application: VERSION=' . F::version((string) ($project->packageJSON['version'] ?? $project->composerJSON['version'] ?? '0.0.0')) . '; STRAPI_VERSION=' . F::version($project->strapiVersion));

        $npmPackage = $options['npmPackage'] ?? StrapiPackage::strapiPackageFactory($project->cwd, $logger);

        // Load all available versions from Packagist and the npm registry
        $npmPackage->refresh();

        // Pin ranged strapi/* and @strapi/* dependencies before resolving upgrade targets
        PinVersions::pinVersions($project, $options);

        // Initialize the upgrade instance
        // Throws during initialization if the provided target is incompatible with the current version
        $upgrader = Upgrader::upgraderFactory($project, $options['target'], $npmPackage)
            ->dry($options['dry'] ?? false)
            ->onConfirm($options['confirm'] ?? null)
            ->setLogger($logger);

        // Manually override the target version for codemods if it's explicitly provided
        if ($codemodsTarget !== null) {
            $upgrader->overrideCodemodsTarget($codemodsTarget);
        }

        // Prompt user for confirmation details before upgrading
        self::runUpgradePrompts($upgrader, $options);

        // Add specific requirements before upgrading
        self::addUpgradeRequirements($upgrader, $options);

        // Actually run the upgrade process once configured,
        // The response contains information about the final status: success/error
        $upgradeReport = $upgrader->upgrade();

        if (!$upgradeReport['success']) {
            throw $upgradeReport['error'];
        }

        $timer->stop();

        $logger->info('Completed in ' . F::durationMs($timer->elapsedMs()));
    }

    /** `path.resolve(cwd ?? process.cwd())` */
    public static function resolve(?string $cwd): string
    {
        $base = (string) getcwd();
        if ($cwd === null || $cwd === '') {
            return $base;
        }

        $path = str_starts_with($cwd, '/') || preg_match('~^[A-Za-z]:[\\\\/]~', $cwd) === 1 ? $cwd : $base . DIRECTORY_SEPARATOR . $cwd;

        return realpath($path) ?: rtrim($path, DIRECTORY_SEPARATOR);
    }

    /** @param UpgradeOptions $options */
    private static function runUpgradePrompts(Upgrader $upgrader, array $options): void
    {
        if ($options['target'] === VersionTypes::LATEST) {
            Latest::latest($upgrader, $options);
        }
    }

    /** @param UpgradeOptions $options */
    private static function addUpgradeRequirements(Upgrader $upgrader, array $options): void
    {
        // Major release upgrades enforce stepping through the latest patch for the current major.
        // Semver targets (via the "to" command) skip these checks intentionally.
        if ($options['target'] === VersionTypes::MAJOR) {
            $upgrader
                ->addRequirement(Major::REQUIRE_AVAILABLE_NEXT_MAJOR())
                ->addRequirement(Major::REQUIRE_LATEST_FOR_CURRENT_MAJOR());
        }

        // Make sure the git repository is in an optimal state before running the upgrade
        // Mainly used to ease rollbacks in case the upgrade is corrupted
        $upgrader->addRequirement(Common::REQUIRE_GIT()->asOptional());
    }
}
