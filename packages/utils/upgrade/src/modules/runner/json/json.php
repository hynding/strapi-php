<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Runner\Json;

use Strapi\Upgrade\Modules\Codemod\Codemod;
use Strapi\Upgrade\Modules\Runner\AbstractRunner;

/**
 * Port of packages/utils/upgrade/src/modules/runner/json/json.ts.
 *
 * @phpstan-import-type JSONRunnerConfiguration from Types
 * @extends AbstractRunner<JSONRunnerConfiguration>
 */
final class JSONRunner extends AbstractRunner
{
    /**
     * @param list<string> $paths
     * @param JSONRunnerConfiguration $configuration
     */
    public static function jsonRunnerFactory(array $paths, array $configuration): self
    {
        return new self($paths, $configuration);
    }

    protected function runner(string $codemodPath, array $paths, array $configuration, Codemod $codemod): array
    {
        return Transform::transformJSON($codemodPath, $paths, $configuration);
    }

    public function valid(Codemod $codemod): bool
    {
        return $codemod->kind === 'json';
    }
}
