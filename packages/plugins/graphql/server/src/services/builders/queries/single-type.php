<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Queries;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ExtendTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Builders\Builders;
use Strapi\Plugin\Graphql\Services\Extension\Extension;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/builders/queries/single-type.ts */
final class SingleType
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

    public function buildSingleTypeQueries(Schema $contentType): ExtendTypeDef
    {
        $findQueryName = 'Query.' . $this->utils()->naming->getFindOneQueryName($contentType);

        $extension = $this->strapi->plugin('graphql')->service('extension');
        \assert($extension instanceof Extension);

        $registerAuthConfig = static fn (string $action, mixed $auth): Extension => $extension->use(['resolversConfig' => [$action => ['auth' => $auth]]]);

        $isActionEnabled = static fn (string $action): bool => $extension->shadowCRUD($contentType->uid)->isActionEnabled($action);

        $isFindEnabled = $isActionEnabled('find');

        if ($isFindEnabled) {
            $registerAuthConfig($findQueryName, ['scope' => ["{$contentType->uid}.find"]]);
        }

        return Nexus::extendType([
            'type' => 'Query',

            'definition' => function (OutputDefinitionBlock $t) use ($contentType, $isFindEnabled): void {
                if ($isFindEnabled) {
                    $this->addFindQuery($t, $contentType);
                }
            },
        ]);
    }

    private function addFindQuery(OutputDefinitionBlock $t, Schema $contentType): void
    {
        $naming = $this->utils()->naming;
        $findQueryName = $naming->getFindOneQueryName($contentType);
        $typeName = $naming->getTypeName($contentType);
        $builders = $this->strapi->plugin('graphql')->service('builders');
        \assert($builders instanceof Builders);

        $t->field($findQueryName, [
            'type' => $typeName,

            'extensions' => [
                'strapi' => [
                    'contentType' => $contentType,
                ],
            ],

            'args' => $builders->utils->getContentTypeArgs($contentType),

            'resolve' => static function (mixed $parent, array $args, mixed $ctx) use ($builders, $contentType): mixed {
                $transformedArgs = $builders->utils->transformArgs($args, ['contentType' => $contentType]);

                $contentApiBuilders = $builders->get('content-api');
                if ($contentApiBuilders === null) {
                    throw new \RuntimeException('The content-api builders are not initialized');
                }

                return $contentApiBuilders->buildQueriesResolvers(['contentType' => $contentType])->findFirst($parent, $transformedArgs, $ctx);
            },
        ]);
    }
}
