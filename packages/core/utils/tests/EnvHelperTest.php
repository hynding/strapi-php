<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Utils\EnvHelper;

/** Port of __tests__/env-helper.test.ts. */
final class EnvHelperTest extends TestCase
{
    /** @param array<string, string> $vars */
    private static function env(array $vars = []): EnvHelper
    {
        return new EnvHelper($vars);
    }

    public function testEnvWithoutCast(): void
    {
        self::assertNull(self::env()('NO_VAR'));
        self::assertSame('', self::env(['WITH_VAR' => ''])('WITH_VAR'));
        self::assertSame('test', self::env(['WITH_VAR' => 'test'])('WITH_VAR'));
        self::assertSame('default', self::env()('NO_VAR', 'default'));
        self::assertSame('test', self::env(['WITH_VAR' => 'test'])->get('WITH_VAR'));
    }

    public function testIntCast(): void
    {
        self::assertNull(self::env()->int('NO_VAR'));
        self::assertNan(self::env(['NOT_INT_VAR' => ''])->int('NOT_INT_VAR'));
        self::assertSame(123, self::env(['INT_VAR' => '123'])->int('INT_VAR'));
        self::assertSame(123, self::env(['INT_VAR' => '123.9'])->int('INT_VAR'));
        self::assertSame(7, self::env()->int('NO_VAR', 7));
    }

    public function testFloatCast(): void
    {
        self::assertNull(self::env()->float('NO_VAR'));
        self::assertNan(self::env(['NOT_FLOAT_VAR' => ''])->float('NOT_FLOAT_VAR'));
        self::assertSame(123.45, self::env(['FLOAT_VAR' => '123.45'])->float('FLOAT_VAR'));
    }

    /** @return iterable<array{string}> */
    public static function notTrue(): iterable
    {
        yield [''];
        yield ['1'];
        yield ['-1'];
        yield ['false'];
    }

    #[DataProvider('notTrue')]
    public function testBoolIsFalseUnlessTrue(string $value): void
    {
        self::assertFalse(self::env(['NOT_TRUE' => $value])->bool('NOT_TRUE'));
    }

    public function testBoolCast(): void
    {
        self::assertNull(self::env()->bool('NO_VAR'));
        self::assertTrue(self::env(['TRUE_VAR' => 'true'])->bool('TRUE_VAR'));
        self::assertTrue(self::env()->bool('TRUE_VAR', true));
    }

    public function testJsonCast(): void
    {
        self::assertNull(self::env()->json('NO_VAR'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid json environment variable');
        self::env(['JSON_VAR' => '{"}'])->json('JSON_VAR');
    }

    /** @return iterable<array{string, mixed}> */
    public static function json(): iterable
    {
        yield ['123.45', 123.45];
        yield ['{}', []];
        yield ['{ "key": "value" }', ['key' => 'value']];
        yield ['{ "key": 12 }', ['key' => 12]];
        yield ['{ "key": { "subKey": "value" } }', ['key' => ['subKey' => 'value']]];
        yield ['"some text"', 'some text'];
        yield ['[12,32]', [12, 32]];
    }

    #[DataProvider('json')]
    public function testValidJson(string $input, mixed $expected): void
    {
        self::assertSame($expected, self::env(['JSON_VAR' => $input])->json('JSON_VAR'));
    }

    public function testArrayCast(): void
    {
        self::assertNull(self::env()->array('NO_VAR'));
        self::assertSame(['somevalue'], self::env(['V' => 'somevalue'])->array('V'));
        self::assertSame(['123', '456'], self::env(['V' => '123,456'])->array('V'));
        self::assertSame(['firstValue', 'secondValue'], self::env(['V' => 'firstValue, secondValue'])->array('V'));
        self::assertSame(['firstValue', 'secondValue'], self::env(['V' => '[firstValue, secondValue]'])->array('V'));
        self::assertSame(['firstValue', 'SecondValue  '], self::env(['V' => '  "firstValue" , SecondValue  "'])->array('V'));
    }

    public function testDateCast(): void
    {
        self::assertNull(self::env()->date('NO_VAR'));
        self::assertNull(self::env(['NOT_DATE_VAR' => 'random string'])->date('NOT_DATE_VAR'));

        $date = self::env(['DATE_VAR' => '2010-02-21T12:34:12'])->date('DATE_VAR');
        self::assertInstanceOf(\DateTimeInterface::class, $date);
        self::assertSame('2010-02-21 12:34:12', $date->format('Y-m-d H:i:s'));
    }

    public function testOneOf(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::env()->oneOf('NO_VAR');
    }

    public function testOneOfRequiresDefaultInExpectedValues(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        self::env()->oneOf('NO_VAR', ['lorem', 'ipsum'], 'test');
    }

    public function testOneOfValues(): void
    {
        self::assertNull(self::env()->oneOf('NO_VAR', ['lorem', 'ipsum']));
        self::assertSame('ipsum', self::env()->oneOf('NO_VAR', ['lorem', 'ipsum'], 'ipsum'));
        self::assertSame('ipsum', self::env(['WITH_VAR' => 'test'])->oneOf('WITH_VAR', ['lorem', 'ipsum'], 'ipsum'));
        self::assertSame('lorem', self::env(['WITH_VAR' => 'lorem'])->oneOf('WITH_VAR', ['lorem', 'ipsum'], 'ipsum'));
    }
}
