<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Utils\ParseType;

/** Port of __tests__/parse-type.test.ts. */
final class ParseTypeTest extends TestCase
{
    public function testHandlesStringBooleans(): void
    {
        self::assertTrue(ParseType::parseType(['type' => 'boolean', 'value' => 'true']));
        self::assertTrue(ParseType::parseType(['type' => 'boolean', 'value' => 't']));
        self::assertTrue(ParseType::parseType(['type' => 'boolean', 'value' => '1']));
        self::assertFalse(ParseType::parseType(['type' => 'boolean', 'value' => 'false']));
        self::assertFalse(ParseType::parseType(['type' => 'boolean', 'value' => 'f']));
        self::assertFalse(ParseType::parseType(['type' => 'boolean', 'value' => '0']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid boolean input. Expected "t","1","true","false","0","f"');
        ParseType::parseType(['type' => 'boolean', 'value' => 'test']);
    }

    public function testHandlesNumericalBooleans(): void
    {
        self::assertTrue(ParseType::parseType(['type' => 'boolean', 'value' => 1]));
        self::assertFalse(ParseType::parseType(['type' => 'boolean', 'value' => 0]));
        self::assertTrue(ParseType::parseType(['type' => 'boolean', 'value' => 12, 'forceCast' => true]));

        $this->expectException(\InvalidArgumentException::class);
        ParseType::parseType(['type' => 'boolean', 'value' => 12]);
    }

    /** @return iterable<array{mixed}> */
    public static function booleanLikeCases(): iterable
    {
        foreach ([true, false, 'true', 't', '1', 1, 'false', 'f', '0', 0, 'test', '', 12, -1, 1.5, NAN, null, [], 'TRUE', 'yes', 1.0, 0.0] as $value) {
            yield [$value];
        }
    }

    #[DataProvider('booleanLikeCases')]
    public function testIsBooleanLikeAgreesWithParseType(mixed $value): void
    {
        try {
            ParseType::parseType(['type' => 'boolean', 'value' => $value]);
            $parseAccepts = true;
        } catch (\InvalidArgumentException) {
            $parseAccepts = false;
        }

        self::assertSame($parseAccepts, ParseType::isBooleanLike($value));
    }

    public function testTimeAlwaysReturnsTheSameFormat(): void
    {
        self::assertSame('12:31:11.000', ParseType::parseType(['type' => 'time', 'value' => '12:31:11']));
        self::assertSame('12:31:11.200', ParseType::parseType(['type' => 'time', 'value' => '12:31:11.2']));
        self::assertSame('12:31:11.310', ParseType::parseType(['type' => 'time', 'value' => '12:31:11.31']));
        self::assertSame('12:31:11.319', ParseType::parseType(['type' => 'time', 'value' => '12:31:11.319']));
        self::assertSame('12:31:11.123', ParseType::parseType(['type' => 'time', 'value' => new \DateTimeImmutable('2020-01-01 12:31:11.123')]));
    }

    /** @return iterable<array{mixed}> */
    public static function invalidTimes(): iterable
    {
        foreach (['25:12:09', '23:78:09', '23:11:99', '12:12', 'test', 122, [], '12:31:11x2', '12:31:11,2', '12:31:11 2'] as $value) {
            yield [$value];
        }
    }

    #[DataProvider('invalidTimes')]
    public function testThrowsOnInvalidTimeFormat(mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ParseType::parseType(['type' => 'time', 'value' => $value]);
    }

    public function testDateSupportsIsoFormats(): void
    {
        self::assertSame('2019-01-01', ParseType::parseType(['type' => 'date', 'value' => '2019-01-01 12:01:11']));
        self::assertSame('2018-11-02', ParseType::parseType(['type' => 'date', 'value' => '2018-11-02']));
        self::assertSame('2018-11-02', ParseType::parseType(['type' => 'date', 'value' => '2018-11-02T10:00:00.000Z']));
        self::assertSame('2018-11-02', ParseType::parseType(['type' => 'date', 'value' => new \DateTimeImmutable('2018-11-02')]));
    }

    /** @return iterable<array{string}> */
    public static function invalidDates(): iterable
    {
        foreach (['-1029-11-02', '2019-13-02', '2019-12-32', '2019-02-31'] as $value) {
            yield [$value];
        }
    }

    #[DataProvider('invalidDates')]
    public function testThrowsOnInvalidDates(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid format, expected an ISO compatible date');
        ParseType::parseType(['type' => 'date', 'value' => $value]);
    }

    /** @return iterable<array{string}> */
    public static function datetimes(): iterable
    {
        foreach (['2019-01-01', '2019-01-01 10:11:12', '1234567890111', '2019-01-01T10:11:12.123Z'] as $value) {
            yield [$value];
        }
    }

    #[DataProvider('datetimes')]
    public function testDatetimeSupportsIsoFormatsAndTimestamps(string $value): void
    {
        self::assertInstanceOf(\DateTimeImmutable::class, ParseType::parseType(['type' => 'datetime', 'value' => $value]));
    }

    public function testDatetimeValues(): void
    {
        $date = ParseType::parseType(['type' => 'datetime', 'value' => '2019-01-01T10:11:12.123Z']);
        self::assertSame('2019-01-01T10:11:12.123+00:00', $date->format('Y-m-d\TH:i:s.vP'));

        $ts = ParseType::parseType(['type' => 'timestamp', 'value' => '1234567890111']);
        self::assertSame('1234567890.111', $ts->format('U.v'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid format, expected a timestamp or an ISO date');
        ParseType::parseType(['type' => 'datetime', 'value' => 'nope']);
    }

    public function testNumbersAndPassthrough(): void
    {
        self::assertSame(12, ParseType::parseType(['type' => 'integer', 'value' => '12']));
        self::assertSame(1.5, ParseType::parseType(['type' => 'float', 'value' => '1.5']));
        self::assertNan(ParseType::parseType(['type' => 'decimal', 'value' => 'x']));
        self::assertSame('x', ParseType::parseType(['type' => 'string', 'value' => 'x']));
        self::assertSame(0, ParseType::toNumber(''));
        self::assertSame(0, ParseType::toNumber(null));
        self::assertSame(1, ParseType::toNumber(true));
        self::assertNan(ParseType::toNumber([1, 2]));
    }
}
