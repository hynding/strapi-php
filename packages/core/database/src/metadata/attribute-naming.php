<?php

declare(strict_types=1);

namespace Strapi\Database\Metadata;

use Strapi\Database\Utils\Identifiers\Identifiers;
use Strapi\Database\Utils\LodashWords as Strings;

/**
 * Port of packages/core/database/src/metadata/attribute-naming.ts.
 *
 * The rules that turn an attribute's logical name into the physical database identifiers it
 * maps to. Components, dynamic zones and media do not derive a physical identifier from the
 * attribute name: the name itself is stored in the link table's `field` column.
 */
final class AttributeNaming
{
    /** Column on the model's own table for a scalar attribute. */
    public static function columnName(string $attributeName): string
    {
        return Identifiers::global()->getColumnName(Strings::snakeCase($attributeName));
    }

    /** Column on the model's own table for a relation stored as a join column (`<attribute>_id`). */
    public static function joinColumnName(string $attributeName): string
    {
        return Identifiers::global()->getJoinColumnAttributeIdName(Strings::snakeCase($attributeName));
    }

    /** Join/link table for a relation owned by `attributeName` on the model whose table is `tableName`. */
    public static function joinTableName(string $tableName, string $attributeName): string
    {
        return Identifiers::global()->getJoinTableName(Strings::snakeCase($tableName), Strings::snakeCase($attributeName));
    }
}
