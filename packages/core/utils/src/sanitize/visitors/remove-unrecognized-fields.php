<?php

declare(strict_types=1);

namespace Strapi\Utils\Sanitize\Visitors;

use Strapi\Utils\ContentTypes;
use Strapi\Utils\Traverse\VisitorOptions;
use Strapi\Utils\Traverse\VisitorUtils;

/** Removes keys that are not attributes of the schema (strictParams input sanitization). */
final class RemoveUnrecognizedFields
{
    public function __invoke(VisitorOptions $options, VisitorUtils $utils): void
    {
        // We only look at properties that are not attributes
        if ($options->attribute !== null) {
            return;
        }

        $key = $options->key;
        $parentAttribute = $options->parent?->attribute;

        // At root level (path.attribute === null), only accept id-like fields
        if ($options->path->attribute === null) {
            if (in_array($key, ContentTypes::ID_FIELDS, true)) {
                return;
            }
            if ($options->allowedExtraRootKeys !== null && in_array($key, $options->allowedExtraRootKeys, true)) {
                return;
            }

            $utils->remove($key);

            return;
        }

        // allow special morphTo keys
        if (ContentTypes::isMorphToRelationalAttribute($parentAttribute) && in_array($key, ContentTypes::MORPH_TO_KEYS, true)) {
            return;
        }

        // allow special dz keys
        if (ContentTypes::isComponentSchema($options->schema) && ContentTypes::isDynamicZoneAttribute($parentAttribute) && in_array($key, ContentTypes::DYNAMIC_ZONE_KEYS, true)) {
            return;
        }

        // allow relation operation keys (connect, disconnect, set, options) for relations and media
        if ((ContentTypes::isRelationalAttribute($parentAttribute) || ContentTypes::isMediaAttribute($parentAttribute)) && in_array($key, ContentTypes::RELATION_OPERATION_KEYS, true)) {
            return;
        }

        // allow id fields for relations, media, and components
        $canUseID = ContentTypes::isRelationalAttribute($parentAttribute) || ContentTypes::isMediaAttribute($parentAttribute) || ContentTypes::isComponentAttribute($parentAttribute);
        if ($canUseID && in_array($key, ContentTypes::ID_FIELDS, true)) {
            return;
        }

        $utils->remove($key);
    }
}
