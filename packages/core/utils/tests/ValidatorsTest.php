<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Utils\Errors\YupValidationError;
use Strapi\Utils\Validators;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\Undefined;
use Strapi\Utils\Yup\YupError;

/** Port of packages/core/utils/src/__tests__/validators.test.ts, plus validators.ts itself. */
final class ValidatorsTest extends TestCase
{
    /** @return list<array{mixed, bool}> */
    public static function strapiIdCases(): array
    {
        return [
            [0, true],
            ['0', true],
            [1, true],
            ['1', true],
            [Undefined::value(), true], // because it's not required
            [['a' => 1], false], // {}
            [[], false],
            [null, false],
        ];
    }

    #[DataProvider('strapiIdCases')]
    public function testStrapiId(mixed $value, bool $expected): void
    {
        self::assertSame($expected, self::passes(static fn () => Yup::strapiID()->validate($value)));
    }

    /** @return list<array{mixed, bool}> */
    public static function strapiIdRequiredCases(): array
    {
        return [
            [0, true],
            ['0', true],
            [1, true],
            ['1', true],
            [Undefined::value(), false],
            [['a' => 1], false],
            [[], false],
            [null, false],
        ];
    }

    #[DataProvider('strapiIdRequiredCases')]
    public function testStrapiIdRequired(mixed $value, bool $expected): void
    {
        self::assertSame($expected, self::passes(static fn () => Yup::strapiID()->required()->validate($value)));
    }

    /** @return list<array{mixed, bool}> */
    public static function strapiIdNullableCases(): array
    {
        return [
            [0, true],
            ['0', true],
            [1, true],
            ['1', true],
            [Undefined::value(), true],
            [['a' => 1], false],
            [[], false],
            [null, true],
        ];
    }

    #[DataProvider('strapiIdNullableCases')]
    public function testStrapiIdNullable(mixed $value, bool $expected): void
    {
        self::assertSame($expected, self::passes(static fn () => Yup::strapiID()->nullable()->validate($value)));
    }

    /** @return list<array{mixed, bool}> */
    public static function strapiIdNullableDefinedCases(): array
    {
        return [
            [0, true],
            ['0', true],
            [1, true],
            ['1', true],
            [Undefined::value(), false],
            [['a' => 1], false],
            [[], false],
            [null, true],
        ];
    }

    #[DataProvider('strapiIdNullableDefinedCases')]
    public function testStrapiIdNullableDefined(mixed $value, bool $expected): void
    {
        self::assertSame($expected, self::passes(static fn () => Yup::strapiID()->nullable()->defined()->validate($value)));
    }

    private static function passes(\Closure $fn): bool
    {
        try {
            $fn();

            return true;
        } catch (YupError) {
            return false;
        }
    }

    // --- validators.ts ---------------------------------------------------------------------------

    public function testValidateYupSchemaReturnsTheValidatedBody(): void
    {
        $validate = Validators::validateYupSchema(Yup::object(['name' => Yup::string()->min(1)->required()])->noUnknown());

        self::assertSame(['name' => 'Editor'], $validate(['name' => 'Editor']));
    }

    public function testValidateYupSchemaIsStrictAndCollectsEveryErrorByDefault(): void
    {
        $validate = Validators::validateYupSchema(Yup::object([
            'name' => Yup::string()->required(),
            'pageSize' => Yup::number()->required(),
        ]));

        try {
            $validate(['pageSize' => '10']);
            self::fail('expected a YupValidationError');
        } catch (YupValidationError $e) {
            self::assertSame('2 errors occurred', $e->getMessage());
            self::assertSame([
                'errors' => [
                    ['path' => ['name'], 'message' => 'name is a required field', 'name' => 'ValidationError', 'value' => null],
                    ['path' => ['pageSize'], 'message' => 'pageSize must be a `number` type, but the final value was: `"10"`.', 'name' => 'ValidationError', 'value' => '10'],
                ],
            ], $e->details);
            self::assertInstanceOf(YupError::class, $e->getPrevious());
        }
    }

