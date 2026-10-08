<?php

declare(strict_types=1);

/** Port of server/src/controllers/index.js. */

use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Controllers\Auth;
use Strapi\Plugin\UsersPermissions\Controllers\ContentManagerUser;
use Strapi\Plugin\UsersPermissions\Controllers\Permissions;
use Strapi\Plugin\UsersPermissions\Controllers\Role;
use Strapi\Plugin\UsersPermissions\Controllers\Settings;
use Strapi\Plugin\UsersPermissions\Controllers\User;

return [
    'auth' => static fn (Strapi $strapi): Auth => new Auth($strapi),
    'user' => static fn (Strapi $strapi): User => new User($strapi),
    'role' => static fn (Strapi $strapi): Role => new Role($strapi),
    'permissions' => static fn (Strapi $strapi): Permissions => new Permissions($strapi),
    'settings' => static fn (Strapi $strapi): Settings => new Settings($strapi),
    'contentmanageruser' => static fn (Strapi $strapi): ContentManagerUser => new ContentManagerUser($strapi),
];
