<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp;

use Strapi\CreateStrapiApp\Utils\ComposerJson;
use Strapi\CreateStrapiApp\Utils\DotEnv;
use Strapi\CreateStrapiApp\Utils\GetPackageManagerArgs;
use Strapi\CreateStrapiApp\Utils\Git;
use Strapi\CreateStrapiApp\Utils\Gitignore;
use Strapi\CreateStrapiApp\Utils\Logger;
use Strapi\CreateStrapiApp\Utils\PackageJson;
use Strapi\CreateStrapiApp\Utils\PnpmConfig;
use Strapi\CreateStrapiApp\Utils\Semver;
use Strapi\CreateStrapiApp\Utils\Template;
use Strapi\CreateStrapiApp\Utils\Usage;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Port of packages/cli/create-strapi-app/src/create-strapi.ts.
 *
 * Same sequence: copy the template, write `package.json` and `.env`, install dependencies,
 * `.gitignore`, `git init`, seed the example, print the commands, run the app for `--quickstart`.
 * PHP additions: `composer.json` is written next to `package.json` and installing dependencies runs
 * `composer install` before the package manager installs the admin bundle. Strapi Cloud (growth
 * SSO trial, `license.txt`) is not ported.
 *
 * In-place mode (`composer create-project`): Composer has unpacked this package into the project
 * directory. The project is generated in `scope.tmpPath`, the bootstrapper's files are removed
 * (except `vendor/`, which the following `composer install` reconciles) and the project is moved in.
 *
 * @phpstan-import-type Scope from Types
 * @phpstan-import-type PackageManager from Types
 */
final class CreateStrapi
{
    private const YARN_NODE_MODULES_CONFIG = "nodeLinker: node-modules\n";

    /** The templates shipped with this package (`templates/<name>`). */
    public static function templatesDir(): string
    {
        return dirname(__DIR__) . '/templates';
    }

    /** @param PackageManager $packageManager */
    private static function getUserAgentPackageManagerVersion(string $packageManager): ?string
    {
        $userAgent = (string) getenv('npm_config_user_agent');
        $agent = explode(' ', $userAgent)[0];
        $parts = explode('/', $agent);

        if ($parts[0] !== $packageManager || !isset($parts[1])) {
            return null;
        }

        return Semver::coerce($parts[1]);
    }

