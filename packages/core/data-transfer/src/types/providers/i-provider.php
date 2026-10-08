<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Types\Providers;

/**
 * Port of `IProvider` (src/types/providers.ts). Implementations expose public `$type`
 * (`'source'` | `'destination'`) and `$name` properties, and may implement the optional lifecycle
 * methods upstream marks with `?`: `bootstrap(Diagnostic $diagnostics)`, `close()`,
 * `getSchemas(): ?array`, `beforeTransfer()`. The engine checks for them with `method_exists`.
 *
 * @property string $type
 * @property string $name
 */
interface IProvider
{
    /**
     * returns the transfer metadata to be used for version validation
     *
     * @return array{strapi?: array{version?: string}, createdAt?: string}|null
     */
    public function getMetadata(): ?array;
}
