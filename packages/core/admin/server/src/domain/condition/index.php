<?php

declare(strict_types=1);

namespace Strapi\Admin\Domain\Condition;

/**
 * Port of server/src/domain/condition/index.ts: the domain representation of a condition.
 *
 * A condition is a plain array `{ id, displayName, handler, plugin?, category }` whose `handler` is
 * a callable `fn (array $user, array $options = []): array|bool`.
 */
final class Condition
{
    public const string DEFAULT_CATEGORY = 'default';

    /** Get the list of all the valid attributes of a condition. */
    public const array CONDITION_FIELDS = ['id', 'displayName', 'handler', 'plugin', 'category'];

    /**
     * Get the default value used for every condition.
     *
     * @return array{category: string}
     */
    public static function getDefaultConditionAttributes(): array
    {
        return ['category' => self::DEFAULT_CATEGORY];
    }

    /** @return list<string> */
    public static function conditionFields(): array
    {
        return self::CONDITION_FIELDS;
    }

    /**
     * Remove unwanted attributes from a condition.
     *
     * @param array<string, mixed> $condition
     * @return array<string, mixed>
     */
    public static function sanitizeConditionAttributes(array $condition): array
    {
        $out = [];
        foreach (self::CONDITION_FIELDS as $field) {
            if (array_key_exists($field, $condition)) {
                $out[$field] = $condition[$field];
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $condition */
    public static function computeConditionId(array $condition): string
    {
        $name = (string) ($condition['name'] ?? '');
        $plugin = $condition['plugin'] ?? null;

        if ($plugin === null || $plugin === '' || $plugin === false) {
            return "api::{$name}";
        }

        if ($plugin === 'admin') {
            return "admin::{$name}";
        }

        return "plugin::{$plugin}.{$name}";
    }

    /**
     * Assign an id attribute to a create-condition payload.
     *
     * @param array<string, mixed> $attrs
     * @return array<string, mixed>
     */
    public static function assignConditionId(array $attrs): array
    {
        $attrs['id'] = self::computeConditionId($attrs);

        return $attrs;
    }

    /**
     * Transform the given attributes into a domain representation of a condition.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function create(array $payload): array
    {
        $condition = self::sanitizeConditionAttributes(self::assignConditionId($payload));

        // merge(getDefaultConditionAttributes(), condition): `undefined` keeps the default
        if (!isset($condition['category'])) {
            $condition['category'] = self::DEFAULT_CATEGORY;
        }

        return $condition;
    }
}
