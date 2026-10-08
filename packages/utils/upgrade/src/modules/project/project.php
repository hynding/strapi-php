<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Project;

use Strapi\Upgrade\Modules\Codemod\Codemod;
use Strapi\Upgrade\Modules\FileScanner\FileScanner;
use Strapi\Upgrade\Modules\Json\File;
use Strapi\Upgrade\Modules\Runner\AbstractRunner;
use Strapi\Upgrade\Modules\Runner\Code\CodeRunner;
use Strapi\Upgrade\Modules\Runner\Json\JSONRunner;
use Strapi\Upgrade\Modules\Runner\Upstream\UpstreamRunner;
use Strapi\Upgrade\Modules\Upgrader\Constants as UpgraderConstants;

/**
 * Port of `Project` and `projectFactory` (packages/utils/upgrade/src/modules/project/project.ts);
 * `AppProject` and `PluginProject` live in app-project.php and plugin-project.php.
 *
 * PHP-only: a project also has a `composer.json` (required for applications, whose Strapi
 * version it holds), and codemods run through three runners: PHP codemods on `.php` files,
 * upstream's codemods on `.js/.ts` files (`UpstreamRunner`), JSON codemods on `.json` files.
 *
 * @phpstan-import-type MinimalPackageJSON from Types
 * @phpstan-import-type MinimalComposerJSON from Types
 * @phpstan-import-type ProjectConfig from Types
 * @phpstan-import-type ProjectType from Types
 * @phpstan-import-type RunCodemodsOptions from Types
 * @phpstan-import-type CodemodReport from \Strapi\Upgrade\Modules\Report\Types
 */
abstract class Project
{
    /** @var list<string> */
    public array $files = [];

    public string $packageJSONPath;

    /** @var MinimalPackageJSON */
    public array $packageJSON = [];

    public string $composerJSONPath;

    /** @var MinimalComposerJSON|null null when the project has no composer.json */
    public ?array $composerJSON = null;

    /** @var list<string> */
    public readonly array $paths;

    /** @param ProjectConfig $config */
    public function __construct(public readonly string $cwd, array $config)
    {
        if (!file_exists($cwd)) {
            throw new \RuntimeException("ENOENT: no such file or directory, access '{$cwd}'");
        }

        $this->paths = $config['paths'];
        $this->packageJSONPath = $cwd . DIRECTORY_SEPARATOR . Constants::PROJECT_PACKAGE_JSON;
        $this->composerJSONPath = $cwd . DIRECTORY_SEPARATOR . Constants::PROJECT_COMPOSER_JSON;

        $this->refresh();
    }

    /** @return ProjectType */
    abstract public function type(): string;

