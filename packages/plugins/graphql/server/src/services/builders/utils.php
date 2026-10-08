<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Internals\Internals;
use Strapi\Plugin\Graphql\Services\Utils\Utils as GraphqlUtils;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\Pagination;
use Strapi\Utils\SortQuery;

/** Port of server/src/services/builders/utils.ts */
final class Utils
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    private function utils(): GraphqlUtils
    {
        $utils = $this->strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof GraphqlUtils);

        return $utils;
    }

    /**
     * @param array{multiple?: bool, isNested?: bool} $options
     * @return array<string, mixed>|null
     */
    public function getContentTypeArgs(Schema $contentType, array $options = []): ?array
    {
        $multiple = $options['multiple'] ?? true;
        $isNested = $options['isNested'] ?? false;

        $naming = $this->utils()->naming;
        $internals = $this->strapi->plugin('graphql')->service('internals');
        \assert($internals instanceof Internals);
        $args = $internals->args;

        $modelType = $contentType->modelType;

        // Components
        if ($modelType === 'component') {
            if (!$multiple) {
                return [];
            }

            return [
                'filters' => $naming->getFiltersInputTypeName($contentType),
                'pagination' => $args->PaginationArg,
                'sort' => $args->SortArg,
            ];
        }

        $kind = $contentType->kind;

        // On non–D&P roots (e.g. User) these args do not version the parent document, but they
        // are required: association resolvers inherit them into nested D&P relations (see
        // builders/resolvers/association.ts + rootQueryArgsByPath). Omitting them broke
        // draft/published control for populated relations (e.g. github.com/strapi/strapi/issues/25746).
        $publicationArgs = [
            'status' => $args->PublicationStatusArg,
            // Deprecated: prefer `publicationFilter` (enum cohorts).
            'hasPublishedVersion' => $args->HasPublishedVersionArg,
            'publicationFilter' => $args->PublicationFilterArg,
        ];

        // Collection Types
        if ($kind === 'collectionType') {
            if (!$multiple) {
                return [
                    'documentId' => Nexus::nonNull(Nexus::idArg()),
                    ...$publicationArgs,
                ];
            }

            $params = [
                'filters' => $naming->getFiltersInputTypeName($contentType),
                'pagination' => $args->PaginationArg,
                'sort' => $args->SortArg,
            ];

            if (!$isNested) {
                $params = [...$params, ...$publicationArgs];
            }

            return $params;
        }

        // Single Types
        if ($kind === 'singleType') {
            $params = [];

            if (!$isNested) {
                $params = [...$params, ...$publicationArgs];
            }

            return $params;
        }

        return null;
    }

    /**
     * Filter an object entries and keep only those whose value is a unique scalar attribute
     *
     * @param array<string, array<string, mixed>> $attributes
     * @return array<string, array<string, mixed>>
     */
    public function getUniqueScalarAttributes(array $attributes): array
    {
        $isStrapiScalar = $this->utils()->attributes->isStrapiScalar(...);

        return array_filter(
            $attributes,
            static fn (array $attribute): bool => $isStrapiScalar($attribute) && ($attribute['unique'] ?? false) === true,
        );
    }

    /**
     * Map each value from an attribute to a FiltersInput type name
     *
     * @param array<string, array<string, mixed>> $attributes - The attributes object to transform
     * @return array<string, string>
     */
    public function scalarAttributesToFiltersMap(array $attributes): array
    {
        $utils = $this->utils();

        return array_map(static function (array $attribute) use ($utils): string {
            $gqlScalar = (string) $utils->mappers->strapiScalarToGraphQLScalar($attribute['type'] ?? null);

            return $utils->naming->getScalarFilterInputTypeName($gqlScalar);
        }, $attributes);
    }

    /**
     * Apply basic transform to GQL args
     *
     * @param array<string, mixed> $args
     * @param array{contentType: Schema|null, usePagination?: bool} $options
     * @return array<string, mixed>
     */
    public function transformArgs(array $args, array $options): array
    {
        $contentType = $options['contentType'];
        $usePagination = $options['usePagination'] ?? false;

        $mappers = $this->utils()->mappers;
        $config = $this->strapi->plugin('graphql');
        $pagination = is_array($args['pagination'] ?? null) ? $args['pagination'] : [];
        $filters = $args['filters'] ?? [];

        // Init
        $newArgs = $args;
        unset($newArgs['pagination'], $newArgs['filters']);

        // Pagination
        if ($usePagination) {
            $defaultLimit = $config->config('defaultLimit');
            $maxLimit = $config->config('maxLimit');

            $defaults = [];
            if (is_int($defaultLimit)) {
                $defaults = [
                    'offset' => ['limit' => $defaultLimit],
                    'page' => ['pageSize' => $defaultLimit],
                ];
            }

            $newArgs = [
                ...$newArgs,
                ...Pagination::withDefaultPagination($pagination, $defaults, is_int($maxLimit) ? $maxLimit : -1),
            ];
        }

        // Filters
        if (($args['filters'] ?? null) !== null && $args['filters'] !== false) {
            $newArgs['filters'] = $mappers->graphQLFiltersToStrapiQuery($filters, $contentType);
        }

        // GraphQL SortArg defaults to `[]`; omit meaningless sort so join-table UI order is preserved.
        if (!SortQuery::hasSort($newArgs['sort'] ?? null)) {
            unset($newArgs['sort']);
        }

        return $newArgs;
    }
}
