<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Services\Helpers\Utils;

/** Port of server/src/services/helpers/utils/get-schema-data.ts: the format of the data response. */
final class GetSchemaData
{
    /**
     * @param bool $isListOfEntities checks for multiple entities
     * @param array<string, mixed> $attributes the (OpenAPI) attributes found on a content type
     *
     * @return array<string, mixed> object | array of attributes
     */
    public static function getSchemaData(bool $isListOfEntities, array $attributes): array
    {
        $properties = [
            'id' => ['oneOf' => [['type' => 'string'], ['type' => 'number']]],
            'documentId' => ['type' => 'string'],
            ...$attributes,
        ];

        if ($isListOfEntities) {
            return [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => $properties,
                ],
            ];
        }

        return [
            'type' => 'object',
            'properties' => $properties,
        ];
    }
}
