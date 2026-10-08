<?php

declare(strict_types=1);

namespace Strapi\Openapi\Tests\Routes;

use PHPUnit\Framework\TestCase;
use Strapi\Openapi\Routes\Providers\ApiRoutesProvider;
use Strapi\Openapi\Tests\Fixtures\Routes;
use Strapi\Openapi\Tests\Mocks\StrapiMock;

/** Port of __tests__/routes/api-routes-provider.test.ts. */
final class ApiRoutesProviderTest extends TestCase
{
    public function testShouldReturnAllRegisteredRoutes(): void
    {
        $provider = new ApiRoutesProvider(new StrapiMock());

        $routes = $provider->routes();

        self::assertCount(count(Routes::test()) + count(Routes::foobar()), $routes);
    }

    public function testShouldBeIterable(): void
    {
        $provider = new ApiRoutesProvider(new StrapiMock());

        $routes = iterator_to_array($provider, false);

        self::assertCount(count(Routes::test()) + count(Routes::foobar()), $routes);
    }
}
