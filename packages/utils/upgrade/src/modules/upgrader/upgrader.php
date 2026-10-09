<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Upgrader;

use Strapi\Upgrade\Modules\CodemodRunner\CodemodRunner;
use Strapi\Upgrade\Modules\Error\NPMCandidateNotFoundError;
use Strapi\Upgrade\Modules\Error\Utils as ErrorUtils;
use Strapi\Upgrade\Modules\Format\Chalk;
use Strapi\Upgrade\Modules\Format\Formats as F;
use Strapi\Upgrade\Modules\Json\File;
use Strapi\Upgrade\Modules\Json\JSONTransformAPI;
use Strapi\Upgrade\Modules\Logger\Logger;
use Strapi\Upgrade\Modules\Npm\Package as NpmPackage;
use Strapi\Upgrade\Modules\Npm\PackageInterface;
use Strapi\Upgrade\Modules\Project\AppProject;
use Strapi\Upgrade\Modules\Project\Constants as ProjectConstants;
use Strapi\Upgrade\Modules\Project\Project;
use Strapi\Upgrade\Modules\Requirement\Requirement;
use Strapi\Upgrade\Modules\Version\NodeSemver\Range as SemverRange;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer as NodeSemVer;
use Strapi\Upgrade\Modules\Version\Range;
use Strapi\Upgrade\Modules\Version\Semver;
use Strapi\Utils\PackageManager;
use Symfony\Component\Process\Process;

/**
 * Port of packages/utils/upgrade/src/modules/upgrader/upgrader.ts.
 *
 * The version a strapi-php project upgrades is its `strapi/strapi` Composer version, and both
 * manifests move in lockstep (VERSIONING.md):
 * - composer.json: `hynding/strapi-php` (and any `strapi/*` requirement) on the current version is set to the target
 *   (`5.56.0-beta.1` → `5.57.0`), and a pre-release target lowers `minimum-stability`
 *   accordingly (with `prefer-stable`);
 * - package.json: every `@strapi/*` dependency on the current upstream release is set to the
 *   target's (`5.56.0` → `5.57.0`); unchanged when both mirror the same release.
 * Step 4 runs `composer update hynding/strapi-php "strapi/*" --with-all-dependencies`, then the Node package
 * manager's install, then (as upstream) offers to clear the admin's Vite cache.
 *
 * PHP-only seams (upstream's tests mock modules instead): `setCodemodRunnerFactory()` and
 * `setInstaller()`.
 *
 * @phpstan-import-type UpgradeReport from Types
 * @phpstan-import-type Installer from Types
 * @phpstan-import-type TestContext from \Strapi\Upgrade\Modules\Requirement\Types
 * @phpstan-import-type ConfirmationCallback from \Strapi\Upgrade\Modules\Common\Types
 */
final class Upgrader
{
    private NodeSemVer $codemodsTarget;

    private bool $isDry = false;

    private ?Logger $logger = null;

    /** @var list<Requirement> */
    private array $requirements = [];

    /** @var ConfirmationCallback|null */
    private ?\Closure $confirmationCallback = null;

    /** @var \Closure(Project, SemverRange): CodemodRunner */
    private \Closure $codemodRunnerFactory;

    /** @var Installer */
    private \Closure $installer;

    private const STABILITIES = ['dev' => 0, 'alpha' => 1, 'beta' => 2, 'RC' => 3, 'stable' => 4];

    public function __construct(private readonly AppProject $project, private NodeSemVer $target, private readonly PackageInterface $npmPackage)
    {
        $this->codemodRunnerFactory = CodemodRunner::codemodRunnerFactory(...);
        $this->installer = self::defaultInstaller(...);
        $this->syncCodemodsTarget();
    }

    public function getNPMPackage(): PackageInterface
    {
        return $this->npmPackage;
    }

    public function getProject(): AppProject
    {
        return $this->project;
    }

    public function getTarget(): NodeSemVer
    {
        return Semver::semVerFactory($this->target->raw);
    }

    public function getCodemodsTarget(): NodeSemVer
    {
        return $this->codemodsTarget;
    }

    /** @param list<Requirement> $requirements */
    public function setRequirements(array $requirements): self
    {
        $this->requirements = $requirements;

        return $this;
    }