    /** @param PackageManager $packageManager */
    private static function shouldWriteYarnNodeModulesConfig(string $packageManager): bool
    {
        if ($packageManager !== 'yarn') {
            return false;
        }

        $userAgentVersion = self::getUserAgentPackageManagerVersion($packageManager);

        if ($userAgentVersion !== null) {
            return Semver::gte($userAgentVersion, '3.0.0');
        }

        try {
            $normalizedVersion = Semver::coerce(GetPackageManagerArgs::getPackageManagerVersion($packageManager));

            return $normalizedVersion !== null && Semver::gte($normalizedVersion, '3.0.0');
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param PackageManager $packageManager */
    private static function resolvePnpmVersion(string $packageManager): ?string
    {
        if ($packageManager !== 'pnpm') {
            return null;
        }

        $userAgentVersion = self::getUserAgentPackageManagerVersion($packageManager);

        if ($userAgentVersion !== null) {
            return $userAgentVersion;
        }

        try {
            return GetPackageManagerArgs::getPackageManagerVersion($packageManager);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param Scope $scope
     * @param list<string> $bootstrapperEntries in-place mode: the top-level entries of the project
     *                                          directory that belong to the bootstrapper
     */
    public static function createStrapi(array $scope, Logger $logger, array $bootstrapperEntries = []): void
    {
        $rootPath = $scope['rootPath'];

        if ($scope['inPlace']) {
            self::createInPlace($scope, $logger, $bootstrapperEntries);

            return;
        }

        try {
            if (!is_dir($rootPath)) {
                mkdir($rootPath, 0777, true);
            }
            self::createApp($scope, $logger);
        } catch (\Throwable $error) {
            Template::removeDirectory($rootPath);

            throw $error;
        }
    }

    /**
     * @param Scope $scope
     * @param list<string> $bootstrapperEntries
     */
    private static function createInPlace(array $scope, Logger $logger, array $bootstrapperEntries): void
    {
        $tmpPath = $scope['tmpPath'];

        try {
            mkdir($tmpPath, 0777, true);
            self::writeProjectFiles([...$scope, 'rootPath' => $tmpPath], $logger, $scope['rootPath']);
        } catch (\Throwable $error) {
            Template::removeDirectory($tmpPath);

            throw $error;
        }

        foreach ($bootstrapperEntries as $entry) {
            if ($entry === 'vendor' || $entry === '' || str_contains($entry, '/')) {
                continue;
            }
            Template::removeDirectory($scope['rootPath'] . '/' . $entry);
        }
        Template::copyDirectory($tmpPath, $scope['rootPath']);
        Template::removeDirectory($tmpPath);

        self::finishApp($scope, $logger);
    }

    /** @param Scope $scope */
    private static function createApp(array $scope, Logger $logger): void
    {
        self::writeProjectFiles($scope, $logger, $scope['rootPath']);
        self::finishApp($scope, $logger);
    }

    /**
     * Everything upstream does before installing dependencies: the files of the project.
     *
     * @param Scope $scope
     * @param string $displayPath where the project ends up (differs from rootPath in place)
     */
    private static function writeProjectFiles(array $scope, Logger $logger, string $displayPath): void
    {
        $rootPath = $scope['rootPath'];
        $template = $scope['template'];

        Usage::trackUsage('willCreateProject', $scope);

        $logger->title('Strapi', "Creating a new application at <fg=green>{$displayPath}</>");

        Usage::trackUsage($scope['isQuickstart'] ? 'didChooseQuickstart' : 'didChooseCustomDatabase', $scope);

        if ($template === null || $template === '') {
            $templateName = $scope['useExample'] ? 'example' : 'vanilla';

            $internalTemplatePath = self::templatesDir() . '/' . $templateName;
            if (is_dir($internalTemplatePath)) {
                Template::copyDirectory($internalTemplatePath, $rootPath);
            }
        } else {
            try {
                $logger->info("<fg=cyan>Installing template</> {$template}");

                Template::copyTemplate($scope, $rootPath);

                $logger->success('Template copied successfully.');
            } catch (Utils\FatalError $error) {
                throw $error;
            } catch (\Throwable $error) {
                $logger->fatal("Template installation failed: {$error->getMessage()}");
            }

            if (!is_file($rootPath . '/composer.json')) {
                $logger->fatal('Missing <options=bold>composer.json</> in template');
            }
        }

        Usage::trackUsage('didCopyProjectFiles', $scope);

        $pnpmVersion = self::resolvePnpmVersion($scope['packageManager']);
        $scopeWithPnpmVersion = [...$scope, 'pnpmVersion' => $pnpmVersion];

        PackageJson::createPackageJSON($scopeWithPnpmVersion);
        ComposerJson::createComposerJSON($scopeWithPnpmVersion);

        Usage::trackUsage('didWritePackageJSON', $scope);

        // create config/database
        file_put_contents($rootPath . '/.env', DotEnv::generateDotEnv($scope));

        if (self::shouldWriteYarnNodeModulesConfig($scope['packageManager']) && !file_exists($rootPath . '/.yarnrc.yml')) {
            file_put_contents($rootPath . '/.yarnrc.yml', self::YARN_NODE_MODULES_CONFIG);
        }

        PnpmConfig::writePnpmWorkspaceConfig($scopeWithPnpmVersion, $pnpmVersion);

        // make sure a gitignore file is created regardless of the user using git or not
        // (upstream writes it after installing; it is a plain file either way)
        if (!file_exists($rootPath . '/.gitignore')) {
            file_put_contents($rootPath . '/.gitignore', Gitignore::gitIgnore());
        }

        Usage::trackUsage('didCopyConfigurationFiles', $scope);
    }

    /**
     * Installing dependencies, git, seeding, the closing message and `--quickstart`'s run.
     *
     * @param Scope $scope
     */
    private static function finishApp(array $scope, Logger $logger): void
    {
        $rootPath = $scope['rootPath'];
        $packageManager = $scope['packageManager'];
        $useExample = $scope['useExample'];
        $installDependencies = $scope['installDependencies'];
        $shouldRunSeed = $useExample && $installDependencies;

        if ($installDependencies) {
            try {
                $logger->title('deps', "Installing dependencies with <fg=cyan>composer</> and <fg=cyan>{$packageManager}</>");

                Usage::trackUsage('willInstallProjectDependencies', $scope);

                self::runInstall($scope, $logger);

                Usage::trackUsage('didInstallProjectDependencies', $scope);

                $logger->success('Dependencies installed');
            } catch (\Throwable $error) {
                Usage::trackUsage('didNotInstallProjectDependencies', $scope, substr($error->getMessage(), -1024));

                $logger->fatal([
                    '<options=bold>Oh, it seems that you encountered an error while installing dependencies in your project</>',
                    '',
                    "Don't give up, your project was created correctly",
                    '',
                    'Fix the issues mentioned in the installation errors and try to run the following command:',
                    '',
                    "cd <fg=green>{$rootPath}</> && <fg=cyan>composer</> install && <fg=cyan>{$packageManager}</> install",
                ]);
            }
        }

        Usage::trackUsage('didCreateProject', $scope);

        // Init git
        if ($scope['gitInit']) {
            $logger->title('git', 'Initializing git repository.');

            Git::tryGitInit($rootPath);

            $logger->success('Initialized a git repository.');
        }

        if ($shouldRunSeed && is_file($rootPath . '/scripts/seed.php')) {
            $logger->title('Seed', 'Seeding your database with sample data');

            $process = self::process([self::phpBinary(), 'scripts/seed.php'], $rootPath);
            if (self::stream($process, $logger) === 0) {
                $logger->success('Sample data added to your database');
            } else {
                $logger->error('Failed to seed your database. Skipping');
            }
        }

        $cmd = '<fg=cyan>php bin/strapi</>';

        $logger->title('Strapi', 'Your application was created!');

        $logger->log([
            'Available commands in your project:',
            '',
            'Start Strapi in development mode (builds the admin panel; PHP sources are re-read on every request).',
            "{$cmd} develop",
            '',
            'Start Strapi without building the admin panel.',
            "{$cmd} start",
            '',
            'Build Strapi admin panel.',
            "{$cmd} build",
            '',
        ]);

        $seed = '<fg=cyan>php</> scripts/seed.php';

        if ($useExample) {
            $logger->log(['Seed your database with sample data.', $seed, '']);
        }

        $logger->log(['Display all available commands.', "{$cmd}\n"]);

        $start = !$shouldRunSeed && $useExample ? "{$seed} && {$cmd} develop" : "{$cmd} develop";

        if ($installDependencies) {
            $logger->log(['To get started run', '', "<fg=cyan>cd</> {$rootPath}", $start]);
        } else {
            $logger->log([
                'To get started run',
                '',
                "<fg=cyan>cd</> {$rootPath}",
                "<fg=cyan>composer</> install && <fg=cyan>{$packageManager}</> install",
                $start,
            ]);
        }

        if ($scope['runApp'] && $installDependencies) {
            $logger->title('Run', 'Running your Strapi application');

            Usage::trackUsage('willStartServer', $scope);

            $process = self::process([self::phpBinary(), 'bin/strapi', 'develop'], $rootPath, ['FORCE_COLOR' => '1']);
            if (self::stream($process, $logger) !== 0) {
                Usage::trackUsage('didNotStartServer', $scope);

                $logger->fatal('Failed to start your Strapi application');
            }
        }
    }

    /**
     * `composer install`, then the package manager's install for the admin bundle.
     *
     * @param Scope $scope
     */
    private static function runInstall(array $scope, Logger $logger): void
    {
        $rootPath = $scope['rootPath'];
        $packageManager = $scope['packageManager'];

        $composer = self::process([...self::composerCommand(), 'install'], $rootPath);
        if (self::stream($composer, $logger) !== 0) {
            throw new \RuntimeException('composer install failed: ' . $composer->getErrorOutput());
        }

        // include same cwd and env to ensure version check returns same version we use below
        $env = ['NODE_ENV' => 'development'];
        ['envArgs' => $envArgs, 'cmdArgs' => $cmdArgs] = GetPackageManagerArgs::getInstallArgs($packageManager, ['cwd' => $rootPath]);

        $install = self::process([$packageManager, ...$cmdArgs], $rootPath, [...$envArgs, ...$env]);
        if (self::stream($install, $logger) !== 0) {
            throw new \RuntimeException("{$packageManager} install failed: " . $install->getErrorOutput());
        }
    }

    /**
     * How to run Composer: the binary running this script (`COMPOSER_BINARY`, set by Composer for
     * `composer create-project` scripts), else `composer` on the PATH.
     *
     * @return list<string>
     */
    public static function composerCommand(): array
    {
        $binary = getenv('COMPOSER_BINARY');
        if (is_string($binary) && $binary !== '' && is_file($binary)) {
            return [self::phpBinary(), $binary];
        }

        $found = (new ExecutableFinder())->find('composer');
        if ($found === null) {
            throw new \RuntimeException('Composer was not found on the PATH (https://getcomposer.org/download/)');
        }

        return [$found];
    }

    public static function phpBinary(): string
    {
        return PHP_BINARY;
    }

    /**
     * @param list<string> $command
     * @param array<string, string> $env
     */
    private static function process(array $command, string $cwd, array $env = []): Process
    {
        return new Process($command, $cwd, $env === [] ? null : $env, null, null);
    }

    /** `stdio: 'inherit'`: a TTY when there is one, else the output is forwarded as it comes. */
    private static function stream(Process $process, Logger $logger): int
    {
        if (Process::isTtySupported() && stream_isatty(STDIN) && stream_isatty(STDOUT)) {
            try {
                $process->setTty(true);

                return $process->run();
            } catch (\Throwable) {
                $process->setTty(false);
            }
        }

        $output = $logger->output();

        return $process->run(static function (string $type, string $buffer) use ($output): void {
            if ($type === Process::ERR) {
                fwrite(STDERR, $buffer);
            } else {
                $output->write($buffer, false, \Symfony\Component\Console\Output\OutputInterface::OUTPUT_RAW);
            }
        });
    }
}
