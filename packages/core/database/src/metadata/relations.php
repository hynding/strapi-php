<?php

declare(strict_types=1);

namespace Strapi\Database\Metadata;

use Strapi\Database\Utils\Identifiers\Identifiers;
use Strapi\Database\Utils\LodashWords as Strings;

/**
 * Port of packages/core/database/src/metadata/relations.ts: builds join column / join table
 * metadata for every relation kind. Attributes are plain arrays; they are mutated in place on the
 * Metadata entries (passed by reference).
 *
 * @phpstan-type Attribute array<string, mixed>
 * @phpstan-import-type Meta from Metadata
 */
final class Relations
{
    /** @param Attribute $attribute */
    public static function hasInversedBy(array $attribute): bool
    {
        return array_key_exists('inversedBy', $attribute);
    }

    /** @param Attribute $attribute */
    public static function hasMappedBy(array $attribute): bool
    {
        return array_key_exists('mappedBy', $attribute);
    }

    /** @param Attribute $attribute */
    public static function isPolymorphic(array $attribute): bool
    {
        return in_array($attribute['relation'] ?? null, ['morphOne', 'morphMany', 'morphToOne', 'morphToMany'], true);
    }

    /** @param Attribute $attribute */
    public static function isOneToAny(array $attribute): bool
    {
        return in_array($attribute['relation'] ?? null, ['oneToOne', 'oneToMany'], true);
    }

    /** @param Attribute $attribute */
    public static function isManyToAny(array $attribute): bool
    {
        return in_array($attribute['relation'] ?? null, ['manyToMany', 'manyToOne'], true);
    }

    /** @param Attribute $attribute */
    public static function isAnyToOne(array $attribute): bool
    {
        return in_array($attribute['relation'] ?? null, ['oneToOne', 'manyToOne'], true);
    }

    /** @param Attribute $attribute */
    public static function isAnyToMany(array $attribute): bool
    {
        return in_array($attribute['relation'] ?? null, ['oneToMany', 'manyToMany'], true);
    }

    /** @param Attribute $attribute */
    public static function isBidirectional(array $attribute): bool
    {
        return self::hasInversedBy($attribute) || self::hasMappedBy($attribute);
    }

    /** @param Attribute $attribute */
    public static function isOwner(array $attribute): bool
    {
        return !self::isBidirectional($attribute) || self::hasInversedBy($attribute);
    }

    /** @param Attribute $attribute */
    public static function shouldUseJoinTable(array $attribute): bool
    {
        return !array_key_exists('useJoinTable', $attribute) || $attribute['useJoinTable'] !== false;
    }

    /** @param Attribute $attribute */
    public static function hasOrderColumn(array $attribute): bool
    {
        return self::isAnyToMany($attribute);
    }

    /** @param Attribute $attribute */
    public static function hasInverseOrderColumn(array $attribute): bool
    {
        return self::isBidirectional($attribute) && self::isManyToAny($attribute);
    }

    /**
     * Creates a relation metadata.
     *
     * @param Attribute $attribute
     * @param Meta $meta
     */
    public static function createRelation(string $attributeName, array &$attribute, array &$meta, Metadata $metadata): void
    {
        switch ($attribute['relation'] ?? null) {
            case 'oneToOne':
                self::createOneToOne($attributeName, $attribute, $meta, $metadata);

                return;
            case 'oneToMany':
                self::createOneToMany($attributeName, $attribute, $meta, $metadata);

                return;
            case 'manyToOne':
                self::createManyToOne($attributeName, $attribute, $meta, $metadata);

                return;
            case 'manyToMany':
                self::createManyToMany($attributeName, $attribute, $meta, $metadata);

                return;
            case 'morphToOne':
                self::createMorphToOne($attributeName, $attribute);

                return;
            case 'morphToMany':
                self::createMorphToMany($attributeName, $attribute, $meta, $metadata);

                return;
            case 'morphOne':
            case 'morphMany':
                self::createMorphX($attributeName, $attribute, $meta, $metadata);

                return;
            default:
                throw new \InvalidArgumentException('Unknown relation');
        }
    }

    /** @param Attribute $attribute  @param Meta $meta */
    private static function createOneToOne(string $attributeName, array &$attribute, array &$meta, Metadata $metadata): void
    {
        if (self::isOwner($attribute)) {
            if (self::shouldUseJoinTable($attribute)) {
                self::createJoinTable($metadata, $attributeName, $attribute, $meta);
            } else {
                self::createJoinColumn($metadata, $attributeName, $attribute);
            }
        }
        // else: this property must be set by the owner side
    }

