<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql\Mutations\Auth;

use Strapi\Core\Services\Server\Context as ServerContext;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

/**
 * Port of server/src/graphql/mutations/auth/rate-limit.js: runs a users-permissions auth
 * controller behind the `plugin::users-permissions.rateLimit` middleware, as the REST route does,
 * with the request path / body / params of that route. Requests are synchronous here, so the
 * per-context operation queue upstream needs (batched operations share one Koa context) is
 * implicit.
 */
final class RateLimit
{
    private const string RATE_LIMIT_MIDDLEWARE = 'plugin::users-permissions.rateLimit';

    public static function getRateLimitPath(Strapi $strapi, string $routeSuffix): string
    {
        $configuredPrefix = $strapi->config()->get('api.rest.prefix', '/api');
        $prefix = is_string($configuredPrefix) ? $configuredPrefix : '/api';
        $normalizedPrefix = trim($prefix, '/');
        $normalizedSuffix = ltrim($routeSuffix, '/');

        return '/' . implode('/', array_values(array_filter([$normalizedPrefix, $normalizedSuffix], static fn (string $part): bool => $part !== '')));
    }

    /**
     * @return \Closure(Context|null, array{body?: mixed, params?: array<string, string>|null}, callable(Context): mixed): mixed
     */
    public static function createRateLimitRunner(Strapi $strapi, string $routeSuffix): \Closure
    {
        $rateLimit = ($strapi->middleware(self::RATE_LIMIT_MIDDLEWARE))([], $strapi);
        $routePath = self::getRateLimitPath($strapi, $routeSuffix);

        return static function (?Context $koaContext, array $input, callable $controller) use ($rateLimit, $routePath): mixed {
            if (!$koaContext instanceof ServerContext) {
                throw new \RuntimeException('The GraphQL context has no Koa context');
            }

            $body = $input['body'] ?? null;
            $params = $input['params'] ?? null;

            $originalRequest = $koaContext->request();
            $originalBody = $koaContext->requestBody();
            $originalParams = $koaContext->params();
            $originalResponseBody = $koaContext->body();
            $responseStatus = 200;

            $koaContext->withRequest($originalRequest->withUri($originalRequest->getUri()->withPath($routePath)));
            $koaContext->setRequestBody($body);
            if ($params !== null) {
                $koaContext->setParams($params);
            }

            try {
                $rateLimit($koaContext, static fn (): mixed => $controller($koaContext));
                $responseStatus = $koaContext->status();

                return $koaContext->body();
            } finally {
                $koaContext->withRequest($originalRequest);
                $koaContext->setRequestBody($originalBody);
                $koaContext->setParams($originalParams);
                $koaContext->setBody($originalResponseBody);
                $koaContext->setStatus($responseStatus);
            }
        };
    }
}
