<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Resolvers;

use GraphQL\Type\Definition\ResolveInfo;
use Strapi\Core\Services\DocumentService\PublicationFilter as DocumentPublicationFilter;
use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\GraphqlContext;
use Strapi\Plugin\Graphql\Services\Builders\Builders;
use Strapi\Plugin\Graphql\Services\ContentApi\ContentApi;
use Strapi\Plugin\Graphql\Services\Format\Format;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Primitives\Objects;
use Strapi\Utils\PublicationFilter;

/** Port of server/src/services/builders/resolvers/association.ts */
final class Association
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @param array{contentTypeUID: string, attributeName: string} $options */
    public function buildAssociationResolver(array $options): \Closure
    {
        $strapi = $this->strapi;
        ['contentTypeUID' => $contentTypeUID, 'attributeName' => $attributeName] = $options;

        $utils = $strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);
        $builders = $strapi->plugin('graphql')->service('builders');
        \assert($builders instanceof Builders);
        $format = $strapi->plugin('graphql')->service('format');
        \assert($format instanceof Format);
        $returnTypes = $format->returnTypes;

        $contentType = $strapi->getModel($contentTypeUID);
        $attribute = $contentType?->attributes[$attributeName] ?? null;

        if ($contentType === null || !is_array($attribute)) {
            throw new ApplicationError("Failed to build an association resolver for {$contentTypeUID}::{$attributeName}");
        }

        $isMediaAttribute = $utils->attributes->isMedia($attribute);
        $isMorphAttribute = $utils->attributes->isMorphRelation($attribute);

        $targetUID = $isMediaAttribute ? 'plugin::upload.file' : (string) ($attribute['target'] ?? '');
        $isToMany = $isMediaAttribute ? ($attribute['multiple'] ?? false) === true : str_ends_with((string) ($attribute['relation'] ?? ''), 'Many');

        $targetContentType = $strapi->getModel($targetUID);

        return static function (mixed $parent, array $args, mixed $context, ?ResolveInfo $info = null) use ($strapi, $builders, $returnTypes, $contentType, $contentTypeUID, $attributeName, $targetUID, $targetContentType, $isMediaAttribute, $isMorphAttribute, $isToMany): mixed {
            $auth = GraphqlContext::authOf($context);

            $transformedArgs = $builders->utils->transformArgs($args, [
                'contentType' => $targetContentType,
                'usePagination' => true,
            ]);

            $strapi->contentAPI()->validate()->query($transformedArgs, $targetContentType, [
                'auth' => $auth,
            ]);

            $sanitizedQuery = $strapi->contentAPI()->sanitize()->query(
                $transformedArgs,
                $targetContentType,
                [
                    'auth' => $auth,
                ],
            );
            $transformedQuery = $strapi->get('query-params')->transform($targetUID, $sanitizedQuery);

            $isTargetDraftAndPublishContentType = ContentTypes::hasDraftAndPublish($targetContentType);

            // Helper to check if a field is from built-in queries (not custom resolvers)
            // Use the precomputed lookup populated by the content-api service at schema build time.
            $isBuiltInQueryField = static function (mixed $fieldName) use ($strapi): bool {
                $graphqlService = $strapi->plugin('graphql')->service('content-api');
                \assert($graphqlService instanceof ContentApi);

                return is_string($fieldName) && $graphqlService->isBuiltInQueryField($fieldName);
            };

            // Walk back to the root of info.path so we pick up the args of *our* query branch
            $rootKey = $info !== null && $info->path !== [] ? $info->path[0] : null;
            $rootQueryArgsByPath = $context instanceof GraphqlContext ? $context->rootQueryArgsByPath : (is_array($context) && is_array($context['rootQueryArgsByPath'] ?? null) ? $context['rootQueryArgsByPath'] : null);
            $rootQueryArgs = $rootKey !== null ? ($rootQueryArgsByPath[$rootKey] ?? null) : null;

            $shouldInheritRootQueryStatus = is_array($rootQueryArgs)
                && ($rootQueryArgs['_originField'] ?? null) !== null
                && $isBuiltInQueryField($rootQueryArgs['_originField']);

            // Only inherit status from built-in queries to avoid conflicts with custom resolvers.
            // Built-in root queries default to published results; draft/preview queries pass `status`.
            // Nested relations should match that parent query.
            $inheritedStatus = $shouldInheritRootQueryStatus
                ? (($rootQueryArgs['status'] ?? null) ?: 'published')
                : null;

            $statusToApply = ($args['status'] ?? null) ?: $inheritedStatus;

            $defaultFilters = $isTargetDraftAndPublishContentType && $statusToApply
                ? [
                    'where' => [
                        'publishedAt' => $statusToApply === 'published' ? ['$notNull' => true] : ['$null' => true],
                    ],
                ]
                : [];

            $inheritedPublicationFilter = is_array($rootQueryArgs)
                && array_key_exists('publicationFilter', $rootQueryArgs) && $rootQueryArgs['publicationFilter'] !== null
                && ($rootQueryArgs['_originField'] ?? null) !== null
                && $isBuiltInQueryField($rootQueryArgs['_originField'])
                ? $rootQueryArgs['publicationFilter']
                : null;

            $inheritedHasPublishedVersion = is_array($rootQueryArgs)
                && array_key_exists('hasPublishedVersion', $rootQueryArgs) && $rootQueryArgs['hasPublishedVersion'] !== null
                && ($rootQueryArgs['_originField'] ?? null) !== null
                && $isBuiltInQueryField($rootQueryArgs['_originField'])
                ? $rootQueryArgs['hasPublishedVersion']
                : null;

            $effectivePublicationFilter = $inheritedPublicationFilter;
            if ($effectivePublicationFilter === null && $inheritedHasPublishedVersion !== null) {
                $effectivePublicationFilter = $inheritedHasPublishedVersion
                    ? 'has-published-version-document'
                    : 'never-published-document';
            }

            $publicationFilterWhere = [];
            if ($isTargetDraftAndPublishContentType && $effectivePublicationFilter !== null) {
                try {
                    $mode = PublicationFilter::parsePublicationFilter($effectivePublicationFilter);
                } catch (\Throwable) {
                    $mode = null;
                }
                if ($mode !== null) {
                    $st = $statusToApply === 'published' ? 'published' : 'draft';
                    $cond = DocumentPublicationFilter::getPublicationFilterCondition($strapi, $targetUID, $mode, $st);
                    if ($cond !== null && $cond !== []) {
                        $publicationFilterWhere = ['where' => $cond];
                    }
                }
            }

            $dbQuery = Objects::merge(Objects::merge($defaultFilters, $publicationFilterWhere), $transformedQuery);

            if (!is_array($parent)) {
                return null;
            }

            // Sign media URLs if upload plugin is available and using private provider
            $rawData = $strapi->db()->query($contentTypeUID)->load($parent, $attributeName, $dbQuery);
            $data = $rawData;
            if ($isMediaAttribute && $strapi->hasPlugin('upload')) {
                $fileService = $strapi->plugin('upload')->service('file');

                if (is_array($rawData) && array_is_list($rawData)) {
                    $data = array_map(static fn (mixed $item): mixed => is_array($item) && method_exists($fileService, 'signFileUrls') ? $fileService->signFileUrls($item) : $item, $rawData);
                } elseif (is_array($rawData) && method_exists($fileService, 'signFileUrls')) {
                    $data = $fileService->signFileUrls($rawData);
                }
            }

            $sanitizeInfo = [
                'args' => $sanitizedQuery,
                'resourceUID' => $targetUID,
            ];

            // If this a polymorphic association, it sanitizes & returns the raw data
            // Note: The value needs to be wrapped in a fake object that represents its parent
            // so that the sanitize util can work properly.
            if ($isMorphAttribute) {
                $sanitized = $strapi->contentAPI()->sanitize()->output([$attributeName => $data], $contentType, ['auth' => $auth]);

                return is_array($sanitized) ? ($sanitized[$attributeName] ?? null) : null;
            }

            // If this is a to-many relation, it returns an object that
            // matches what the entity-response-collection's resolvers expect
            if ($isToMany) {
                return $returnTypes->toEntityResponseCollection($data, $sanitizeInfo);
            }

            // Else, it returns an object that matches
            // what the entity-response's resolvers expect
            return $returnTypes->toEntityResponse($data, $sanitizeInfo);
        };
    }
}
