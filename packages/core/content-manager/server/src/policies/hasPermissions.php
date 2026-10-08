<?php

declare(strict_types=1);

/** Port of server/src/policies/hasPermissions.ts. */

use Strapi\ContentManager\Validation\Policies\HasPermissions;
use Strapi\Utils\Policy;
use Strapi\Utils\Policy\PolicyContext;

return Policy::createPolicy([
    'name' => 'plugin::content-manager.hasPermissions',
    'validator' => HasPermissions::validateHasPermissionsInput(...),
    /**
     * NOTE: Action aliases are currently not checked at this level (policy).
     *       This is currently the intended behavior to avoid changing the behavior of API related permissions.
     *       If you want to add support for it, please create a dedicated RFC with a list of potential side effect this could have.
     */
    'handler' => static function (PolicyContext $ctx, mixed $config = []): bool {
        $actions = is_array($config) && is_array($config['actions'] ?? null) ? $config['actions'] : [];
        $hasAtLeastOne = is_array($config) && ($config['hasAtLeastOne'] ?? false) === true;

        $state = $ctx['state'];
        $userAbility = is_object($state) && method_exists($state, 'get') ? $state->get('userAbility') : null;
        $params = $ctx['params'];
        $model = is_array($params) ? ($params['model'] ?? null) : null;

        if (!$userAbility instanceof \Strapi\Permissions\Engine\Abilities\Ability) {
            return false;
        }

        $can = static fn (mixed $action): bool => is_string($action) && $userAbility->can($action, $model ?? 'all');

        if ($hasAtLeastOne) {
            foreach ($actions as $action) {
                if ($can($action)) {
                    return true;
                }
            }

            return false;
        }

        foreach ($actions as $action) {
            if (!$can($action)) {
                return false;
            }
        }

        return true;
    }
]);
