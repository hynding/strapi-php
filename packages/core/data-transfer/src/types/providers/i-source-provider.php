<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Types\Providers;

/**
 * Port of `ISourceProvider` (src/types/providers.ts). Optional methods: `validateStage(string $stage)`,
 * `getStageTotals(string $stage): ?array{totalBytes?: int, totalCount?: int}`, and the
 * `create{Entities,Links,Assets,Configuration,Schemas}ReadStream(): iterable` readers.
 */
interface ISourceProvider extends IProvider
{
}
