<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils;

/**
 * Port of src/utils/middleware.ts. A middleware is `callable(mixed $context, callable $next)`;
 * contexts are objects (or `\ArrayObject`s) so that handlers can mutate them as upstream does.
 */
final class Middleware
{
    /** @param list<callable(mixed, callable(mixed): mixed): mixed> $middlewares */
    public static function runMiddleware(mixed $context, array $middlewares): void
    {
        if ($middlewares === []) {
            return;
        }

        $cb = $middlewares[0];
        $cb($context, static function (mixed $newContext) use ($middlewares): void {
            self::runMiddleware($newContext, array_slice($middlewares, 1));
        });
    }
}
