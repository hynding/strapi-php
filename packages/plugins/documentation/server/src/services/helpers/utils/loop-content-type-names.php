<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Services\Helpers\Utils;

use Strapi\Core\Strapi;
use Strapi\Utils\Primitives\Strings;

/**
 * Port of server/src/services/helpers/utils/loop-content-type-names.ts: a reusable loop for
 * building api endpoint paths and component schemas. Upstream reads the global `strapi`; here it
 * is passed explicitly.
 *
 * @phpstan-import-type Api from \Strapi\Plugin\Documentation\Types
 * @phpstan-import-type ApiInfo from \Strapi\Plugin\Documentation\Types
 */
final class LoopContentTypeNames
{
    /**
     * @param Strapi $strapi
     * @param Api $api
     * @param callable(ApiInfo): array<string, mixed> $callback
     *
     * @return array<string, mixed>
     */
    public static function loopContentTypeNames(object $strapi, array $api, callable $callback): array
    {
        $result = [];
        foreach ($api['ctNames'] as $contentTypeName) {
            // Get the attributes found on the api's contentType
            $uid = "{$api['getter']}::{$api['name']}.{$contentTypeName}";

            $contentType = $strapi->contentType($uid);
            $attributes = $contentType->attributes;
            $contentTypeInfo = $contentType->info;
            $kind = $contentType->kind;

            // Get the routes for the current api
            $routeInfo = $api['getter'] === 'plugin'
                ? ($strapi->plugin($api['name'])->routes()['content-api'] ?? null)
                : ($strapi->api($api['name'])->routes()[$contentTypeName] ?? null);

            // Continue to next iteration if routeInfo is undefined
            if (!is_array($routeInfo)) {
                continue;
            }

            // Uppercase the first letter of the api name
            $apiName = Strings::upperFirst($api['name']);

            // Create a unique name if the api name and contentType name don't match
            $uniqueName = $api['name'] === $contentTypeName ? $apiName : "{$apiName} - " . Strings::upperFirst($contentTypeName);

            $apiInfo = [
                ...$api,
                'routeInfo' => $routeInfo,
                'attributes' => $attributes,
                'uniqueName' => $uniqueName,
                'contentTypeInfo' => $contentTypeInfo,
                'kind' => $kind,
            ];

            // { ...result, ...callback(apiInfo) }
            foreach ($callback($apiInfo) as $key => $value) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
