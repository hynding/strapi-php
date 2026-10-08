<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Providers\LocalSource;

use Strapi\Core\Strapi;
use Strapi\DataTransfer\Strapi\Queries\Entity;
use Strapi\DataTransfer\Strapi\Queries\Stream as QueryStream;

/**
 * Port of src/strapi/providers/local-source/entities.ts.
 */
final class Entities
{
    /**
     * Generate and consume content-types streams in order to stream each entity individually:
     * yields `['entity' => row, 'contentType' => Schema]`.
     *
     * @param array{onWarning?: callable(string): void} $options
     *
     * @return \Generator<int, array{entity: array<string, mixed>, contentType: \Strapi\Types\Schema\Schema}>
     */
    public static function createEntitiesStream(Strapi $strapi, array $options = []): \Generator
    {
        foreach ($strapi->contentTypes() as $contentType) {
            $query = Entity::createEntityQuery($strapi)($contentType->uid);

            $populate = $query->deepPopulateComponentLikeQuery();
            $stream = QueryStream::rows($strapi, $contentType->uid, $populate);

            try {
                foreach ($stream as $entity) {
                    yield ['entity' => $entity, 'contentType' => $contentType];
                }
            } catch (\Throwable $error) {
                // Surface the failure instead of silently dropping the remaining
                // entities of this content type: every entity skipped here leaves
                // dangling links pointing at rows that were never transferred
                if (isset($options['onWarning'])) {
                    ($options['onWarning'])("Failed to read all entities of type \"{$contentType->uid}\" from the source, the remaining entities of this type were skipped: {$error->getMessage()}");
                }
            }
        }
    }

    /**
     * Create an entity transform which converts the output of the multi-content-types stream to
     * the transfer entity format
     *
     * @return \Closure(array{entity: array<string, mixed>, contentType: \Strapi\Types\Schema\Schema}): array{type: string, id: mixed, data: array<string, mixed>}
     */
    public static function createEntitiesTransformStream(): \Closure
    {
        return static function (array $data): array {
            ['entity' => $entity, 'contentType' => $contentType] = $data;
            $id = $entity['id'] ?? null;
            unset($entity['id']);

            return [
                'type' => $contentType->uid,
                'id' => $id,
                'data' => $entity,
            ];
        };
    }
}
