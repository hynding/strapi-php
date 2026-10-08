<?php

declare(strict_types=1);

namespace Strapi\Admin\Controllers;

use Strapi\Admin\Services\ProjectSettings as ProjectSettingsService;
use Strapi\Admin\Utils\Utils;
use Strapi\Admin\Validation\ProjectSettings as ProjectSettingsValidation;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\NotImplementedError;

/**
 * Port of server/src/controllers/admin.ts (`admin::admin`), community edition: `strapi.EE` is
 * never on in the PHP port, so `getProjectType` reports CE and `communityEdition` is true.
 */
final class Admin
{
    /**
     * List of core plugins that are always enabled,
     * and so it's not necessary to display them in the plugins list
     */
    private const CORE_PLUGINS = [
        'content-manager',
        'content-type-builder',
        'email',
        'upload',
        'i18n',
        'content-releases',
        'review-workflows',
    ];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    // NOTE: admin/ee/server overrides this controller, and adds the EE features
    // This returns an empty feature list for CE
    /** @return array{data: array<string, mixed>} */
    public function getProjectType(): array
    {
        $flags = $this->strapi->config()->get('admin.flags', []);

        return ['data' => [
            'isEE' => false,
            'isTrial' => false,
            'features' => [],
            'flags' => $flags === [] ? new \stdClass() : $flags,
            'ai' => ['enabled' => false],
        ]];
    }

    /** @return array{data: array<string, mixed>} */
    public function init(): array
    {
        $uuid = $this->strapi->config()->get('uuid', false);
        $hasAdmin = Utils::getService($this->strapi, 'user')->exists();
        $projectSettings = Utils::getService($this->strapi, 'project-settings')->getProjectSettings();
        $menuLogo = $projectSettings['menuLogo'] ?? null;
        $authLogo = $projectSettings['authLogo'] ?? null;
        // set to null if telemetryDisabled flag not avaialble in package.json
        $telemetryDisabled = $this->strapi->config()->get('packageJsonStrapi.telemetryDisabled', null);

        if ($telemetryDisabled !== null && $telemetryDisabled === true) {
            $uuid = false;
        }

        return [
            'data' => [
                'uuid' => $uuid,
                'hasAdmin' => $hasAdmin,
                ...self::logoUrl('menuLogo', $menuLogo),
                ...self::logoUrl('authLogo', $authLogo),
            ],
        ];
    }

    /**
     * `logo ? logo.url : null`; a logo without `url` (an empty object is truthy) leaves the key
     * out, as JSON drops `undefined`.
     *
     * @return array<string, mixed>
     */
    private static function logoUrl(string $key, mixed $logo): array
    {
        if (is_object($logo)) {
            $logo = get_object_vars($logo);
        } elseif (!is_array($logo)) {
            return [$key => null];
        }

        return array_key_exists('url', $logo) ? [$key => $logo['url']] : [];
    }

    public function getProjectSettings(): mixed
    {
        return Utils::getService($this->strapi, 'project-settings')->getProjectSettings();
    }

    public function updateProjectSettings(Context $ctx): mixed
    {
        $body = $ctx->requestBody();
        $files = array_map(ProjectSettingsService::toFormidableFile(...), $ctx->files());

        $projectSettingsService = Utils::getService($this->strapi, 'project-settings');

        ProjectSettingsValidation::validateUpdateProjectSettings(is_array($body) ? $body : []);
        ProjectSettingsValidation::validateUpdateProjectSettingsFiles($files);

        $formatedFiles = $projectSettingsService->parseFilesData($files);
        ProjectSettingsValidation::validateUpdateProjectSettingsImagesDimensions($formatedFiles);

        return $projectSettingsService->updateProjectSettings([
            ...(is_array($body) ? $body : []),
            ...$formatedFiles,
        ]);
    }

    /** `@strapi/typescript-utils` isUsingTypeScript(dir): a `tsconfig.json` in `dir`. */
    private static function isUsingTypeScript(string $dir): bool
    {
        return is_file(rtrim($dir, '/') . '/tsconfig.json');
    }

