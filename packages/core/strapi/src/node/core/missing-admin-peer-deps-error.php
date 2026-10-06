<?php

declare(strict_types=1);

namespace Strapi\Cli\Node\Core;

/** `MissingAdminPeerDepsError` of packages/core/strapi/src/node/core/dependencies.ts. */
final class MissingAdminPeerDepsError extends \RuntimeException
{
    /** @param list<array{name: string, wantedVersion: string}> $missing */
    public function __construct(public readonly array $missing)
    {
        parent::__construct('Missing admin dependencies: ' . implode(', ', array_map(static fn (array $m): string => $m['name'], $missing)));
    }
}
