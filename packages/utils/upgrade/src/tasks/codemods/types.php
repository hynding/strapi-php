<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tasks\Codemods;

/**
 * Port of packages/utils/upgrade/src/tasks/codemods/types.ts.
 *
 * PHP-only: `codemodRunnerFactory`, injectable for tests (upstream mocks the module).
 *
 * @phpstan-type RunCodemodsOptions array{logger: \Strapi\Upgrade\Modules\Logger\Logger, confirm?: (\Closure(string): bool)|null, selectCodemods?: (\Closure(list<array{version: \Strapi\Upgrade\Modules\Version\NodeSemver\SemVer, codemods: list<\Strapi\Upgrade\Modules\Codemod\Codemod>}>): list<array{version: \Strapi\Upgrade\Modules\Version\NodeSemver\SemVer, codemods: list<\Strapi\Upgrade\Modules\Codemod\Codemod>}>)|null, cwd?: string|null, dry?: bool, target: string|\Strapi\Upgrade\Modules\Version\NodeSemver\Range, uid?: string|null, codemodRunnerFactory?: (\Closure(\Strapi\Upgrade\Modules\Project\Project, \Strapi\Upgrade\Modules\Version\NodeSemver\Range): \Strapi\Upgrade\Modules\CodemodRunner\CodemodRunner)|null}
 * @phpstan-type ListCodemodsOptions array{logger: \Strapi\Upgrade\Modules\Logger\Logger, cwd?: string|null, target: string|\Strapi\Upgrade\Modules\Version\NodeSemver\Range}
 */
final class Types
{
}
