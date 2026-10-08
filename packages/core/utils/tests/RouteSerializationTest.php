<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Utils\RouteSerialization;

/** Port of packages/core/utils/src/__tests__/route-serialization.vitest.test.ts. */
final class RouteSerializationTest extends TestCase
{
    public function testSanitizeRouteForSerializationStripsRequestAndResponse(): void
    {
        $route = [
            'method' => 'GET',
            'path' => '/articles',
            'request' => ['query' => []],
            'response' => ['schema' => []],
            'handler' => 'article.find',
        ];

        self::assertSame(['method' => 'GET', 'path' => '/articles', 'handler' => 'article.find'], RouteSerialization::sanitizeRouteForSerialization($route));
    }

    public function testSanitizeRoutesArrayForSerializationFiltersInvalidEntries(): void
    {
        $routes = [
            ['method' => 'GET', 'path' => '/a'],
            null,
            null,
            'invalid',
            ['method' => 'POST', 'path' => '/b', 'request' => [], 'response' => []],
        ];

        self::assertSame([
            ['method' => 'GET', 'path' => '/a'],
            ['method' => 'POST', 'path' => '/b'],
        ], RouteSerialization::sanitizeRoutesArrayForSerialization($routes));
    }

    public function testSanitizeRoutesMapForSerializationSanitizesEachRouteArray(): void
    {
        $map = [
            'api::article.article' => [['method' => 'GET', 'path' => '/articles', 'request' => [], 'response' => []]],
            'other' => 'passthrough',
        ];

        self::assertSame([
            'api::article.article' => [['method' => 'GET', 'path' => '/articles']],
            'other' => 'passthrough',
        ], RouteSerialization::sanitizeRoutesMapForSerialization($map));
    }
}
