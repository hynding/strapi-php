<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli\Utils;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Not an upstream file on its own: what `loadersFactory()` (utils/data-transfer.ts) returns —
 * one {@see ProgressLoader} per stage.
 */
final class ProgressLoaders
{
    /** @var array<string, ProgressLoader> */
    private array $loaders = [];

    public function __construct(private readonly ?OutputInterface $output = null)
    {
    }

    /** @param array<string, array<string, mixed>> $data */
    public function updateLoader(string $stage, array $data): ProgressLoader
    {
        if (!isset($this->loaders[$stage])) {
            $this->createLoader($stage);
        }

        return $this->loaders[$stage]->setText(DataTransfer::progressText($stage, $data));
    }

    public function createLoader(string $stage): ProgressLoader
    {
        return $this->loaders[$stage] = new ProgressLoader($this->output);
    }

    public function getLoader(string $stage): ?ProgressLoader
    {
        return $this->loaders[$stage] ?? null;
    }
}
