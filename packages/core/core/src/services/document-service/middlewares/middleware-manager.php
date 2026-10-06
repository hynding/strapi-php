<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService\Middlewares;

/**
 * Port of middlewares/middleware-manager.ts: the document-service middleware stack
 * (`strapi.documents.use(fn)`). A middleware is `callable(array $ctx, callable $next): mixed` where
 * `$ctx = ['uid', 'contentType', 'action', 'params']` (mutable: change `$ctx['params']` before
 * calling `$next($ctx)` — pass the context back to `next` to apply changes).
 */
final class MiddlewareManager
{
    /** @var list<callable> */
    private array $middlewares = [];

    public static function createMiddlewareManager(): self
    {
        return new self();
    }

    /** @return \Closure(): void unsubscribe */
    public function use(callable $middleware): \Closure
    {
        $this->middlewares[] = $middleware;

        return function () use ($middleware): void {
            $index = array_search($middleware, $this->middlewares, true);
            if ($index !== false) {
                array_splice($this->middlewares, $index, 1);
            }
        };
    }

    /**
     * @param array<string, mixed> $ctx
     * @param callable(array<string, mixed>): mixed $cb
     */
    public function run(array $ctx, callable $cb): mixed
    {
        $index = 0;
        $middlewares = $this->middlewares;

        $next = function (?array $nextCtx = null) use (&$next, &$index, $middlewares, &$ctx, $cb): mixed {
            if ($nextCtx !== null) {
                $ctx = $nextCtx;
            }
            if ($index < count($middlewares)) {
                $middleware = $middlewares[$index++];

                return $middleware($ctx, $next);
            }

            return $cb($ctx);
        };

        return $next();
    }
}
