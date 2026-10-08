<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

use Strapi\Utils\Zod\ZodArray;
use Strapi\Utils\Zod\ZodDefault;
use Strapi\Utils\Zod\ZodObject;
use Strapi\Utils\Zod\ZodOptional;
use Strapi\Utils\Zod\ZodType;

/**
 * Port of packages/core/core/src/utils/zod.ts: structural inspection of a Zod schema.
 *
 * @phpstan-type ZodSchemaInspection array{type: string, element?: ZodType, innerType?: ZodType, shape?: array<string, ZodType>}
 */
final class Zod
{
    /** Checks whether a value is a Zod schema. */
    public static function isZodType(mixed $value): bool
    {
        return $value instanceof ZodType;
    }

    /**
     * Reads the supported structural fields from a Zod schema definition.
     *
     * @return ZodSchemaInspection
     */
    public static function inspectZodSchema(ZodType $schema): array
    {
        return match (true) {
            $schema instanceof ZodArray => ['type' => 'array', 'element' => $schema->element()],
            $schema instanceof ZodOptional => ['type' => 'optional', 'innerType' => $schema->unwrap()],
            $schema instanceof ZodDefault => ['type' => 'default', 'innerType' => $schema->unwrap()],
            $schema instanceof ZodObject => ['type' => 'object', 'shape' => $schema->shape()],
            default => ['type' => $schema->type()],
        };
    }
}
