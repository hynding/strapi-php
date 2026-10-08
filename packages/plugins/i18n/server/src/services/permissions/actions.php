<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Services\Permissions;

use Strapi\Admin\Services\Permission;
use Strapi\Admin\Services\Role;
use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Utils\Utils;
use Strapi\Utils\HookContext;

/** Port of server/src/services/permissions/actions.ts. */
final class Actions
{
    public const array ACTIONS = [
        [
            'section' => 'settings',
            'category' => 'Internationalization',
            'subCategory' => 'Locales',
            'pluginName' => 'i18n',
            'displayName' => 'Create',
            'uid' => 'locale.create',
        ],
        [
            'section' => 'settings',
            'category' => 'Internationalization',
            'subCategory' => 'Locales',
            'pluginName' => 'i18n',
            'displayName' => 'Read',
            'uid' => 'locale.read',
            'aliases' => [
                ['actionId' => 'plugin::content-manager.explorer.read', 'subjects' => ['plugin::i18n.locale']],
            ],
        ],
        [
            'section' => 'settings',
            'category' => 'Internationalization',
            'subCategory' => 'Locales',
            'pluginName' => 'i18n',
            'displayName' => 'Update',
            'uid' => 'locale.update',
        ],
        [
            'section' => 'settings',
            'category' => 'Internationalization',
            'subCategory' => 'Locales',
            'pluginName' => 'i18n',
            'displayName' => 'Delete',
            'uid' => 'locale.delete',
        ],
    ];

    /** @var list<array<string, mixed>> */
    public readonly array $actions;

    public function __construct(private readonly Strapi $strapi)
    {
        $this->actions = self::ACTIONS;
    }

    private function permissionService(): Permission
    {
        $service = $this->strapi->service('admin::permission');
        assert($service instanceof Permission);

        return $service;
    }

    private function roleService(): Role
    {
        $service = $this->strapi->service('admin::role');
        assert($service instanceof Role);

        return $service;
    }

    /**
     * Adds the `locales` property to a contentTypes action (mutates `$context->value`).
     *
     * @param HookContext|array{value: array<string, mixed>} $context
     * @return array<string, mixed> the (possibly) updated action
     */
    public static function addLocalesPropertyIfNeeded(HookContext|array $context): array
    {
        /** @var array<string, mixed> $action */
        $action = $context instanceof HookContext ? $context->value : $context['value'];
        $section = $action['section'] ?? null;
        $applyToProperties = $action['options']['applyToProperties'] ?? null;

        // Only add the locales property to contentTypes' actions
        if ($section !== 'contentTypes') {
            return $action;
        }

        // If the 'locales' property is already declared within the applyToProperties array, then ignore the next steps
        if (is_array($applyToProperties) && in_array('locales', $applyToProperties, true)) {
            return $action;
        }

        // Add the 'locales' property to the applyToProperties array (create it if necessary)
        $options = is_array($action['options'] ?? null) ? $action['options'] : [];
        $options['applyToProperties'] = is_array($applyToProperties) ? [...array_values($applyToProperties), 'locales'] : ['locales'];
        $action['options'] = $options;

        if ($context instanceof HookContext) {
            $context->value = $action;
        }

        return $action;
    }

    /** @param array{property?: mixed, subject?: mixed} $params */
    public function shouldApplyLocalesPropertyToSubject(array $params): bool
    {
        if (($params['property'] ?? null) === 'locales') {
            $model = $this->strapi->getModel((string) ($params['subject'] ?? ''));

            return Utils::contentTypes($this->strapi)->isLocalizedContentType($model);
        }

        return true;
    }

    /**
     * @param list<array<string, mixed>> $permissions
     * @return list<array<string, mixed>>
     */
    public function addAllLocalesToPermissions(array $permissions): array
    {
        $actionProvider = $this->permissionService()->actionProvider;

        $allLocales = Utils::locales($this->strapi)->find();
        $allLocalesCode = array_map(static fn (array $locale): mixed => $locale['code'] ?? null, $allLocales);

        return array_map(static function (array $permission) use ($actionProvider, $allLocalesCode): array {
            $action = (string) ($permission['action'] ?? '');
            $subject = $permission['subject'] ?? null;

            $appliesToLocalesProperty = $actionProvider->appliesToProperty('locales', $action, is_string($subject) ? $subject : null);

            if (!$appliesToLocalesProperty) {
                return $permission;
            }

            $oldPermissionProperties = is_array($permission['properties'] ?? null) ? $permission['properties'] : [];

            return [...$permission, 'properties' => [...$oldPermissionProperties, 'locales' => $allLocalesCode]];
        }, array_values($permissions));
    }

    /**
     * `properties.locales === null` means access to all locales (see engine handler) — do not patch.
     *
     * @param array<string, mixed> $properties
     */
    public static function needsLocalesPatch(array $properties = []): bool
    {
        if (!array_key_exists('locales', $properties)) {
            return true;
        }

        $locales = $properties['locales'];

        if ($locales === null) {
            return false;
        }

        return is_array($locales) && count($locales) === 0;
    }

