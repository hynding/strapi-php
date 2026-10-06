<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Routes;

use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;

/**
 * Port of core-api/routes/index.ts (`createRoutes`): the default REST routes of a content type.
 * The Zod request/response validators (core-api/routes/validation/*) are not ported; `request.query`
 * lists the accepted query params as keys so the strictParams allowlist keeps working.
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

    /** @param list<string> $params @return array<string, null> */
    private static function queryParams(array $params): array
    {
        return array_fill_keys($params, null);
    }

    /** @return array<string, array<string, mixed>> */
    private static function getSingleTypeRoutes(Strapi $strapi, Schema $schema): array
    {
        $uid = $schema->uid;
        $singularName = (string) $schema->info['singularName'];
        $conditional = self::getConditionalQueryParams($strapi, $schema);

        return [
            'find' => [
                'method' => 'GET',
                'path' => "/{$singularName}",
                'handler' => "{$uid}.find",
                'request' => ['query' => self::queryParams(['fields', 'populate', 'filters', ...$conditional])],
                'config' => [],
            ],
            'update' => [
                'method' => 'PUT',
                'path' => "/{$singularName}",
                'handler' => "{$uid}.update",
                'request' => ['query' => self::queryParams(['fields', 'populate', ...$conditional])],
                'config' => [],
            ],
            'delete' => [
                'method' => 'DELETE',
                'path' => "/{$singularName}",
                'handler' => "{$uid}.delete",
                'request' => ['query' => self::queryParams(['fields', 'populate', ...$conditional])],
                'config' => [],
            ],
        ];
    }

    /** @return array<string, array<string, mixed>> */
    private static function getCollectionTypeRoutes(Strapi $strapi, Schema $schema): array
    {
        $uid = $schema->uid;
        $pluralName = (string) $schema->info['pluralName'];
        $conditional = self::getConditionalQueryParams($strapi, $schema);

        return [
            'find' => [
                'method' => 'GET',
                'path' => "/{$pluralName}",
                'handler' => "{$uid}.find",
                'request' => ['query' => self::queryParams(['fields', 'filters', '_q', 'pagination', 'sort', 'populate', ...$conditional])],
                'config' => [],
            ],
            'findOne' => [
                'method' => 'GET',
                'path' => "/{$pluralName}/:id",
                'handler' => "{$uid}.findOne",
                'request' => ['params' => ['id' => null], 'query' => self::queryParams(['fields', 'populate', 'filters', 'sort', ...$conditional])],
            ],
            'create' => [
                'method' => 'POST',
                'path' => "/{$pluralName}",
                'handler' => "{$uid}.create",
                'request' => ['query' => self::queryParams(['fields', 'populate', ...$conditional])],
                'config' => [],
            ],
            'update' => [
                'method' => 'PUT',
                'path' => "/{$pluralName}/:id",
                'handler' => "{$uid}.update",
                'request' => ['query' => self::queryParams(['fields', 'populate', ...$conditional]), 'params' => ['id' => null]],
            ],
            'delete' => [
                'method' => 'DELETE',
                'path' => "/{$pluralName}/:id",
                'handler' => "{$uid}.delete",
                'request' => ['query' => self::queryParams(['fields', 'populate', 'filters', ...$conditional]), 'params' => ['id' => null]],
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
