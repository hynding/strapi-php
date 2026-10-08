<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Controllers;

use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Utils\Utils;
use Strapi\Types\Core\Context;

/** Port of server/src/controllers/permissions.js. */
final class Permissions
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function getPermissions(Context $ctx): mixed
    {
        $permissions = Utils::getService($this->strapi, 'users-permissions')->getActions();

        $ctx->send(['permissions' => $permissions === [] ? new \stdClass() : $permissions]);

        return null;
    }

    public function getPolicies(Context $ctx): mixed
    {
        $policies = array_map('strval', array_keys($this->strapi->plugin('users-permissions')->policies()));

        $ctx->send([
            'policies' => array_values(array_filter($policies, static fn (string $name): bool => $name !== 'permissions')),
        ]);

        return null;
    }

    public function getRoutes(Context $ctx): mixed
    {
        $routes = Utils::getService($this->strapi, 'users-permissions')->getRoutes();

        $ctx->send(['routes' => $routes === [] ? new \stdClass() : $routes]);

        return null;
    }
}
