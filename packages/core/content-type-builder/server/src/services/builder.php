<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Services;

use Strapi\Utils\ContentTypes;

/**
 * Port of server/src/services/builder.ts.
 *
 * Reserved names are centralised in `Strapi\Utils\ContentTypes` so the bootstrap validator,
 * the schema-to-model transform and this CTB service all share a single source. The methods
 * are static (upstream exports plain functions) and also callable on the service instance.
 */
final class Builder
{
    /** @return list<string> */
    public static function reservedAttributes(): array
    {
        return ContentTypes::getReservedAttributeNames();
    }

    /** @return list<string> */
    public static function reservedModels(): array
    {
        return ContentTypes::getReservedModelNames();
    }

    /** @return array{models: list<string>, attributes: list<string>} */
    public static function getReservedNames(): array
    {
        return [
            'models' => self::reservedModels(),
            'attributes' => self::reservedAttributes(),
        ];
    }

    public static function isReservedModelName(string $name): bool
    {
        return ContentTypes::isReservedModelName($name);
    }

    public static function isReservedAttributeName(string $name): bool
    {
        return ContentTypes::isReservedAttributeName($name);
    }
}
