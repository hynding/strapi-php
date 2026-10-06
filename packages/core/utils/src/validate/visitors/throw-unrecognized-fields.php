<?php

declare(strict_types=1);

namespace Strapi\Utils\Validate\Visitors;

use Strapi\Utils\ContentTypes;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;
use Strapi\Utils\Validate\Utils;

/** Throws for keys that are not attributes of the schema. */
final class ThrowUnrecognizedFields
{
    public function __invoke(VisitorOptions $options, VisitorUtils $utils): void
    {
        if ($options->attribute !== null) {
            return;
        }

        $key = $options->key;
        $parentAttribute = $options->parent?->attribute;

        if ($options->path->attribute === null) {
            if (in_array($key, ContentTypes::ID_FIELDS, true)) {
                return;
            }
            if ($options->allowedExtraRootKeys !== null && in_array($key, $options->allowedExtraRootKeys, true)) {
                return;
            }

            Utils::throwInvalidKey(['key' => $key, 'path' => $options->path->attribute]);
        }

        if (ContentTypes::isMorphToRelationalAttribute($parentAttribute) && in_array($key, ContentTypes::MORPH_TO_KEYS, true)) {
            return;
        }

        if (ContentTypes::isComponentSchema($options->schema) && ContentTypes::isDynamicZoneAttribute($parentAttribute) && in_array($key, ContentTypes::DYNAMIC_ZONE_KEYS, true)) {
            return;
        }

        if ((ContentTypes::isRelationalAttribute($parentAttribute) || ContentTypes::isMediaAttribute($parentAttribute)) && in_array($key, ContentTypes::RELATION_OPERATION_KEYS, true)) {
            return;
        }

        $canUseID = ContentTypes::isRelationalAttribute($parentAttribute) || ContentTypes::isMediaAttribute($parentAttribute) || ContentTypes::isComponentAttribute($parentAttribute);
        if ($canUseID && in_array($key, ContentTypes::ID_FIELDS, true)) {
            return;
        }

        Utils::throwInvalidKey(['key' => $key, 'path' => $options->path->attribute]);
    }
}
