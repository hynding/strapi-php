<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli;

use Strapi\Cli\Cli\Commands\Commands;
use Strapi\Cli\Cli\Utils\Logger;
use Strapi\Cli\Strapi as CliStrapi;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/index.ts: `createCLI` / `runCLI`.
 *
 * commander becomes symfony/console. Every command receives the {@see CliContext} (cwd, logger)
 * exactly like upstream's `StrapiCommand` factories; `--debug` / `--silent` are read from argv
 * before the application is built, so the logger is configured the way upstream configures it.
 */
final class Cli
{
    /**
     * Build the console application for a project root (`cwd` upstream = `process.cwd()`).
     *
     * @param list<string> $argv
     */
    public static function createCLI(array $argv, ?string $cwd = null): Application
    {
        $cwd ??= (string) getcwd();

        $hasDebug = in_array('--debug', $argv, true) || in_array('-d', $argv, true);
        $hasSilent = in_array('--silent', $argv, true);

        $logger = Logger::createLogger(['debug' => $hasDebug, 'silent' => $hasSilent, 'timestamp' => false]);

        $ctx = new CliContext($cwd, $logger);

        $application = new Application('strapi', CliStrapi::version());
        $application->setAutoExit(false);

        // Load all commands
        foreach (Commands::all() as $commandFactory) {
            try {
                $subCommand = $commandFactory($ctx);

                if ($subCommand instanceof Command) {
                    $application->add($subCommand);
                }
            } catch (\Throwable $e) {
                fwrite(STDERR, "Failed to load command: {$e->getMessage()}\n");
            }
        }

        // TODO v6: remove these deprecation notices
        $deprecatedCommands = [
            ['name' => 'plugin:init', 'message' => 'Please use `npx @strapi/sdk-plugin init` instead.'],
            ['name' => 'plugin:verify', 'message' => 'After migrating your plugin to v5, use `strapi-plugin verify`'],
            ['name' => 'plugin:watch', 'message' => 'After migrating your plugin to v5, use `strapi-plugin watch`'],
            ['name' => 'plugin:watch:link', 'message' => 'After migrating your plugin to v5, use `strapi-plugin watch:link`'],
            ['name' => 'plugin:build', 'message' => 'After migrating your plugin to v5, use `strapi-plugin build`'],
        ];

        foreach ($deprecatedCommands as ['name' => $name, 'message' => $message]) {
            $deprecated = new Command($name);
            $deprecated->setDescription('(deprecated)')->setHidden(true);
            $deprecated->setCode(static function (InputInterface $input, OutputInterface $output) use ($name, $message): int {
                $output->writeln("<comment>The command {$name} has been deprecated. See the Strapi 5 migration guide for more information.</comment>");
                $output->writeln("<comment>{$message}</comment>");

                return Command::SUCCESS;
            });
            $application->add($deprecated);
        }

        return $application;
    }

    /**
     * Run the CLI. Returns the exit code (the `bin/strapi` scripts `exit()` with it).
     *
     * @param list<string>|null $argv defaults to `$_SERVER['argv']`
     */
    public static function runCLI(?string $cwd = null, ?array $argv = null, ?OutputInterface $output = null): int
    {
        $argv ??= $_SERVER['argv'] ?? [];
        $cwd ??= (string) getcwd();

        $application = self::createCLI($argv, $cwd);

        // `--debug` / `--silent` are command options upstream (global here, so any command accepts them)
        $input = new ArgvInput($argv);

        return $application->run($input, $output ?? new ConsoleOutput());
    }

    /** Entry point used by a project's `bin/strapi`: `exit(Cli::run($projectRoot))`. */
    public static function run(?string $cwd = null): int
    {
        return self::runCLI($cwd);
    }
}
