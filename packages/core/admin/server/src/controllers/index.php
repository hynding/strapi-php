<?php

declare(strict_types=1);

/** Port of server/src/controllers/index.ts. */

use Strapi\Admin\Ai;
use Strapi\Admin\Controllers;
use Strapi\Core\Strapi;

return [
    'admin' => static fn (Strapi $strapi): object => new Controllers\Admin($strapi),
    'api-token' => static fn (Strapi $strapi): object => new Controllers\ApiToken($strapi),
    'admin-token' => static fn (Strapi $strapi): object => new Controllers\AdminToken($strapi),
    'authenticated-user' => static fn (Strapi $strapi): object => new Controllers\AuthenticatedUser($strapi),
    'authenticated-session' => static fn (Strapi $strapi): object => new Controllers\AuthenticatedSession($strapi),
    'authentication' => static fn (Strapi $strapi): object => new Controllers\Authentication($strapi),
    'permission' => static fn (Strapi $strapi): object => new Controllers\Permission($strapi),
    'role' => static fn (Strapi $strapi): object => new Controllers\Role($strapi),
    'transfer' => static fn (Strapi $strapi): object => new Controllers\Transfer\Transfer($strapi),
    'user' => static fn (Strapi $strapi): object => new Controllers\User($strapi),
    'webhooks' => static fn (Strapi $strapi): object => new Controllers\Webhooks($strapi),
    'content-api' => static fn (Strapi $strapi): object => new Controllers\ContentApi($strapi),
    'homepage' => static fn (Strapi $strapi): object => new Controllers\Homepage($strapi),
    'ai' => static fn (Strapi $strapi): object => new Ai\Controllers\Ai($strapi),
];
