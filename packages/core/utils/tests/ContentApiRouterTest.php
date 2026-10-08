<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Utils\ContentApiRouter;

/** Port of packages/core/utils/src/__tests__/content-api-router.vitest.test.ts. */
final class ContentApiRouterTest extends TestCase
{
    public function testBuildsContentApiRoutesLazilyAndCachesThem(): void
    {
        $buildCount = 0;
        $factory = ContentApiRouter::createContentApiRoutesFactory(static function () use (&$buildCount): array {
            $buildCount += 1;

            return [['method' => 'GET', 'path' => '/items']];
        });

        $first = $factory();
        $second = $factory();

        self::assertSame(1, $buildCount);
        self::assertSame(['type' => 'content-api', 'routes' => [['method' => 'GET', 'path' => '/items']]], $first);
        self::assertSame($first['routes'], $second['routes']);
    }

    public function testExposesMutableRoutesPropertyForLegacyExtensions(): void
    {
        $factory = ContentApiRouter::createContentApiRoutesFactory(static fn (): array => [['method' => 'GET', 'path' => '/original']]);

        self::assertSame([['method' => 'GET', 'path' => '/original']], $factory->routes);

        $factory->routes = [['method' => 'POST', 'path' => '/replacement']];

        self::assertSame([['method' => 'POST', 'path' => '/replacement']], $factory()['routes']);
        self::assertSame([['method' => 'POST', 'path' => '/replacement']], $factory->routes);
    }
}
