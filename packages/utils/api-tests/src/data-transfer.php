<?php

declare(strict_types=1);

namespace Strapi\ApiTests;

use Strapi\Core\Strapi;
use Strapi\DataTransfer\Strapi\Providers\LocalDestination\LocalDestination;
use Strapi\DataTransfer\Strapi\Providers\LocalSource\LocalSource;

/**
 * The PHP half of tests/api/lib/data-transfer.js, the `@strapi/data-transfer` the suite imports
 * (`strapi.providers.createLocalStrapiSourceProvider(...)`). A provider's `getStrapi` is the
 * worker's instance; one bridge call runs a provider's whole life for one stage (bootstrap, the
 * stage's stream, close), since a provider cannot be held across calls from the test process.
 */
final readonly class DataTransfer
{
    public function __construct(private Strapi $strapi)
    {
    }

    /**
     * Every item `createLocalStrapiSourceProvider(options)` streams for the stage.
     *
     * @param array{autoDestroy?: bool|null} $options
     *
     * @return list<mixed>
     */
    public function readSourceStage(array $options, string $stage): array
    {
        $strapi = $this->strapi;
        $source = LocalSource::createLocalStrapiSourceProvider([...$options, 'getStrapi' => static fn (): Strapi => $strapi]);
        $source->bootstrap();

        try {
            $items = [];
            $stream = match ($stage) {
                'entities' => $source->createEntitiesReadStream(),
                'links' => $source->createLinksReadStream(),
                'configuration' => $source->createConfigurationReadStream(),
                'schemas' => $source->createSchemasReadStream(),
                'assets' => $source->createAssetsReadStream(),
                default => throw new \InvalidArgumentException("Unknown transfer stage {$stage}"),
            };
            foreach ($stream as $item) {
                $items[] = $item;
            }

            return $items;
        } finally {
            $source->close();
        }
    }

    /**
     * Writes the items to the stage's stream of `createLocalStrapiDestinationProvider(options)`.
     *
     * @param array{autoDestroy?: bool|null, strategy: string, restore?: array<string, mixed>|null} $options
     * @param list<mixed> $items
     */
    public function writeDestinationStage(array $options, string $stage, array $items): void
    {
        $strapi = $this->strapi;
        $destination = LocalDestination::createLocalStrapiDestinationProvider([...$options, 'getStrapi' => static fn (): Strapi => $strapi]);
        $destination->bootstrap();

        try {
            $stream = match ($stage) {
                'entities' => $destination->createEntitiesWriteStream(),
                'links' => $destination->createLinksWriteStream(),
                'configuration' => $destination->createConfigurationWriteStream(),
                'assets' => $destination->createAssetsWriteStream(),
                default => throw new \InvalidArgumentException("Unknown transfer stage {$stage}"),
            };
            foreach ($items as $item) {
                $stream->write($item);
            }
            $stream->end();
        } finally {
            $destination->close();
        }
    }
}
