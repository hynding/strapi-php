<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Utils\Cuid2;

final class Cuid2Test extends TestCase
{
    public function testFormat(): void
    {
        $ids = [];
        for ($i = 0; $i < 200; $i++) {
            $id = Cuid2::createId();
            self::assertMatchesRegularExpression('/^[a-z][a-z0-9]{23}$/', $id);
            self::assertTrue(Cuid2::isCuid($id));
            $ids[$id] = true;
        }
        self::assertCount(200, $ids, 'ids are unique');
    }

    public function testCustomLength(): void
    {
        self::assertMatchesRegularExpression('/^[a-z][a-z0-9]{9}$/', Cuid2::createId(10));
        self::assertMatchesRegularExpression('/^[a-z][a-z0-9]{31}$/', Cuid2::createId(32));
    }

    public function testIsCuid(): void
    {
        self::assertFalse(Cuid2::isCuid(''));
        self::assertFalse(Cuid2::isCuid('ABC'));
        self::assertFalse(Cuid2::isCuid(str_repeat('a', 33)));
        self::assertTrue(Cuid2::isCuid('tz4a98xxat96iws9zmbrgj3a'));
    }
}
