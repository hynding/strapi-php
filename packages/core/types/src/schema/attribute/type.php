<?php

declare(strict_types=1);

namespace Strapi\Types\Schema\Attribute;

/** Mirrors packages/core/types/src/schema/attribute/*.ts — every attribute "type" in schema.json. */
enum Type: string
{
    case String = 'string';
    case Text = 'text';
    case RichText = 'richtext';
    case Blocks = 'blocks';
    case Email = 'email';
    case Password = 'password';
    case Uid = 'uid';
    case Enumeration = 'enumeration';
    case Integer = 'integer';
    case BigInteger = 'biginteger';
    case Float = 'float';
    case Decimal = 'decimal';
    case Boolean = 'boolean';
    case Date = 'date';
    case Time = 'time';
    case DateTime = 'datetime';
    case Timestamp = 'timestamp';
    case Json = 'json';
    case Media = 'media';
    case Relation = 'relation';
    case Component = 'component';
    case DynamicZone = 'dynamiczone';
    case CustomField = 'customField';

    public function isScalar(): bool
    {
        return !in_array($this, [self::Media, self::Relation, self::Component, self::DynamicZone], true);
    }

    public function isWritableScalar(): bool
    {
        return $this->isScalar();
    }
}
