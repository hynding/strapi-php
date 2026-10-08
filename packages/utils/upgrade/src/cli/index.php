<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Cli;

use Strapi\Upgrade\Cli\Commands\Codemods;
use Strapi\Upgrade\Cli\Commands\Upgrade;
use Strapi\Upgrade\Modules\Format\Chalk;
use Strapi\Upgrade\Modules\Upgrader\Constants;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/utils/upgrade/src/cli/index.ts, the `strapi-upgrade` program (upstream's
 * `npx @strapi/upgrade`).
 *
 * Commander → Symfony Console: the global options are only upstream's `-h, --help` and
 * `-V, --version` (Symfony's own `-n`, `-q`, `-v`, `--silent`, `--ansi` would clash with the
 * upgrade options `-n, --dry` and `-s, --silent`); `codemods run|ls` map to `codemods:run|ls`;
 * an unknown command prints upstream's error (no abbreviations).
 */
final class Cli
{
    public static function create(): Application
    {
        $program = new class ('strapi-upgrade', Constants::version()) extends Application {
            protected function getDefaultInputDefinition(): InputDefinition
            {
                return new InputDefinition([
                    new InputArgument('command', InputArgument::REQUIRED, 'The command to execute'),
                    new InputOption('--help', '-h', InputOption::VALUE_NONE, 'Print command line options'),
                    new InputOption('--version', '-V', InputOption::VALUE_NONE, 'Output the version number'),
                ]);
            }

            protected function configureIO(InputInterface $input, OutputInterface $output): void
            {
                $output->setDecorated(Chalk::enabled());
                if (!defined('STDIN') || !stream_isatty(\STDIN)) {
                    $input->setInteractive(false);
                }
            }
        };

        Upgrade::register($program);
        Codemods::register($program);

        return $program;
    }

    /** @param list<string>|null $argv */
    public static function run(?array $argv = null): int
    {
        $argv = self::normalizeArgv($argv ?? array_values(array_map('strval', $_SERVER['argv'] ?? [])));
        $program = self::create();
        $program->setAutoExit(false);

        $commandName = $argv[1] ?? '--help';
        if ($commandName === '-h' || $commandName === '--help') {
            // like commander: the program's help is the list of commands
            $argv = [$argv[0] ?? 'strapi-upgrade', 'list'];
            $commandName = 'list';
        }
        if (!str_starts_with($commandName, '-') && !$program->has($commandName)) {
            fwrite(\STDERR, Chalk::red("[ERROR] Invalid command: {$commandName}." . PHP_EOL . ' See --help for a list of available commands.') . PHP_EOL);

            return 1;
        }

        return $program->run(new ArgvInput($argv), new ConsoleOutput());
    }

    /**
     * `codemods run …` → `codemods:run …`, `codemods ls …` → `codemods:ls …`, bare `codemods` →
     * the list of codemods commands.
     *
     * @param list<string> $argv
     * @return list<string>
     */
    public static function normalizeArgv(array $argv): array
    {
        if (($argv[1] ?? null) !== 'codemods') {
            return $argv;
        }

        $sub = $argv[2] ?? null;
        if ($sub === 'run' || $sub === 'ls') {
            return [$argv[0], "codemods:{$sub}", ...array_slice($argv, 3)];
        }

        return [$argv[0], 'list', 'codemods', ...array_slice($argv, 2)];
    }
}
