<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Types\Providers;

/**
 * Port of `IDestinationProvider` (src/types/providers.ts). Optional methods: `rollback(\Throwable $e)`,
 * `setMetadata(string $target, array $metadata)`, an `onWarning` callable property and the
 * `create{Entities,Links,Assets,Configuration,Schemas}WriteStream(): Writable` writers.
 */
interface IDestinationProvider extends IProvider
{
}
