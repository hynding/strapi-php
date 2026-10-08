<?php

declare(strict_types=1);

namespace Strapi\Openapi\Routes;

/**
 * Port of packages/core/openapi/src/routes/matcher.ts: matches routes based on provided rules.
 *
 * @phpstan-import-type MatcherRule from Types
 */
class RouteMatcher
{
    /** @var list<MatcherRule> */
    private readonly array $rules;

    /** @param list<MatcherRule> $rules A list of matcher rules to apply. Defaults to an empty array. */
    public function __construct(array $rules = [])
    {
        $this->rules = $rules;
    }

    /**
     * Checks if a given route matches all provided rules. Exits early if any rule fails.
     *
     * @param array<string, mixed> $route
     */
    public function match(array $route): bool
    {
        foreach ($this->rules as $rule) {
            if (!$rule($route)) {
                return false;
            }
        }

        return true;
    }
}
