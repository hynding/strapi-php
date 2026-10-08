<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Services\Permissions;

use Strapi\Admin\Services\Permission;
use Strapi\Admin\Services\Role;
use Strapi\Core\Strapi;
use Strapi\Permissions\Engine\Hooks\WillRegisterContext;
use Strapi\Plugin\I18n\Utils\Utils;

/** Port of server/src/services/permissions/engine.ts. */
final class Engine
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * Locales property handler for the permission engine
     * Add the has-locale-access condition if the locales property is defined
     */
    public function willRegisterPermission(WillRegisterContext $context): void
    {
        $permission = $context->permission();
        $user = $context->options()['user'] ?? [];
        $subject = $permission['subject'] ?? null;
        $properties = is_array($permission['properties'] ?? null) ? $permission['properties'] : [];

        $roleService = $this->strapi->service('admin::role');
        assert($roleService instanceof Role);
        $isSuperAdmin = $roleService->hasSuperAdminRole(is_array($user) ? $user : []);

        if ($isSuperAdmin) {
            return;
        }

        // If there is no subject defined, ignore the permission
        if (!is_string($subject) || $subject === '') {
            return;
        }

        $ct = $this->strapi->contentTypes()[$subject] ?? null;

        // If the subject exists but isn't localized, ignore the permission
        if (!Utils::contentTypes($this->strapi)->isLocalizedContentType($ct)) {
            return;
        }

        // If the subject is localized but the locales property is null (access to all locales), ignore the permission
        if (array_key_exists('locales', $properties) && $properties['locales'] === null) {
            return;
        }

        $locales = $properties['locales'] ?? null;

        $context->condition->and([
            'locale' => [
                '$in' => is_array($locales) ? $locales : [],
            ],
        ]);
    }

    public function registerI18nPermissionsHandlers(): void
    {
        $permission = $this->strapi->service('admin::permission');
        assert($permission instanceof Permission);

        $permission->engine->hooks()['before-register.permission']->register(function (mixed $context): void {
            if ($context instanceof WillRegisterContext) {
                $this->willRegisterPermission($context);
            }
        });
    }
}
