<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\ContentApi;

use GraphQL\Type\Definition\ResolveInfo;
use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\GraphqlContext;
use Strapi\Utils\Errors\PolicyError;
use Strapi\Utils\Policy as PolicyUtils;
use Strapi\Utils\Policy\PolicyContext;

/** Port of server/src/services/content-api/policy.ts */
final class Policy
{
    /**
     * @param array<string, mixed> $resolverConfig
     * @return \Closure(callable, mixed, mixed, mixed, ResolveInfo|null): mixed
     */
    public static function createPoliciesMiddleware(array $resolverConfig, Strapi $strapi): \Closure
    {
        $resolverPolicies = $resolverConfig['policies'] ?? [];
        $policies = $strapi->get('policies')->resolve($resolverPolicies === [] ? [] : $resolverPolicies, []);

        return static function (callable $resolve, mixed $parent, mixed $args, mixed $context, ?ResolveInfo $info) use ($policies, $strapi): mixed {
            // Create a graphql policy context
            $policyContext = self::createGraphQLPolicyContext($parent, $args, $context, $info);

            // Run policies & throw an error if one of them fails
            foreach ($policies as ['handler' => $handler, 'config' => $config]) {
                $result = $handler($policyContext, $config, $strapi);

                if (!in_array($result, [true, null], true)) {
                    throw new PolicyError();
                }
            }

            return $resolve($parent, $args, $context, $info);
        };
    }

    private static function createGraphQLPolicyContext(mixed $parent, mixed $args, mixed $context, ?ResolveInfo $info): PolicyContext
    {
        $policyContext = [
            'parent' => $parent,

            'args' => $args,

            'context' => $context,

            'info' => $info,

            'state' => GraphqlContext::stateOf($context),

            'http' => GraphqlContext::koaContextOf($context),
        ];

        return PolicyUtils::createPolicyContext('graphql', $policyContext);
    }
}
