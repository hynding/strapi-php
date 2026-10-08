<?php

declare(strict_types=1);

// Port of server/src/policies/isAuthenticatedAdmin.ts
use Strapi\Utils\Policy\PolicyContext;

return static function (PolicyContext $policyCtx): bool {
    $state = $policyCtx['state'];

    return (bool) (is_object($state) && method_exists($state, 'get') ? $state->get('isAuthenticated') : ($state['isAuthenticated'] ?? false));
};
