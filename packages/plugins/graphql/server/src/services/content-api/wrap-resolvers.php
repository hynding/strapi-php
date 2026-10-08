<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\ContentApi;

use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Schema;
use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\GraphqlContext;
use Strapi\Utils\Errors\ForbiddenError;

/**
 * Port of server/src/services/content-api/wrap-resolvers.ts
 *
 * @phpstan-type GraphQLMiddleware callable(callable, mixed, mixed, mixed, ResolveInfo|null): mixed
 */
final class WrapResolvers
{
    private const array INTROSPECTION_QUERIES = [
        '__Schema',
        '__Type',
        '__Field',
        '__InputValue',
        '__EnumValue',
        '__Directive',
    ];

    /**
     * Get & parse middlewares definitions from the resolver's config
     *
     * @param array<string, mixed> $resolverConfig
     * @return list<callable>
     */
    private static function parseMiddlewares(array $resolverConfig, Strapi $strapi): array
    {
        $resolverMiddlewares = is_array($resolverConfig['middlewares'] ?? null) ? $resolverConfig['middlewares'] : [];

        // TODO: [v4] to factorize with compose endpoints (routes)
        $middlewares = [];
        foreach ($resolverMiddlewares as $middleware) {
            if (is_string($middleware)) {
                $middlewares[] = $strapi->middleware($middleware);
                continue;
            }

            if (is_callable($middleware)) {
                $middlewares[] = $middleware;
                continue;
            }

            if (is_array($middleware)) {
                $name = (string) ($middleware['name'] ?? '');
                $options = $middleware['options'] ?? [];

                $middlewares[] = ($strapi->middleware($name))($options, $strapi);
                continue;
            }

            throw new \RuntimeException('Invalid middleware type, expected (function,string,object), received ' . get_debug_type($middleware));
        }

        return $middlewares;
    }

    /**
     * Wrap the schema's resolvers if they've been
     * customized using the GraphQL extension service
     *
     * @param array{schema: Schema, strapi: Strapi, extension?: array<string, mixed>} $options
     */
    public static function wrapResolvers(array $options): Schema
    {
        ['schema' => $schema, 'strapi' => $strapi] = $options;
        $extension = $options['extension'] ?? [];

        // Get all the registered resolvers configuration
        $resolversConfig = is_array($extension['resolversConfig'] ?? null) ? $extension['resolversConfig'] : [];

        // Fields filters
        $isValidFieldName = static fn (string $field): bool => !str_starts_with($field, '__');

        $typeMap = $schema->getTypeMap();

        foreach ($typeMap as $type => $definition) {
            $isGraphQLObjectType = $definition instanceof ObjectType;
            $isIgnoredType = in_array($type, self::INTROSPECTION_QUERIES, true);

            if (!$isGraphQLObjectType || $isIgnoredType) {
                continue;
            }

            $fields = $definition->getFields();

            foreach ($fields as $fieldName => $fieldDefinition) {
                if (!$isValidFieldName($fieldName)) {
                    continue;
                }

                $defaultResolver = static function (mixed $parent) use ($fieldName): mixed {
                    if (is_array($parent) || $parent instanceof \ArrayAccess) {
                        return $parent[$fieldName] ?? null;
                    }
                    if (is_object($parent)) {
                        return $parent->{$fieldName} ?? null;
                    }

                    return null;
                };

                $path = "{$type}.{$fieldName}";
                $resolverConfig = is_array($resolversConfig[$path] ?? null) ? $resolversConfig[$path] : [];

                $baseResolver = $fieldDefinition->resolveFn ?? $defaultResolver;

                // Parse & initialize the middlewares
                $middlewares = self::parseMiddlewares($resolverConfig, $strapi);

                // Generate the policy middleware
                $policyMiddleware = Policy::createPoliciesMiddleware($resolverConfig, $strapi);

                // Add the policyMiddleware at the end of the middlewares collection
                $middlewares[] = $policyMiddleware;

                // Bind every middleware to the next one
                $boundMiddlewares = [];
                $count = count($middlewares);
                for ($index = $count - 1; $index >= 0; --$index) {
                    $middleware = $middlewares[$index];
                    // Make sure the last middleware in the list calls the baseResolver
                    $next = $index >= $count - 1 ? $baseResolver : $boundMiddlewares[$index + 1];
                    $boundMiddlewares[$index] = static fn (mixed $parents, mixed $args, mixed $context, ?ResolveInfo $info = null): mixed => $middleware($next, $parents, $args, $context, $info);
                }

                /**
                 * GraphQL authorization flow
                 */
                $authorize = static function (mixed $context) use ($strapi, $resolverConfig, $type): void {
                    $authConfig = $resolverConfig['auth'] ?? null;
                    $authContext = GraphqlContext::authOf($context);

                    $isValidType = in_array($type, ['Mutation', 'Query', 'Subscription'], true);
                    $hasConfig = $authConfig !== null;

                    $isAuthDisabled = $authConfig === false;

                    if (($isValidType || $hasConfig) && !$isAuthDisabled) {
                        try {
                            $strapi->auth()->verify($authContext, $authConfig ?? []);
                        } catch (\Throwable) {
                            throw new ForbiddenError();
                        }
                    }
                };

                $first = $boundMiddlewares[0];

                /**
                 * Base resolver wrapper that handles authorization, middlewares & policies
                 */
                $fieldDefinition->resolveFn = static function (mixed $parent, mixed $args, mixed $context, ?ResolveInfo $info = null) use ($authorize, $first): mixed {
                    $authorize($context);

                    // Execute middlewares (including the policy middleware which will always be included)
                    return $first($parent, $args, $context, $info);
                };
            }
        }

        return $schema;
    }
}
