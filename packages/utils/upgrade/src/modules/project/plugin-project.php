<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Project;

/** Port of `PluginProject` (packages/utils/upgrade/src/modules/project/project.ts). */
final class PluginProject extends Project
{
    public function __construct(string $cwd)
    {
        parent::__construct($cwd, ['paths' => self::paths()]);
    }

    public function type(): string
    {
        return 'plugin';
    }

    /**
     * The plugin default files, the root package.json and composer.json files, and the
     * plugin-specific root files.
     *
     * @return list<string>
     */
    private static function paths(): array
    {
        return self::pathsFor(Constants::PROJECT_PLUGIN_ALLOWED_ROOT_PATHS, [
            Constants::PROJECT_PACKAGE_JSON,
            Constants::PROJECT_COMPOSER_JSON,
            ...Constants::PROJECT_PLUGIN_ROOT_FILES,
        ]);
    }
}
