<?php

declare(strict_types=1);

namespace Strapi\Core\CoreApi\Routes;

use Strapi\Core\CoreApi\Routes\Validation\CoreContentTypeRouteValidator;
use Strapi\Core\Strapi;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Zod as z;
use Strapi\Utils\Zod\ZodType;

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
                    'query' => self::queryParams($strapi, $validator, ['fields', 'populate', 'filters', ...$conditional]),
                ],
                'response' => self::defer($strapi, static fn (): ZodType => z::object(['data' => $validator->document()])),
                'config' => [],
            ],
            'update' => [
                'method' => 'PUT',
                'path' => "/{$singularName}",
                'handler' => "{$uid}.update",
                'request' => [
                    'query' => self::queryParams($strapi, $validator, ['fields', 'populate', ...$conditional]),
                    'body' => ['application/json' => $validator->partialBody()],
                ],
                'response' => self::defer($strapi, static fn (): ZodType => z::object(['data' => $validator->document()])),
                'config' => [],
            ],
            'delete' => [
                'method' => 'DELETE',
                'path' => "/{$singularName}",
                'handler' => "{$uid}.delete",
                'request' => [
                    'query' => self::queryParams($strapi, $validator, ['fields', 'populate', ...$conditional]),
                ],
                'response' => self::defer($strapi, static fn (): ZodType => z::object(['data' => $validator->document()])),
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
                    'query' => self::queryParams($strapi, $validator, ['fields', 'filters', '_q', 'pagination', 'sort', 'populate', ...$conditional]),
                ],
                'response' => self::defer($strapi, static fn (): ZodType => z::object(['data' => $validator->documents()])),
                'config' => [],
            ],
            'findOne' => [
                'method' => 'GET',
                'path' => "/{$pluralName}/:id",
                'handler' => "{$uid}.findOne",
                'request' => [
                    'params' => ['id' => $validator->documentID()],
                    'query' => self::queryParams($strapi, $validator, ['fields', 'populate', 'filters', 'sort', ...$conditional]),
                ],
                'response' => self::defer($strapi, static fn (): ZodType => z::object(['data' => $validator->document()])),
            ],
            'create' => [
                'method' => 'POST',
                'path' => "/{$pluralName}",
                'handler' => "{$uid}.create",
                'request' => [
                    'query' => self::queryParams($strapi, $validator, ['fields', 'populate', ...$conditional]),
                    'body' => ['application/json' => $validator->body()],
                ],
                'response' => self::defer($strapi, static fn (): ZodType => z::object(['data' => $validator->document()])),
                'config' => [],
            ],
            'update' => [
                'method' => 'PUT',
                'path' => "/{$pluralName}/:id",
                'handler' => "{$uid}.update",
                'request' => [
                    'query' => self::queryParams($strapi, $validator, ['fields', 'populate', ...$conditional]),
                    'params' => ['id' => $validator->documentID()],
                    'body' => ['application/json' => $validator->partialBody()],
                ],
                'response' => self::defer($strapi, static fn (): ZodType => z::object(['data' => $validator->document()])),
            ],
            'delete' => [
                'method' => 'DELETE',
                'path' => "/{$pluralName}/:id",
                'handler' => "{$uid}.delete",
                'request' => [
                    'query' => self::queryParams($strapi, $validator, ['fields', 'populate', 'filters', ...$conditional]),
                    'params' => ['id' => $validator->documentID()],
                ],
                'response' => self::defer($strapi, static fn (): ZodType => z::object(['data' => $validator->document()])),
            ],
        ];
    }

    /**
     * PHP port: the route's query and response schemas are built on first use on a settled boot
     * (Strapi::bootstrapSettled(); under PHP-FPM, most requests). They describe the routes for
     * OpenAPI documents; building them for every content type was a fifth of a settled boot. A
     * `z.lazy()` stands in (the documents unwrap it, and it parses as what it wraps); the query
     * params keep their names, which requests read (`ContentApiRouteParams`). The body schema is
     * built as before: requests read its shape (the extra input params' root keys).
     *
     * @param \Closure(): ZodType $build
     */
    private static function defer(Strapi $strapi, \Closure $build): ZodType
    {
        return $strapi->bootstrapSettled() ? z::lazy($build) : $build();
    }

    /**
     * @param list<string> $params
     * @return array<string, ZodType>
     */
    private static function queryParams(Strapi $strapi, CoreContentTypeRouteValidator $validator, array $params): array
    {
        if (!$strapi->bootstrapSettled()) {
            return $validator->queryParams($params);
        }

        $query = [];
        foreach ($params as $param) {
            $query[$param] = z::lazy(static fn (): ZodType => $validator->queryParams([$param])[$param]);
        }

        return $query;
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
