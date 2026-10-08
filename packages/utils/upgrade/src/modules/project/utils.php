<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Project;

/** Port of packages/utils/upgrade/src/modules/project/utils.ts. */
final class Utils
{
    /** @phpstan-assert-if-true PluginProject $project */
    public static function isPluginProject(mixed $project): bool
    {
        return $project instanceof PluginProject;
    }

    /** @phpstan-assert PluginProject $project */
    public static function assertPluginProject(mixed $project): void
    {
        if (!self::isPluginProject($project)) {
            throw new \RuntimeException('Project is not a plugin');
        }
    }

    /** @phpstan-assert-if-true AppProject $project */
    public static function isApplicationProject(mixed $project): bool
    {
        return $project instanceof AppProject;
    }

    /** @phpstan-assert AppProject $project */
    public static function assertAppProject(mixed $project): void
    {
        if (!self::isApplicationProject($project)) {
            throw new \RuntimeException('Project is not an application');
        }
    }
}
