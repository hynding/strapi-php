<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Queries;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ExtendTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Builders\Builders;
use Strapi\Plugin\Graphql\Services\Builders\BuildersInstance;
use Strapi\Plugin\Graphql\Services\Extension\Extension;
use Strapi\Plugin\Graphql\Services\Format\Format;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/builders/queries/collection-type.ts */
final class CollectionType
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    private function utils(): Utils
    {
        $utils = $this->strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);

        return $utils;
    }

    private function builders(): Builders
    {
        $builders = $this->strapi->plugin('graphql')->service('builders');
        \assert($builders instanceof Builders);

        return $builders;
    }

    private function contentApiBuilders(): BuildersInstance
    {
        $builders = $this->builders()->get('content-api');
        if ($builders === null) {
            throw new \RuntimeException('The content-api builders are not initialized');
        }

        return $builders;
    }

    public function buildCollectionTypeQueries(Schema $contentType): ExtendTypeDef
    {
        $naming = $this->utils()->naming;
        $findOneQueryName = 'Query.' . $naming->getFindOneQueryName($contentType);
        $findQueryName = 'Query.' . $naming->getFindQueryName($contentType);
        $findConnectionQueryName = 'Query.' . $naming->getFindConnectionQueryName($contentType);

        $extension = $this->strapi->plugin('graphql')->service('extension');
        \assert($extension instanceof Extension);

        $registerAuthConfig = static fn (string $action, mixed $auth): Extension => $extension->use(['resolversConfig' => [$action => ['auth' => $auth]]]);

        $isActionEnabled = static fn (string $action): bool => $extension->shadowCRUD($contentType->uid)->isActionEnabled($action);

        $isFindOneEnabled = $isActionEnabled('findOne');
        $isFindEnabled = $isActionEnabled('find');

        if ($isFindOneEnabled) {
            $registerAuthConfig($findOneQueryName, ['scope' => ["{$contentType->uid}.findOne"]]);
        }

        if ($isFindEnabled) {
            $registerAuthConfig($findQueryName, ['scope' => ["{$contentType->uid}.find"]]);
            $registerAuthConfig($findConnectionQueryName, ['scope' => ["{$contentType->uid}.find"]]);
        }

        return Nexus::extendType([
            'type' => 'Query',

            'definition' => function (OutputDefinitionBlock $t) use ($contentType, $isFindOneEnabled, $isFindEnabled): void {
                if ($isFindOneEnabled) {
                    $this->addFindOneQuery($t, $contentType);
                }

                if ($isFindEnabled) {
                    $this->addFindConnectionQuery($t, $contentType);
                    $this->addFindQuery($t, $contentType);
                }
            },
        ]);
    }

    /**
     * Register a "find one" query field to the nexus type definition
     */
    private function addFindOneQuery(OutputDefinitionBlock $t, Schema $contentType): void
    {
        $naming = $this->utils()->naming;
        $findOneQueryName = $naming->getFindOneQueryName($contentType);
        $typeName = $naming->getTypeName($contentType);
        $builders = $this->builders();

        $t->field($findOneQueryName, [
            'type' => $typeName,

            'extensions' => [
                'strapi' => [
                    'contentType' => $contentType,
                ],
            ],

            'args' => $builders->utils->getContentTypeArgs($contentType, ['multiple' => false]),

            'resolve' => function (mixed $parent, array $args, mixed $ctx) use ($builders, $contentType): mixed {
                $transformedArgs = $builders->utils->transformArgs($args, ['contentType' => $contentType]);

                $queriesResolvers = $this->contentApiBuilders()->buildQueriesResolvers(['contentType' => $contentType]);

                // queryResolvers will sanitize params
                return $queriesResolvers->findOne($parent, $transformedArgs, $ctx);
            },
        ]);
    }

    /**
     * Register a "find" query field to the nexus type definition
     */
    private function addFindQuery(OutputDefinitionBlock $t, Schema $contentType): void
    {
        $naming = $this->utils()->naming;
        $findQueryName = $naming->getFindQueryName($contentType);
        $typeName = $naming->getTypeName($contentType);
        $builders = $this->builders();

        $t->field($findQueryName, [
            'type' => Nexus::nonNull(Nexus::list($typeName)),

            'extensions' => [
                'strapi' => [
                    'contentType' => $contentType,
                ],
            ],

            'args' => $builders->utils->getContentTypeArgs($contentType),

            'resolve' => function (mixed $parent, array $args, mixed $ctx) use ($builders, $contentType): mixed {
                $transformedArgs = $builders->utils->transformArgs($args, ['contentType' => $contentType, 'usePagination' => true]);

                $queriesResolvers = $this->contentApiBuilders()->buildQueriesResolvers(['contentType' => $contentType]);

                // queryResolvers will sanitize params
                return $queriesResolvers->findMany($parent, $transformedArgs, $ctx);
            },
        ]);
    }

    /**
     * Register a "find" query field to the nexus type definition
     */
    private function addFindConnectionQuery(OutputDefinitionBlock $t, Schema $contentType): void
    {
        $uid = $contentType->uid;
        $naming = $this->utils()->naming;
        $builders = $this->builders();
        $format = $this->strapi->plugin('graphql')->service('format');
        \assert($format instanceof Format);

        $queryName = $naming->getFindConnectionQueryName($contentType);
        $responseCollectionTypeName = $naming->getEntityResponseCollectionName($contentType);

        $t->field($queryName, [
            'type' => $responseCollectionTypeName,

            'extensions' => [
                'strapi' => [
                    'contentType' => $contentType,
                ],
            ],

            'args' => $builders->utils->getContentTypeArgs($contentType),

            'resolve' => function (mixed $parent, array $args, mixed $ctx) use ($builders, $contentType, $format, $uid): mixed {
                $transformedArgs = $builders->utils->transformArgs($args, ['contentType' => $contentType, 'usePagination' => true]);

                $queriesResolvers = $this->contentApiBuilders()->buildQueriesResolvers(['contentType' => $contentType]);

                // queryResolvers will sanitize params
                $nodes = $queriesResolvers->findMany($parent, $transformedArgs, $ctx);

                return $format->returnTypes->toEntityResponseCollection($nodes, ['args' => $transformedArgs, 'resourceUID' => $uid]);
            },
        ]);
    }
}
