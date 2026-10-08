<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp;

use Strapi\CreateStrapiApp\Utils\CheckInstallPath;
use Strapi\CreateStrapiApp\Utils\CheckRequirements;
use Strapi\CreateStrapiApp\Utils\Database;
use Strapi\CreateStrapiApp\Utils\FatalError;
use Strapi\CreateStrapiApp\Utils\InstallId;
use Strapi\CreateStrapiApp\Utils\Logger;
use Strapi\CreateStrapiApp\Utils\Usage;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\SingleCommandApplication;

/**
 * Port of packages/cli/create-strapi-app/src/index.ts (`run()`): commander becomes a Symfony
 * console single-command application with the same arguments and options.
 *
 * Options that do not apply to a PHP project are accepted and ignored, so scripts written for
 * `npx create-strapi-app` keep working: `--ts/--typescript`, `--js/--javascript` (the server is
 * PHP, the admin customisation files stay TypeScript), `--skip-cloud` (Strapi Cloud login is not
 * ported: there is nothing to skip) and the legacy `--enable-ab-tests`.
 *
 * `--in-place` is what `composer create-project strapi/create-strapi-app <dir>` runs
 * (`post-create-project-cmd`): the project is generated into the directory Composer created for
 * this package. Composer cannot forward flags to that script, so the `CREATE_STRAPI_APP_ARGS`
 * environment variable carries them (`CREATE_STRAPI_APP_ARGS="--quickstart --no-run"`).
 *
 * @phpstan-import-type Options from Types
 * @phpstan-import-type Scope from Types
 * @phpstan-import-type PackageManager from Types
 */
final class CreateStrapiApp
{
    /** Package names whose `composer create-project` directory `--in-place` may take over. */
    public const BOOTSTRAPPER_PACKAGES = ['strapi/create-strapi-app', 'strapi/create-strapi'];

    public const ARGS_ENV = 'CREATE_STRAPI_APP_ARGS';

    private static ?string $version = null;

    /** This package's version (`composer.json`), which is the strapi-php version it creates. */
    public static function version(): string
    {
        if (self::$version !== null) {
            return self::$version;
        }

        $composer = dirname(__DIR__) . '/composer.json';
        $json = is_file($composer) ? json_decode((string) file_get_contents($composer), true) : null;
        $version = is_array($json) && is_string($json['version'] ?? null) ? $json['version'] : null;

        if ($version === null && class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled('strapi/create-strapi-app')) {
            $version = \Composer\InstalledVersions::getPrettyVersion('strapi/create-strapi-app');
        }

        return self::$version = $version ?? '0.0.0';
    }

    /**
     * The upstream Strapi release the npm side pins (VERSIONING.md rule 2): `5.56.0` for `5.56.0`,
     * `5.56.0.1` and `5.56.0-beta.1` (same rule as `Strapi\Cli\Strapi::upstreamVersion()`).
     */
    public static function upstreamVersion(?string $version = null): string
    {
        $version ??= self::version();

        return preg_match('/^v?(\d+\.\d+\.\d+)/', $version, $m) === 1 ? $m[1] : $version;
    }

