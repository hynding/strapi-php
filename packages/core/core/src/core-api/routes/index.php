<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Routes;

use Strapi\Core\CoreApi\Routes\Validation\CoreContentTypeRouteValidator;
use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Zod as z;

/**
 * Port of core-api/routes/index.ts (`createRoutes`): the default REST routes of a content type,
 * with their Zod request (`query`, `params`, `body`) and `response` schemas
 * (core-api/routes/validation, `CoreContentTypeRouteValidator`).
 */
final class Routes
{
    /** @return array<string, array<string, mixed>> keyed by route name (find, findOne, create, update, delete) */
    public static function createRoutes(Strapi $strapi, Schema $contentType): array
    {
        if (ContentTypes::isSingleType($contentType)) {
            return self::getSingleTypeRoutes($strapi, $contentType);
        }

        return self::getCollectionTypeRoutes($strapi, $contentType);
    }

    /** @return array<string, array<string, mixed>> */
    private static function getSingleTypeRoutes(Strapi $strapi, Schema $schema): array
    {
        $uid = $schema->uid;
        $singularName = (string) $schema->info['singularName'];

        $validator = new CoreContentTypeRouteValidator($strapi, $uid);
        $conditional = self::getConditionalQueryParams($strapi, $schema);

        return [
            'find' => [
                'method' => 'GET',
                'path' => "/{$singularName}",
                'handler' => "{$uid}.find",
                'request' => [
                    'query' => $validator->queryParams(['fields', 'populate', 'filters', ...$conditional]),
                ],
                'response' => z::object(['data' => $validator->document()]),
                'config' => [],
            ],
            'update' => [
                'method' => 'PUT',
                'path' => "/{$singularName}",
                'handler' => "{$uid}.update",
                'request' => [
                    'query' => $validator->queryParams(['fields', 'populate', ...$conditional]),
                    'body' => ['application/json' => $validator->partialBody()],
                ],
                'response' => z::object(['data' => $validator->document()]),
                'config' => [],
            ],
            'delete' => [
                'method' => 'DELETE',
                'path' => "/{$singularName}",
                'handler' => "{$uid}.delete",
                'request' => [
                    'query' => $validator->queryParams(['fields', 'populate', ...$conditional]),
                ],
                'response' => z::object(['data' => $validator->document()]),
                'config' => [],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function getCollectionTypeRoutes(Strapi $strapi, Schema $schema): array
    {
        $uid = $schema->uid;
        $pluralName = (string) $schema->info['pluralName'];

        $validator = new CoreContentTypeRouteValidator($strapi, $uid);
        $conditional = self::getConditionalQueryParams($strapi, $schema);

        return [
            'find' => [
                'method' => 'GET',
                'path' => "/{$pluralName}",
                'handler' => "{$uid}.find",
                'request' => [
                    'query' => $validator->queryParams(['fields', 'filters', '_q', 'pagination', 'sort', 'populate', ...$conditional]),
                ],
                'response' => z::object(['data' => $validator->documents()]),
                'config' => [],
            ],
            'findOne' => [
                'method' => 'GET',
                'path' => "/{$pluralName}/:id",
                'handler' => "{$uid}.findOne",
                'request' => [
                    'params' => ['id' => $validator->documentID()],
                    'query' => $validator->queryParams(['fields', 'populate', 'filters', 'sort', ...$conditional]),
                ],
                'response' => z::object(['data' => $validator->document()]),
            ],
            'create' => [
                'method' => 'POST',
                'path' => "/{$pluralName}",
                'handler' => "{$uid}.create",
                'request' => [
                    'query' => $validator->queryParams(['fields', 'populate', ...$conditional]),
                    'body' => ['application/json' => $validator->body()],
                ],
                'response' => z::object(['data' => $validator->document()]),
                'config' => [],
            ],
            'update' => [
                'method' => 'PUT',
                'path' => "/{$pluralName}/:id",
                'handler' => "{$uid}.update",
                'request' => [
                    'query' => $validator->queryParams(['fields', 'populate', ...$conditional]),
                    'params' => ['id' => $validator->documentID()],
                    'body' => ['application/json' => $validator->partialBody()],
                ],
                'response' => z::object(['data' => $validator->document()]),
            ],
            'delete' => [
                'method' => 'DELETE',
                'path' => "/{$pluralName}/:id",
                'handler' => "{$uid}.delete",
                'request' => [
                    'query' => $validator->queryParams(['fields', 'populate', 'filters', ...$conditional]),
                    'params' => ['id' => $validator->documentID()],
                ],
                'response' => z::object(['data' => $validator->document()]),
            ],
        ];
    }

    /**
     * Query params that are conditionally part of this route's contract based on the content type:
     * locale only for localized types, status only for draft & publish.
     *
     * @return list<string>
     */
    private static function getConditionalQueryParams(Strapi $strapi, Schema $schema): array
    {
        $isLocalized = $strapi->localization()->isLocalizedContentType($schema);
        $hasDraftAndPublish = ContentTypes::hasDraftAndPublish($schema);

        return [
            ...($isLocalized ? ['locale'] : []),
            ...($hasDraftAndPublish ? ['status', 'publicationFilter', 'hasPublishedVersion'] : []),
        ];
    }
}
