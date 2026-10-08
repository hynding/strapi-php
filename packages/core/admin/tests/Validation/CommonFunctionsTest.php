<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Validation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Strapi\Admin\Validation\CommonFunctions\CheckFieldsAreCorrectlyNested;
use Strapi\Admin\Validation\CommonFunctions\CheckFieldsDontHaveDuplicates;
use Strapi\Utils\Yup\Undefined;

/** Port of server/src/validation/__tests__/common-functions.test.ts. */
final class CommonFunctionsTest extends TestCase
{
    /** @return list<array{mixed, bool}> */
    public static function nestedCases(): array
    {
        return [
            [['name'], true],
            [['name', 'description'], true],
            [['name.firstname'], true],
            [['name.firstname', 'name.lastname'], true],
            [['name.firstname.french'], true],
            [['name.firstname.french', 'firstname'], true],
            [['name.firstname.french', 'french'], true],
            [['name.firstname.french', 'firstname.french'], true],
            [['name', 'name.firstname'], false],
            [['name', 'name.firstname.french'], false],
            [['name.firstname', 'name.firstname.french'], false],
            [['address', 'addresses'], true],
            [[], true],
            [Undefined::value(), true],
            [null, true],
            ['', false],
            [3, false],
        ];
    }

    #[DataProvider('nestedCases')]
    public function testCheckFieldsAreCorrectlyNested(mixed $fields, bool $expected): void
    {
        self::assertSame($expected, CheckFieldsAreCorrectlyNested::checkFieldsAreCorrectlyNested($fields));
    }

    /** @return list<array{mixed, bool}> */
    public static function duplicatesCases(): array
    {
        return [
            [['name'], true],
            [['name', 'description'], true],
            [['name', 'description', 'name'], false],
            [['name.firstname', 'name.lastname'], true],
            [[], true],
            [Undefined::value(), true],
            [null, true],
            ['', false],
            [3, false],
        ];
    }

    #[DataProvider('duplicatesCases')]
    public function testCheckFieldsDontHaveDuplicates(mixed $fields, bool $expected): void
    {
        self::assertSame($expected, CheckFieldsDontHaveDuplicates::checkFieldsDontHaveDuplicates($fields));
    }
}
