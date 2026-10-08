<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\CodemodRunner;

/**
 * Port of packages/utils/upgrade/src/modules/codemod-runner/types.ts.
 *
 * @phpstan-type SelectCodemodsCallback \Closure(list<array{version: \Strapi\Upgrade\Modules\Version\NodeSemver\SemVer, codemods: list<\Strapi\Upgrade\Modules\Codemod\Codemod>}>): list<array{version: \Strapi\Upgrade\Modules\Version\NodeSemver\SemVer, codemods: list<\Strapi\Upgrade\Modules\Codemod\Codemod>}>
 * @phpstan-type CodemodRunnerReport array{success: true, error: null}|array{success: false, error: \Throwable}
 */
final class Types
{
}
