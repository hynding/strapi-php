<?php

declare(strict_types=1);

use Strapi\Admin\Middlewares\RateLimit;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\RateLimitError;

/**
 * Port of server/src/middlewares/rateLimit.ts. `koa2-ratelimit`'s `RateLimit.middleware` (and its
 * in-process memory store) is the one the admin package ports (Strapi\Admin\Middlewares\RateLimit).
 */
return static fn (array $config, Strapi $strapi): callable => static function (Context $ctx, callable $next) use ($config, $strapi): mixed {
    $pluginConfig = $strapi->config()->get('plugin::email');
    $ratelimit = is_array($pluginConfig) && is_array($pluginConfig['ratelimit'] ?? null) ? $pluginConfig['ratelimit'] : [];
    $rateLimitConfig = [
        'enabled' => true,
        ...$ratelimit,
    ];

    if ($rateLimitConfig['enabled'] === true) {
        $body = $ctx->requestBody();
        $requestEmail = is_array($body) ? ($body['email'] ?? null) : null;
        $userEmail = is_string($requestEmail) ? strtolower($requestEmail) : 'unknownEmail';

        $loadConfig = [
            'interval' => ['min' => 5],
            'max' => 5,
            'prefixKey' => $userEmail,
            'handler' => static function (): never {
                throw new RateLimitError();
            },
            ...$rateLimitConfig,
            ...$config,
        ];

        return RateLimit::middleware($loadConfig)($ctx, $next);
    }

    return $next();
};
