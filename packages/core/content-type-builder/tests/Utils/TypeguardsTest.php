<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Utils\Typeguards;

/** Port of server/src/utils/__tests__/typeguards.vitest.test.ts. */
final class TypeguardsTest extends TestCase
{
    public function testHasDefaultAttribute(): void
    {
        self::assertTrue(Typeguards::hasDefaultAttribute(['type' => 'string', 'default' => 'x']));
        self::assertFalse(Typeguards::hasDefaultAttribute(['type' => 'string']));
        self::assertTrue(Typeguards::hasDefaultAttribute(['type' => 'string', 'default' => null]));
    }
}
