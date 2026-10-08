<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Port of packages/core/strapi/src/cli/commands/report.ts: `$ strapi report`.
 *
 * `Node/Yarn Version` (npm_config_user_agent) becomes `PHP Version`; the dependencies are the
 * project's package.json ones like upstream, else its composer.json `require`/`require-dev`.
 * `--debug` stays the global debugging flag every command has, so the dependencies option is `-D`.
 */
final class Report extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('report')
            ->setDescription('Get system stats for debugging and submitting issues')
            ->addOption('uuid', 'u', InputOption::VALUE_NONE, 'Include Project UUID')
            ->addOption('dependencies', 'D', InputOption::VALUE_NONE, 'Include Project Dependencies')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Include All Information');
    }

    protected function action(InputInterface $input, OutputInterface $output): int
    {
        $all = (bool) $input->getOption('all');
        $config = [
            'reportUUID' => $all || (bool) $input->getOption('uuid'),
            'reportDependencies' => $all || (bool) $input->getOption('dependencies'),
        ];

        $app = $this->createStrapi()->register();

        $launchedAt = $app->config()->get('launchedAt');
        $elapsed = (int) floor(microtime(true) * 1000) - (is_numeric($launchedAt) ? (int) $launchedAt : 0);
        $client = $app->config()->get('database.connection.client');
        $eol = PHP_EOL;

        $debugInfo = "Launched In: {$elapsed} ms
Environment: " . self::str($app->config()->get('environment')) . '
OS: ' . strtolower(PHP_OS_FAMILY) . '-' . php_uname('m') . '
Strapi Version: ' . self::str($app->config()->get('info.strapi')) . '
PHP Version: ' . PHP_VERSION . '
Edition: ' . ($app->EE() ? 'Enterprise' : 'Community') . '
Database: ' . (is_string($client) ? $client : 'unknown');

        if ($config['reportUUID']) {
            $debugInfo .= "{$eol}UUID: " . self::str($app->config()->get('uuid'));
        }

        if ($config['reportDependencies']) {
            $dependencies = $app->config()->get('info.dependencies') ?? $app->config()->get('info.composer.require');
            $devDependencies = $app->config()->get('info.devDependencies') ?? $app->config()->get('info.composer.require-dev');
            $debugInfo .= "{$eol}Dependencies: " . self::json($dependencies) . "
Dev Dependencies: " . self::json($devDependencies);
        }

        $output->writeln($debugInfo, OutputInterface::OUTPUT_RAW);

        $app->destroy();

        return Command::SUCCESS;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : 'undefined';
    }

    /** `JSON.stringify(value, null, 2)` */
    private static function json(mixed $value): string
    {
        if ($value === null) {
            return 'undefined';
        }
        $json = (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return (string) preg_replace_callback('/^( {4})+/m', static fn (array $m): string => str_repeat('  ', intdiv(strlen($m[0]), 4)), $json);
    }
}
