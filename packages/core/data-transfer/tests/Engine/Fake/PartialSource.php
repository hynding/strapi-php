<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Engine\Fake;

use Strapi\DataTransfer\Types\Providers\ISourceProvider;

/** engine.test.ts "emits 'stage::skip' events": a source without the schemas, links and entities streams. */
final class PartialSource implements ISourceProvider
{
    public string $type = 'source';

    public string $name = 'partialSource';

    public function getMetadata(): ?array
    {
        return Data::METADATA;
    }

    /** @return array<string, mixed> */
    public function getSchemas(): array
    {
        return Data::schemas();
    }

    /** @return iterable<mixed> */
    public function createAssetsReadStream(): iterable
    {
        return Data::assets();
    }

    /** @return iterable<mixed> */
    public function createConfigurationReadStream(): iterable
    {
        return Data::CONFIGURATION;
    }
}
