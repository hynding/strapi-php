<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ObjectTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Constants;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/builders/response-collection.ts */
final class ResponseCollection
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Build a type definition for a content API collection response for a given content type
     */
    public function buildResponseCollectionDefinition(Schema $contentType): ObjectTypeDef
    {
        $strapi = $this->strapi;
        $utils = $strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);

        $name = $utils->naming->getEntityResponseCollectionName($contentType);
        $typeName = $utils->naming->getTypeName($contentType);
        $builders = $strapi->plugin('graphql')->service('builders');
        \assert($builders instanceof Builders);
        $contentApiBuilders = $builders->get('content-api');
        if ($contentApiBuilders === null) {
            throw new \RuntimeException('The content-api builders are not initialized');
        }
        $resolvePagination = $contentApiBuilders->resolvePagination(...);
        $nodes = static fn (mixed $parent): mixed => (is_array($parent) ? ($parent['nodes'] ?? null) : null) ?? [];

        return Nexus::objectType([
            'name' => $name,
            'definition' => static function (OutputDefinitionBlock $t) use ($strapi, $typeName, $resolvePagination, $nodes): void {
                // NOTE: add edges & cursor based pagination to support the relay spec in a later version

                $t->nonNull->list->field('nodes', [
                    'type' => Nexus::nonNull($typeName),
                    'resolve' => $nodes,
                ]);

                $t->nonNull->field('pageInfo', [
                    'type' => Constants::PAGINATION_TYPE_NAME,
                    'resolve' => $resolvePagination,
                ]);

                if ($strapi->plugin('graphql')->config('v4CompatibilityMode', false)) {
                    $t->nonNull->list->field('data', [
                        'deprecation' => 'Use `nodes` field instead',
                        'type' => Nexus::nonNull($typeName),
                        'resolve' => $nodes,
                    ]);

                    $t->nonNull->field('meta', [
                        'deprecation' => 'Use the `pageInfo` field instead',
                        'type' => Constants::RESPONSE_COLLECTION_META_TYPE_NAME,
                        'resolve' => static fn (mixed $parent): mixed => $parent,
                    ]);
                }
            },
        ]);
    }
}
