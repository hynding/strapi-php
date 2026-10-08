<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Utils\Mappers;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Services\Builders\Builders;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Types\Schema\Schema;

/** Port of server/src/services/utils/mappers/graphql-filters-to-strapi-query.ts */
final class GraphqlFiltersToStrapiQuery
{
    // todo[v4]: Find a way to get that dynamically
    private const array VIRTUAL_SCALAR_ATTRIBUTES = ['id', 'documentId'];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    private function builders(): Builders
    {
        $builders = $this->strapi->plugin('graphql')->service('builders');
        \assert($builders instanceof Builders);

        return $builders;
    }

    private function recursivelyReplaceScalarOperators(mixed $data): mixed
    {
        $operators = $this->builders()->filters->operators;

        if (is_array($data) && array_is_list($data)) {
            return array_map(fn (mixed $item): mixed => $this->recursivelyReplaceScalarOperators($item), $data);
        }

        // Note: We need to make an exception for date since GraphQL
        // automatically cast date strings to date instances in args
        if ($data instanceof \DateTimeInterface || !is_array($data)) {
            return $data;
        }

        $result = [];

        foreach ($data as $key => $value) {
            $operator = $operators[(string) $key] ?? null;

            $newKey = $operator !== null ? $operator->strapiOperator : $key;

            $result[$newKey] = $this->recursivelyReplaceScalarOperators($value);
        }

        return $result;
    }

    /**
     * Transform one or many GraphQL filters object into a valid Strapi query
     */
    public function graphQLFiltersToStrapiQuery(mixed $filters, ?Schema $contentType): mixed
    {
        $utils = $this->strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);
        $attributesUtils = $utils->attributes;
        $operators = $this->builders()->filters->operators;

        $rootLevelOperators = [$operators['and'], $operators['or'], $operators['not']];

        // Handle unwanted scenario where there is no filters defined
        if ($filters === null) {
            return [];
        }

        // If filters is a collection, then apply the transformation to every item of the list
        if (is_array($filters) && array_is_list($filters) && $filters !== []) {
            return array_map(fn (mixed $filtersItem): mixed => $this->graphQLFiltersToStrapiQuery($filtersItem, $contentType), $filters);
        }

        if (!is_array($filters)) {
            return [];
        }

        $resultMap = [];
        $attributes = $contentType !== null ? $contentType->attributes : [];

        $isAttribute = static fn (string $attributeName): bool => in_array($attributeName, self::VIRTUAL_SCALAR_ATTRIBUTES, true) || array_key_exists($attributeName, $attributes);

        foreach ($filters as $key => $value) {
            $key = (string) $key;

            // If the key is an attribute, update the value
            if ($isAttribute($key)) {
                $attribute = $attributes[$key] ?? [];

                // If it's a scalar attribute
                if (in_array($key, self::VIRTUAL_SCALAR_ATTRIBUTES, true) || $attributesUtils->isStrapiScalar($attribute)) {
                    // Replace (recursively) every GraphQL scalar operator with the associated Strapi operator
                    $resultMap[$key] = $this->recursivelyReplaceScalarOperators($value);
                }

                // If it's a deep filter on a relation
                elseif ($attributesUtils->isRelation($attribute) || $attributesUtils->isMedia($attribute)) {
                    // Fetch the model from the relation
                    $target = $attributesUtils->isMedia($attribute) ? 'plugin::upload.file' : (string) ($attribute['target'] ?? '');
                    $relModel = $this->strapi->getModel($target);

                    // Recursively apply the mapping to the value using the fetched model,
                    // and update the value within `resultMap`
                    $resultMap[$key] = $this->graphQLFiltersToStrapiQuery($value, $relModel);
                }

                // If it's a deep filter on a component
                elseif ($attributesUtils->isComponent($attribute)) {
                    // Fetch the model from the component attribute
                    $componentModel = $this->strapi->getModel((string) ($attribute['component'] ?? ''));

                    // Recursively apply the mapping to the value using the fetched model,
                    // and update the value within `resultMap`
                    $resultMap[$key] = $this->graphQLFiltersToStrapiQuery($value, $componentModel);
                }
            }

            // Handle the case where the key is not an attribute (operator, ...)
            else {
                $rootLevelOperator = null;
                foreach ($rootLevelOperators as $operator) {
                    if ($operator->fieldName === $key) {
                        $rootLevelOperator = $operator;
                        break;
                    }
                }

                // If it's a root level operator (AND, NOT, OR, ...)
                if ($rootLevelOperator !== null) {
                    $strapiOperator = $rootLevelOperator->strapiOperator;

                    // Transform the current value recursively and add it to the resultMap
                    // object using the strapiOperator equivalent of the GraphQL key
                    $resultMap[$strapiOperator] = $this->graphQLFiltersToStrapiQuery($value, $contentType);
                }
            }
        }

        return $resultMap;
    }
}
