<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Runner;

use Strapi\Upgrade\Modules\Codemod\Codemod;

/**
 * Port of packages/utils/upgrade/src/modules/runner/runner.ts.
 *
 * @template TConfig of array<string, mixed>
 * @phpstan-import-type ReportData from \Strapi\Upgrade\Modules\Report\Types
 */
abstract class AbstractRunner
{
    /**
     * @param list<string> $paths
     * @param TConfig $configuration
     */
    public function __construct(public array $paths, public array $configuration)
    {
    }

    /**
     * `runner(codemodPath, paths, configuration)`
     *
     * @param list<string> $paths
     * @param TConfig $configuration
     * @return ReportData
     */
    abstract protected function runner(string $codemodPath, array $paths, array $configuration, Codemod $codemod): array;

    /**
     * @param TConfig|array{} $configuration
     * @return ReportData
     */
    public function run(Codemod $codemod, array $configuration = []): array
    {
        $isValidCodemod = $this->valid($codemod);

        if (!$isValidCodemod) {
            throw new \RuntimeException("Invalid codemod provided to the runner: {$codemod->filename}");
        }

        /** @var TConfig $runConfiguration */
        $runConfiguration = [...$this->configuration, ...$configuration];

        return $this->runner($codemod->path, $this->paths, $runConfiguration, $codemod);
    }

    abstract public function valid(Codemod $codemod): bool;
}
