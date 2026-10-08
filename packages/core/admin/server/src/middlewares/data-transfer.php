<?php

declare(strict_types=1);

use Strapi\Admin\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

// Port of server/src/middlewares/data-transfer.ts
return static fn (array $config, Strapi $strapi): callable => static function (Context $ctx, callable $next) use ($strapi): mixed {
    $transferUtils = Utils::getService($strapi, 'transfer')->utils;

    // verify that data transfer is enabled
    if ($transferUtils->isRemoteTransferEnabled()) {
        return $next();
    }

    // if it has been manually disabled, return a not found
    if ($strapi->config()->get('server.transfer.remote.enabled') === false) {
        $ctx->notFound();

        return null;
    }

    // if it's enabled but doesn't have a valid salt, throw a not implemented
    if (!$transferUtils->hasValidTokenSalt()) {
        $ctx->notImplemented(
            'The server configuration for data transfer is invalid. Please contact your server administrator.',
            [
                'code' => 'INVALID_TOKEN_SALT',
            ]
        );

        return null;
    }

    // This should never happen as long as we're handling individual scenarios above
    throw new \RuntimeException('Unexpected error while trying to access a data transfer route');
};
