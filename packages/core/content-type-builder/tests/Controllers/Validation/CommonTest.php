<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests\Controllers\Validation;

use PHPUnit\Framework\TestCase;
use Strapi\ContentTypeBuilder\Controllers\Validation\Common;
use Strapi\Utils\Yup;
use Strapi\Utils\Yup\YupError;

/** Port of server/src/controllers/validation/__tests__/common.test.ts. */
final class CommonTest extends TestCase
{
    /** @param array<string, mixed> $test */
    private static function throws(array $test, string $value): bool
    {
        try {
            Yup::string()->test($test)->validateSync($value);

            return false;
        } catch (YupError) {
            return true;
        }
    }

    public function testValidatesNames(): void
    {
        self::assertTrue(self::throws(Common::isValidName(), '89121'));
        self::assertTrue(self::throws(Common::isValidName(), '_zada'));
        self::assertTrue(self::throws(Common::isValidName(), 'AZopd azd a*$'));
        self::assertTrue(self::throws(Common::isValidName(), 'azda-azdazd'));
        self::assertFalse(self::throws(Common::isValidName(), ''));

        self::assertFalse(self::throws(Common::isValidName(), 'SomeValidName'));
        self::assertFalse(self::throws(Common::isValidName(), 'Some_azdazd_azdazd'));
        self::assertFalse(self::throws(Common::isValidName(), 'Som122e_azdazd_azdazd'));
    }

    public function testValidatesCategoryNames(): void
    {
        self::assertTrue(self::throws(Common::isValidCategoryName(), '123test'));
        self::assertTrue(self::throws(Common::isValidCategoryName(), 'my category'));
        self::assertTrue(self::throws(Common::isValidCategoryName(), '_category'));
        self::assertTrue(self::throws(Common::isValidCategoryName(), 'category!'));
        self::assertFalse(self::throws(Common::isValidCategoryName(), ''));

        self::assertFalse(self::throws(Common::isValidCategoryName(), 'default'));
        self::assertFalse(self::throws(Common::isValidCategoryName(), 'question-items'));
        self::assertFalse(self::throws(Common::isValidCategoryName(), 'my_category'));
        self::assertFalse(self::throws(Common::isValidCategoryName(), 'myCategory123'));
    }

    public function testReturnsAHumanReadableErrorMessage(): void
    {
        $this->expectException(YupError::class);
        $this->expectExceptionMessage('must start with a letter and only contain letters, numbers, dashes and underscores');

        Yup::string()->test(Common::isValidCategoryName())->validateSync('123test');
    }
}
