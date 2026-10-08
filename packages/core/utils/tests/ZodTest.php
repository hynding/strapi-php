<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Tests\Zod\ZodTestStatus;
use Strapi\Utils\Zod;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\Undefined;
use Strapi\Utils\Zod\Z as ZodFactories;
use Strapi\Utils\Zod\ZodArray;
use Strapi\Utils\Zod\ZodDefault;
use Strapi\Utils\Zod\ZodError;
use Strapi\Utils\Zod\ZodNumber;
use Strapi\Utils\Zod\ZodObject;
use Strapi\Utils\Zod\ZodOptional;
use Strapi\Utils\Zod\ZodString;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of packages/core/utils/src/__tests__/zod.test.ts, plus validateZodSchema(),
 * getZodValidationErrors() and the PHP-specific parts of the zod subset. The issue-level
 * behaviour of every factory and method is pinned against real zod output in
 * Zod/ZodOracleTest.php.
 */
final class ZodTest extends TestCase
{
    // --- zod.test.ts ---------------------------------------------------------------------------

    public function testUsesTheSameZodInstanceAsDirectZodImports(): void
    {
        $schema = Zod::object(['name' => Zod::string()]);

        self::assertInstanceOf(ZodObject::class, $schema);
        self::assertTrue(is_subclass_of(Zod::class, ZodFactories::class));
        self::assertInstanceOf(ZodError::class, Zod::string()->safeParse(1)['error']);
        self::assertSame(['name' => 'Article'], Zod::validateZodSchema($schema)(['name' => 'Article']));
    }

    // --- validateZodSchema ---------------------------------------------------------------------

    public function testValidateZodSchemaReturnsParsedData(): void
    {
        $validate = Zod::validateZodSchema(z::object(['page' => z::coerce()->number()->int()->min(1)->optional()]));

        self::assertSame(['page' => 2], $validate(['page' => '2', 'extra' => true]));
        self::assertSame([], $validate([]));
    }

    public function testValidateZodSchemaThrowsValidationErrorWithEveryIssue(): void
    {
        $validate = Zod::validateZodSchema(z::object([
            'name' => z::string(),
            'age' => z::number()->min(18),
            'tags' => z::array(z::string()),
        ]));

        try {
            $validate(['age' => 3, 'tags' => ['a', 1]]);
            self::fail('expected a ValidationError');
        } catch (ValidationError $error) {
            self::assertSame('ValidationError', $error->name);
            self::assertSame(400, $error->status);
            self::assertSame('Invalid input: expected string, received undefined', $error->getMessage());
            self::assertSame(['errors' => [
                ['path' => ['name'], 'message' => 'Invalid input: expected string, received undefined', 'name' => 'ValidationError'],
                ['path' => ['age'], 'message' => 'Too small: expected number to be >=18', 'name' => 'ValidationError'],
                ['path' => ['tags', '1'], 'message' => 'Invalid input: expected string, received number', 'name' => 'ValidationError'],
            ]], $error->details);
        }
    }

    public function testValidateZodSchemaUsesTheGivenErrorMessage(): void
    {
        try {
            Zod::validateZodSchema(z::string())(1, 'Invalid body');
            self::fail('expected a ValidationError');
        } catch (ValidationError $error) {
            self::assertSame('Invalid body', $error->getMessage());
            self::assertSame([['path' => [], 'message' => 'Invalid input: expected string, received number', 'name' => 'ValidationError']], $error->details['errors']);
        }
    }

    public function testValidateZodSchemaLetsOtherExceptionsThrough(): void
    {
        $this->expectException(\DomainException::class);

        Zod::validateZodSchema(z::string()->transform(static fn () => throw new \DomainException('boom')))('a');
    }

    // --- getZodValidationErrors ----------------------------------------------------------------

    public function testGetZodValidationErrorsKeepsTheFirstMessagePerDotPath(): void
    {
        $schema = z::object([
            'title' => z::string()->min(3)->regex('/^[a-z]+$/'),
            'blocks' => z::array(z::object(['type' => z::string()])),
        ])->refine(static fn (): bool => false, 'Root problem');

        $error = $schema->safeParse(['title' => 'A1', 'blocks' => [['type' => 1]]])['error'];
        self::assertInstanceOf(ZodError::class, $error);

        self::assertSame([
            'title' => 'Too small: expected string to have >=3 characters',
            'blocks.0.type' => 'Invalid input: expected string, received number',
        ], Zod::getZodValidationErrors($error));

        $rootError = $schema->safeParse(['title' => 'abc', 'blocks' => []])['error'];
        self::assertInstanceOf(ZodError::class, $rootError);
        self::assertSame(['' => 'Root problem'], Zod::getZodValidationErrors($rootError));
    }

