<?php

declare(strict_types=1);

namespace Strapi\Openapi\Tests\Routes;

use PHPUnit\Framework\TestCase;
use Strapi\Openapi\Routes\RouteMatcher;

/** Port of __tests__/routes/route-matcher.test.ts. */
final class RouteMatcherTest extends TestCase
{
    public function testShouldCorrectlyMatchARoute(): void
    {
        $matcher = new RouteMatcher([static fn (array $route): bool => $route['path'] === '/users/123']);
        $route = ['path' => '/users/123', 'method' => 'GET', 'info' => ['type' => 'content-api'], 'handler' => ''];

        self::assertTrue($matcher->match($route));
    }

    public function testShouldNotMatchARouteItDoesntPassTheRulesValidation(): void
    {
        $matcher = new RouteMatcher([static fn (array $route): bool => $route['path'] === '/users/123']);
        $route = ['path' => '/products/123', 'method' => 'GET', 'info' => ['type' => 'content-api'], 'handler' => ''];

        self::assertFalse($matcher->match($route));
    }

    public function testShouldMatchRoutesWithQueryParametersCorrectlyUsingMultipleRules(): void
    {
        $matcher = new RouteMatcher([
            static fn (array $route): bool => str_starts_with($route['path'], '/search'),
            static fn (array $route): bool => $route['method'] === 'GET',
        ]);
        $route = ['path' => '/search?query=example', 'method' => 'GET', 'info' => ['type' => 'content-api'], 'handler' => ''];

        self::assertTrue($matcher->match($route));
    }

    public function testShouldFailToMatchIfAnyRuleFails(): void
    {
        $matcher = new RouteMatcher([
            static fn (array $route): bool => str_starts_with($route['path'], '/search'),
            static fn (array $route): bool => $route['method'] === 'POST',
        ]);
        $route = ['path' => '/search?query=example', 'method' => 'GET', 'info' => ['type' => 'content-api'], 'handler' => ''];

        self::assertFalse($matcher->match($route));
    }
}