    /**
     * @param list<string> $extensions e.g. `['.js', '.ts']`
     * @return list<string>
     */
    public function getFilesByExtensions(array $extensions): array
    {
        return array_values(array_filter($this->files, static function (string $filePath) use ($extensions): bool {
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);

            return in_array(".{$extension}", $extensions, true);
        }));
    }

    public function refresh(): static
    {
        $this->refreshPackageJSON();
        $this->refreshComposerJSON();
        $this->refreshProjectFiles();

        return $this;
    }

    /**
     * @param list<Codemod> $codemods
     * @param RunCodemodsOptions $options
     * @return list<CodemodReport>
     */
    public function runCodemods(array $codemods, array $options = []): array
    {
        $runners = $this->createProjectCodemodsRunners($options);
        $reports = [];

        foreach ($codemods as $codemod) {
            foreach ($runners as $runner) {
                if ($runner->valid($codemod)) {
                    $report = $runner->run($codemod);
                    $reports[] = ['codemod' => $codemod, 'report' => $report];
                }
            }
        }

        return $reports;
    }

    /** the `@strapi/strapi` version upstream's tool should see for this project's JS files */
    protected function upstreamStrapiVersion(): string
    {
        return UpgraderConstants::upstreamVersion();
    }

    /**
     * @param RunCodemodsOptions $options
     * @return list<AbstractRunner<array<string, mixed>>>
     */
    private function createProjectCodemodsRunners(array $options): array
    {
        $dry = $options['dry'] ?? false;
        $ext = static fn (array $extensions): array => array_values(array_map(static fn (string $e): string => ".{$e}", $extensions));

        $phpFiles = $this->getFilesByExtensions($ext(Constants::PROJECT_PHP_EXTENSIONS));
        $codeFiles = $this->getFilesByExtensions($ext(Constants::PROJECT_CODE_EXTENSIONS));
        $jsonFiles = $this->getFilesByExtensions($ext(Constants::PROJECT_JSON_EXTENSIONS));

        $codeRunner = CodeRunner::codeRunnerFactory($phpFiles, [
            'dry' => $dry,
            'cwd' => $this->cwd,
            'extensions' => implode(',', Constants::PROJECT_PHP_EXTENSIONS),
            // Don't output any log coming from the runner
            'silent' => true,
        ]);

        $upstreamRunner = UpstreamRunner::upstreamRunnerFactory($codeFiles, [
            'dry' => $dry,
            'cwd' => $this->cwd,
            'strapiVersion' => $this->upstreamStrapiVersion(),
            'command' => $options['upstreamCommand'] ?? self::upstreamCommandFromEnv(),
        ]);

        $jsonRunner = JSONRunner::jsonRunnerFactory($jsonFiles, ['dry' => $dry, 'cwd' => $this->cwd]);

        /** @var list<AbstractRunner<array<string, mixed>>> */
        return [$codeRunner, $upstreamRunner, $jsonRunner];
    }

    /** @return list<string>|null `STRAPI_UPGRADE_UPSTREAM_COMMAND`, e.g. `node /path/to/upgrade/bin/upgrade.js` */
    private static function upstreamCommandFromEnv(): ?array
    {
        $command = getenv('STRAPI_UPGRADE_UPSTREAM_COMMAND');

        return is_string($command) && trim($command) !== '' ? (preg_split('/\s+/', trim($command)) ?: null) : null;
    }

    private function refreshPackageJSON(): void
    {
        if (!is_file($this->packageJSONPath)) {
            throw new \RuntimeException('Could not find a ' . Constants::PROJECT_PACKAGE_JSON . " file in {$this->cwd}");
        }

        $json = File::readJSON($this->packageJSONPath);
        $this->packageJSON = is_array($json) ? $json : [];
    }

    private function refreshComposerJSON(): void
    {
        if (!is_file($this->composerJSONPath)) {
            $this->composerJSON = null;

            return;
        }

        $json = File::readJSON($this->composerJSONPath);
        $this->composerJSON = is_array($json) ? $json : [];
    }

    private function refreshProjectFiles(): void
    {
        $scanner = FileScanner::fileScannerFactory($this->cwd);

        $this->files = $scanner->scan($this->paths);
    }

    /**
     * Returns the allowed file paths for a project rooted in `$rootPaths`: default files with an
     * allowed extension, minus dependency and build directories, plus the given root files.
     *
     * @param list<string> $rootPaths
     * @param list<string> $rootFiles
     * @return list<string>
     */
    protected static function pathsFor(array $rootPaths, array $rootFiles): array
    {
        $allowedRootPaths = self::formatGlobCollectionPattern($rootPaths);
        $allowedExtensions = self::formatGlobCollectionPattern(Constants::PROJECT_ALLOWED_EXTENSIONS);

        return [
            "./{$allowedRootPaths}/**/*.{$allowedExtensions}",
            ...array_map(static fn (string $d): string => "!./**/{$d}/**/*", Constants::PROJECT_EXCLUDED_DIRECTORIES),
            ...$rootFiles,
        ];
    }

    /** @param list<string> $collection */
    private static function formatGlobCollectionPattern(array $collection): string
    {
        if ($collection === []) {
            throw new \InvalidArgumentException('Invalid pattern provided, the given collection needs at least 1 element');
        }

        return count($collection) === 1 ? $collection[0] : '{' . implode(',', $collection) . '}';
    }

    private static function isPlugin(string $cwd): bool
    {
        $packageJSONPath = $cwd . DIRECTORY_SEPARATOR . Constants::PROJECT_PACKAGE_JSON;

        if (!is_file($packageJSONPath)) {
            throw new \RuntimeException('Could not find a ' . Constants::PROJECT_PACKAGE_JSON . " file in {$cwd}");
        }

        $packageJSON = json_decode((string) file_get_contents($packageJSONPath), true);
        if (is_array($packageJSON) && (($packageJSON['strapi']['kind'] ?? null) === 'plugin')) {
            return true;
        }

        // PHP-only: a strapi-php plugin declares itself in composer.json's `extra.strapi`
        $composerJSONPath = $cwd . DIRECTORY_SEPARATOR . Constants::PROJECT_COMPOSER_JSON;
        $composerJSON = is_file($composerJSONPath) ? json_decode((string) file_get_contents($composerJSONPath), true) : null;

        return is_array($composerJSON) && (($composerJSON['extra']['strapi']['kind'] ?? null) === 'plugin');
    }

    public static function projectFactory(string $cwd): AppProject|PluginProject
    {
        if (!file_exists($cwd)) {
            throw new \RuntimeException("ENOENT: no such file or directory, access '{$cwd}'");
        }

        return self::isPlugin($cwd) ? new PluginProject($cwd) : new AppProject($cwd);
    }
}
