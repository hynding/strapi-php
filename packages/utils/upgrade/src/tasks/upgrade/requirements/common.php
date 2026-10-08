<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Tasks\Upgrade\Requirements;

use Strapi\Upgrade\Modules\Requirement\Requirement;
use Symfony\Component\Process\Process;

/**
 * Port of packages/utils/upgrade/src/tasks/upgrade/requirements/common.ts. Upstream asks
 * `simple-git`; this runs the same read-only git commands (`--version`, `rev-parse`,
 * `status --porcelain`). Upstream's `REQUIRE_GIT…` constants are the static methods below.
 *
 * @phpstan-import-type TestContext from \Strapi\Upgrade\Modules\Requirement\Types
 */
final class Common
{
    /** @return array{0: bool, 1: string} [success, stdout] */
    private static function git(string $cwd, string ...$args): array
    {
        try {
            $process = new Process(['git', ...$args], $cwd, null, null, 30.0);
            $process->run();

            return [$process->isSuccessful(), $process->getOutput()];
        } catch (\Throwable) {
            return [false, ''];
        }
    }

    public static function REQUIRE_GIT_CLEAN_REPOSITORY(): Requirement
    {
        return Requirement::requirementFactory('REQUIRE_GIT_CLEAN_REPOSITORY', static function (array $context): void {
            [$ok, $status] = self::git($context['project']->cwd, 'status', '--porcelain');

            if (!$ok || trim($status) !== '') {
                throw new \RuntimeException('Repository is not clean. Please commit or stash any changes before upgrading');
            }
        });
    }

    public static function REQUIRE_GIT_REPOSITORY(): Requirement
    {
        return Requirement::requirementFactory('REQUIRE_GIT_REPOSITORY', static function (array $context): void {
            [$ok, $isRepo] = self::git($context['project']->cwd, 'rev-parse', '--is-inside-work-tree');

            if (!$ok || trim($isRepo) !== 'true') {
                throw new \RuntimeException('Not a git repository (or any of the parent directories)');
            }
        })->addChild(self::REQUIRE_GIT_CLEAN_REPOSITORY()->asOptional());
    }

    public static function REQUIRE_GIT_INSTALLED(): Requirement
    {
        return Requirement::requirementFactory('REQUIRE_GIT_INSTALLED', static function (array $context): void {
            [$ok] = self::git($context['project']->cwd, '--version');

            if (!$ok) {
                throw new \RuntimeException('Git is not installed');
            }
        })->addChild(self::REQUIRE_GIT_REPOSITORY()->asOptional());
    }

    public static function REQUIRE_GIT(): Requirement
    {
        return Requirement::requirementFactory('REQUIRE_GIT', null)->addChild(self::REQUIRE_GIT_INSTALLED()->asOptional());
    }
}
