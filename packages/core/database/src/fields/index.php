<?php

declare(strict_types=1);

namespace Strapi\Database\Fields;

/** Port of packages/core/database/src/fields/index.ts: `createField(attribute)`. */
final class Fields
{
    private const TYPE_TO_FIELD = [
        'increments' => Field::class,
        'password' => StringField::class,
        'email' => StringField::class,
        'string' => StringField::class,
        'uid' => StringField::class,
        'richtext' => StringField::class,
        'text' => StringField::class,
        'enumeration' => StringField::class,
        'json' => JsonField::class,
        'biginteger' => BigIntegerField::class,
        'integer' => NumberField::class,
        'float' => NumberField::class,
        'decimal' => NumberField::class,
        'date' => DateField::class,
        'time' => TimeField::class,
        'datetime' => DatetimeField::class,
        'timestamp' => TimestampField::class,
        'boolean' => BooleanField::class,
        'blocks' => JsonField::class,
    ];

    /** @var array<string, Field> one shared instance per type (they are pure) */
    private static array $instances = [];

    /** @param array<string, mixed> $attribute */
    public static function createField(array $attribute): Field
    {
        $type = (string) ($attribute['type'] ?? '');

        if (isset(self::$instances[$type])) {
            return self::$instances[$type];
        }

        if (!array_key_exists($type, self::TYPE_TO_FIELD)) {
            throw new \InvalidArgumentException("Undefined field for type {$type}");
        }

        $class = self::TYPE_TO_FIELD[$type];

        return self::$instances[$type] = new $class([]);
    }
}