    /** @param Attribute $attribute  @param Meta $meta */
    private static function createOneToMany(string $attributeName, array &$attribute, array &$meta, Metadata $metadata): void
    {
        if (self::shouldUseJoinTable($attribute) && !self::isBidirectional($attribute)) {
            self::createJoinTable($metadata, $attributeName, $attribute, $meta);
        } elseif (self::isOwner($attribute)) {
            throw new \InvalidArgumentException('one side of a oneToMany cannot be the owner side in a bidirectional relation');
        }
    }

    /** @param Attribute $attribute  @param Meta $meta */
    private static function createManyToOne(string $attributeName, array &$attribute, array &$meta, Metadata $metadata): void
    {
        if (self::isBidirectional($attribute) && !self::isOwner($attribute)) {
            throw new \InvalidArgumentException('The many side of a manyToOne must be the owning side');
        }

        if (self::shouldUseJoinTable($attribute)) {
            self::createJoinTable($metadata, $attributeName, $attribute, $meta);
        } else {
            self::createJoinColumn($metadata, $attributeName, $attribute);
        }
    }

    /** @param Attribute $attribute  @param Meta $meta */
    private static function createManyToMany(string $attributeName, array &$attribute, array &$meta, Metadata $metadata): void
    {
        if (self::shouldUseJoinTable($attribute) && (!self::isBidirectional($attribute) || self::isOwner($attribute))) {
            self::createJoinTable($metadata, $attributeName, $attribute, $meta);
        }
    }

    /** @param Attribute $attribute */
    private static function createMorphToOne(string $attributeName, array &$attribute): void
    {
        $identifiers = Identifiers::global();
        $idColumnName = $identifiers->getJoinColumnAttributeIdName('target');
        $typeColumnName = $identifiers->getMorphColumnTypeName('target');

        $attribute['owner'] = true;
        $attribute['morphColumn'] ??= [
            'typeColumn' => ['name' => $typeColumnName],
            'idColumn' => ['name' => $idColumnName, 'referencedColumn' => Identifiers::ID_COLUMN],
        ];
    }

    /** @param Attribute $attribute  @param Meta $meta */
    private static function createMorphToMany(string $attributeName, array &$attribute, array &$meta, Metadata $metadata): void
    {
        if (isset($attribute['joinTable']) && empty($attribute['joinTable']['__internal__'])) {
            return;
        }

        $identifiers = Identifiers::global();
        $joinTableName = $identifiers->getMorphTableName($meta['tableName'], $attributeName);
        $joinColumnName = $identifiers->getMorphColumnJoinTableIdName(Strings::snakeCase($meta['singularName']));
        $idColumnName = $identifiers->getMorphColumnAttributeIdName($attributeName);
        $typeColumnName = $identifiers->getMorphColumnTypeName($attributeName);

        $fkIndexName = $identifiers->getFkIndexName($joinTableName);

        $metadata->add([
            'singularName' => $joinTableName,
            'uid' => $joinTableName,
            'tableName' => $joinTableName,
            'attributes' => [
                Identifiers::ID_COLUMN => ['type' => 'increments'],
                $joinColumnName => [
                    'type' => 'integer',
                    'column' => ['unsigned' => true],
                    // This must be set explicitly so that it is used instead of shortening the attribute name, which is already shortened
                    'columnName' => $joinColumnName,
                ],
                $idColumnName => ['type' => 'integer', 'column' => ['unsigned' => true]],
                $typeColumnName => ['type' => 'string'],
                Identifiers::FIELD_COLUMN => ['type' => 'string'],
                Identifiers::ORDER_COLUMN => ['type' => 'float', 'column' => ['unsigned' => true]],
            ],
            'indexes' => [
                ['name' => $fkIndexName, 'columns' => [$joinColumnName]],
                ['name' => $identifiers->getOrderIndexName($joinTableName), 'columns' => [Identifiers::ORDER_COLUMN]],
                ['name' => $identifiers->getIdColumnIndexName($joinTableName), 'columns' => [$idColumnName]],
            ],
            'foreignKeys' => [
                [
                    'name' => $fkIndexName,
                    'columns' => [$joinColumnName],
                    'referencedColumns' => [Identifiers::ID_COLUMN],
                    'referencedTable' => $meta['tableName'],
                    'onDelete' => 'CASCADE',
                ],
            ],
            'lifecycles' => [],
            'columnToAttribute' => [],
        ]);

        $attribute['joinTable'] = [
            '__internal__' => true,
            'name' => $joinTableName,
            'joinColumn' => ['name' => $joinColumnName, 'referencedColumn' => Identifiers::ID_COLUMN],
            'morphColumn' => [
                'typeColumn' => ['name' => $typeColumnName],
                'idColumn' => ['name' => $idColumnName, 'referencedColumn' => Identifiers::ID_COLUMN],
            ],
            'orderBy' => ['order' => 'asc'],
            'pivotColumns' => [$joinColumnName, $typeColumnName, $idColumnName],
        ];
    }

