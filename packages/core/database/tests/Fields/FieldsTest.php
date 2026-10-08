<?php

declare(strict_types=1);

namespace Strapi\Database\Tests\Fields;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Database\Errors\InvalidDateError;
use Strapi\Database\Errors\InvalidDateTimeError;
use Strapi\Database\Errors\InvalidTimeError;
use Strapi\Database\Fields\BigIntegerField;
use Strapi\Database\Fields\BooleanField;
use Strapi\Database\Fields\DatetimeField;
use Strapi\Database\Fields\Fields;
use Strapi\Database\Fields\JsonField;
use Strapi\Database\Fields\NumberField;
use Strapi\Database\Fields\Shared\Parsers;
use Strapi\Database\Fields\StringField;
use Strapi\Database\Fields\TimestampField;
use Strapi\Database\Query\Helpers\Transform;
use Strapi\Utils\EmptyObject;

/** Port of fields/__tests__/*.vitest.test.ts, fields/shared/__tests__/parsers.vitest.test.ts and __tests__/numeric-fields.test.ts. */
final class FieldsTest extends TestCase
{
    /** @return iterable<array{mixed, mixed}> */
    public static function booleanToDb(): iterable
    {
        yield [true, true];
        yield [false, false];
        yield [null, null];
        yield ['true', true];
        yield ['t', true];
        yield ['1', true];
        yield [1, true];
        yield ['false', false];
        yield ['f', false];
        yield ['0', false];
        yield [0, false];
        yield ['yes', true];
        yield ['', false];
    }

    #[DataProvider('booleanToDb')]
    public function testBooleanToDb(mixed $input, mixed $expected): void
    {
        self::assertSame($expected, (new BooleanField())->toDB($input));
    }

    public function testBooleanFromDb(): void
    {
        $field = new BooleanField();
        self::assertTrue($field->fromDB(true));
        self::assertFalse($field->fromDB(false));
        self::assertTrue($field->fromDB('1'));
        self::assertFalse($field->fromDB('0'));
        self::assertTrue($field->fromDB(1));
        self::assertFalse($field->fromDB(0));
        self::assertNull($field->fromDB('true'));
        self::assertNull($field->fromDB(null));
    }

    public function testDatetime(): void
    {
        $field = new DatetimeField();
        $result = $field->toDB('2024-06-15T10:00:00.000Z');
        self::assertInstanceOf(\DateTimeImmutable::class, $result);
        self::assertSame('2024-06-15T10:00:00.000Z', Parsers::toIsoString($result));

        self::assertSame('2024-06-15T10:00:00.000Z', $field->fromDB('2024-06-15T10:00:00.000Z'));
        self::assertSame('2024-06-15T10:00:00.000Z', $field->fromDB(new \DateTimeImmutable('2024-06-15T10:00:00.000Z')));
        self::assertSame('2024-06-15T10:00:00.000Z', $field->fromDB(1718445600000), 'sqlite epoch millis');
        self::assertSame('2024-06-15T10:00:00.000Z', $field->fromDB('2024-06-15 10:00:00.000000'), 'mysql/pg string is UTC');
        self::assertNull($field->fromDB('invalid'));
    }

    public function testTimestamp(): void
    {
        $field = new TimestampField();
        self::assertInstanceOf(\DateTimeImmutable::class, $field->toDB('2024-06-15T10:00:00.000Z'));
        self::assertSame('1718445600000', $field->fromDB('2024-06-15T10:00:00.000Z'));
        self::assertNull($field->fromDB('not-a-date'));
    }

    public function testJson(): void
    {
        $field = new JsonField();
        self::assertNull($field->toDB(null));
        self::assertSame('{"foo":"bar"}', $field->toDB(['foo' => 'bar']));
        self::assertSame('[1,2]', $field->toDB([1, 2]));
        self::assertSame('already-string', $field->toDB('already-string'));
        self::assertSame(42, $field->toDB(42));

        self::assertSame(['a' => 1], $field->fromDB('{"a":1}'));
        self::assertSame([1, 2], $field->fromDB('[1,2]'));
        self::assertSame(['legacy' => true], $field->fromDB(json_encode(json_encode(['legacy' => true]))));
        self::assertSame('{not json', $field->fromDB('{not json'));
        self::assertSame(['already' => 'object'], $field->fromDB(['already' => 'object']));
        self::assertNull($field->fromDB(null));
    }

    /** PHP port: `{}` stays apart from `[]` both ways (Strapi\Utils\EmptyObject). */
    public function testJsonEmptyObjects(): void
    {
        $field = new JsonField();
        self::assertSame('{}', $field->toDB(new EmptyObject()));
        self::assertSame('{"a":{},"b":[]}', $field->toDB(['a' => new EmptyObject(), 'b' => []]));

        self::assertSame([], $field->fromDB('{}'), 'fromDB() reads `{}` as json_decode($json, true) does');
        self::assertInstanceOf(EmptyObject::class, $field->fromDBKeepingEmptyObjects('{}'));
        self::assertSame([], $field->fromDBKeepingEmptyObjects('[]'));
        self::assertSame('{"a":{},"b":[]}', json_encode($field->fromDBKeepingEmptyObjects('{"a":{},"b":[]}')));
        self::assertInstanceOf(EmptyObject::class, $field->fromDBKeepingEmptyObjects(json_encode('{}')), 'legacy double-encoded value');
    }

