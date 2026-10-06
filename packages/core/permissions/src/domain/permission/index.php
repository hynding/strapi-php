<?php

declare(strict_types=1);

namespace Strapi\Permissions\Domain\Permission;

use Strapi\Utils\Primitives\Objects;

/**
 * Port of packages/core/permissions/src/domain/permission/index.ts.
 *
 * A permission is a plain array: `{ action, actionParameters?, subject?, properties?, conditions? }`.
 *
 * @phpstan-type PermissionArray array{action: string, actionParameters?: array<string, mixed>, subject?: string|array<string, mixed>|null, properties?: array<string, mixed>, conditions?: list<string>}
 */
final class Permission
{
    public const PERMISSION_FIELDS = ['action', 'subject', 'properties', 'conditions'];

    /**
     * Retains only fields supported by the permission domain.
     *
     * @param array<string, mixed> $permission
     * @return array<string, mixed>
     */
    public static function sanitizePermissionFields(array $permission): array
    {
        return Objects::pick($permission, self::PERMISSION_FIELDS);
    }

    /**
     * Creates a permission with default values for optional properties.
     *
     * @return array{conditions: list<string>, properties: array<string, mixed>, subject: null}
     */
    public static function getDefaultPermission(): array
    {
        return ['conditions' => [], 'properties' => [], 'subject' => null];
    }

    /**
     * Create a new permission based on given attributes (`_.merge(defaults, sanitized)`).
     *
     * @param array<string, mixed> $attributes
     * @return PermissionArray
     */
    public static function create(array $attributes): array
    {
        /** @var PermissionArray $permission */
        $permission = Objects::merge(self::getDefaultPermission(), self::sanitizePermissionFields($attributes));

        return $permission;
    }

    /**
     * Add a condition to a permission (curried when `$permission` is omitted).
     *
     * @param array<string, mixed>|null $permission
     * @return array<string, mixed>|\Closure(array<string, mixed>): array<string, mixed>
     */
    public static function addCondition(string $condition, ?array $permission = null): array|\Closure
    {
        if ($permission === null) {
            return static fn (array $p): array => self::addCondition($condition, $p);
        }

        $conditions = $permission['conditions'] ?? null;

        $newConditions = is_array($conditions)
            ? array_values(array_unique([...$conditions, $condition]))
            : [$condition];

        return [...$permission, 'conditions' => $newConditions];
    }

    /**
     * Gets a property or a part of a property from a permission (curried when `$permission` is omitted).
     *
     * @param array<string, mixed>|null $permission
     */
    public static function getProperty(string $property, ?array $permission = null): mixed
    {
        if ($permission === null) {
            return static fn (array $p): mixed => self::getProperty($property, $p);
        }

        return Objects::get($permission, "properties.{$property}");
    }
}
