<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Types\Schema\Schema;
use Strapi\Utils\Errors\PaginationError;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Primitives\Objects;

/**
 * Port of packages/core/utils/src/convert-query-params.ts: converts REST / Document Service params
 * (fields, filters, sort, populate, page/pageSize, start/limit, _q, status) into database query
 * params (select, where, orderBy, populate, page/pageSize, offset/limit, filters, _q).
 *
 * `ConvertQueryParams::createTransformer(['getModel' => fn (string $uid) => ...])` returns a
 * {@see QueryParamsTransformer}. Upstream's `private_*` helpers are public methods on it.
 *
 * @phpstan-type Model Schema|array<string, mixed>
 */
final class ConvertQueryParams
{
    /** Members of `Object.prototype` a sort path may not reach (upstream: `key in current && !hasOwnProperty`). */
    public const OBJECT_PROTOTYPE_MEMBERS = [
        'constructor', '__proto__', 'hasOwnProperty', 'isPrototypeOf', 'propertyIsEnumerable', 'toLocaleString',
        'toString', 'valueOf', '__defineGetter__', '__defineSetter__', '__lookupGetter__', '__lookupSetter__',
    ];

    /**
     * @param array{getModel: callable(string): (Schema|array<string, mixed>|null)} $options
     */
    public static function createTransformer(array $options): QueryParamsTransformer
    {
        return new QueryParamsTransformer($options['getModel']);
    }

    /**
     * Build a sort map from a dotted field and an order: `'author.name'` → `['author' => ['name' => 'asc']]`.
     *
     * @return array<string, mixed>
     */
    public static function setSortMapValue(string $field, string $order): array
    {
        $path = Objects::toPath($field);
        if ($path === []) {
            throw new ValidationError('Invalid sort query');
        }

        foreach ($path as $key) {
            // upstream rejects `prototype` and any segment that is an inherited Object.prototype member
            // (`toString.call`, `constructor`, `__proto__`...) since it would walk into built-ins
            if ($key === 'prototype' || in_array($key, self::OBJECT_PROTOTYPE_MEMBERS, true)) {
                throw new ValidationError('Invalid sort query');
            }
        }

        $sortMap = [];
        $current = &$sortMap;
        $count = count($path);

        foreach ($path as $index => $key) {
            if ($index === $count - 1) {
                $current[$key] = $order;
                break;
            }
            $current[$key] = [];
            $current = &$current[$key];
        }

        return $sortMap;
    }

    /** @param array<string, mixed> $sortMap */
    public static function isEmptySortMap(array $sortMap): bool
    {
        if ($sortMap === []) {
            return true;
        }

        foreach ($sortMap as $value) {
            if (is_string($value)) {
                if (trim($value) !== '') {
                    return false;
                }
                continue;
            }
            if (is_array($value)) {
                if (!self::isEmptySortMap($value)) {
                    return false;
                }
                continue;
            }
        }

        return true;
    }

    /**
     * Drops empty sort maps so a trailing comma does not leave a truthy but meaningless `orderBy`.
     *
     * @param array<string, mixed>|list<array<string, mixed>> $orderBy
     * @return array<string, mixed>|list<array<string, mixed>>|null
     */
    public static function normalizeOrderBy(array $orderBy): ?array
    {
        if (array_is_list($orderBy) && $orderBy !== []) {
            $filtered = array_values(array_filter($orderBy, static fn (array $sortMap): bool => !self::isEmptySortMap($sortMap)));

            return $filtered !== [] ? $filtered : null;
        }

        return self::isEmptySortMap($orderBy) ? null : $orderBy;
    }
}
