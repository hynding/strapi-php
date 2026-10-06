<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;

/**
 * Port of packages/core/core/src/utils/convert-custom-field-type.ts: swap `type: customField` for the
 * underlying data type on every content type and component. Schemas are immutable, so the
 * registries are updated with rebuilt copies.
 */
final class ConvertCustomFieldType
{
    public static function convertCustomFieldType(Strapi $strapi): void
    {
        foreach ($strapi->contentTypes() as $uid => $schema) {
            $converted = self::convert($strapi, $schema);
            if ($converted !== $schema) {
                $strapi->get('content-types')->set($uid, $converted);
            }
        }

        foreach ($strapi->components() as $uid => $schema) {
            $converted = self::convert($strapi, $schema);
            if ($converted !== $schema) {
                $strapi->get('components')->replace($uid, $converted);
            }
        }
    }

    public static function convert(Strapi $strapi, Schema $schema): Schema
    {
        $attributes = $schema->attributes;
        $changed = false;

        foreach ($attributes as $name => $attribute) {
            if (($attribute['type'] ?? null) !== 'customField') {
                continue;
            }
            $customField = $strapi->get('custom-fields')->get((string) $attribute['customField']);
            $attributes[$name]['type'] = $customField['type'];
            $changed = true;
        }

        if (!$changed) {
            return $schema;
        }

        return new Schema(
            uid: $schema->uid,
            modelType: $schema->modelType,
            kind: $schema->kind,
            modelName: $schema->modelName,
            globalId: $schema->globalId,
            collectionName: $schema->collectionName,
            plugin: $schema->plugin,
            apiName: $schema->apiName,
            category: $schema->category,
            info: $schema->info,
            options: $schema->options,
            pluginOptions: $schema->pluginOptions,
            attributes: $attributes,
            config: $schema->config,
        );
    }
}
