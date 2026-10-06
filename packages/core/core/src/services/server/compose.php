<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Server;

use Strapi\Types\Core\Context;

/**
 * Not an upstream file: `koa-compose`. Composes middlewares `callable(Context $ctx, callable $next): mixed`
 * into one; the return value of the innermost middleware is passed back up through `next()`
 * (upstream's returnBodyMiddleware relies on it).
 */
final class Compose
{
    /**
     * @param list<callable> $middlewares
     * @return \Closure(Context, callable|null): mixed
     */
    public static function compose(array $middlewares): \Closure
    {
        foreach ($middlewares as $fn) {
            if (!is_callable($fn)) {
                throw new \InvalidArgumentException('Middleware must be composed of functions!');
            }
        }

        return static function (Context $context, ?callable $next = null) use ($middlewares): mixed {
            // last called middleware #
            $index = -1;

            $dispatch = static function (int $i) use (&$dispatch, &$index, $middlewares, $context, $next): mixed {
                if ($i <= $index) {
                    throw new \RuntimeException('next() called multiple times');
                }
                $index = $i;

                $fn = $middlewares[$i] ?? $next;
                if ($fn === null) {
                    return null;
                }

                return $fn($context, static fn (): mixed => $dispatch($i + 1));
            };

            return $dispatch(0);
        };
    }
}