    public function testFromRowKeepsEmptyObjectsForContentModelsOnly(): void
    {
        $meta = static fn (string $uid): array => [
            'uid' => $uid, 'singularName' => 'x', 'tableName' => 'x', 'attributes' => ['data' => ['type' => 'json']],
            'indexes' => [], 'foreignKeys' => [], 'lifecycles' => [], 'columnToAttribute' => ['data' => 'data'],
        ];

        self::assertInstanceOf(EmptyObject::class, Transform::fromSingleRow($meta('api::article.article'), ['data' => '{}'])['data'] ?? null);
        self::assertInstanceOf(EmptyObject::class, Transform::fromSingleRow($meta('default.dish'), ['data' => '{}'])['data'] ?? null);
        self::assertSame(['data' => []], Transform::fromSingleRow($meta('admin::permission'), ['data' => '{}']));
        self::assertSame(['data' => []], Transform::fromSingleRow($meta('plugin::upload.file'), ['data' => '{}']));
    }

    public function testString(): void
    {
        $field = new StringField();
        self::assertSame('hello', $field->toDB('hello'));
        self::assertSame('123', $field->toDB(123));
        self::assertSame('true', $field->toDB(true));
        self::assertSame('', $field->toDB(null));
        self::assertSame('42', $field->fromDB(42));
        self::assertSame('', $field->fromDB(null));
    }

    public function testBigInteger(): void
    {
        $field = new BigIntegerField();
        self::assertSame('9007199254740993', $field->toDB('9007199254740993'));
        self::assertSame('123', $field->toDB(123));
        self::assertSame('-5', $field->toDB(' -0005 '));
        self::assertSame('9007199254740993', $field->fromDB(9007199254740993));
        self::assertSame('abc', $field->fromDB('abc'), 'legacy malformed values are kept');
        self::assertNull($field->toDB(null));
        $this->expectException(\InvalidArgumentException::class);
        $field->toDB('12.5');
    }

    public function testNumber(): void
    {
        $field = new NumberField();
        self::assertSame(42, $field->toDB('42'));
        self::assertSame(1.5, $field->toDB(' 1.5 '));
        self::assertSame(3, $field->toDB(3));
        self::assertSame(2, $field->fromDB('2'));
        self::assertSame(2.5, $field->fromDB('2.5'));
        self::assertSame(0, $field->fromDB(null));
        self::assertNan($field->fromDB('abc'));
        $this->expectException(\InvalidArgumentException::class);
        $field->toDB('');
    }

    public function testParsers(): void
    {
        self::assertSame('2024-06-15', Parsers::parseDate(new \DateTimeImmutable('2024-06-15T12:00:00.000Z')));
        self::assertSame('2024-06-15', Parsers::parseDate('2024-06-15'));
        self::assertSame('2024-01-01', Parsers::parseDate('2024-01-01'));

        self::assertSame('14:30:05.040', Parsers::parseTime(new \DateTimeImmutable('1970-01-01T14:30:05.040Z')));
        self::assertSame('14:30:05.000', Parsers::parseTime('14:30:05'));
        self::assertSame('14:30:05.500', Parsers::parseTime('14:30:05.5'));
        self::assertSame('14:30:05.123', Parsers::parseTime('14:30:05.123'));
        self::assertSame('00:00:00.000', Parsers::parseTime('00:00:00'));
        self::assertSame('23:59:59.999', Parsers::parseTime('23:59:59.999'));

        $date = new \DateTimeImmutable('2024-06-15T10:00:00.000Z');
        self::assertSame($date, Parsers::parseDateTimeOrTimestamp($date));
        self::assertSame('2024-06-15T10:00:00.000Z', Parsers::toIsoString(Parsers::parseDateTimeOrTimestamp('2024-06-15T10:00:00.000Z')));
        self::assertSame('2024-06-15T10:00:00.000Z', Parsers::toIsoString(Parsers::parseDateTimeOrTimestamp('1718445600000')));
    }

    /** @return iterable<array{callable, class-string<\Throwable>}> */
    public static function invalidParserInputs(): iterable
    {
        yield [static fn () => Parsers::parseDate('not-a-date'), InvalidDateError::class];
        yield [static fn () => Parsers::parseDate(''), InvalidDateError::class];
        yield [static fn () => Parsers::parseDate(123), InvalidDateError::class];
        yield [static fn () => Parsers::parseTime('25:00:00'), InvalidTimeError::class];
        yield [static fn () => Parsers::parseTime('12:60:00'), InvalidTimeError::class];
        yield [static fn () => Parsers::parseTime(123), InvalidTimeError::class];
        yield [static fn () => Parsers::parseTime('12:31:11x2'), InvalidTimeError::class];
        yield [static fn () => Parsers::parseTime('12:31:11,2'), InvalidTimeError::class];
        yield [static fn () => Parsers::parseTime('12:31:11 2'), InvalidTimeError::class];
        yield [static fn () => Parsers::parseDateTimeOrTimestamp('not-a-datetime'), InvalidDateTimeError::class];
    }

    #[DataProvider('invalidParserInputs')]
    public function testParsersThrow(callable $fn, string $exception): void
    {
        $this->expectException($exception);
        $fn();
    }

    public function testCreateFieldCachesAndRejectsUnknown(): void
    {
        self::assertSame(Fields::createField(['type' => 'string']), Fields::createField(['type' => 'string']));
        self::assertInstanceOf(\Strapi\Database\Fields\StringField::class, Fields::createField(['type' => 'email']));
        self::assertInstanceOf(JsonField::class, Fields::createField(['type' => 'blocks']));
        $this->expectExceptionMessage('Undefined field for type nope');
        Fields::createField(['type' => 'nope']);
    }
}
