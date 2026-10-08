<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql;

use GraphQL\Type\Definition\ResolveInfo;
use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\Lib\ApolloServer\ApolloServer;
use Strapi\Plugin\Graphql\Lib\ApolloServer\KoaMiddleware;
use Strapi\Plugin\Graphql\Lib\ApolloServer\LandingPage;
use Strapi\Plugin\Graphql\Lib\GraphqlDepthLimit;
use Strapi\Plugin\Graphql\Lib\KoaBodyparser;
use Strapi\Plugin\Graphql\Lib\KoaCors;
use Strapi\Plugin\Graphql\Services\ContentApi\ContentApi;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Types\Core\Context;

/**
 * Port of server/src/bootstrap.ts: builds the content API schema, starts the (ported) Apollo
 * server and mounts it on the GraphQL endpoint.
 */
final class Bootstrap
{
    /** @var \WeakMap<Strapi, ApolloServer>|null */
    private static ?\WeakMap $servers = null;

    /**
     * lodash `mergeWith(customizer)` where arrays (lists) are concatenated.
     *
     * @param array<array-key, mixed> $a
     * @param array<array-key, mixed> $b
     * @return array<array-key, mixed>
     */
    private static function merge(array $a, array $b): array
    {
        foreach ($b as $key => $value) {
            $current = $a[$key] ?? null;
            if (is_array($current) && is_array($value) && array_is_list($current) && array_is_list($value)) {
                $a[$key] = [...$current, ...$value];
            } elseif (is_array($current) && is_array($value) && !array_is_list($current) && !array_is_list($value)) {
                $a[$key] = self::merge($current, $value);
            } else {
                $a[$key] = $value;
            }
        }

        return $a;
    }

    private static function isPositiveFiniteNumber(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value > 0;
    }

    /** @param array<string, mixed> $config `{ depthLimit, maxLimit }` */
    public static function getOperationLimitsWarning(array $config): ?string
    {
        $unboundedOrInvalidKeys = [];

        if (!self::isPositiveFiniteNumber($config['depthLimit'] ?? null)) {
            $unboundedOrInvalidKeys[] = 'depthLimit';
        }

        if (!self::isPositiveFiniteNumber($config['maxLimit'] ?? null)) {
            $unboundedOrInvalidKeys[] = 'maxLimit';
        }

        if ($unboundedOrInvalidKeys === []) {
            return null;
        }

        return 'Built-in GraphQL operation limits are unbounded or invalid for: ' . implode(', ', $unboundedOrInvalidKeys) . '. Configure these limits (for example: defaultLimit: 25, maxLimit: 100, depthLimit: 10). Custom Apollo validation rules may independently enforce limits. See https://docs.strapi.io/cms/configurations/plugins.';
    }

    /** @return array<string, mixed> an Apollo plugin */
    public static function determineLandingPage(Strapi $strapi): array
    {
        $graphql = $strapi->plugin('graphql');
        $utils = $graphql->service('utils');
        \assert($utils instanceof Utils);

        /**
         * configLanding page may be one of the following:
         *
         * - true: always use "playground" even in production
         * - false: never show "playground" even in non-production
         * - undefined: default Apollo behavior (hide playground on production)
         * - a function that returns an Apollo plugin that implements renderLandingPage
         */
        $configLandingPage = $graphql->config('landingPage');

        $isProduction = getenv('NODE_ENV') === 'production';

        $localLanding = static function () use ($strapi, $utils): array {
            $strapi->log()->debug('Apollo landing page: local');
            $utils->playground->setEnabled(true);

            return LandingPage::localDefault();
        };

        $prodLanding = static function () use ($strapi, $utils): array {
            $strapi->log()->debug('Apollo landing page: production');
            $utils->playground->setEnabled(false);

            return LandingPage::productionDefault();
        };

        $userLanding = static function (callable $userFunction) use ($strapi, $localLanding, $prodLanding): array {
            $strapi->log()->debug('Apollo landing page: from user-defined function...');
            $result = $userFunction($strapi);
            if ($result === true) {
                return $localLanding();
            }
            if ($result === false) {
                return $prodLanding();
            }
            $strapi->log()->debug('Apollo landing page: user-defined');

            return is_array($result) ? $result : [];
        };

        // DEPRECATED, remove in Strapi v6
        $playgroundAlways = $graphql->config('playgroundAlways');
        if ($playgroundAlways !== null) {
            $strapi->log()->warning(
                'The graphql config playgroundAlways is deprecated. This will be removed in Strapi 6. Please use landingPage instead. ',
            );
        }
        if ($playgroundAlways === false) {
            $strapi->log()->warning(
                'graphql config playgroundAlways:false has no effect, please use landingPage:false to disable Graphql Playground in all environments',
            );
        }

        if ($playgroundAlways || $configLandingPage === true) {
            return $localLanding();
        }

        // if landing page has been disabled, use production
        if ($configLandingPage === false) {
            return $prodLanding();
        }

        // If user did not define any settings, use our defaults
        if ($configLandingPage === null) {
            return $isProduction ? $prodLanding() : $localLanding();
        }

        // if user provided a landing page function, return that
        if (is_callable($configLandingPage)) {
            return $userLanding($configLandingPage);
        }

        // If no other setting could be found, default to production settings
        $strapi->log()->warning(
            'Your Graphql landing page has been disabled because there is a problem with your Graphql settings',
        );

        return $prodLanding();
    }

