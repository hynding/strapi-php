<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Tests\Engine\Fake;

use Strapi\DataTransfer\Types\Providers\ISourceProvider;

/** engine.test.ts `minimalSource`: only metadata and schemas (both empty). */
class MinimalSource implements ISourceProvider
{
    public string $type = 'source';

    public string $name = 'minimalSource';

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
