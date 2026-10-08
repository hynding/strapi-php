<?php

declare(strict_types=1);

namespace Strapi\Openapi\Tests\Mocks;

use Strapi\Openapi\Routes\RouteMatcher;

/**
 * Port of __tests__/mocks/route-matcher.mock.ts; also counts `match()` calls (upstream:
 * `jest.spyOn(matcher, 'match')`).
 */
final class RouteMatcherMock extends RouteMatcher
{
    public int $matchCalls = 0;

    /** @param list<callable(array<string, mixed>): bool>|null $rules */
    public function __construct(?array $rules = null)
    {
        parent::__construct($rules ?? [static fn (array $route): bool => ($route['info']['type'] ?? null) === 'content-api']);
    }

    public function match(array $route): bool
    {
        ++$this->matchCalls;

        return parent::match($route);
    }
}