    // --- parse / safeParse / ZodError ----------------------------------------------------------

    public function testParseThrowsZodErrorWithJsonMessage(): void
    {
        try {
            z::object(['a' => z::string()])->parse(['a' => 1]);
            self::fail('expected a ZodError');
        } catch (ZodError $error) {
            self::assertSame('ZodError', $error->name);
            self::assertSame([['expected' => 'string', 'code' => 'invalid_type', 'path' => ['a'], 'message' => 'Invalid input: expected string, received number']], $error->issues);
            self::assertSame($error->issues, json_decode($error->getMessage(), true));
            self::assertSame(['formErrors' => [], 'fieldErrors' => ['a' => ['Invalid input: expected string, received number']]], $error->flatten());
        }
    }

    public function testSafeParseShape(): void
    {
        self::assertSame(['success' => true, 'data' => 'a', 'error' => null], z::string()->safeParse('a'));

        $failure = z::string()->safeParse(1);
        self::assertFalse($failure['success']);
        self::assertNull($failure['data']);
        self::assertInstanceOf(ZodError::class, $failure['error']);
    }

    public function testUndefinedIsTheDefaultInputAndNeverAnOutput(): void
    {
        self::assertNull(z::string()->optional()->parse());
        self::assertSame('d', z::string()->default('d')->parse());
        self::assertFalse(z::string()->safeParse()['success']);
        self::assertSame([null], z::array(z::string()->optional())->parse([Undefined::Value]));
        self::assertSame([], z::object(['a' => z::string()->optional()])->parse(['a' => Undefined::Value]));
        self::assertSame(Undefined::Value, ZodFactories::NEVER);
    }

    public function testCallbacksSeeUndefinedForMissingValues(): void
    {
        $seen = [];
        $schema = z::object([
            'a' => z::preprocess(static function (mixed $v) use (&$seen): mixed {
                $seen[] = $v;

                return $v;
            }, z::string()->optional()),
        ]);

        self::assertSame([], $schema->parse([]));
        self::assertSame([Undefined::Value], $seen);
    }

    public function testObjectsAcceptStdClassAndEmptyArrays(): void
    {
        $schema = z::object(['a' => z::string()->optional()]);

        self::assertSame(['a' => 'x'], $schema->parse((object) ['a' => 'x', 'b' => 1]));
        self::assertSame([], $schema->parse([]));
        self::assertSame([], $schema->parse(new \stdClass()));
        self::assertFalse($schema->safeParse([1, 2])['success']);
        self::assertSame([], z::array(z::string())->parse([]));
        self::assertFalse(z::array(z::string())->safeParse(['a' => 'x'])['success']);
    }

    // --- schema API ----------------------------------------------------------------------------

    public function testSchemasAreImmutable(): void
    {
        $base = z::string();
        $min = $base->min(3);

        self::assertNotSame($base, $min);
        self::assertTrue($base->safeParse('a')['success']);
        self::assertFalse($min->safeParse('a')['success']);
    }

    public function testDescribeStoresADescriptionWithoutChangingValidation(): void
    {
        $schema = z::string()->min(1);
        $described = $schema->describe('A title');

        self::assertNull($schema->description());
        self::assertSame('A title', $described->description());
        self::assertInstanceOf(ZodString::class, $described);
        self::assertSame($schema->safeParse('')['error']?->issues, $described->safeParse('')['error']?->issues);
    }

    public function testDefExposesStructureForInspection(): void
    {
        $element = z::string();
        $shape = ['a' => z::number()->optional(), 'b' => z::array($element)->default([])];
        $schema = z::object($shape);

        self::assertSame('object', $schema->def()['type']);
        self::assertSame($shape, $schema->def()['shape']);
        self::assertSame($shape, $schema->shape());

        $optional = $shape['a'];
        self::assertInstanceOf(ZodOptional::class, $optional);
        self::assertSame('optional', $optional->def()['type']);
        self::assertInstanceOf(ZodNumber::class, $optional->def()['innerType']);

        $default = $shape['b'];
        self::assertInstanceOf(ZodDefault::class, $default);
        self::assertSame('default', $default->type());
        $array = $default->unwrap();
        self::assertInstanceOf(ZodArray::class, $array);
        self::assertSame($element, $array->def()['element']);
    }