    /** @return array{data: array<string, mixed>}|null */
    public function telemetryProperties(Context $ctx): ?array
    {
        // If the telemetry is disabled, ignore the request and return early
        if ($this->strapi->telemetry()->isDisabled()) {
            $ctx->setStatus(204);

            return null;
        }

        $root = $this->strapi->dirs()->root;
        $useTypescriptOnServer = self::isUsingTypeScript($root);
        $useTypescriptOnAdmin = self::isUsingTypeScript($root . '/src/admin');
        $isHostedOnStrapiCloud = $this->strapi->env()('STRAPI_HOSTING', null) === 'strapi.cloud';

        $contentTypes = $this->strapi->contentTypes();
        $numberOfAllContentTypes = count($contentTypes);
        $numberOfComponents = count($this->strapi->components());

        $numberOfDynamicZones = 0;
        foreach ($contentTypes as $contentType) {
            foreach ($contentType->attributes as $attribute) {
                if (($attribute['type'] ?? null) === 'dynamiczone') {
                    $numberOfDynamicZones++;
                }
            }
        }

        $numberOfFolders = 0;
        try {
            $contentStructure = $this->strapi->has('content-structure') ? $this->strapi->get('content-structure') : null;
            if (is_object($contentStructure) && method_exists($contentStructure, 'countGroups')) {
                $numberOfFolders = (int) $contentStructure->countGroups();
            }
        } catch (\Throwable) {
            $numberOfFolders = 0;
        }

        return [
            'data' => [
                'useTypescriptOnServer' => $useTypescriptOnServer,
                'useTypescriptOnAdmin' => $useTypescriptOnAdmin,
                'isHostedOnStrapiCloud' => $isHostedOnStrapiCloud,
                'numberOfAllContentTypes' => $numberOfAllContentTypes, // TODO: V5: This event should be renamed numberOfContentTypes in V5 as the name is already taken to describe the number of content types using i18n.
                'numberOfComponents' => $numberOfComponents,
                'numberOfDynamicZones' => $numberOfDynamicZones,
                'numberOfContentTypeFolders' => $numberOfFolders,
            ],
        ];
    }

    /** @return array{data: array<string, mixed>} */
    public function information(): array
    {
        $currentEnvironment = $this->strapi->config()->get('environment');
        $autoReload = $this->strapi->config()->get('autoReload', false);
        $strapiVersion = $this->strapi->config()->get('info.strapi', null);
        $dependencies = $this->strapi->config()->get('info.dependencies', []);
        $projectId = $this->strapi->config()->get('uuid', null);
        // upstream reports `process.version`; the PHP port reports the PHP runtime version
        $nodeVersion = 'v' . PHP_VERSION;
        $communityEdition = !$this->strapi->EE();
        $cwd = getcwd();
        $useYarn = $cwd !== false && file_exists($cwd . '/yarn.lock');

        return [
            'data' => [
                'currentEnvironment' => $currentEnvironment,
                'autoReload' => $autoReload,
                'strapiVersion' => $strapiVersion,
                'dependencies' => $dependencies === [] ? new \stdClass() : $dependencies,
                'projectId' => $projectId,
                'nodeVersion' => $nodeVersion,
                'communityEdition' => $communityEdition,
                'useYarn' => $useYarn,
            ],
        ];
    }

    public function plugins(Context $ctx): mixed
    {
        $enabledPlugins = $this->strapi->config()->get('enabledPlugins', []);

        $plugins = [];
        foreach (is_array($enabledPlugins) ? $enabledPlugins : [] as $key => $plugin) {
            if (in_array($key, self::CORE_PLUGINS, true)) {
                continue;
            }
            $info = is_array($plugin) && is_array($plugin['info'] ?? null) ? $plugin['info'] : [];
            $plugins[] = [
                'name' => ($info['name'] ?? null) ?: $key,
                'displayName' => ($info['displayName'] ?? null) ?: (($info['name'] ?? null) ?: $key),
                'description' => ($info['description'] ?? null) ?: '',
                'packageName' => $info['packageName'] ?? null,
            ];
        }

        $ctx->send(['plugins' => $plugins]);

        return null;
    }

    public function licenseTrialTimeLeft(): mixed
    {
        // upstream calls `strapi.ee.getTrialEndDate()` (the license registry): Enterprise code, not ported
        throw new NotImplementedError('The license trial is an Enterprise feature, not available in the PHP port');
    }

    /** @return array{data: array<string, mixed>} */
    public function getGuidedTourMeta(Context $ctx): array
    {
        $user = $ctx->state()->get('user');
        $isFirstSuperAdminUser = Utils::getService($this->strapi, 'user')->isFirstSuperAdminUser(is_array($user) ? ($user['id'] ?? null) : null);

        return [
            'data' => [
                'isFirstSuperAdminUser' => $isFirstSuperAdminUser,
                'schemas' => array_map(static fn (object $schema): mixed => method_exists($schema, 'toArray') ? $schema->toArray() : $schema, $this->strapi->contentTypes()),
            ],
        ];
    }
}
