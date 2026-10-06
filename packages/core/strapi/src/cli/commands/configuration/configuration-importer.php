<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Commands\Configuration;

/** Not an upstream file: the `{ import, printStatistics }` object the restore importers of restore.ts return. */
final class ConfigurationImporter
{
    /**
     * @param array<string, int> $stats
     * @param callable(array<string, int>): string $printStatistics
     * @param callable(array<string, mixed>, array<string, int>&): void $import
     */
    public function __construct(private array $stats, private readonly mixed $printStatistics, private readonly mixed $import)
    {
    }

    public function printStatistics(): string
    {
        return ($this->printStatistics)($this->stats);
    }

    /** @param array<string, mixed> $conf */
    public function import(array $conf): void
    {
        ($this->import)($conf, $this->stats);
    }

    /** @return array<string, int> */
    public function stats(): array
    {
        return $this->stats;
    }
}
