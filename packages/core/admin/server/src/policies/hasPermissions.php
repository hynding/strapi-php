<?php

declare(strict_types=1);

/** Port of server/src/policies/hasPermissions.ts. */

use Strapi\Admin\Validation\Policies\HasPermissions;
use Strapi\Utils\Policy;
use Strapi\Utils\Policy\PolicyContext;

return Policy::createPolicy([
    'name' => 'admin::hasPermissions',
    'validator' => HasPermissions::validateHasPermissionsInput(...),
    'handler' => static function (PolicyContext $ctx, mixed $config): bool {
        $actions = is_array($config) && is_array($config['actions'] ?? null) ? $config['actions'] : [];
        $state = $ctx['state'];
        $ability = is_object($state) && method_exists($state, 'get') ? $state->get('userAbility') : null;

        foreach ($actions as $action) {
            // inputModifiers: string → { action }, array → { action: arr[0], subject: arr[1] }, object → as is
            if (is_string($action)) {
                $permission = ['action' => $action];
            } elseif (is_array($action) && array_is_list($action)) {
                $permission = ['action' => $action[0] ?? null, 'subject' => $action[1] ?? null];
            } elseif (is_array($action)) {
                $permission = $action;
            } else {
                $permission = [];
            }

            $name = $permission['action'] ?? null;
            $subject = $permission['subject'] ?? null;

            if (!$ability instanceof \Strapi\Permissions\Engine\Abilities\Ability || !is_string($name)) {
                return false;
            }

            if (!$ability->can($name, $subject ?? 'all')) {
                return false;
            }
        }

        return true;
    },
]);
