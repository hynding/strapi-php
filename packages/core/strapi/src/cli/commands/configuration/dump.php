<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Configuration;

use Strapi\Cli\Cli\Commands\StrapiCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Port of packages/core/strapi/src/cli/commands/configuration/dump.ts: `$ strapi configuration:dump` (alias `config:dump`). */
final class Dump extends StrapiCommand
{
    private const CHUNK_SIZE = 100;

    protected function configure(): void
    {
        parent::configure();
        $this
            ->setName('configuration:dump')
            ->setAliases(['config:dump'])
            ->addOption('file', 'f', InputOption::VALUE_REQUIRED, 'Output file, default output is stdout')
            ->addOption('pretty', 'p', InputOption::VALUE_NONE, 'Format the output JSON with indentation and line breaks')
            ->setDescription('Dump configurations of your application');
    }

    /**
     * Will dump configurations to a file or stdout
     */
    protected function action(InputInterface $input, OutputInterface $output): int
    {
        $filePath = $input->getOption('file');
        $pretty = (bool) $input->getOption('pretty');

        $app = $this->createStrapi()->load();

        $count = $app->query('strapi::core-store')->count();

        $exportData = [];

        $pageCount = (int) ceil($count / self::CHUNK_SIZE);

        for ($page = 0; $page < $pageCount; $page++) {
            $results = $app->query('strapi::core-store')->findMany(['limit' => self::CHUNK_SIZE, 'offset' => $page * self::CHUNK_SIZE, 'orderBy' => 'key']);

            foreach ($results as $result) {
                if (!str_starts_with((string) ($result['key'] ?? ''), 'plugin_')) {
                    continue;
                }
                $exportData[] = [
                    'key' => $result['key'],
                    'value' => $result['value'] ?? null,
                    'type' => $result['type'] ?? null,
                    'environment' => $result['environment'] ?? null,
                    'tag' => $result['tag'] ?? null,
                ];
            }
        }

        $str = (string) json_encode($exportData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0));

        if (is_string($filePath) && $filePath !== '') {
            file_put_contents($filePath, $str . "\n");
            // log success only when writing to file
            $output->writeln('Successfully exported ' . count($exportData) . ' configuration entries');
        } else {
            $output->write($str . "\n", false, OutputInterface::OUTPUT_RAW);
        }

        $app->destroy();

        return Command::SUCCESS;
    }
}
