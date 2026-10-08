<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Runner\Code;

use Strapi\Upgrade\Modules\Codemod\Codemod;
use Strapi\Upgrade\Modules\Runner\AbstractRunner;

/**
 * Port of packages/utils/upgrade/src/modules/runner/code/code.ts. Upstream's runner is
 * jscodeshift over JS/TS files; this one runs the PHP codemods over the project's PHP files
 * (`Transform::transformCode`). The project's JS/TS files go to `UpstreamRunner`.
 *
 * @phpstan-import-type CodeRunnerConfiguration from Types
 * @extends AbstractRunner<CodeRunnerConfiguration>
 */
final class CodeRunner extends AbstractRunner
{
    /**
     * @param list<string> $paths
     * @param CodeRunnerConfiguration $configuration
     */
    public static function codeRunnerFactory(array $paths, array $configuration): self
    {
        return new self($paths, $configuration);
    }

    protected function runner(string $codemodPath, array $paths, array $configuration, Codemod $codemod): array
    {
        return Transform::transformCode($codemodPath, $paths, $configuration);
    }

    public function valid(Codemod $codemod): bool
    {
        return $codemod->kind === 'code';
    }
}
