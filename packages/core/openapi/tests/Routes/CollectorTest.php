<?php

declare(strict_types=1);

namespace Strapi\Openapi\Tests\Routes;

use PHPUnit\Framework\TestCase;
use Strapi\Openapi\Routes\RouteCollector;
use Strapi\Openapi\Tests\Fixtures\Routes;
use Strapi\Openapi\Tests\Mocks\RouteMatcherMock;
use Strapi\Openapi\Tests\Mocks\RoutesProviderMock;

/** Port of __tests__/routes/collector.test.ts. */
final class CollectorTest extends TestCase
{
    public function testShouldReturnEmptyArrayWhenNoProviders(): void
    {
        $collector = new RouteCollector();

        self::assertSame([], $collector->collect());
    }

    public function testShouldCollectRoutesFromAllProviders(): void
    {
        $mockProviderTest = new RoutesProviderMock(Routes::test());
        $mockProviderFoobar = new RoutesProviderMock(Routes::foobar());

        $collector = new RouteCollector([$mockProviderTest, $mockProviderFoobar]);

        $collected = $collector->collect();

        self::assertSame(1, $mockProviderTest->iteratorCalls);
        self::assertSame(1, $mockProviderFoobar->iteratorCalls);

        self::assertCount(count(Routes::test()) + count(Routes::foobar()), $collected);
    }

    public function testShouldIgnoreRoutesNotPassingTheMatchersRules(): void
    {
        $mockProviderTest = new RoutesProviderMock(Routes::test());
        $mockProviderFoobar = new RoutesProviderMock(Routes::foobar());

        $matcher = new RouteMatcherMock();

        $collector = new RouteCollector([$mockProviderTest, $mockProviderFoobar], $matcher);

        $collected = $collector->collect();

        self::assertSame(1, $mockProviderTest->iteratorCalls);
        self::assertSame(1, $mockProviderFoobar->iteratorCalls);

        self::assertSame(count(Routes::test()) + count(Routes::foobar()), $matcher->matchCalls);

        self::assertNotCount(count(Routes::test()) + count(Routes::foobar()), $collected);

        foreach ($collected as $route) {
            self::assertSame('content-api', $route['info']['type']);
        }
    }
}
