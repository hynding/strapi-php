<?php

declare(strict_types=1);

use Strapi\Plugin\UsersPermissions\Routes\ContentApi\UsersPermissionsRouteValidator;

/** Port of server/src/routes/content-api/permissions.js. */
return static function (): array {
    $validator = new UsersPermissionsRouteValidator();

    return [
        [
            'method' => 'GET',
            'path' => '/permissions',
            'handler' => 'permissions.getPermissions',
            'response' => $validator->permissionsResponseSchema(),
        ],
    ];
};