    public function setTarget(NodeSemVer $target): self
    {
        $this->target = $target;

        return $this;
    }

    public function syncCodemodsTarget(): self
    {
        // Extract the <major>.<minor>.<patch> version from the target and assign it to the codemods target
        //
        // This is useful when dealing with alphas, betas or release candidates:
        // e.g. "5.0.0-beta.951" becomes "5.0.0" (and a PHP-only "5.56.0.1" becomes "5.56.0")
        //
        // For experimental versions (e.g. "0.0.0-experimental.hex"), it is necessary to
        // override the codemods target manually in order to run the appropriate ones.
        $this->codemodsTarget = Semver::semVerFactory("{$this->target->major}.{$this->target->minor}.{$this->target->patch}");

        $this->logger?->debug('The codemods target has been synced with the upgrade target. The codemod runner will now look for ' . F::version($this->codemodsTarget));

        return $this;
    }

    public function overrideCodemodsTarget(NodeSemVer $target): self
    {
        $this->codemodsTarget = $target;

        $this->logger?->debug('Overriding the codemods target. The codemod runner will now look for ' . F::version($target));

        return $this;
    }

    public function setLogger(Logger $logger): self
    {
        $this->logger = $logger;

        return $this;
    }

    /** @param ConfirmationCallback|null $callback */
    public function onConfirm(?\Closure $callback): self
    {
        $this->confirmationCallback = $callback;

        return $this;
    }

    public function dry(bool $enabled = true): self
    {
        $this->isDry = $enabled;

        return $this;
    }

    /** @param \Closure(Project, SemverRange): CodemodRunner $factory */
    public function setCodemodRunnerFactory(\Closure $factory): self
    {
        $this->codemodRunnerFactory = $factory;

        return $this;
    }

    /** @param Installer $installer */
    public function setInstaller(\Closure $installer): self
    {
        $this->installer = $installer;

        return $this;
    }

    public function addRequirement(Requirement $requirement): self
    {
        $this->requirements[] = $requirement;

        $fRequired = $requirement->isRequired ? '(required)' : '(optional)';
        $this->logger?->debug('Added a new requirement to the upgrade: ' . F::highlight($requirement->name) . " {$fRequired}");

        return $this;
    }

    /** @return UpgradeReport */
    public function upgrade(): array
    {
        $this->logger?->info('Upgrading from ' . F::version($this->project->strapiVersion) . ' to ' . F::version($this->target));

        if ($this->isDry) {
            $this->logger?->warn('Running the upgrade in dry mode. No files will be modified during the process.');
        }

        $range = Range::rangeFromVersions($this->project->strapiVersion, $this->target);
        $codemodsRange = Range::rangeFromVersions($this->project->strapiVersion, $this->codemodsTarget);

        $npmVersionsMatches = $this->npmPackage->findVersionsInRange($range);

        $this->logger?->debug('Found ' . F::highlight(count($npmVersionsMatches)) . ' versions satisfying ' . F::versionRange($range));

        try {
            $this->logger?->info(F::upgradeStep('Checking requirement', [1, 4]));
            $this->checkRequirements($this->requirements, [
                'npmVersionsMatches' => $npmVersionsMatches,
                'project' => $this->project,
                'target' => $this->target,
            ]);

            $this->logger?->info(F::upgradeStep('Applying the latest code modifications', [2, 4]));
            $this->runCodemods($codemodsRange);

            // We need to refresh the project files to make sure we have
            // the latest version of each file (including package.json) for the next steps
            $this->logger?->debug('Refreshing project information...');
            $this->project->refresh();

            $this->logger?->info(F::upgradeStep('Upgrading Strapi dependencies', [3, 4]));
            $this->updateDependencies();

            $this->logger?->info(F::upgradeStep('Installing dependencies', [4, 4]));
            $this->installDependencies();
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => ErrorUtils::unknownToError($e)];
        }

