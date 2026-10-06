<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Attributes;

use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;

/** Port of attributes/index.ts (`applyTransforms`). */
final class Attributes
{
    /**
     * @param Schema|array<string, mixed> $schema
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function applyTransforms(Schema|array $schema, array $data): array
    {
        $attributes = ContentTypes::attributes($schema);

        foreach ($data as $attributeName => $value) {
            $attribute = $attributes[$attributeName] ?? null;

            if ($attribute === null) {
                continue;
            }

            $transform = Transforms::for((string) ($attribute['type'] ?? ''));

            if ($transform !== null) {
                $data[$attributeName] = $transform($value, ['attributeName' => (string) $attributeName, 'attribute' => $attribute]);
            }
        }

        return $data;
    }
}
