<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests\Primitives;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Utils\Primitives\Arrays;
use Strapi\Utils\Primitives\Dates;

/** Port of primitives/__tests__/arrays.test.ts (+ dates). */
final class ArraysTest extends TestCase
{
    /** @return iterable<array{list<mixed>, mixed, bool}> */
    public static function cases(): iterable
    {
        yield [['1', '2', '3'], '1', true];
        yield [['1', '2', '3'], '4', false];
        yield [[1, 2, 3], 1, true];
        yield [[1, 2, 3], 4, false];
        yield [[1, 2, 3], '1', true];
        yield [[1, 2, 3], '4', false];
        yield [[1, 2, 3], '01', false];
        yield [['1', '2', '3'], 1, true];
        yield [['1', '2', '3'], 4, false];
        yield [['01', '02', '03'], 1, false];
    }

    /** @param list<mixed> $arr */
    #[DataProvider('cases')]
    public function testIncludesString(array $arr, mixed $val, bool $expected): void
    {
        self::assertSame($expected, Arrays::includesString($arr, $val));
    }

    public function testTimestampCode(): void
    {
        $date = new \DateTimeImmutable('@1700000000');
        self::assertSame(base_convert('1700000000000', 10, 36), Dates::timestampCode($date));
        self::assertMatchesRegularExpression('/^[0-9a-z]+$/', Dates::timestampCode());
    }
}