        return ['success' => true, 'error' => null];
    }

    public function confirm(string $message): bool
    {
        if ($this->confirmationCallback === null) {
            return true;
        }

        return ($this->confirmationCallback)($message);
    }

    /**
     * @param list<Requirement> $requirements
     * @param TestContext $context
     */
    private function checkRequirements(array $requirements, array $context): void
    {
        foreach ($requirements as $requirement) {
            $result = $requirement->test($context);

            if ($result['pass']) {
                $this->onSuccessfulRequirement($requirement, $context);
            } else {
                $this->onFailedRequirement($requirement, $result['error']);
            }
        }
    }

    /** @param TestContext $context */
    private function onSuccessfulRequirement(Requirement $requirement, array $context): void
    {
        if ($requirement->children !== []) {
            $this->checkRequirements($requirement->children, $context);
        }
    }

    private function onFailedRequirement(Requirement $requirement, \Throwable $originalError): void
    {
        $errorMessage = "Requirement failed: {$originalError->getMessage()} (" . F::highlight($requirement->name) . ')';
        $warningMessage = $originalError->getMessage();
        $confirmationMessage = 'Ignore optional requirement "' . F::highlight($requirement->name) . '" ?';

        $error = new \RuntimeException($errorMessage, 0, $originalError);

        if ($requirement->isRequired) {
            throw $error;
        }

        $this->logger?->warn($warningMessage);

        $response = $this->confirmationCallback !== null ? ($this->confirmationCallback)($confirmationMessage) : false;

        if (!$response) {
            throw $error;
        }
    }

    private function updateDependencies(): void
    {
        $current = $this->project->strapiVersion;
        $currentUpstream = Constants::upstreamVersion($current->raw);
        $targetUpstream = Constants::upstreamVersion($this->target->raw);

        // composer.json: hynding/strapi-php (and any strapi/*) on the current version → the target version
        $composer = JSONTransformAPI::createJSONTransformAPI($this->project->composerJSON ?? []);
        $require = self::record($composer->get('require', []));
        $requireDev = self::record($composer->get('require-dev', []));

        $composerProduction = self::scoped($require, ProjectConstants::isStrapiComposerPackage(...), $current->raw);
        $composerDevelopment = self::scoped($requireDev, ProjectConstants::isStrapiComposerPackage(...), $current->raw);

        // package.json: @strapi/* on the current upstream release → the target's
        $package = JSONTransformAPI::createJSONTransformAPI($this->project->packageJSON);
        $dependencies = self::record($package->get('dependencies', []));
        $devDependencies = self::record($package->get('devDependencies', []));

        $npmProduction = self::scoped($dependencies, ProjectConstants::isScopedStrapiPackage(...), $currentUpstream);
        $npmDevelopment = self::scoped($devDependencies, ProjectConstants::isScopedStrapiPackage(...), $currentUpstream);
        $npmChanged = $currentUpstream !== $targetUpstream;

        $strapiPackagesToUpgradeCount = count($composerProduction) + count($composerDevelopment) + ($npmChanged ? count($npmProduction) + count($npmDevelopment) : 0);

        $this->logger?->debug('Found ' . F::highlight($strapiPackagesToUpgradeCount) . ' dependency(ies) to update');
        foreach ($composerProduction as $name) {
            $this->logger?->debug("- {$name} ({$current->raw} -> {$this->target->raw})");
        }
        foreach ($composerDevelopment as $name) {
            $this->logger?->debug("- {$name} (require-dev) ({$current->raw} -> {$this->target->raw})");
        }
        if ($npmChanged) {
            foreach ($npmProduction as $name) {
                $this->logger?->debug("- {$name} ({$currentUpstream} -> {$targetUpstream})");
            }
            foreach ($npmDevelopment as $name) {
                $this->logger?->debug("- {$name} (devDependencies) ({$currentUpstream} -> {$targetUpstream})");
            }
        }

        if ($strapiPackagesToUpgradeCount === 0) {
            return;
        }

        foreach ($composerProduction as $name) {
            $composer->set("require[\"{$name}\"]", $this->target->raw);
        }
        foreach ($composerDevelopment as $name) {
            $composer->set("require-dev[\"{$name}\"]", $this->target->raw);
        }
        $this->updateMinimumStability($composer);

        if ($npmChanged) {
            foreach ($npmProduction as $name) {
                $package->set("dependencies[\"{$name}\"]", $targetUpstream);
            }
            foreach ($npmDevelopment as $name) {
                $package->set("devDependencies[\"{$name}\"]", $targetUpstream);
            }
        }

        if ($this->isDry) {
            $this->logger?->debug('Skipping dependencies update (' . Chalk::italic('dry mode') . ')');

            return;
        }

        if ($composerProduction !== [] || $composerDevelopment !== []) {
            File::saveJSON($this->project->composerJSONPath, $composer->root());
        }
        if ($npmChanged && ($npmProduction !== [] || $npmDevelopment !== [])) {
            File::saveJSON($this->project->packageJSONPath, $package->root());
        }
    }

    /** VERSIONING.md rule 3: a pre-release target needs a matching `minimum-stability` */
    private function updateMinimumStability(JSONTransformAPI $composer): void
    {
        if ($this->target->prerelease === []) {
            return;
        }

        $identifier = strtolower((string) $this->target->prerelease[0]);
        $needed = match (true) {
            str_starts_with($identifier, 'alpha') => 'alpha',
            str_starts_with($identifier, 'beta') => 'beta',
            str_starts_with($identifier, 'rc') => 'RC',
            default => 'dev',
        };

        $current = $composer->get('minimum-stability', 'stable');
        $currentLevel = self::STABILITIES[is_string($current) ? $current : 'stable'] ?? self::STABILITIES['stable'];

        if ($currentLevel > self::STABILITIES[$needed]) {
            $this->logger?->debug("- minimum-stability ({$current} -> {$needed}), prefer-stable");
            $composer->set('minimum-stability', $needed);
            $composer->set('prefer-stable', true);
        }
    }

    /** @return array<string, string> */
    private static function record(mixed $value): array
    {
        return is_array($value) ? array_filter($value, 'is_string') : [];
    }

    /**
     * Find all Strapi packages (upstream: the `@strapi/` prefix) on the current version.
     *
     * @param array<string, string> $dependencies
     * @param \Closure(string): bool $isStrapiPackage
     * @return list<string>
     */
    private static function scoped(array $dependencies, \Closure $isStrapiPackage, string $currentVersion): array
    {
        $names = [];
        foreach ($dependencies as $name => $version) {
            $name = (string) $name;
            if ($isStrapiPackage($name) && Semver::isValidSemVer($version) && $version === $currentVersion) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function installDependencies(): void
    {
        $projectPath = $this->project->cwd;
        $hasPackageJSON = is_file($this->project->packageJSONPath);

        $packageManagerName = null;
        if ($hasPackageJSON) {
            try {
                $packageManagerName = PackageManager::getPreferred($projectPath);
                $this->logger?->debug('Using ' . F::highlight($packageManagerName) . ' as package manager');
            } catch (\Throwable $e) {
                if (!$this->isDry) {
                    throw $e;
                }
                $this->logger?->debug($e->getMessage());
            }
        }

        if ($this->isDry) {
            $this->logger?->debug('Skipping dependencies installation (' . Chalk::italic('dry mode') . ')');

            return;
        }

        ($this->installer)('composer', $projectPath, $this);

        if ($packageManagerName !== null) {
            ($this->installer)($packageManagerName, $projectPath, $this);
        }

        $this->clearStrapiAdminViteCacheAfterUpgrade();
    }

    /** the default installer: `composer update hynding/strapi-php "strapi/*" -W`, or `<pm> install` */
    private static function defaultInstaller(string $tool, string $cwd, Upgrader $upgrader): void
    {
        $logger = $upgrader->logger;

        if ($tool !== 'composer') {
            PackageManager::installDependencies($cwd, $tool, ['stdout' => $logger?->stdout(), 'stderr' => $logger?->stderr()]);

            return;
        }

        $binary = getenv('COMPOSER_BINARY') ?: 'composer';
        $command = str_ends_with($binary, '.phar') ? [PHP_BINARY, $binary] : [$binary];

        $process = new Process([...$command, 'update', ProjectConstants::STRAPI_COMPOSER_DEPENDENCY_NAME, ProjectConstants::STRAPI_COMPOSER_PACKAGE_PREFIX . '*', '--with-all-dependencies', '--no-interaction'], $cwd, null, null, null);
        $process->run(static function (string $type, string $buffer) use ($logger): void {
            $stream = $type === Process::ERR ? $logger?->stderr() : $logger?->stdout();
            if ($stream !== null) {
                fwrite($stream, $buffer);
            }
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException("Command failed with exit code {$process->getExitCode()}: composer update");
        }
    }

    /**
     * Removes only `node_modules/.strapi/vite` (Vite `cacheDir` for the admin panel). Generated
     * cache — not user source. Optionally gated by `confirm` when the CLI passes a callback so
     * users know the next `strapi develop` may spend longer once re-optimizing dependencies.
     */
    private function clearStrapiAdminViteCacheAfterUpgrade(): void
    {
        if ($this->isDry) {
            return;
        }

        $shouldClear = $this->confirmationCallback !== null && $this->confirm(implode(' ', [
            'Remove the Strapi admin dev cache',
            Chalk::dim('(node_modules/.strapi/vite)'),
            '?',
            'Recommended after dependency changes to avoid stale bundles;',
            'the next',
            Chalk::bold('strapi develop'),
            'may take longer once while Vite re-optimizes.',
        ]));

        if (!$shouldClear) {
            $this->logger?->info('Skipped clearing admin dev cache. If the admin panel misbehaves after upgrading, delete node_modules/.strapi/vite and run develop again.');

            return;
        }

        $cachePath = $this->project->cwd . DIRECTORY_SEPARATOR . 'node_modules' . DIRECTORY_SEPARATOR . '.strapi' . DIRECTORY_SEPARATOR . 'vite';

        try {
            self::rm($cachePath);
            $this->logger?->debug("Removed Strapi admin Vite cache at {$cachePath}");
        } catch (\Throwable $error) {
            $this->logger?->warn("Could not remove Strapi admin Vite cache at {$cachePath}: {$error->getMessage()}");
        }
    }

    /** `rm(path, { recursive: true, force: true })` */
    private static function rm(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::rm($path . DIRECTORY_SEPARATOR . $entry);
                }
            }
            if (!rmdir($path)) {
                throw new \RuntimeException("Could not remove {$path}");
            }
        } elseif (file_exists($path) || is_link($path)) {
            if (!unlink($path)) {
                throw new \RuntimeException("Could not remove {$path}");
            }
        }
    }

    private function runCodemods(SemverRange $range): void
    {
        $codemodRunner = ($this->codemodRunnerFactory)($this->project, $range);

        $codemodRunner->dry($this->isDry);

        if ($this->logger !== null) {
            $codemodRunner->setLogger($this->logger);
        }

        $codemodRunner->run();
    }

    /**
     * Resolves the target version based on the given project, target, and package versions.
     * If target is a SemVer, it directly finds it. If it's a release type (major, minor, patch),
     * it calculates the range of versions for this release type and returns the latest version
     * within this range.
     *
     * @return array<string, mixed>
     */
    private static function resolveNPMTarget(AppProject $project, NodeSemVer|string $target, PackageInterface $npmPackage): array
    {
        // Semver
        if ($target instanceof NodeSemVer) {
            $version = $npmPackage->findVersion($target);

            if ($version === null) {
                throw new NPMCandidateNotFoundError($target);
            }

            return $version;
        }

        // Release Types
        if (Semver::isSemVerReleaseType($target)) {
            $range = Range::rangeFromVersions($project->strapiVersion, $target);
            $npmVersionsMatches = $npmPackage->findVersionsInRange($range);

            // The targeted version is the latest one that matches the given range
            $version = $npmVersionsMatches === [] ? null : $npmVersionsMatches[count($npmVersionsMatches) - 1];

            if ($version === null) {
                throw new NPMCandidateNotFoundError($range, "The project is already up-to-date ({$target})");
            }

            return $version;
        }

        throw new NPMCandidateNotFoundError($target);
    }

    public static function upgraderFactory(AppProject $project, NodeSemVer|string $target, PackageInterface $npmPackage): self
    {
        $npmTarget = self::resolveNPMTarget($project, $target, $npmPackage);
        $semverTarget = Semver::semVerFactory(NpmPackage::versionOf($npmTarget));

        if ($semverTarget->compare($project->strapiVersion) === 0) {
            throw new \RuntimeException("The project is already using v{$semverTarget}");
        }

        if ($semverTarget->compare($project->strapiVersion) < 0) {
            throw new \RuntimeException("The target version v{$semverTarget} must be greater than the current version v{$project->strapiVersion}");
        }

        return new self($project, $semverTarget, $npmPackage);
    }
}
