<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\ContentApi\RegisterFunctions;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Services\Builders\BuildersInstance;
use Strapi\Plugin\Graphql\Services\Constants;
use Strapi\Plugin\Graphql\Services\Extension\Extension;
use Strapi\Plugin\Graphql\Services\TypeRegistry;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/content-api/register-functions/collection-type.ts */
final class CollectionType
{
    /** @param array{registry: TypeRegistry, strapi: Strapi, builders: BuildersInstance} $options */
    public static function registerCollectionType(Schema $contentType, array $options): void
    {
        ['registry' => $registry, 'strapi' => $strapi, 'builders' => $builders] = $options;

        $utils = $strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);
        $naming = $utils->naming;
        $KINDS = Constants::KINDS;

        $extension = $strapi->plugin('graphql')->service('extension');
        \assert($extension instanceof Extension);

        // Types name (as string)
        $types = [
            'base' => $naming->getTypeName($contentType),
            'entity' => $naming->getEntityName($contentType),
            'response' => $naming->getEntityResponseName($contentType),
            'responseCollection' => $naming->getEntityResponseCollectionName($contentType),
            'relationResponseCollection' => $naming->getRelationResponseCollectionName($contentType),
            'queries' => $naming->getEntityQueriesTypeName($contentType),
            'mutations' => $naming->getEntityMutationsTypeName($contentType),
        ];

        $getConfig = static fn (string $kind): array => ['kind' => $kind, 'contentType' => $contentType];

        // Type definition
        $registry->register($types['base'], $builders->buildTypeDefinition($contentType), $getConfig($KINDS['type']));

        // Higher level entity definition
        $registry->register(
            $types['entity'],
            $builders->buildEntityDefinition($contentType),
            $getConfig($KINDS['entity']),
        );

        // Responses definition
        $registry->register(
            $types['response'],
            $builders->buildResponseDefinition($contentType),
            $getConfig($KINDS['entityResponse']),
        );

        $registry->register(
            $types['responseCollection'],
            $builders->buildResponseCollectionDefinition($contentType),
            $getConfig($KINDS['entityResponseCollection']),
        );

        $registry->register(
            $types['relationResponseCollection'],
            $builders->buildRelationResponseCollectionDefinition($contentType),
            $getConfig($KINDS['relationResponseCollection']),
        );

        if ($extension->shadowCRUD($contentType->uid)->areQueriesEnabled()) {
            // Query extensions
            $registry->register(
                $types['queries'],
                $builders->buildCollectionTypeQueries($contentType),
                $getConfig($KINDS['query']),
            );
        }

        if ($extension->shadowCRUD($contentType->uid)->areMutationsEnabled()) {
            // Mutation extensions
            $registry->register(
                $types['mutations'],
                $builders->buildCollectionTypeMutations($contentType),
                $getConfig($KINDS['mutation']),
            );
        }
    }
}
