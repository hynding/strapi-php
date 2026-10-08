<?php

declare(strict_types=1);

/** Port of server/src/services/index.js. */

use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Services\Jwt;
use Strapi\Plugin\UsersPermissions\Services\Permission;
use Strapi\Plugin\UsersPermissions\Services\Providers;
use Strapi\Plugin\UsersPermissions\Services\ProvidersRegistry;
use Strapi\Plugin\UsersPermissions\Services\Role;
use Strapi\Plugin\UsersPermissions\Services\User;
use Strapi\Plugin\UsersPermissions\Services\UsersPermissions;

return [
    'jwt' => static fn (Strapi $strapi): Jwt => new Jwt($strapi),
    'providers' => static fn (Strapi $strapi): Providers => new Providers($strapi),
    'providers-registry' => static fn (Strapi $strapi): ProvidersRegistry => new ProvidersRegistry($strapi),
    'role' => static fn (Strapi $strapi): Role => new Role($strapi),
    'user' => static fn (Strapi $strapi): User => new User($strapi),
    'users-permissions' => static fn (Strapi $strapi): UsersPermissions => new UsersPermissions($strapi),
    'permission' => static fn (Strapi $strapi): Permission => new Permission($strapi),
];
