<?php

declare(strict_types=1);

namespace Strapi\Cli\Cli;

use Strapi\Cli\Cli\Utils\Logger;

/**
 * Port of packages/core/strapi/src/cli/types.ts: the `CLIContext` handed to every command factory
 * (`cwd`, `logger`; `tsconfig` has no PHP counterpart).
 *
 * A command factory is `callable(CliContext): ?\Symfony\Component\Console\Command\Command`
 * (upstream's `StrapiCommand`).
 */
final class CliContext
{
    public function __construct(
        public readonly string $cwd,
        public readonly Logger $logger,
    ) {
    }
}