    /** morphOne / morphMany: only validate the target. @param Attribute $attribute  @param Meta $meta */
    private static function createMorphX(string $attributeName, array &$attribute, array &$meta, Metadata $metadata): void
    {
        $target = (string) ($attribute['target'] ?? '');
        if (!$metadata->has($target)) {
            throw new \InvalidArgumentException("Morph target not found. Looking for {$target}");
        }

        $targetMeta = $metadata->get($target);

        if (!empty($attribute['morphBy']) && !isset($targetMeta['attributes'][$attribute['morphBy']])) {
            throw new \InvalidArgumentException("Morph target attribute not found. Looking for {$attribute['morphBy']}");
        }
    }

    /** Creates a join column info and adds it to the attribute meta. @param Attribute $attribute */
    private static function createJoinColumn(Metadata $metadata, string $attributeName, array &$attribute): void
    {
        $target = (string) ($attribute['target'] ?? '');
        if (!$metadata->has($target)) {
            throw new \InvalidArgumentException("Unknown target {$target}");
        }
        $targetMeta = $metadata->get($target);

        $joinColumnName = AttributeNaming::joinColumnName($attributeName);
        $joinColumn = [
            'name' => $joinColumnName,
            'referencedColumn' => Identifiers::ID_COLUMN,
            'referencedTable' => $targetMeta['tableName'],
        ];

        if (isset($attribute['joinColumn']) && is_array($attribute['joinColumn'])) {
            $joinColumn = array_merge($joinColumn, $attribute['joinColumn']);
        }

        $attribute['owner'] = true;
        $attribute['joinColumn'] = $joinColumn;

        if (self::isBidirectional($attribute)) {
            $inverseName = (string) $attribute['inversedBy'];
            $metadata->updateAttribute($target, $inverseName, static function (array $inverse) use ($joinColumn, $joinColumnName): array {
                $inverse['joinColumn'] = [
                    'name' => $joinColumn['referencedColumn'],
                    'referencedColumn' => $joinColumnName,
                ];

                return $inverse;
            });
        }
    }

