<?php

declare(strict_types=1);

/** Port of server/src/services/index.ts. */

use Strapi\Admin\Services;
use Strapi\Core\Strapi;

$contentApiTokenService = static fn (Strapi $strapi): object => Services\ApiToken::createTokenService($strapi, 'content-api');

return [
    'auth' => static fn (Strapi $strapi): object => new Services\Auth($strapi),
    'user' => static fn (Strapi $strapi): object => new Services\User($strapi),
    'role' => static fn (Strapi $strapi): object => new Services\Role($strapi),
    'passport' => static fn (Strapi $strapi): object => new Services\Passport($strapi),
    'token' => static fn (Strapi $strapi): object => new Services\Token($strapi),
    'permission' => static fn (Strapi $strapi): object => new Services\Permission($strapi),
    'metrics' => static fn (Strapi $strapi): object => new Services\Metrics($strapi),
    'content-type' => static fn (Strapi $strapi): object => new Services\ContentType($strapi),
    'constants' => static fn (Strapi $strapi): object => new Services\Constants(),
    'condition' => static fn (Strapi $strapi): object => new Services\Condition($strapi),
    'action' => static fn (Strapi $strapi): object => new Services\Action($strapi),
    /** @deprecated Use 'api-token-content-api' instead */
    'api-token' => $contentApiTokenService,
    'api-token-content-api' => $contentApiTokenService,
    'api-token-admin' => static fn (Strapi $strapi): object => Services\ApiToken::createTokenService($strapi, 'admin'),
    'transfer' => static fn (Strapi $strapi): object => new Services\Transfer\Transfer($strapi),
    'project-settings' => static fn (Strapi $strapi): object => new Services\ProjectSettings($strapi),
    'encryption' => static fn (Strapi $strapi): object => new Services\Encryption($strapi),
    'homepage' => static fn (Strapi $strapi): object => new Services\Homepage($strapi),
];