    /**
     * @param list<array<string, mixed>> $permissions
     * @return list<array<string, mixed>>
     */
    public function normalizeRolePermissionsLocales(array $permissions): array
    {
        $contentTypes = Utils::contentTypes($this->strapi);
        $actionProvider = $this->permissionService()->actionProvider;
        $defaultLocale = Utils::locales($this->strapi)->getDefaultLocale();

        return array_map(function (array $permission) use ($contentTypes, $actionProvider, $defaultLocale): array {
            $subject = $permission['subject'] ?? null;
            $action = (string) ($permission['action'] ?? '');
            $properties = is_array($permission['properties'] ?? null) ? $permission['properties'] : [];

            if ($subject === null || $subject === '') {
                return $permission;
            }

            $model = $this->strapi->getModel((string) $subject);

            if ($model === null || !$contentTypes->isLocalizedContentType($model)) {
                return $permission;
            }

            $appliesToLocales = $actionProvider->appliesToProperty('locales', $action, (string) $subject);

            if (!$appliesToLocales) {
                return $permission;
            }

            // null means access to all locales — same semantics as the permission engine handler
            if (array_key_exists('locales', $properties) && $properties['locales'] === null) {
                return $permission;
            }

            if (self::needsLocalesPatch($properties) && $defaultLocale !== null && $defaultLocale !== '') {
                return [...$permission, 'properties' => [...$properties, 'locales' => [$defaultLocale]]];
            }

            return $permission;
        }, array_values($permissions));
    }

    /**
     * Repairs role permissions on content types that just had i18n enabled in this sync.
     * Only patches permissions where `properties.locales` is missing or `[]`; leaves `null` alone.
     * Safe to run at `afterSync` time because it does not depend on the actionProvider.
     *
     * @param array{oldContentTypes?: mixed, contentTypes?: mixed} $params
     */
    public function repairPermissionsForNewlyLocalizedTypes(array $params): void
    {
        $oldContentTypes = $params['oldContentTypes'] ?? null;
        $contentTypes = is_array($params['contentTypes'] ?? null) ? $params['contentTypes'] : [];

        if (!is_array($oldContentTypes) || $oldContentTypes === []) {
            return;
        }

        $contentTypesService = Utils::contentTypes($this->strapi);

        $newlyLocalizedUids = [];
        foreach ($contentTypes as $uid => $contentType) {
            $uid = (string) $uid;
            $old = $oldContentTypes[$uid] ?? null;
            if (
                ($old !== null && $old !== [])
                && !$contentTypesService->isLocalizedContentType(is_array($old) || $old instanceof \Strapi\Types\Schema\Schema ? $old : null)
                && $contentTypesService->isLocalizedContentType(is_array($contentType) || $contentType instanceof \Strapi\Types\Schema\Schema ? $contentType : null)
            ) {
                $newlyLocalizedUids[] = $uid;
            }
        }

        if ($newlyLocalizedUids === []) {
            return;
        }

        try {
            $defaultLocaleCode = Utils::locales($this->strapi)->getDefaultLocale();
            if ($defaultLocaleCode === null || $defaultLocaleCode === '') {
                return;
            }

            $allPermissions = $this->permissionService()->findMany([
                'where' => ['subject' => ['$in' => $newlyLocalizedUids]],
            ]);

            foreach ($allPermissions as $permission) {
                $properties = is_array($permission['properties'] ?? null) ? $permission['properties'] : [];
                if (!self::needsLocalesPatch($properties)) {
                    continue;
                }

                try {
                    $this->strapi->db()->query('admin::permission')->update([
                        'where' => ['id' => $permission['id'] ?? null],
                        'data' => [
                            'properties' => [...$properties, 'locales' => [$defaultLocaleCode]],
                        ],
                    ]);
                } catch (\Throwable $reason) {
                    $this->strapi->log()->error('Failed to patch i18n permission during newly-localized type repair.', ['error' => $reason]);
                }
            }
        } catch (\Throwable $error) {
            $this->strapi->log()->error('Failed to repair i18n permissions for newly localized content types.', ['error' => $error]);
        }
    }

    public function syncSuperAdminPermissionsWithLocales(): void
    {
        $roleService = $this->roleService();
        $permissionService = $this->permissionService();

        $superAdminRole = $roleService->getSuperAdmin();

        if ($superAdminRole === null) {
            return;
        }

        $superAdminPermissions = $permissionService->findMany([
            'where' => [
                'role' => [
                    'id' => $superAdminRole['id'] ?? null,
                ],
            ],
        ]);

        $newSuperAdminPermissions = $this->addAllLocalesToPermissions($superAdminPermissions);

        $roleService->assignPermissions($superAdminRole['id'], $newSuperAdminPermissions);
    }

    public function registerI18nActions(): void
    {
        $this->permissionService()->actionProvider->registerMany(self::ACTIONS);
    }

    public function registerI18nActionsHooks(): void
    {
        $actionProvider = $this->permissionService()->actionProvider;
        $hooks = $this->roleService()->hooks;

        $actionProvider->hooks['appliesPropertyToSubject']->register(fn (mixed $params): bool => $this->shouldApplyLocalesPropertyToSubject(is_array($params) ? $params : []));
        $hooks['willResetSuperAdminPermissions']->register(fn (mixed $permissions): array => $this->addAllLocalesToPermissions(is_array($permissions) ? array_values($permissions) : []));
        $hooks['willValidateUpdatePermissions']->register(fn (mixed $permissions): array => $this->normalizeRolePermissionsLocales(is_array($permissions) ? array_values($permissions) : []));
    }

    public function updateActionsProperties(): void
    {
        $actionProvider = $this->permissionService()->actionProvider;

        // Register the transformation for every new action
        $actionProvider->hooks['willRegister']->register(static function (mixed $context): mixed {
            if ($context instanceof HookContext) {
                self::addLocalesPropertyIfNeeded($context);
            }

            return $context;
        });

        // Handle already registered actions
        foreach ($actionProvider->values() as $action) {
            $updated = self::addLocalesPropertyIfNeeded(['value' => $action]);
            if ($updated !== $action) {
                $actionProvider->replace((string) $action['actionId'], $updated);
            }
        }
    }
}
