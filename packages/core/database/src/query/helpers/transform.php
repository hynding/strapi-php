<?php

declare(strict_types=1);

namespace Strapi\Database\Query\Helpers;

use Strapi\Database\Fields\Fields;
use Strapi\Database\Fields\JsonField;
use Strapi\Database\Utils\Types;

/**
 * Port of packages/core/database/src/query/helpers/transform.ts.
 *
 * @phpstan-import-type Meta from \Strapi\Database\Metadata\Metadata
 */
final class Transform
{
    /**
     * @param Meta $meta
     * @param array<string, mixed>|null $row
     *
     * @return array<string, mixed>|null
     */
    public static function fromSingleRow(array $meta, ?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $attributes = $meta['attributes'];
        $obj = [];
        // PHP port: a content `json` attribute (api:: content types, components) reads `{}` as an
        // EmptyObject, answered as `{}`; internal models (admin::, plugin::, strapi::) keep `[]`
        $uid = $meta['uid'];
        $keepEmptyObjects = str_starts_with($uid, 'api::') || ($uid !== '' && !str_contains($uid, '::'));

        foreach ($row as $column => $value) {
            if (!array_key_exists($column, $meta['columnToAttribute'])) {
                continue;
            }

            $attributeName = $meta['columnToAttribute'][$column];
            $attribute = $attributes[$attributeName];
            $type = (string) ($attribute['type'] ?? '');

            if ($type === 'json' && $value !== null && $keepEmptyObjects) {
                $field = Fields::createField($attribute);
                $obj[$attributeName] = $field instanceof JsonField ? $field->fromDBKeepingEmptyObjects($value) : $field->fromDB($value);
            } elseif (Types::isScalar($type)) {
                $obj[$attributeName] = $value === null ? null : Fields::createField($attribute)->fromDB($value);
            } elseif (Types::isRelation($type)) {
                $obj[$attributeName] = $value;
            }
        }

        return $obj;
    }

    /**
     * @param Meta $meta
     * @param array<string, mixed>|list<array<string, mixed>>|null $row
     *
     * @return array<string, mixed>|list<array<string, mixed>>|null
     */
    public static function fromRow(array $meta, ?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        if (!array_is_list($row)) {
            /** @var array<string, mixed> $row a single row keyed by column */
            return self::fromSingleRow($meta, $row);
        }

        $rows = [];
        foreach ($row as $r) {
            $rows[] = self::fromSingleRow($meta, $r);
        }

        return $rows;
    }

    /**
     * @param Meta $meta
     * @param array<string, mixed>|null $data
     *
     * @return array<string, mixed>|null
     */
    public static function toSingleRow(array $meta, ?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        $attributes = $meta['attributes'];

        foreach (array_keys($data) as $key) {
            $attribute = $attributes[$key] ?? null;
            if ($attribute === null || empty($attribute['columnName']) || $attribute['columnName'] === $key) {
                continue;
            }

            $data[$attribute['columnName']] = $data[$key];
            unset($data[$key]);
        }

        return $data;
    }

    /**
     * @param Meta $meta
     * @param array<string, mixed>|list<array<string, mixed>>|null $data
     *
     * @return array<string, mixed>|list<array<string, mixed>>|null
     */
    public static function toRow(array $meta, ?array $data): ?array
    {
        if ($data === null) {
            return null;
        }

        if (!array_is_list($data) || $data === []) {
            /** @var array<string, mixed> $data a single row keyed by attribute */
            return self::toSingleRow($meta, $data);
        }

        $rows = [];
        foreach ($data as $d) {
            $rows[] = self::toSingleRow($meta, $d);
        }

        return $rows;
    }

    /** @param Meta $meta */
    public static function toColumnName(array $meta, ?string $name): string
    {
        if ($name === null || $name === '') {
            throw new \InvalidArgumentException('Name cannot be null');
        }

        $attribute = $meta['attributes'][$name] ?? null;
        if ($attribute === null) {
            return $name;
        }

        return !empty($attribute['columnName']) ? $attribute['columnName'] : $name;
    }
}
