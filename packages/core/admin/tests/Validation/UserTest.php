<?php

declare(strict_types=1);

namespace Strapi\Admin\Tests\Validation;

use PHPUnit\Framework\TestCase;
use Strapi\Admin\Validation\User;
use Strapi\Utils\Errors\YupValidationError;

/** Port of server/src/validation/__tests__/user.test.ts (strict mode: no silent normalization). */
final class UserTest extends TestCase
{
    public function testProfileUpdateRejectsAnUppercaseEmailRatherThanLowercasingIt(): void
    {
        $this->expectException(YupValidationError::class);
        User::validateProfileUpdateInput(['email' => 'Kai@Doe.com']);
    }

    public function testProfileUpdateRejectsAnUntrimmedFirstnameRatherThanTrimmingIt(): void
    {
        $this->expectException(YupValidationError::class);
        User::validateProfileUpdateInput(['firstname' => '  Kai  ']);
    }

    public function testProfileUpdateReturnsAlreadyNormalizedInputUnchanged(): void
    {
        $input = ['email' => 'kai@doe.com', 'firstname' => 'Kai'];

        self::assertSame($input, User::validateProfileUpdateInput($input));
    }

    public function testProfileUpdateRequiresTheCurrentPasswordWithANewPassword(): void
    {
        try {
            User::validateProfileUpdateInput(['password' => 'Password123']);
            self::fail('expected a YupValidationError');
        } catch (YupValidationError $e) {
            self::assertSame('currentPassword is a required field', $e->getMessage());
        }
    }

    public function testUserUpdateRejectsAnUppercaseEmailRatherThanLowercasingIt(): void
    {
        $this->expectException(YupValidationError::class);
        User::validateUserUpdateInput(['email' => 'Kai@Doe.com']);
    }

    public function testUsersDeleteRequiresIds(): void
    {
        self::assertSame(['ids' => [1, '2']], User::validateUsersDeleteInput(['ids' => [1, '2']]));

        $this->expectException(YupValidationError::class);
        User::validateUsersDeleteInput(['ids' => []]);
    }
}
