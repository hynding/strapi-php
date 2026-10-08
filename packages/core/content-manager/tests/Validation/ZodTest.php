<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Tests\Validation;

use PHPUnit\Framework\TestCase;
use Strapi\ContentManager\Validation\Zod;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodError;

/** Port of server/src/validation/__tests__/zod.test.ts. */
final class ZodTest extends TestCase
{
    private static function error(mixed $result): ZodError
    {
        self::assertFalse($result['success']);
        self::assertInstanceOf(ZodError::class, $result['error']);

        return $result['error'];
    }

    public function testFormatsASingleFieldError(): void
    {
        $error = self::error(z::object(['name' => z::string()])->safeParse(['name' => 123]));
        $formatted = Zod::formatZodErrors($error);

        self::assertCount(1, $formatted['errors']);
        self::assertSame(['name'], $formatted['errors'][0]['path']);
        self::assertSame('ValidationError', $formatted['errors'][0]['name']);
        self::assertIsString($formatted['errors'][0]['message']);
        self::assertSame($error->issues[0]['message'], $formatted['message']);
    }

    public function testFormatsMultipleFieldErrors(): void
    {
        $error = self::error(z::object(['name' => z::string(), 'price' => z::number()->int()])->safeParse(['name' => 123, 'price' => 'abc']));
        $formatted = Zod::formatZodErrors($error);

        self::assertCount(2, $formatted['errors']);
        self::assertSame(['name'], $formatted['errors'][0]['path']);
        self::assertSame(['price'], $formatted['errors'][1]['path']);
        self::assertSame($error->issues[0]['message'], $formatted['message']);
    }

    public function testFormatsNestedPathErrors(): void
    {
        $error = self::error(z::object(['address' => z::object(['street' => z::string()])])->safeParse(['address' => ['street' => 42]]));

        self::assertSame(['address', 'street'], Zod::formatZodErrors($error)['errors'][0]['path']);
    }

    public function testDeduplicatesErrorsPerPathKeepsFirstOnly(): void
    {
        $schema = z::object([
            'name' => z::string()->superRefine(static function (mixed $val, mixed $ctx): void {
                $ctx->addIssue(['code' => 'custom', 'message' => 'error one']);
                $ctx->addIssue(['code' => 'custom', 'message' => 'error two']);
            }),
        ]);

        $formatted = Zod::formatZodErrors(self::error($schema->safeParse(['name' => 'test'])));
        $namePaths = array_values(array_filter($formatted['errors'], static fn (array $e): bool => $e['path'] === ['name']));

        self::assertCount(1, $namePaths);
        self::assertSame('error one', $namePaths[0]['message']);
    }

    public function testStrapiIdAcceptsAString(): void
    {
        self::assertTrue(Zod::strapiID()->safeParse('abc')['success']);
        self::assertTrue(Zod::strapiID()->safeParse('123')['success']);
        self::assertTrue(Zod::strapiID()->safeParse('')['success']);
    }

    public function testStrapiIdAcceptsANonNegativeInteger(): void
    {
        foreach ([0, 1, 999] as $value) {
            self::assertTrue(Zod::strapiID()->safeParse($value)['success']);
        }
    }

    public function testStrapiIdRejectsNegativeNumbersAndFloats(): void
    {
        self::assertFalse(Zod::strapiID()->safeParse(-1)['success']);
        self::assertFalse(Zod::strapiID()->safeParse(1.5)['success']);
    }

    public function testStrapiIdRejectsNonStringNonNumberTypes(): void
    {
        self::assertFalse(Zod::strapiID()->safeParse(null)['success']);
        self::assertFalse(Zod::strapiID()->safeParse()['success']);
        self::assertFalse(Zod::strapiID()->safeParse(true)['success']);
        self::assertFalse(Zod::strapiID()->safeParse(['a' => 1])['success']);
    }

    public function testValidateZodAsyncReturnsValidatedDataOnSuccess(): void
    {
        $validate = Zod::validateZodAsync(z::object(['name' => z::string(), 'age' => z::number()]));

        self::assertSame(['name' => 'John', 'age' => 30], $validate(['name' => 'John', 'age' => 30]));
    }

    public function testValidateZodAsyncThrowsValidationErrorWithFormattedErrorsInDetails(): void
    {
        $validate = Zod::validateZodAsync(z::object(['name' => z::string(), 'age' => z::number()]));

        try {
            $validate(['name' => 123]);
            self::fail('should have thrown');
        } catch (ValidationError $e) {
            self::assertSame('ValidationError', $e->name);
            $paths = array_map(static fn (array $error): array => $error['path'], $e->details['errors']);
            self::assertContains(['name'], $paths);
        }
    }

    public function testValidateZodAsyncUsesCustomErrorMessageWhenProvided(): void
    {
        $validate = Zod::validateZodAsync(z::object(['name' => z::string()]));

        $this->expectException(ValidationError::class);
        $this->expectExceptionMessage('Custom validation failed');
        $validate(['name' => 123], 'Custom validation failed');
    }

    public function testValidateZodAsyncReThrowsNonZodErrorExceptions(): void
    {
        $validate = Zod::validateZodAsync(z::string()->transform(static fn (): never => throw new \RuntimeException('unexpected')));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unexpected');
        $validate('test');
    }
}