    public function testValidateYupSchemaOptionsOverrideTheDefaults(): void
    {
        $schema = Yup::object(['fileInfo' => Yup::object(['focalPoint' => Yup::object(['x' => Yup::number()->required()])->nullable()->default(null)])]);

        self::assertSame(['fileInfo' => ['focalPoint' => ['x' => 1]]], Validators::validateYupSchema($schema, ['strict' => false])(['fileInfo' => ['focalPoint' => ['x' => '1']]]));
        self::assertSame(['fileInfo' => ['focalPoint' => null]], Validators::validateYupSchema($schema, ['strict' => false])(['fileInfo' => []]));

        try {
            Validators::validateYupSchema(Yup::object(['a' => Yup::string()->required(), 'b' => Yup::string()->required()]), ['abortEarly' => true])([]);
            self::fail('expected a YupValidationError');
        } catch (YupValidationError $e) {
            self::assertCount(1, $e->errors());
        }
    }

    public function testValidateYupSchemaUsesTheGivenErrorMessage(): void
    {
        try {
            Validators::validateYupSchema(Yup::string()->required())(Undefined::value(), 'Invalid payload');
            self::fail('expected a YupValidationError');
        } catch (YupValidationError $e) {
            self::assertSame('Invalid payload', $e->getMessage());
            self::assertSame('this is a required field', $e->errors()[0]['message']);
            self::assertSame([], $e->errors()[0]['path']);
        }
    }

    public function testValidateYupSchemaSync(): void
    {
        $validate = Validators::validateYupSchemaSync(Yup::array()->of(Yup::strapiID())->min(1));

        self::assertSame([1, '2'], $validate([1, '2']));

        $this->expectException(YupValidationError::class);
        $this->expectExceptionMessage('this field must have at least 1 items');
        $validate([]);
    }

    public function testOtherExceptionsAreRethrown(): void
    {
        $validate = Validators::validateYupSchema(Yup::string()->test('boom', 'unused', static function (): bool {
            throw new \RuntimeException('database down');
        }));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('database down');
        $validate('x');
    }

    public function testHandleYupError(): void
    {
        $this->expectException(YupValidationError::class);
        $this->expectExceptionMessage('custom');
        Validators::handleYupError(new YupError('name is invalid', null, 'name'), 'custom');
    }

    /** A typical upstream admin validator (packages/core/admin/server/src/validation/user.ts). */
    public function testAdminUserCreationSchema(): void
    {
        $password = Yup::string()
            ->min(8)
            ->test('required-byte-size', '${path} must be less than 73 bytes', static fn (mixed $value): bool => !Yup\Yup::truthy($value) || strlen((string) $value) <= 72)
            ->matches('/[a-z]/', '${path} must contain at least one lowercase character')
            ->matches('/[A-Z]/', '${path} must contain at least one uppercase character')
            ->matches('/\d/', '${path} must contain at least one number');
        $schema = Yup::object([
            'email' => Yup::string()->email()->lowercase()->required(),
            'firstname' => Yup::string()->trim()->min(1)->required(),
            'lastname' => Yup::string(),
            'roles' => Yup::array(Yup::strapiID())->min(1),
            'password' => $password,
            'preferedLanguage' => Yup::string()->nullable(),
        ])->noUnknown();
        $validate = Validators::validateYupSchema($schema);

        self::assertSame(
            ['email' => 'kai@doe.com', 'firstname' => 'Kai', 'roles' => [1]],
            $validate(['email' => 'kai@doe.com', 'firstname' => 'Kai', 'roles' => [1]]),
        );

        try {
            $validate(['email' => 'Kai@Doe.com', 'firstname' => ' Kai', 'roles' => [], 'password' => 'short', 'isAdmin' => true]);
            self::fail('expected a YupValidationError');
        } catch (YupValidationError $e) {
            self::assertSame('7 errors occurred', $e->getMessage());
            self::assertSame([
                [['email'], 'email must be a lowercase string'],
                [['firstname'], 'firstname must be a trimmed string'],
                [['roles'], 'roles field must have at least 1 items'],
                [['password'], 'password must be at least 8 characters'],
                [['password'], 'password must contain at least one uppercase character'],
                [['password'], 'password must contain at least one number'],
                [[], 'this field has unspecified keys: isAdmin'],
            ], array_map(static fn (array $err): array => [$err['path'], $err['message']], $e->errors()));
        }
    }
}
