<?php

declare(strict_types=1);

namespace Strapi\Admin\Services;

use Strapi\Core\Strapi;
use Strapi\Utils\Errors\NotImplementedError;
use Strapi\Utils\Primitives\Objects;

/**
 * PLACEHOLDER: partly ported (services/project-settings.ts). `getProjectSettings()` (read by
 * `GET /admin/init`) is ported; `parseFilesData`, `updateProjectSettings` and `deleteOldFiles`
 * need the upload plugin and throw {@see NotImplementedError}.
 */
final class ProjectSettings
{
    private const PROJECT_SETTINGS_FILE_INPUTS = ['menuLogo', 'authLogo'];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return array<string, mixed> */
    public function getProjectSettings(): array
    {
        $store = $this->strapi->store()->scoped(['type' => 'core', 'name' => 'admin']);

        // Returns an object with file inputs names as key and null as value
        $projectSettings = array_fill_keys(self::PROJECT_SETTINGS_FILE_INPUTS, null);
        $stored = $store->get(['key' => 'project-settings']);
        if (is_array($stored)) {
            $projectSettings = [...$projectSettings, ...$stored];
        }

        // Filter file input fields
        foreach (self::PROJECT_SETTINGS_FILE_INPUTS as $inputName) {
            if (empty($projectSettings[$inputName]) || !is_array($projectSettings[$inputName])) {
                continue;
            }

            $projectSettings[$inputName] = Objects::pick($projectSettings[$inputName], ['name', 'url', 'width', 'height', 'ext', 'size']);
        }

        return $projectSettings;
    }

    /** @param list<mixed> $args */
    public function __call(string $name, array $args): never
    {
        throw new NotImplementedError("admin::project-settings {$name}() is not ported yet");
    }
}
