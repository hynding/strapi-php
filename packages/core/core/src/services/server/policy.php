<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Server;

use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\PolicyError;
use Strapi\Utils\Policy as PolicyUtils;

/** Port of packages/core/core/src/services/server/policy.ts (`createPoliciesMiddleware`). */
final class Policy
{
    /**
     * @param array<string, mixed> $route
     * @return callable(Context, callable): void
     */
    public static function createPoliciesMiddleware(array $route, Strapi $strapi): callable
    {
        $policiesConfig = $route['config']['policies'] ?? [];
        $resolvedPolicies = $strapi->get('policies')->resolve($policiesConfig, $route['info'] ?? null);

        return static function (Context $ctx, callable $next) use ($resolvedPolicies, $strapi): void {
            $context = PolicyUtils::createPolicyContext('koa', [
                'request' => $ctx->request(),
                'params' => $ctx->params(),
                'query' => $ctx->query(),
                'state' => $ctx->state(),
                'ctx' => $ctx,
            ]);

            foreach ($resolvedPolicies as ['handler' => $handler, 'config' => $config]) {
                $result = $handler($context, $config, $strapi);

                if ($result !== true && $result !== null) {
                    throw new PolicyError();
                }
            }

            $next();
        };
    }
}
