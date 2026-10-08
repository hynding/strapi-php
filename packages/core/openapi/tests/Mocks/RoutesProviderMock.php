<?php

declare(strict_types=1);

namespace Strapi\Openapi\Tests\Mocks;

use Strapi\Openapi\Routes\Providers\AbstractRoutesProvider;
use Strapi\Openapi\Tests\Fixtures\Routes;

/**
 * Port of __tests__/mocks/routes-provider.mock.ts; also counts iterations (upstream:
 * `jest.spyOn(provider, Symbol.iterator)`).
 */
final class RoutesProviderMock extends AbstractRoutesProvider
{
    public int $iteratorCalls = 0;

    /** @var list<array<string, mixed>> */
    private readonly array $mockRoutes;

    /** @param list<array<string, mixed>>|null $routes */
    public function __construct(?array $routes = null)
    {
        parent::__construct(null);
        $this->mockRoutes = $routes ?? Routes::test();
    }

    public function routes(): array
    {
        return $this->mockRoutes;
    }

    public function getIterator(): \Generator
    {
        ++$this->iteratorCalls;

        return parent::getIterator();
    }
}