    public static function command(): SingleCommandApplication
    {
        $command = new SingleCommandApplication();
        $command
            ->setName('create-strapi-app')
            ->setVersion(self::version())
            ->setDescription('create a new application')
            ->addArgument('directory', InputArgument::OPTIONAL, 'The directory of the project')
            ->addOption('quickstart', null, InputOption::VALUE_NONE, 'Quickstart app creation (deprecated)')
            ->addOption('run', null, InputOption::VALUE_NEGATABLE, 'Start the application after it is created (--no-run: do not start it)')

            // setup options (no-ops: the server is PHP)
            ->addOption('typescript', null, InputOption::VALUE_NONE, 'Ignored (--ts): the server is PHP')
            ->addOption('ts', null, InputOption::VALUE_NONE, 'Ignored: the server is PHP')
            ->addOption('javascript', null, InputOption::VALUE_NONE, 'Ignored (--js): the server is PHP')
            ->addOption('js', null, InputOption::VALUE_NONE, 'Ignored: the server is PHP')

            // Package manager options (the admin panel bundle)
            ->addOption('use-npm', null, InputOption::VALUE_NONE, 'Use npm to install the admin panel dependencies')
            ->addOption('use-yarn', null, InputOption::VALUE_NONE, 'Use yarn to install the admin panel dependencies')
            ->addOption('use-pnpm', null, InputOption::VALUE_NONE, 'Use pnpm to install the admin panel dependencies')

            // dependencies options
            ->addOption('install', null, InputOption::VALUE_NEGATABLE, 'Install dependencies (composer + the package manager)')

            // Cloud options
            ->addOption('skip-cloud', null, InputOption::VALUE_NONE, 'Skip cloud login and project creation (always skipped: not ported)')

            // Example app
            ->addOption('example', null, InputOption::VALUE_NEGATABLE, 'Use an example app')

            // git options
            ->addOption('git-init', null, InputOption::VALUE_NEGATABLE, 'Initialize a git repository')

            // Legacy no-ops: accept old flags so existing CI/scripts keep working
            ->addOption('enable-ab-tests', null, InputOption::VALUE_NEGATABLE, 'Ignored')

            // Automation
            ->addOption('non-interactive', null, InputOption::VALUE_NONE, 'Skip all interactive prompts and use defaults')

            // Database options
            ->addOption('dbclient', null, InputOption::VALUE_REQUIRED, 'Database client')
            ->addOption('dbhost', null, InputOption::VALUE_REQUIRED, 'Database host')
            ->addOption('dbport', null, InputOption::VALUE_REQUIRED, 'Database port')
            ->addOption('dbname', null, InputOption::VALUE_REQUIRED, 'Database name')
            ->addOption('dbusername', null, InputOption::VALUE_REQUIRED, 'Database username')
            ->addOption('dbpassword', null, InputOption::VALUE_REQUIRED, 'Database password')
            ->addOption('dbssl', null, InputOption::VALUE_REQUIRED, 'Database SSL')
            ->addOption('dbfile', null, InputOption::VALUE_REQUIRED, 'Database file path for sqlite')
            ->addOption('skip-db', null, InputOption::VALUE_NONE, 'Skip database configuration')

            ->addOption('template', null, InputOption::VALUE_REQUIRED, 'Specify a Strapi template')
            ->addOption('template-branch', null, InputOption::VALUE_REQUIRED, 'Specify a branch for the template')
            ->addOption('template-path', null, InputOption::VALUE_REQUIRED, 'Specify a path to the template inside the repository')

            ->addOption('in-place', null, InputOption::VALUE_NONE, 'Internal: generate into the current directory created by `composer create-project`')
            ->setCode(static fn (InputInterface $input, OutputInterface $output): int => self::execute($input, $output));

        return $command;
    }

    /**
     * `bin/create-strapi-app`.
     *
     * @param list<string> $argv
     */
    public static function run(array $argv): int
    {
        if (in_array('--in-place', $argv, true)) {
            $extra = getenv(self::ARGS_ENV);
            if (is_string($extra) && trim($extra) !== '') {
                $argv = [...$argv, ...self::splitArgs($extra)];
            }
        }

        $command = self::command();
        $command->setAutoExit(false);

        $input = new ArgvInput($argv);
        // no terminal to ask in (CI, `composer create-project -n`): like inquirer, do not prompt
        if (!stream_isatty(STDIN) && getenv('SHELL_INTERACTIVE') === false) {
            $input->setInteractive(false);
        }

        return $command->run($input, new ConsoleOutput());
    }

    /** @return list<string> shell-like split (double or single quotes group words) */
    public static function splitArgs(string $args): array
    {
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|\'([^\']*)\'|(\S+)/', $args, $matches, PREG_SET_ORDER);

        $out = [];
        foreach ($matches as $m) {
            $out[] = isset($m[3]) ? $m[3] : (($m[2] ?? '') !== '' ? $m[2] : stripcslashes($m[1] ?? ''));
        }

