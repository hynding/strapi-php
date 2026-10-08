<?php

declare(strict_types=1);

namespace Strapi\Admin\Services\Permission\PermissionsManager;

use Strapi\Permissions\Engine\Abilities\Ability;
use Strapi\Permissions\Engine\Abilities\Rule;
use Strapi\Utils\Primitives\Objects;

/** Port of server/src/services/permission/permissions-manager/query-builders.ts. */
final class QueryBuilders
{
    public const array OPERATORS_MAP = [
        '$in' => '$in',
        '$nin' => '$notIn',
        '$exists' => '$notNull',
        '$gte' => '$gte',
        '$gt' => '$gt',
        '$lte' => '$lte',
        '$lt' => '$lt',
        '$eq' => '$eq',
        '$ne' => '$ne',
        '$and' => '$and',
        '$or' => '$or',
        '$not' => '$not',
    ];

    private static function mapKey(string|int $key): string|int
    {
        if (is_string($key) && str_starts_with($key, '$') && array_key_exists($key, self::OPERATORS_MAP)) {
            return self::OPERATORS_MAP[$key];
        }

        return $key;
    }

    /**
     * `rulesToQuery(ability, action, model, o => o.conditions)`: `null` when nothing is allowed,
     * `[]` (an empty object) when everything is.
     *
     * @return array<string, mixed>|null
     */
    public static function buildCaslQuery(Ability $ability, string $action, ?string $model): ?array
    {
        return $ability->rulesToQuery($action, $model ?? 'all', static fn (Rule $rule): mixed => $rule->conditions);
    }

    public static function buildStrapiQuery(mixed $caslQuery): mixed
    {
        return self::unwrapDeep($caslQuery);
    }

    private static function isPlainObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    private static function unwrapDeep(mixed $obj): mixed
    {
        if (!is_array($obj)) {
            return $obj;
        }
        if ($obj !== [] && array_is_list($obj)) {
            return array_map(self::unwrapDeep(...), $obj);
        }

        $acc = [];
        foreach ($obj as $k => $v) {
            $key = (string) self::mapKey($k);

            if (self::isPlainObject($v)) {
                $acc = Objects::set($acc, $key, is_array($v) && array_key_exists('$elemMatch', $v) ? self::unwrapDeep($v['$elemMatch']) : self::unwrapDeep($v));
            } elseif (is_array($v)) {
                $acc = Objects::set($acc, $key, array_map(self::unwrapDeep(...), $v));
            } else {
                $acc = Objects::set($acc, $key, $v);
            }
        }

        return $acc;
    }
}