    /** Creates a join table and adds it to the attribute meta. @param Attribute $attribute  @param Meta $meta */
    private static function createJoinTable(Metadata $metadata, string $attributeName, array &$attribute, array &$meta): void
    {
        if (!self::shouldUseJoinTable($attribute)) {
            throw new \InvalidArgumentException('Attempted to create join table when useJoinTable is false');
        }

        $target = (string) ($attribute['target'] ?? '');
        if (!$metadata->has($target)) {
            throw new \InvalidArgumentException("Unknown target {$target}");
        }
        $targetMeta = $metadata->get($target);

        // TODO: implement overwrite logic instead
        if (isset($attribute['joinTable']) && empty($attribute['joinTable']['__internal__'])) {
            return;
        }

        $identifiers = Identifiers::global();

        $joinTableName = AttributeNaming::joinTableName($meta['tableName'], $attributeName);

        $joinColumnName = $identifiers->getJoinColumnAttributeIdName(Strings::snakeCase($meta['singularName']));

        $inverseJoinColumnName = $identifiers->getJoinColumnAttributeIdName(Strings::snakeCase($targetMeta['singularName']));

        // if relation is self referencing
        if ($joinColumnName === $inverseJoinColumnName) {
            $inverseJoinColumnName = $identifiers->getInverseJoinColumnAttributeIdName(Strings::snakeCase($targetMeta['singularName']));
        }

        $orderColumnName = $identifiers->getOrderColumnName(Strings::snakeCase($targetMeta['singularName']));
        $inverseOrderColumnName = $identifiers->getOrderColumnName(Strings::snakeCase($meta['singularName']));

        // if relation is self referencing
        if ($attribute['relation'] === 'manyToMany' && $orderColumnName === $inverseOrderColumnName) {
            $inverseOrderColumnName = $identifiers->getInverseOrderColumnName(Strings::snakeCase($meta['singularName']));
        }

        $fkIndexName = $identifiers->getFkIndexName($joinTableName);
        $invFkIndexName = $identifiers->getInverseFkIndexName($joinTableName);

        $metadataSchema = [
            'singularName' => $joinTableName,
            'uid' => $joinTableName,
            'tableName' => $joinTableName,
            'attributes' => [
                Identifiers::ID_COLUMN => ['type' => 'increments'],
                $joinColumnName => [
                    'type' => 'integer',
                    'column' => ['unsigned' => true],
                    'columnName' => $joinColumnName,
                ],
                $inverseJoinColumnName => [
                    'type' => 'integer',
                    'column' => ['unsigned' => true],
                    'columnName' => $inverseJoinColumnName,
                ],
            ],
            'indexes' => [
                ['name' => $fkIndexName, 'columns' => [$joinColumnName]],
                ['name' => $invFkIndexName, 'columns' => [$inverseJoinColumnName]],
                [
                    'name' => $identifiers->getUniqueIndexName($joinTableName),
                    'columns' => [$joinColumnName, $inverseJoinColumnName],
                    'type' => 'unique',
                ],
            ],
            'foreignKeys' => [
                [
                    'name' => $fkIndexName,
                    'columns' => [$joinColumnName],
                    'referencedColumns' => [Identifiers::ID_COLUMN],
                    'referencedTable' => $meta['tableName'],
                    'onDelete' => 'CASCADE',
                ],
                [
                    'name' => $invFkIndexName,
                    'columns' => [$inverseJoinColumnName],
                    'referencedColumns' => [Identifiers::ID_COLUMN],
                    'referencedTable' => $targetMeta['tableName'],
                    'onDelete' => 'CASCADE',
                ],
            ],
            'lifecycles' => [],
            'columnToAttribute' => [],
        ];

        $joinTable = [
            '__internal__' => true,
            'name' => $joinTableName,
            'joinColumn' => [
                'name' => $joinColumnName,
                'referencedColumn' => Identifiers::ID_COLUMN,
                'referencedTable' => $meta['tableName'],
            ],
            'inverseJoinColumn' => [
                'name' => $inverseJoinColumnName,
                'referencedColumn' => Identifiers::ID_COLUMN,
                'referencedTable' => $targetMeta['tableName'],
            ],
            'pivotColumns' => [$joinColumnName, $inverseJoinColumnName],
        ];

        // order
        if (self::isAnyToMany($attribute)) {
            $metadataSchema['attributes'][$orderColumnName] = [
                'type' => 'float',
                'column' => ['unsigned' => true, 'defaultTo' => null],
                'columnName' => $orderColumnName,
            ];
            $metadataSchema['indexes'][] = [
                'name' => $identifiers->getOrderFkIndexName($joinTableName),
                'columns' => [$orderColumnName],
            ];
            $joinTable['orderColumnName'] = $orderColumnName;
            $joinTable['orderBy'] = [$orderColumnName => 'asc'];
        }

        // inv order
        if (self::isBidirectional($attribute) && self::isManyToAny($attribute)) {
            $metadataSchema['attributes'][$inverseOrderColumnName] = [
                'type' => 'float',
                'column' => ['unsigned' => true, 'defaultTo' => null],
                'columnName' => $inverseOrderColumnName,
            ];
            $metadataSchema['indexes'][] = [
                'name' => $identifiers->getOrderInverseFkIndexName($joinTableName),
                'columns' => [$inverseOrderColumnName],
            ];
            $joinTable['inverseOrderColumnName'] = $inverseOrderColumnName;
        }

        $metadata->add($metadataSchema);

        $attribute['joinTable'] = $joinTable;

        if (self::isBidirectional($attribute)) {
            $inversedBy = $attribute['inversedBy'] ?? null;
            $inverseAttribute = $inversedBy !== null ? ($targetMeta['attributes'][$inversedBy] ?? null) : null;

            if ($inverseAttribute === null) {
                throw new \InvalidArgumentException("inversedBy attribute {$inversedBy} not found target {$targetMeta['uid']}");
            }

            if (($inverseAttribute['type'] ?? null) !== 'relation') {
                throw new \InvalidArgumentException("inversedBy attribute {$inversedBy} targets non relational attribute in {$targetMeta['uid']}");
            }

            $isManyToAny = self::isManyToAny($attribute);
            $isAnyToMany = self::isAnyToMany($attribute);

            $metadata->updateAttribute($target, (string) $inversedBy, static function (array $inverse) use ($joinTable, $joinTableName, $isManyToAny, $isAnyToMany, $inverseOrderColumnName, $orderColumnName): array {
                $inverse['joinTable'] = [
                    '__internal__' => true,
                    'name' => $joinTableName,
                    'joinColumn' => $joinTable['inverseJoinColumn'],
                    'inverseJoinColumn' => $joinTable['joinColumn'],
                    'pivotColumns' => $joinTable['pivotColumns'],
                ];

                if ($isManyToAny) {
                    $inverse['joinTable']['orderColumnName'] = $inverseOrderColumnName;
                    $inverse['joinTable']['orderBy'] = [$inverseOrderColumnName => 'asc'];
                }
                if ($isAnyToMany) {
                    $inverse['joinTable']['inverseOrderColumnName'] = $orderColumnName;
                }

                return $inverse;
            });
        }
    }
}
