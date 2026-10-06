<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Configuration;

use Strapi\Cli\Cli\Commands\StrapiCommand;
use Strapi\Database\Database;
use Strapi\Utils\Primitives\Objects;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Port of packages/core/strapi/src/cli/commands/configuration/restore.ts: `$ strapi configuration:restore` (alias `config:restore`). */
final class Restore extends StrapiCommand
{
    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('configuration:restore')
            ->setAliases(['config:restore'])
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Input file, default input is stdin')
            ->addOption('strategy', 's', InputOption::VALUE_REQUIRED, 'Strategy name, one of: "replace", "merge", "keep"', 'replace')
            ->setDescription('Restore configurations of your application');
    }

    /**
     * Will restore configurations. It reads from a file or stdin
     */
    protected function action(InputInterface $input, OutputInterface $output): int
    {
        $filePath = $input->getOption('file');
        $strategy = (string) ($input->getOption('strategy') ?? 'replace');

        $raw = is_string($filePath) && $filePath !== '' ? (string) file_get_contents($filePath) : self::readStdin();

        $app = $this->createStrapi()->load();

        try {
            $dataToImport = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException("Invalid input data: {$error->getMessage()}. Expected a valid JSON array.");
        }

        if (!is_array($dataToImport) || !array_is_list($dataToImport)) {
            throw new \RuntimeException('Invalid input data. Expected a valid JSON array.');
        }

        $importer = self::createImporter($app->db(), $strategy);

        foreach ($dataToImport as $config) {
            $importer->import(is_array($config) ? $config : []);
        }

        $output->writeln("Successfully imported configuration with {$strategy} strategy. Statistics: {$importer->printStatistics()}.");

        $app->destroy();

        return Command::SUCCESS;
    }

    private static function readStdin(): string
    {
        if (function_exists('posix_isatty') && @posix_isatty(STDIN)) {
            return '';
        }

        return (string) stream_get_contents(STDIN);
    }

    public static function createImporter(Database $db, string $strategy): ConfigurationImporter
    {
        return match ($strategy) {
            'replace' => self::createReplaceImporter($db),
            'merge' => self::createMergeImporter($db),
            'keep' => self::createKeepImporter($db),
            default => throw new \RuntimeException("No importer available for strategy \"{$strategy}\""),
        };
    }

    /**
     * Replace importer. Will replace the keys that already exist and create the new ones
     */
    private static function createReplaceImporter(Database $db): ConfigurationImporter
    {
        return new ConfigurationImporter(
            ['created' => 0, 'replaced' => 0],
            static fn (array $stats): string => "{$stats['created']} created, {$stats['replaced']} replaced",
            static function (array $conf, array &$stats) use ($db): void {
                $matching = $db->query('strapi::core-store')->count(['where' => ['key' => $conf['key'] ?? null]]);
                if ($matching > 0) {
                    $stats['replaced']++;
                    $db->query('strapi::core-store')->update(['where' => ['key' => $conf['key'] ?? null], 'data' => $conf]);
                } else {
                    $stats['created']++;
                    $db->query('strapi::core-store')->create(['data' => $conf]);
                }
            },
        );
    }

    /**
     * Merge importer. Will merge the keys that already exist with their new value and create the new ones
     */
    private static function createMergeImporter(Database $db): ConfigurationImporter
    {
        return new ConfigurationImporter(
            ['created' => 0, 'merged' => 0],
            static fn (array $stats): string => "{$stats['created']} created, {$stats['merged']} merged",
            static function (array $conf, array &$stats) use ($db): void {
                $existingConf = $db->query('strapi::core-store')->findOne(['where' => ['key' => $conf['key'] ?? null]]);

                if ($existingConf !== null) {
                    $stats['merged']++;
                    $db->query('strapi::core-store')->update(['where' => ['key' => $conf['key'] ?? null], 'data' => Objects::merge($existingConf, $conf)]);
                } else {
                    $stats['created']++;
                    $db->query('strapi::core-store')->create(['data' => $conf]);
                }
            },
        );
    }

    /**
     * Keep importer. Will keep the keys that already exist without changing them and create the new ones
     */
    private static function createKeepImporter(Database $db): ConfigurationImporter
    {
        return new ConfigurationImporter(
            ['created' => 0, 'untouched' => 0],
            static fn (array $stats): string => "{$stats['created']} created, {$stats['untouched']} untouched",
            static function (array $conf, array &$stats) use ($db): void {
                $matching = $db->query('strapi::core-store')->count(['where' => ['key' => $conf['key'] ?? null]]);
                if ($matching > 0) {
                    $stats['untouched']++;
                    // if configuration already exists do not overwrite it
                    return;
                }

                $stats['created']++;
                $db->query('strapi::core-store')->create(['data' => $conf]);
            },
        );
    }
}
