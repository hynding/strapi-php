<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Resolvers;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\GraphqlContext;
use Strapi\Plugin\Graphql\Services\Builders\Builders;

/** Port of server/src/services/builders/resolvers/component.ts */
final class Component
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @param array{contentTypeUID: string, attributeName: string} $options */
    public function buildComponentResolver(array $options): \Closure
    {
        $strapi = $this->strapi;
        ['contentTypeUID' => $contentTypeUID, 'attributeName' => $attributeName] = $options;

        return static function (mixed $parent, array $args, mixed $ctx) use ($strapi, $contentTypeUID, $attributeName): mixed {
            $builders = $strapi->plugin('graphql')->service('builders');
            \assert($builders instanceof Builders);

            $contentType = $strapi->getModel($contentTypeUID);

            $componentName = (string) ($contentType?->attributes[$attributeName]['component'] ?? '');

            $component = $strapi->getModel($componentName);
            if ($component === null || !is_array($parent)) {
                return null;
            }

            $auth = GraphqlContext::authOf($ctx);
            $transformedArgs = $builders->utils->transformArgs($args, ['contentType' => $component, 'usePagination' => true]);
            $strapi->contentAPI()->validate()->query($transformedArgs, $component, ['auth' => $auth]);

            $sanitizedQuery = $strapi->contentAPI()->sanitize()->query($transformedArgs, $component, ['auth' => $auth]);

            $dbQuery = $strapi->get('query-params')->transform($component->uid, $sanitizedQuery);

            return $strapi->db()->query($contentTypeUID)->load($parent, $attributeName, $dbQuery);
        };
    }
}