    public static function bootstrap(Strapi $strapi): void
    {
        $graphql = $strapi->plugin('graphql');

        // Generate the GraphQL schema for the content API
        $contentApi = $graphql->service('content-api');
        \assert($contentApi instanceof ContentApi);
        $schema = $contentApi->buildSchema();

        $path = (string) $graphql->config('endpoint');
        $configuredDepthLimit = $graphql->config('depthLimit');
        $maxLimit = $graphql->config('maxLimit');
        $operationLimitsWarning = self::getOperationLimitsWarning([
            'depthLimit' => $configuredDepthLimit,
            'maxLimit' => $maxLimit,
        ]);

        if ($operationLimitsWarning !== null) {
            $strapi->log()->warning($operationLimitsWarning);
        }

        $landingPage = self::determineLandingPage($strapi);

        /**
         * We need the arguments passed to the root query to be available in the association resolver
         * so we can forward those arguments along to any relations.
         *
         * In order to do that we are currently storing the arguments in context.
         * There is likely a better solution, but for now this is the simplest fix we could find.
         *
         * @see https://github.com/strapi/strapi/issues/23524
         */
        $pluginAddRootQueryArgs = [
            'requestDidStart' => static fn (): array => [
                'executionDidStart' => static fn (): array => [
                    'willResolveField' => static function (array $params): void {
                        ['source' => $source, 'args' => $args, 'contextValue' => $contextValue, 'info' => $info] = $params;
                        if ($source === null && $info instanceof ResolveInfo && $info->operation->operation === 'query' && $contextValue instanceof GraphqlContext) {
                            // Key args per root field (alias)
                            if ($contextValue->rootQueryArgsByPath === null) {
                                $contextValue->rootQueryArgsByPath = [];
                            }
                            $contextValue->rootQueryArgsByPath[$info->path[0]] = [
                                ...(is_array($args) ? $args : []),
                                '_originField' => $info->fieldName,
                            ];
                        }
                    },
                ],
            ],
        ];

        $defaultServerConfig = [
            // Schema
            'schema' => $schema,

            // Validation
            // Keep v5 compatibility: depthLimit is passed through unchanged, so an unset or invalid value
            // does not become an enforced finite limit during an upgrade.
            'validationRules' => [new GraphqlDepthLimit($configuredDepthLimit)],

            // Errors
            'formatError' => static fn (array $formattedError, mixed $error): array => FormatGraphqlError::formatGraphqlError($formattedError, $error, $strapi),

            // Misc
            'cors' => null,
            'uploads' => false,
            'bodyParserConfig' => true,
            // send 400 http status instead of 200 for input validation errors
            'status400ForVariableCoercionErrors' => true,
            'plugins' => [$landingPage, $pluginAddRootQueryArgs],

            'cache' => 'bounded',
        ];

        $apolloServerConfig = $graphql->config('apolloServer');
        $serverConfig = self::merge($defaultServerConfig, is_array($apolloServerConfig) ? $apolloServerConfig : []);

        // Create a new Apollo server
        $server = new ApolloServer($serverConfig);

        try {
            // server.start() must be called before using server.applyMiddleware()
            $server->start();
        } catch (\Throwable $error) {
            $strapi->log()->error('Failed to start the Apollo server', ['message' => $error->getMessage()]);

            throw $error;
        }

        // Create the route handlers for Strapi
        $handler = [];

        // add cors middleware
        $cors = $serverConfig['cors'] ?? null;
        if ($cors === false) {
            // Explicitly disabled - don't add middleware
        } elseif ($cors === null || $cors === true) {
            // enable with defaults (backwards compatible)
            $handler[] = KoaCors::create();
        } else {
            // Custom options object
            $handler[] = KoaCors::create(is_array($cors) ? $cors : []);
        }

        // add koa bodyparser middleware
        $bodyParserConfig = $serverConfig['bodyParserConfig'] ?? null;
        if (is_array($bodyParserConfig)) {
            $handler[] = KoaBodyparser::create($bodyParserConfig);
        } elseif ($bodyParserConfig) {
            $handler[] = KoaBodyparser::create();
        } else {
            $strapi->log()->debug('Body parser has been disabled for Apollo server');
        }

        // add the Strapi auth middleware
        $handler[] = static function (Context $ctx, callable $next) use ($strapi, $path): mixed {
            $ctx->state()->set('route', [
                'info' => [
                    // Indicate it's a content API route
                    'type' => 'content-api',
                ],
            ]);

            $utils = $strapi->plugin('graphql')->service('utils');
            \assert($utils instanceof Utils);

            $isPlaygroundRequest = $ctx->method() === 'GET'
                && $ctx->url() === $path // Matches the GraphQL endpoint
                && $utils->playground->isEnabled() // Only allow if the Playground is enabled
                && str_contains((string) $ctx->header('accept'), 'text/html'); // Specific to Playground UI loading

            // Skip authentication for the GraphQL Playground UI
            if ($isPlaygroundRequest) {
                return $next();
            }

            $strapi->auth()->authenticate($ctx, $next);

            return null;
        };

        // add the graphql server for koa
        $handler[] = KoaMiddleware::create($server, [
            // Initialize loaders for this request.
            'context' => static fn (array $params): GraphqlContext => new GraphqlContext($params['ctx']->state(), $params['ctx']),
        ]);

        // now that handlers are set up, add the graphql route to our apollo server
        $strapi->server()->routes([
            [
                'method' => 'ALL',
                'path' => $path,
                'handler' => $handler,
                'config' => [
                    'auth' => false,
                ],
            ],
        ]);

        // Register destroy behavior
        self::$servers ??= new \WeakMap();
        self::$servers[$strapi] = $server;
    }

    public static function destroy(Strapi $strapi): void
    {
        if (self::$servers !== null && isset(self::$servers[$strapi])) {
            self::$servers[$strapi]->stop();
            unset(self::$servers[$strapi]);
        }
    }
}
