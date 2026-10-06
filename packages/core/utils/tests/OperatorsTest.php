<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Utils\Operators;

/** Port of __tests__/operators.vitest.test.ts. */
final class OperatorsTest extends TestCase
{
    /** @return iterable<array{string, string, bool, bool}> */
    public static function ofType(): iterable
    {
        yield ['where', '$eq', true, false];
        yield ['where', '$EQ', false, false];
        yield ['where', '$EQ', true, true];
        yield ['cast', '$between', true, false];
        yield ['group', '$and', true, false];
        yield ['array', '$in', true, false];
        yield ['where', '$unknown', false, false];
        yield ['unknownType', '$eq', false, false];
    }

    #[DataProvider('ofType')]
    public function testIsOperatorOfType(string $type, string $key, bool $expected, bool $ignoreCase): void
    {
        self::assertSame($expected, Operators::isOperatorOfType($type, $key, $ignoreCase));
    }

    public function testIsOperator(): void
    {
        self::assertTrue(Operators::isOperator('$eq'));
        self::assertTrue(Operators::isOperator('$and'));
        self::assertTrue(Operators::isOperator('$in'));
        self::assertFalse(Operators::isOperator('title'));
        self::assertFalse(Operators::isOperator('$unknown'));
        self::assertTrue(Operators::isOperator('$EQ', true));
        self::assertFalse(Operators::isOperator('$EQ', false));
    }
}
