<?php

declare(strict_types=1);

namespace Strapi\Types\Core;

/**
 * A middleware is `callable(Context $ctx, callable $next): void`.
 * A middleware factory (the file in src/middlewares) is `callable(array $config, Strapi $strapi): callable`.
 * Mirrors Core.MiddlewareFactory / Core.MiddlewareHandler.
 */
interface MiddlewareFactory
{
    /**
     * @param array<string, mixed> $config
     * @return callable(Context, callable): void
     */
    public function __invoke(array $config, Strapi $strapi): callable;
}
