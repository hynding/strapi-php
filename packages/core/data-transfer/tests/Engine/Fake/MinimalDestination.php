<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Engine\Fake;

use Strapi\DataTransfer\Types\Providers\IDestinationProvider;

/** engine.test.ts `minimalDestination`: only metadata and schemas (both empty). */
class MinimalDestination implements IDestinationProvider
{
    public string $type = 'destination';

    public string $name = 'minimalDestination';

    public function getMetadata(): ?array
    {
        return null;
    }

    /** @return array<string, mixed>|null */
    public function getSchemas(): ?array
    {
        return null;
    }
}
