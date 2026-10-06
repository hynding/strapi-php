<?php

declare(strict_types=1);

namespace Strapi\Database\Schema;

use Strapi\Database\Metadata\Metadata;
use Strapi\Database\Utils\Identifiers\Identifiers;
use Strapi\Database\Utils\Types as AttributeTypes;

/**
 * Port of packages/core/database/src/schema/schema.ts: `metadataToSchema`.
 *
 * @phpstan-import-type Schema as SchemaArray from Types
 * @phpstan-import-type Table from Types
 * @phpstan-import-type Column from Types
 * @phpstan-import-type Meta from Metadata
 */
final class Schema
{
    /** @return SchemaArray */
    public static function metadataToSchema(Metadata $metadata): array
    {
        $schema = ['tables' => []];

        foreach ($metadata as $meta) {
            $schema['tables'][] = self::createTable($meta);
        }

        return $schema;
    }

    /** @param Meta $meta  @return Table */
    public static function createTable(array $meta): array
    {
        $identifiers = Identifiers::global();

        $table = [
            'name' => $meta['tableName'],
            'indexes' => $meta['indexes'] ?? [],
            'foreignKeys' => $meta['foreignKeys'] ?? [],
            'columns' => [],
        ];

        foreach ($meta['attributes'] as $key => $attribute) {
            if (($attribute['type'] ?? null) === 'relation') {
                if (!empty($attribute['morphColumn']) && !empty($attribute['owner'])) {
                    $idColumnName = $identifiers->getName($attribute['morphColumn']['idColumn']['name']);
                    $typeColumnName = $identifiers->getName($attribute['morphColumn']['typeColumn']['name']);

                    $table['columns'][] = self::createColumn($idColumnName, ['type' => 'integer', 'column' => ['unsigned' => true]]);
                    $table['columns'][] = self::createColumn($typeColumnName, ['type' => 'string']);
                } elseif (!empty($attribute['joinColumn']) && !empty($attribute['owner']) && !empty($attribute['joinColumn']['referencedTable'])) {
                    $joinColumn = $attribute['joinColumn'];
                    $columnName = $identifiers->getName($joinColumn['name']);
                    $columnType = $joinColumn['columnType'] ?? 'integer';

                    $column = self::createColumn($columnName, ['type' => $columnType, 'column' => ['unsigned' => true]]);
                    $table['columns'][] = $column;

                    $fkName = $identifiers->getFkIndexName([$table['name'], $columnName]);
                    $table['foreignKeys'][] = [
                        'name' => $fkName,
                        'columns' => [$column['name']],
                        'referencedTable' => $joinColumn['referencedTable'],
                        'referencedColumns' => [$joinColumn['referencedColumn']],
                        'onDelete' => 'SET NULL',
                    ];
                    $table['indexes'][] = ['name' => $fkName, 'columns' => [$column['name']]];
                }
            } elseif (AttributeTypes::isScalarAttribute($attribute)) {
                $columnName = $identifiers->getName($attribute['columnName'] ?? (string) $key);
                $column = self::createColumn($columnName, $attribute);

                if (!empty($column['unique'])) {
                    $table['indexes'][] = [
                        'type' => 'unique',
                        'name' => $identifiers->getUniqueIndexName([$table['name'], $column['name']]),
                        'columns' => [$columnName],
                    ];
                }

                if (!empty($column['primary'])) {
                    $table['indexes'][] = [
                        'type' => 'primary',
                        'name' => $identifiers->getPrimaryIndexName([$table['name'], $column['name']]),
                        'columns' => [$columnName],
                    ];
                }

                $table['columns'][] = $column;
            }
        }

        return $table;
    }

    /** @param array<string, mixed> $attribute  @return Column */
    public static function createColumn(string $name, array $attribute): array
    {
        $columnType = self::getColumnType($attribute);
        $type = $columnType['type'];
        $args = $columnType['args'] ?? [];
        unset($columnType['type'], $columnType['args']);

        return [
            'name' => Identifiers::global()->getName($name),
            'type' => $type,
            'args' => $args,
            'defaultTo' => null,
            'notNullable' => false,
            'unsigned' => false,
            ...$columnType,
            ...($attribute['column'] ?? []),
        ];
    }

    /** @param array<string, mixed> $attribute  @return array<string, mixed> */
    public static function getColumnType(array $attribute): array
    {
        if (!empty($attribute['columnType'])) {
            return $attribute['columnType'];
        }

        return match ($attribute['type'] ?? null) {
            'increments' => ['type' => 'increments', 'args' => [['primary' => true, 'primaryKey' => true]], 'notNullable' => true],
            'password', 'email', 'string', 'enumeration', 'uid' => ['type' => 'string'],
            'richtext', 'text' => ['type' => 'text', 'args' => ['longtext']],
            'blocks', 'json' => ['type' => 'jsonb'],
            'integer' => ['type' => 'integer'],
            'biginteger' => ['type' => 'bigInteger'],
            'float' => ['type' => 'double'],
            'decimal' => ['type' => 'decimal', 'args' => [10, 2]],
            'date' => ['type' => 'date'],
            'time' => ['type' => 'time', 'args' => [['precision' => 3]]],
            'datetime' => ['type' => 'datetime', 'args' => [['useTz' => false, 'precision' => 6]]],
            'timestamp' => ['type' => 'timestamp', 'args' => [['useTz' => false, 'precision' => 6]]],
            'boolean' => ['type' => 'boolean'],
            default => throw new \InvalidArgumentException('Unknown type ' . ($attribute['type'] ?? 'undefined')),
        };
    }
}
