<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Controller;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;

/**
 * Port of core-api/controller/transform.ts (`transformResponse`): wraps the sanitized entity in the
 * `{ data, meta }` envelope. Strapi 5 returns flat entities (`id`, `documentId` and the attributes at
 * the top level); the v4 `{ id, attributes }` JSON:API shape is produced only when the request sends
 * `strapi-response-format: v4` (`useJsonAPIFormat`).
 */
final class Transform
{
    /**
     * @param array<string, mixed> $meta
     * @param array{contentType?: Schema|null, useJsonAPIFormat?: bool, encodeSourceMaps?: bool} $opts
     * @return array{data: mixed, meta: array<string, mixed>|\stdClass}|null
     */
    public static function transformResponse(Strapi $strapi, mixed $resource, array $meta = [], array $opts = []): ?array
    {
        if ($resource === null) {
            return null;
        }

        if (!is_array($resource)) {
            throw new \RuntimeException('Entry must be an object or an array of objects');
        }

        $useJsonAPIFormat = $opts['useJsonAPIFormat'] ?? false;
        $contentType = $opts['contentType'] ?? null;

        $data = $useJsonAPIFormat ? self::transformEntry($strapi, $resource, $contentType) : $resource;

        // encodeSourceMaps (content-source-maps service) is not ported: no-op

        // an empty meta must serialize as `{}` like upstream, not `[]`
        return ['data' => $data, 'meta' => $meta === [] ? new \stdClass() : $meta];
    }

    private static function isEntry(mixed $property): bool
    {
        return $property === null || is_array($property);
    }

    /** @param array<string, mixed>|list<array<string, mixed>>|null $data */
    private static function transformComponent(Strapi $strapi, mixed $data, ?Schema $component): mixed
    {
        if (is_array($data) && array_is_list($data)) {
            return array_map(static fn (mixed $datum): mixed => self::transformComponent($strapi, $datum, $component), $data);
        }

        $res = self::transformEntry($strapi, $data, $component);

        if ($res === null) {
            return $res;
        }

        return ['id' => $res['id'], ...$res['attributes']];
    }

    /** @param array<string, mixed>|list<array<string, mixed>>|null $entry */
    public static function transformEntry(Strapi $strapi, mixed $entry, ?Schema $type): mixed
    {
        if ($entry === null) {
            return $entry;
        }

        if (is_array($entry) && array_is_list($entry)) {
            return array_map(static fn (mixed $single): mixed => self::transformEntry($strapi, $single, $type), $entry);
        }

        if (!is_array($entry)) {
            throw new \RuntimeException('Entry must be an object');
        }

        $id = $entry['id'] ?? null;
        $documentId = $entry['documentId'] ?? null;
        $properties = $entry;
        unset($properties['id'], $properties['documentId']);

        $attributeValues = [];

        foreach ($properties as $key => $property) {
            $attribute = $type?->attributes[$key] ?? null;
            $attrType = $attribute['type'] ?? null;

            if ($attrType === 'relation' && self::isEntry($property) && isset($attribute['target'])) {
                $attributeValues[$key] = ['data' => self::transformEntry($strapi, $property, $strapi->getModel((string) $attribute['target']))];
            } elseif ($attrType === 'component' && self::isEntry($property)) {
                $attributeValues[$key] = self::transformComponent($strapi, $property, $strapi->getModel((string) $attribute['component']));
            } elseif ($attrType === 'dynamiczone' && is_array($property) && array_is_list($property)) {
                $attributeValues[$key] = array_map(
                    static fn (mixed $subProperty): mixed => self::transformComponent($strapi, $subProperty, is_array($subProperty) && isset($subProperty['__component']) ? $strapi->getModel((string) $subProperty['__component']) : null),
                    $property,
                );
            } elseif ($attrType === 'media' && self::isEntry($property)) {
                $attributeValues[$key] = ['data' => self::transformEntry($strapi, $property, $strapi->getModel('plugin::upload.file'))];
            } else {
                $attributeValues[$key] = $property;
            }
        }

        // an entry without documentId serializes without the key (`documentId: undefined` upstream)
        return array_key_exists('documentId', $entry)
            ? ['id' => $id, 'documentId' => $documentId, 'attributes' => $attributeValues]
            : ['id' => $id, 'attributes' => $attributeValues];
    }
}
