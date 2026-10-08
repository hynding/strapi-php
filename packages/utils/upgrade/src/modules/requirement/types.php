<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Requirement;

/**
 * Port of packages/utils/upgrade/src/modules/requirement/types.ts (the `Requirement` interface is
 * the `Requirement` class itself).
 *
 * @phpstan-type TestResult array{pass: true, error: null}|array{pass: false, error: \Throwable}
 * @phpstan-type TestContext array{target: \Strapi\Upgrade\Modules\Version\NodeSemver\SemVer, npmVersionsMatches: list<array<string, mixed>>, project: \Strapi\Upgrade\Modules\Project\AppProject}
 * @phpstan-type RequirementTestCallback \Closure(TestContext): void
 */
final class Types
{
}