    public function testIsOptionalAndIsNullable(): void
    {
        self::assertTrue(z::string()->optional()->isOptional());
        self::assertFalse(z::string()->isOptional());
        self::assertTrue(z::string()->nullish()->isNullable());
        self::assertFalse(z::string()->optional()->isNullable());
    }

    public function testEnumAccessors(): void
    {
        $enum = z::enum(['draft', 'published']);

        self::assertSame(['draft', 'published'], $enum->options());
        self::assertSame(['draft' => 'draft', 'published' => 'published'], $enum->enum());
        self::assertSame(['a', 'b'], z::object(['a' => z::string(), 'b' => z::string()])->keyof()->options());
        self::assertTrue(z::enum(['A' => 'a'])->safeParse('a')['success']);
    }

    public function testEnumFromBackedEnum(): void
    {
        $schema = z::enum(ZodTestStatus::class);

        self::assertSame('draft', $schema->parse('draft'));
        self::assertSame(
            'Invalid option: expected one of "draft"|"published"',
            $schema->safeParse('x')['error']?->issues[0]['message'],
        );
    }

    public function testExtendRejectsOverwritingKeysOfRefinedObjects(): void
    {
        $this->expectException(\LogicException::class);

        z::object(['a' => z::string()])->refine(static fn (): bool => true)->extend(['a' => z::number()]);
    }

    public function testDiscriminatedUnionRequiresLiteralDiscriminators(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Invalid discriminated union option at index "1"');

        z::discriminatedUnion('type', [z::object(['type' => z::literal('a')]), z::object(['type' => z::string()])])->parse(['type' => 'x']);
    }

    public function testIssueCodesAndCtxIssueAdder(): void
    {
        $schema = z::string()->superRefine(static function (string $value, Zod\ParsePayload $ctx): void {
            self::assertSame('x', $ctx->value);
            $ctx->addIssue(['code' => Zod\ZodIssueCode::custom, 'message' => 'nope', 'params' => ['reason' => 'test']]);
        });

        self::assertSame(
            [['code' => 'custom', 'message' => 'nope', 'params' => ['reason' => 'test'], 'path' => []]],
            $schema->safeParse('x')['error']?->issues,
        );
    }

    public function testRegistry(): void
    {
        $registry = z::registry();
        $schema = z::string();
        $registry->add($schema, ['id' => 'Title']);

        self::assertTrue($registry->has($schema));
        self::assertSame(['id' => 'Title'], $registry->get($schema));
        $registry->remove($schema);
        self::assertFalse($registry->has($schema));
        self::assertNull($registry->get($schema));
    }

    public function testToJsonSchemaWritesEmptySchemasAsObjects(): void
    {
        self::assertSame(
            '{"$schema":"https://json-schema.org/draft/2020-12/schema","type":"object","properties":{"a":{}},"required":["a"],"additionalProperties":false}',
            json_encode(z::object(['a' => z::unknown()])->toJSONSchema(), JSON_UNESCAPED_SLASHES),
        );
    }

    public function testToJsonSchemaThrowsOnUnrepresentableTypes(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Transforms cannot be represented in JSON Schema');

        z::toJSONSchema(z::string()->transform(static fn (string $v): int => strlen($v)));
    }

    public function testUtf16Lengths(): void
    {
        self::assertTrue(z::string()->max(2)->safeParse('éé')['success']);
        self::assertFalse(z::string()->max(3)->safeParse('😀😀')['success']);
    }

    public function testSchemaTypesAreZodTypes(): void
    {
        foreach ([z::string(), z::number(), z::boolean(), z::null(), z::any(), z::unknown(), z::never(), z::literal(1), z::enum(['a']), z::object(), z::array(z::any()), z::union([z::any()]), z::record(z::any()), z::lazy(static fn (): ZodType => z::any()), z::custom(), z::preprocess(static fn (mixed $v): mixed => $v, z::any()), z::uuid(), z::email(), z::looseObject()] as $schema) {
            self::assertInstanceOf(ZodType::class, $schema);
        }
    }
}
