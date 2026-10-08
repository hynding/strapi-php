<?php

declare(strict_types=1);

namespace Strapi\Plugin\Sentry\Middlewares;

use Strapi\Core\Strapi;
use Strapi\Plugin\Sentry\Sdk\Scope;
use Strapi\Plugin\Sentry\Services\Sentry as SentryService;
use Strapi\Types\Core\Context;

/**
 * Port of server/src/middlewares/sentry.ts.
 *
 * Programmatic sentry middleware. We do not want to expose it in the plugin
 *
 * Koa's `ctx._matchedRoute` (the matched route pattern, router prefix included) is looked up in
 * the server's route list from the route the endpoint stored in `ctx.state.route`.
 */
final class Sentry
{
    public function __invoke(Strapi $strapi): void
    {
        $sentryService = $strapi->plugin('sentry')->service('sentry');
        if (!$sentryService instanceof SentryService) {
            return;
        }
        $sentryService->init();
        $sentry = $sentryService->getInstance();

        if ($sentry === null) {
            // initialization failed
            return;
        }

        $strapi->server()->use(static function (Context $ctx, callable $next) use ($strapi, $sentryService, $sentry): mixed {
            try {
                return $next();
            } catch (\Throwable $error) {
                $sentryService->sendError($error, static function (Scope $scope) use ($ctx, $strapi, $sentry): void {
                    $scope->addEventProcessor(
                        // Parse Koa context to add error metadata
                        static fn (array $event): array => $sentry->Handlers::parseRequest($event, $ctx, [
                            // Don't parse the transaction name, we'll do it manually
                            'transaction' => false,
                        ]),
                    );

                    // Manually add transaction name
                    $scope->setTag('transaction', $ctx->method() . ' ' . self::matchedRoute($strapi, $ctx));
                    // Manually add Strapi version
                    $scope->setTag('strapi_version', $strapi->config()->get('info.strapi'));
                    $scope->setTag('method', $ctx->method());
                });

                throw $error;
            }
        });
    }

    private static function matchedRoute(Strapi $strapi, Context $ctx): string
    {
        $route = $ctx->state()->route();
        if ($route === null) {
            return 'undefined';
        }

        $method = $ctx->method();
        $path = (string) ($route['path'] ?? '');
        $handler = $route['handler'] ?? null;

        foreach ($strapi->server()->listRoutes() as $entry) {
            if (($entry['route']['handler'] ?? null) !== $handler || !str_ends_with($entry['path'], $path)) {
                continue;
            }
            if (in_array($entry['method'], [$method, 'ALL'], true)) {
                return $entry['path'];
            }
        }

        return $path !== '' ? $path : 'undefined';
    }
}
