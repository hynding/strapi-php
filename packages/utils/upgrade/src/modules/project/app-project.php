<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Project;

use Strapi\Upgrade\Modules\Upgrader\Constants as UpgraderConstants;
use Strapi\Upgrade\Modules\Version\NodeSemver\SemVer as NodeSemVer;
use Strapi\Upgrade\Modules\Version\Semver;

/**
 * Port of `AppProject` (packages/utils/upgrade/src/modules/project/project.ts).
 *
 * Upstream reads the Strapi version from `package.json`'s `@strapi/strapi` (or the installed
 * package when the declaration is a range). A strapi-php application's version is its
 * `strapi/strapi` Composer requirement (VERSIONING.md: `5.x.y`, `5.x.y-beta.N`, `5.x.y.N`), or,
 * when that is a constraint, the installed `strapi/strapi` (vendor/composer/installed.json).
 * `upstreamVersion()` is the release its `@strapi/*` npm packages are pinned to.
 *
 * @phpstan-import-type MinimalPackageJSON from Types
 * @phpstan-import-type MinimalComposerJSON from Types
 */
final class AppProject extends Project
{
    public NodeSemVer $strapiVersion;

    public function __construct(string $cwd)
    {
        parent::__construct($cwd, ['paths' => self::paths()]);
    }

    public function type(): string
    {
        return 'application';
    }

    /**
     * The app default files plus the root package.json and composer.json files.
     *
     * @return list<string>
     */
    private static function paths(): array
    {
        return self::pathsFor(Constants::PROJECT_APP_ALLOWED_ROOT_PATHS, [Constants::PROJECT_PACKAGE_JSON, Constants::PROJECT_COMPOSER_JSON]);
    }

    public function refresh(): static
    {
        parent::refresh();
        $this->refreshStrapiVersion();

        return $this;
    }

    /** @param MinimalPackageJSON $packageJSON */
    public function applyPackageJSON(array $packageJSON): void
    {
        $this->packageJSON = $packageJSON;
    }

    /**
     * PHP-only: the composer.json counterpart of `applyPackageJSON` (used by dry-run pinning)
     *
     * @param MinimalComposerJSON $composerJSON
     */
    public function applyComposerJSON(array $composerJSON): void
    {
        $this->composerJSON = $composerJSON;
        $this->refreshStrapiVersion();
    }

    public function getInstalledStrapiVersion(): NodeSemVer
    {
        return $this->findLocallyInstalledStrapiVersion();
    }

    /** the upstream Strapi release this project mirrors (`5.56.0-beta.1` → `5.56.0`) */
    public function upstreamVersion(): string
    {
        return UpgraderConstants::upstreamVersion($this->strapiVersion->raw);
    }

    protected function upstreamStrapiVersion(): string
    {
        return $this->upstreamVersion();
    }

    public function refreshStrapiVersion(): void
    {
        $this->strapiVersion =
            // First try to get the strapi version from the composer.json requirements
            $this->findStrapiVersionFromProjectComposerJSON()
            // If the version found is not a pinned version, get the Strapi version from the installed package
            ?? $this->findLocallyInstalledStrapiVersion();
    }

    private function findStrapiVersionFromProjectComposerJSON(): ?NodeSemVer
    {
        if ($this->composerJSON === null) {
            throw new \RuntimeException('Could not find a ' . Constants::PROJECT_COMPOSER_JSON . " file in {$this->cwd}. strapi-upgrade upgrades strapi-php projects; for a Node.js Strapi project, use `npx @strapi/upgrade`.");
        }

        $projectName = is_string($this->composerJSON['name'] ?? null) ? $this->composerJSON['name'] : (string) ($this->packageJSON['name'] ?? '');
        $require = is_array($this->composerJSON['require'] ?? null) ? $this->composerJSON['require'] : [];
        $version = $require[Constants::STRAPI_COMPOSER_DEPENDENCY_NAME] ?? null;

        if (!is_string($version)) {
            throw new \RuntimeException('No version of ' . Constants::STRAPI_COMPOSER_DEPENDENCY_NAME . " was found in {$projectName}. Are you in a valid Strapi project?");
        }

        // We return null only if a strapi/strapi version is found, but it's not a pinned version
        return StrapiDependencies::isPinnedComposerVersion($version) ? Semver::semVerFactory($version) : null;
    }

    private function findLocallyInstalledStrapiVersion(): NodeSemVer
    {
        $vendorDir = $this->composerJSON['config']['vendor-dir'] ?? 'vendor';
        $installedPath = $this->cwd . DIRECTORY_SEPARATOR . (is_string($vendorDir) ? $vendorDir : 'vendor') . '/composer/installed.json';

        $version = null;
        if (is_file($installedPath)) {
            $installed = json_decode((string) file_get_contents($installedPath), true);
            $packages = is_array($installed) ? ($installed['packages'] ?? $installed) : [];
            foreach (is_array($packages) ? $packages : [] as $package) {
                if (is_array($package) && ($package['name'] ?? null) === Constants::STRAPI_COMPOSER_DEPENDENCY_NAME) {
                    $version = is_string($package['version'] ?? null) ? ltrim($package['version'], 'v') : '';
                    break;
                }
            }
        }

        if ($version === null) {
            throw new \RuntimeException('Cannot resolve package "' . Constants::STRAPI_COMPOSER_DEPENDENCY_NAME . "\" from paths [{$this->cwd}]");
        }

        if (!Semver::isValidSemVer($version)) {
            throw new \RuntimeException('Invalid ' . Constants::STRAPI_COMPOSER_DEPENDENCY_NAME . " version found in {$installedPath} ({$version})");
        }

        return Semver::semVerFactory($version);
    }
}
