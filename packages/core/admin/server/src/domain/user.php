<?php

declare(strict_types=1);

namespace Strapi\Admin\Domain;

/** Port of server/src/domain/user.ts. */
final class User
{
    /** services/constants.ts `SUPER_ADMIN_CODE` */
    private const string SUPER_ADMIN_CODE = 'strapi-super-admin';

    public const array ADMIN_USER_ALLOWED_FIELDS = [
        'id',
        'firstname',
        'lastname',
        'username',
        'email',
        'isActive',
    ];

    /**
     * Create a new user model by merging default and specified attributes.
     *
     * @param array<string, mixed> $attributes A partial user object
     * @return array<string, mixed>
     */
    public static function createUser(array $attributes): array
    {
        return [
            'roles' => [],
            'isActive' => false,
            'username' => null,
            ...$attributes,
        ];
    }

    /** @param array<string, mixed> $user */
    public static function hasSuperAdminRole(array $user): bool
    {
        foreach ($user['roles'] ?? [] as $role) {
            if (is_array($role) && ($role['code'] ?? null) === self::SUPER_ADMIN_CODE) {
                return true;
            }
        }

        return false;
    }
}
