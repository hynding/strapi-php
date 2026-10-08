<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Utils\Errors\YupValidationError;
use Strapi\Utils\FormatYupError;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupError;

/** Port of packages/core/utils/src/__tests__/yup-formatter.test.ts. */
final class YupFormatterTest extends TestCase
{
    private static function catchYupError(\Closure $fn): YupError
    {
        try {
            $fn();
        } catch (YupError $e) {
            return $e;
        }
        self::fail('expected a YupError');
    }

    public function testErrorMessageIsSanitized(): void
    {
        $schema = Yup::object()->shape([
            'name' => Yup::string()->required(),
        ]);

        $e = self::catchYupError(static fn () => $schema->validateSync(['name' => null]));
        $formattedError = new YupValidationError($e);

        self::assertSame('name must be a `string` type, but the final value was: `null`.', $formattedError->getMessage());
    }

    public function testFormatSingleErrors(): void
    {
        $e = self::catchYupError(static fn () => Yup::object([
            'name' => Yup::string()->required('name is required'),
        ])->validate([]));

        $formatted = FormatYupError::formatYupErrors($e);

        self::assertSame('name is required', $formatted['message']);
        self::assertCount(1, $formatted['errors']);
        self::assertEquals(['message' => 'name is required', 'name' => 'ValidationError', 'path' => ['name']], array_intersect_key($formatted['errors'][0], ['message' => 1, 'name' => 1, 'path' => 1]));
    }

    public function testFormatMultipleErrors(): void
    {
        $e = self::catchYupError(static fn () => Yup::object([
            'name' => Yup::string()->min(2, 'min length is 2')->required(),
        ])->validate(['name' => '1'], ['strict' => true, 'abortEarly' => false]));

        $formatted = FormatYupError::formatYupErrors($e);

        self::assertSame('min length is 2', $formatted['message']);
        self::assertSame([
            ['path' => ['name'], 'message' => 'min length is 2', 'name' => 'ValidationError', 'value' => '1'],
        ], $formatted['errors']);
    }

    public function testFormatMultipleErrorsOnMultipleKeys(): void
    {
        $e = self::catchYupError(static fn () => Yup::object([
            'name' => Yup::string()->min(2, 'min length is 2')->typeError('name must be a string')->required(),
            'price' => Yup::number()->integer()->required('price is required'),
        ])->validate(['name' => 12], ['strict' => true, 'abortEarly' => false]));

        $formatted = FormatYupError::formatYupErrors($e);

        self::assertSame('2 errors occurred', $formatted['message']);
        self::assertSame([
            ['path' => ['name'], 'message' => 'name must be a string', 'name' => 'ValidationError', 'value' => 12],
            ['path' => ['price'], 'message' => 'price is required', 'name' => 'ValidationError', 'value' => null],
        ], $formatted['errors']);
    }

    public function testYupValidationErrorDetails(): void
    {
        $e = self::catchYupError(static fn () => Yup::array()->of(Yup::object(['id' => Yup::strapiID()->required()]))->validate([['id' => 1], []], ['abortEarly' => false]));
        $error = new YupValidationError($e, 'Invalid ids');

        self::assertSame('Invalid ids', $error->getMessage());
        self::assertSame(400, $error->status);
        self::assertSame([['path' => ['1', 'id'], 'message' => '[1].id is a required field', 'name' => 'ValidationError', 'value' => null]], $error->errors());
    }
}