        return $out;
    }

    /** @return Options */
    public static function options(InputInterface $input): array
    {
        $string = static function (string $name) use ($input): ?string {
            $value = $input->getOption($name);

            return is_scalar($value) ? (string) $value : null;
        };
        $negatable = static function (string $name) use ($input): ?bool {
            $value = $input->getOption($name);

            return is_bool($value) ? $value : null;
        };

        return [
            'useNpm' => (bool) $input->getOption('use-npm'),
            'usePnpm' => (bool) $input->getOption('use-pnpm'),
            'useYarn' => (bool) $input->getOption('use-yarn'),
            'quickstart' => (bool) $input->getOption('quickstart'),
            'run' => $negatable('run') !== false,
            'dbclient' => $string('dbclient'),
            'skipCloud' => (bool) $input->getOption('skip-cloud'),
            'skipDb' => (bool) $input->getOption('skip-db'),
            'dbhost' => $string('dbhost'),
            'dbport' => $string('dbport'),
            'dbname' => $string('dbname'),
            'dbusername' => $string('dbusername'),
            'dbpassword' => $string('dbpassword'),
            'dbssl' => $string('dbssl'),
            'dbfile' => $string('dbfile'),
            'template' => $string('template'),
            'typescript' => $input->getOption('typescript') || $input->getOption('ts') ? true : null,
            'javascript' => $input->getOption('javascript') || $input->getOption('js') ? true : null,
            'install' => $negatable('install'),
            'example' => $negatable('example'),
            'gitInit' => $negatable('git-init'),
            'nonInteractive' => (bool) $input->getOption('non-interactive'),
            'templateBranch' => $string('template-branch'),
            'templatePath' => $string('template-path'),
        ];
    }

    public static function execute(InputInterface $input, OutputInterface $output): int
    {
        $logger = new Logger($output);

        try {
            self::create($input, $output, $logger);

            return 0;
        } catch (FatalError) {
            return 1;
        }
    }

    private static function create(InputInterface $input, OutputInterface $output, Logger $logger): void
    {
        $options = self::options($input);
        $inPlace = (bool) $input->getOption('in-place');
        $directoryArg = $input->getArgument('directory');
        $directory = is_string($directoryArg) && $directoryArg !== '' ? $directoryArg : null;
        if ($inPlace) {
            $directory = (string) getcwd();
        }

        $logger->title('Strapi', '<fg=green;options=bold>v' . self::version() . "</> <options=bold>🚀 Let's create your new project</>\n");

        if (($options['javascript'] !== null || $options['typescript'] !== null) && $options['template'] !== null) {
            $logger->fatal('You cannot use <options=bold>--javascript</> or <options=bold>--typescript</> with <options=bold>--template</>');
        }

        if ($options['javascript'] === true && $options['typescript'] === true) {
            $logger->fatal('You cannot use both <options=bold>--typescript</> (--ts) and <options=bold>--javascript</> (--js) flags together');
        }

        if ($options['javascript'] !== null || $options['typescript'] !== null) {
            $logger->warn('<options=bold>--typescript</> and <options=bold>--javascript</> are ignored: the Strapi server of this project is PHP.');
        }

        // Only prompt the example app option if there is no template option
        if ($options['example'] === true && $options['template'] !== null) {
            $logger->fatal('You cannot use <options=bold>--example</> with <options=bold>--template</>');
        }

        if ($options['template'] !== null && str_starts_with($options['template'], '-')) {
            $logger->fatal("Template name <options=bold>\"{$options['template']}\"</> is invalid");
        }

        if (count(array_filter([$options['useNpm'], $options['usePnpm'], $options['useYarn']])) > 1) {
            $logger->fatal('You cannot specify multiple package managers at the same time <options=bold>(--use-npm, --use-pnpm, --use-yarn)</>');
        }

        if ($options['quickstart'] && $directory === null) {
            $logger->fatal('Please specify the <options=bold><directory></> of your project when using <options=bold>--quickstart</>');
        }

        // a non-interactive terminal (CI, `composer create-project -n`) behaves like --non-interactive
        if (!$input->isInteractive()) {
            $options['nonInteractive'] = true;
        }

        if ($options['nonInteractive'] && $directory === null) {
            $logger->fatal('Please specify the <options=bold><directory></> of your project when using <options=bold>--non-interactive</>');
        }

        $skipPrompts = $options['quickstart'] || $options['nonInteractive'];
        $prompts = $skipPrompts ? null : new Prompts($input, $output);

        CheckRequirements::checkPhpRequirements($logger);
        CheckRequirements::checkNodeRequirements($logger);

        $bootstrapperEntries = [];
        if ($inPlace) {
            $rootPath = (string) $directory;
            $bootstrapperEntries = self::bootstrapperEntries($rootPath, $logger);
        } else {
            $appDirectory = $directory ?? $prompts?->directory() ?? 'my-strapi-project';
            $rootPath = CheckInstallPath::checkInstallPath($appDirectory, $logger);
        }

        // Strapi Cloud login (`handleCloudLogin`) is not ported: --skip-cloud is implied

        $tmpPath = rtrim(sys_get_temp_dir(), '/') . '/strapi' . bin2hex(random_bytes(6));

        $randomUUID = InstallId::randomUUID();
        $uuid = (string) getenv('STRAPI_UUID_PREFIX') . $randomUUID;
        $installId = InstallId::installID($uuid);

        $version = self::version();
        $upstream = self::upstreamVersion($version);

        /** @var Scope $scope */
        $scope = [
            'rootPath' => $rootPath,
            'name' => basename($rootPath),
            'packageManager' => self::getPkgManager($options),
            'database' => Database::getDatabaseInfos($options, $logger, $prompts),
            'template' => $options['template'],
            'templateBranch' => $options['templateBranch'],
            'templatePath' => $options['templatePath'],
            'isQuickstart' => $options['quickstart'],
            'useExample' => false,
            'runApp' => $options['quickstart'] && $options['run'],
            'strapiVersion' => $version,
            'packageJsonStrapi' => $options['template'] !== null ? ['template' => $options['template']] : [],
            'uuid' => $uuid,
            'docker' => getenv('DOCKER') === 'true',
            'installId' => $installId,
            'tmpPath' => $tmpPath,
            'gitInit' => true,
            'installDependencies' => true,
            'devDependencies' => [],
            // npm: the admin panel and the admin halves of the plugins, pinned to the upstream release
            'dependencies' => [
                '@strapi/admin' => $upstream,
                '@strapi/strapi' => $upstream,
                '@strapi/plugin-users-permissions' => $upstream,
                // third party
                'react' => '^18.0.0',
                'react-dom' => '^18.0.0',
                // Match @strapi/* peer ranges (^6.30.3) so npm peer resolution stays clean
                'react-router-dom' => '^6.30.3',
                'styled-components' => '^6.0.0',
            ],
            // Composer: what upstream's @strapi/strapi brings in, at this package's version
            'composerDependencies' => Utils\ComposerJson::strapiDependencies($version),
            'pnpmVersion' => null,
            'inPlace' => $inPlace,
        ];

        if ($options['template'] !== null) {
            $scope['useExample'] = false;
        } elseif ($options['example'] === true) {
            $scope['useExample'] = true;
        } elseif ($options['example'] === false || $prompts === null) {
            $scope['useExample'] = false;
        } else {
            $scope['useExample'] = $prompts->example();
        }

        $scope['installDependencies'] = self::resolveOption(
            $options['install'],
            $prompts === null,
            true,
            static fn (): bool => $prompts?->installDependencies($scope['packageManager']) ?? true,
        );

        $scope['gitInit'] = self::resolveOption($options['gitInit'], $prompts === null, true, static fn (): bool => $prompts?->gitInit() ?? true);

        $scope = Database::addDatabaseDependencies($scope);

        if ($inPlace) {
            self::preloadClasses();
        }

        try {
            CreateStrapi::createStrapi($scope, $logger, $bootstrapperEntries);
        } catch (FatalError $error) {
            throw $error;
        } catch (\Throwable $error) {
            Usage::trackError($scope, $error);

            $logger->fatal($error->getMessage());
        }
    }

    /**
     * Resolves a boolean CLI option: explicit flag wins, then non-interactive default, then interactive prompt.
     *
     * @param callable(): bool $promptFn
     */
    private static function resolveOption(?bool $explicitValue, bool $skipPrompts, bool $defaultValue, callable $promptFn): bool
    {
        if ($explicitValue !== null) {
            return $explicitValue;
        }
        if ($skipPrompts) {
            return $defaultValue;
        }

        return $promptFn();
    }

    /**
     * @param Options $options
     * @return PackageManager
     */
    public static function getPkgManager(array $options): string
    {
        if ($options['useNpm']) {
            return 'npm';
        }

        if ($options['usePnpm']) {
            return 'pnpm';
        }

        if ($options['useYarn']) {
            return 'yarn';
        }

        $userAgent = (string) getenv('npm_config_user_agent');

        if (str_starts_with($userAgent, 'yarn')) {
            return 'yarn';
        }

        if (str_starts_with($userAgent, 'pnpm')) {
            return 'pnpm';
        }

        return 'npm';
    }

    /**
     * In-place mode: the directory must be the one `composer create-project` made for this package
     * (its composer.json names a bootstrapper); everything in it but `vendor/` is the bootstrapper.
     *
     * @return list<string>
     */
    private static function bootstrapperEntries(string $rootPath, Logger $logger): array
    {
        $composer = is_file($rootPath . '/composer.json') ? json_decode((string) file_get_contents($rootPath . '/composer.json'), true) : null;
        $name = is_array($composer) ? ($composer['name'] ?? null) : null;

        if (!in_array($name, self::BOOTSTRAPPER_PACKAGES, true)) {
            $logger->fatal([
                '<options=bold>--in-place</> only runs in the directory `composer create-project strapi/create-strapi-app` created',
                "{$rootPath}/composer.json is not " . implode(' or ', self::BOOTSTRAPPER_PACKAGES) . '.',
            ]);
        }

        return array_values(array_filter(
            array_diff(scandir($rootPath) ?: [], ['.', '..']),
            static fn (string $entry): bool => $entry !== 'vendor',
        ));
    }

    /** In place, this package's own files are removed before the run ends: load them all first. */
    private static function preloadClasses(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__, \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                require_once $file->getPathname();
            }
        }

        // what runs after the files are gone: processes, prompts, output
        foreach ([
            \Symfony\Component\Process\Process::class,
            \Symfony\Component\Process\ExecutableFinder::class,
            \Symfony\Component\Process\PhpExecutableFinder::class,
            \Symfony\Component\Process\Pipes\UnixPipes::class,
            \Symfony\Component\Process\Pipes\WindowsPipes::class,
            \Symfony\Component\Process\Exception\ProcessFailedException::class,
            \Symfony\Component\Process\Exception\RuntimeException::class,
            \Symfony\Component\Process\Exception\ProcessTimedOutException::class,
        ] as $class) {
            class_exists($class);
        }
    }
}
