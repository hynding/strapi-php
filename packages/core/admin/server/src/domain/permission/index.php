<?php

declare(strict_types=1);

namespace Strapi\Admin\Domain\Permission;

/**
 * Port of server/src/domain/permission/index.ts.
 *
 * A permission is a plain array:
 * `{ id?, action, actionParameters, subject, properties, conditions, role?, apiToken? }`.
 * A JS `undefined` property is an absent key.
 *
 * @phpstan-type PermissionArray array<string, mixed>
 */
final class Permission
{
    public const array PERMISSION_FIELDS = [
        'id',
        'action',
        'actionParameters',
        'subject',
        'properties',
        'conditions',
        'role',
        'apiToken',
    ];

    public const array SANITIZED_PERMISSION_FIELDS = [
        'id',
        'action',
        'actionParameters',
        'subject',
        'properties',
        'conditions',
    ];

    /**
     * @param array<string, mixed> $object
     * @param list<string> $fields
     * @return array<string, mixed>
     */
    private static function pick(array $object, array $fields): array
    {
        $out = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $object)) {
                $out[$field] = $object[$field];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $permission
     * @return array<string, mixed>
     */
    public static function sanitizePermissionFields(array $permission): array
    {
        return self::pick($permission, self::SANITIZED_PERMISSION_FIELDS);
    }

    /**
     * Creates a permission with default values.
     *
     * @return array{actionParameters: array<string, mixed>, conditions: list<string>, properties: array<string, mixed>, subject: null}
     */
    public static function getDefaultPermission(): array
    {
        return [
            'actionParameters' => [],
            'conditions' => [],
            'properties' => [],
            'subject' => null,
        ];
    }

    /**
     * Returns a new permission with the given condition.
     *
     * @param array<string, mixed> $permission
     * @return array<string, mixed>
     */
    public static function addCondition(string $condition, array $permission): array
    {
        $conditions = $permission['conditions'] ?? null;
        $permission['conditions'] = is_array($conditions)
            ? array_values(array_unique([...$conditions, $condition], SORT_REGULAR))
            : [$condition];

        return $permission;
    }

    /**
     * Returns a new permission without the given condition.
     *
     * @param array<string, mixed> $permission
     * @return array<string, mixed>
     */
    public static function removeCondition(string $condition, array $permission): array
    {
        $conditions = is_array($permission['conditions'] ?? null) ? $permission['conditions'] : [];
        $permission['conditions'] = array_values(array_filter($conditions, static fn (mixed $c): bool => $c !== $condition));

        return $permission;
    }

    /**
     * Gets a property or a part of a property from a permission (`get('properties.' + property)`).
     *
     * @param array<string, mixed> $permission
     */
    public static function getProperty(string $property, array $permission): mixed
    {
        $current = $permission['properties'] ?? null;
        foreach (explode('.', $property) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Set a value for a given property on a new permission object.
     *
     * @param array<string, mixed> $permission
     * @return array<string, mixed>
     */
    public static function setProperty(string $property, mixed $value, array $permission): array
    {
        return \Strapi\Utils\Primitives\Objects::set($permission, "properties.{$property}", $value);
    }

    /**
     * Returns a new permission without the given property name set.
     *
     * @param array<string, mixed> $permission
     * @return array<string, mixed>
     */
    public static function deleteProperty(string $property, array $permission): array
    {
        $segments = explode('.', "properties.{$property}");

        return self::unsetPath($permission, $segments);
    }

    /**
     * @param array<string|int, mixed> $object
     * @param list<string> $segments
     * @return array<string|int, mixed>
     */
    private static function unsetPath(array $object, array $segments): array
    {
        $key = array_shift($segments);
        if ($key === null || !array_key_exists($key, $object)) {
            return $object;
        }
        if ($segments === []) {
            unset($object[$key]);

            return $object;
        }
        if (is_array($object[$key])) {
            $object[$key] = self::unsetPath($object[$key], $segments);
        }

        return $object;
    }

    /**
     * Creates a new permission from raw attributes, with default values for certain fields.
     *
     * @param array<string, mixed> $attributes
     * @return array<string, mixed>
     */
    public static function create(array $attributes): array
    {
        // pipe(pick(permissionFields), merge(getDefaultPermission()))
        $permission = self::getDefaultPermission();
        foreach (self::pick($attributes, self::PERMISSION_FIELDS) as $key => $value) {
            $permission[$key] = $value;
        }

        return $permission;
    }

    /**
     * Using the given condition provider, check and remove invalid conditions from the
     * permission's conditions array.
     *
     * @param object $provider the condition provider (anything with `has(string): bool`)
     * @param array<string, mixed> $permission
     * @return array<string, mixed>
     */
    public static function sanitizeConditions(object $provider, array $permission): array
    {
        $has = [$provider, 'has'];
        if (!is_callable($has)) {
            throw new \InvalidArgumentException('The condition provider must have a has() method');
        }

        if (!is_array($permission['conditions'] ?? null)) {
            return $permission;
        }

        foreach ($permission['conditions'] as $condition) {
            if (!is_string($condition) || $has($condition) !== true) {
                $permission = self::removeCondition((string) $condition, $permission);
            }
        }

        return $permission;
    }

    /**
     * Transform raw attributes into valid permissions using the create domain function.
     * A list gives a list, a single permission gives a single permission.
     *
     * @param array<int|string, mixed> $payload
     * @return array<int|string, mixed>
     */
    public static function toPermission(array $payload): array
    {
        if (array_is_list($payload) && $payload !== []) {
            return array_map(static fn (array $value): array => self::create($value), $payload);
        }

        if ($payload === []) {
            return [];
        }

        /** @var array<string, mixed> $payload */
        return self::create($payload);
    }
}
