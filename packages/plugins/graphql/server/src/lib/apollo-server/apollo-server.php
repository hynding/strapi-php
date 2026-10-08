<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\ApolloServer;

use GraphQL\Error\Error;
use GraphQL\Error\SyntaxError;
use GraphQL\Executor\Executor;
use GraphQL\Executor\Values;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\AST\VariableDefinitionNode;
use GraphQL\Language\Parser;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Schema;
use GraphQL\Utils\AST;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\CustomValidationRule;
use GraphQL\Validator\Rules\ValidationRule;
use GraphQL\Validator\ValidationContext;

/**
 * The parts of @apollo/server 4.13 the plugin relies on, on top of webonyx/graphql-php:
 * `executeHTTPGraphQLRequest` (ApolloServer.ts), `runPotentiallyBatchedHttpQuery`
 * (httpBatching.ts), `runHttpQuery` (runHttpQuery.ts), `preventCsrf` (preventCsrf.ts) and
 * `processGraphQLRequest` (requestPipeline.ts) with Apollo's status codes, error codes and
 * response shapes.
 *
 * Options (`new ApolloServer(config)`): `schema`, `validationRules`, `formatError`, `plugins`,
 * `introspection`, `csrfPrevention`, `allowBatchedHttpRequests`,
 * `includeStacktraceInErrorResponses`, `status400ForVariableCoercionErrors`, `nodeEnv`,
 * `rootValue`, `fieldResolver`, `persistedQueries`, `dangerouslyDisableValidation`,
 * `stringifyResult`, `logger`.
 *
 * Plugins are arrays of hooks shaped like Apollo's: `serverWillStart(server)` →
 * `{ renderLandingPage() → { html } }`, `requestDidStart(requestContext)` →
 * `{ executionDidStart() → { willResolveField({ source, args, contextValue, info }) } }`.
 *
 * @phpstan-type HTTPGraphQLRequest array{method: string, headers: array<string, string>, search: string, body: mixed}
 * @phpstan-type HTTPGraphQLResponse array{status: int|null, headers: array<string, string>, body: string}
 * @phpstan-type HTTPGraphQLHead array{status: int|null, headers: array<string, string>}
 */
final class ApolloServer
{
    public const string APPLICATION_JSON = 'application/json; charset=utf-8';

    public const string APPLICATION_JSON_GRAPHQL_CALLBACK = 'application/json; callbackSpec=1.0; charset=utf-8';

    public const string APPLICATION_GRAPHQL_RESPONSE_JSON = 'application/graphql-response+json; charset=utf-8';

    public const string MULTIPART_MIXED_NO_DEFER_SPEC = 'multipart/mixed';

    public const string MULTIPART_MIXED_EXPERIMENTAL = 'multipart/mixed; deferSpec=20220824';

    public const string TEXT_HTML = 'text/html';

    private const array RECOMMENDED_CSRF_PREVENTION_REQUEST_HEADERS = [
        'x-apollo-operation-name',
        'apollo-require-preflight',
    ];

    private const array NON_PREFLIGHTED_CONTENT_TYPES = [
        'application/x-www-form-urlencoded',
        'multipart/form-data',
        'text/plain',
    ];

    /** @var array<string, string> APQ cache (per process, like Apollo's in-memory LRU) */
    private static array $persistedQueriesCache = [];

    private readonly Schema $schema;

    /** @var list<ValidationRule> */
    private readonly array $validationRules;

    /** @var callable|null */
    private readonly mixed $formatError;

    /** @var list<array<string, mixed>> */
    private readonly array $plugins;

    private readonly bool $includeStacktraceInErrorResponses;

    private readonly bool $allowBatchedHttpRequests;

    /** @var list<string>|null */
    private readonly ?array $csrfPreventionRequestHeaders;

    private readonly bool $status400ForVariableCoercionErrors;

    private readonly bool $persistedQueriesEnabled;

    private readonly bool $dangerouslyDisableValidation;

    /** @var array{html: string|callable}|null */
    private ?array $landingPage = null;

    private bool $started = false;

    private bool $stopped = false;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
        $schema = $config['schema'] ?? null;
        if (!$schema instanceof Schema) {
            throw new \InvalidArgumentException('ApolloServer requires a `schema`');
        }
        $this->schema = $schema;

        $nodeEnv = is_string($config['nodeEnv'] ?? null) ? $config['nodeEnv'] : (string) (getenv('NODE_ENV') ?: '');
        $isDev = $nodeEnv !== 'production';
        $introspectionEnabled = is_bool($config['introspection'] ?? null) ? $config['introspection'] : $isDev;

        $validationRules = $introspectionEnabled ? [] : [self::noIntrospection()];
        foreach (is_array($config['validationRules'] ?? null) ? $config['validationRules'] : [] as $rule) {
            if ($rule instanceof ValidationRule) {
                $validationRules[] = $rule;
            } elseif (is_callable($rule)) {
                $validationRules[] = new CustomValidationRule('CustomValidationRule' . count($validationRules), $rule);
            }
        }
        $this->validationRules = $validationRules;

        $formatError = $config['formatError'] ?? null;
        $this->formatError = is_callable($formatError) ? $formatError : null;
        $this->plugins = array_values(array_filter(is_array($config['plugins'] ?? null) ? $config['plugins'] : [], 'is_array'));
        $this->includeStacktraceInErrorResponses = is_bool($config['includeStacktraceInErrorResponses'] ?? null)
            ? $config['includeStacktraceInErrorResponses']
            : ($nodeEnv !== 'production' && $nodeEnv !== 'test');
        $this->allowBatchedHttpRequests = ($config['allowBatchedHttpRequests'] ?? false) === true;

        $csrfPrevention = $config['csrfPrevention'] ?? null;
        $this->csrfPreventionRequestHeaders = $csrfPrevention === true || $csrfPrevention === null
            ? self::RECOMMENDED_CSRF_PREVENTION_REQUEST_HEADERS
            : ($csrfPrevention === false
                ? null
                : (is_array($csrfPrevention) && is_array($csrfPrevention['requestHeaders'] ?? null)
                    ? array_values(array_map('strval', $csrfPrevention['requestHeaders']))
                    : self::RECOMMENDED_CSRF_PREVENTION_REQUEST_HEADERS));
        $this->status400ForVariableCoercionErrors = ($config['status400ForVariableCoercionErrors'] ?? false) === true;
        $this->persistedQueriesEnabled = ($config['persistedQueries'] ?? null) !== false;
        $this->dangerouslyDisableValidation = ($config['dangerouslyDisableValidation'] ?? false) === true;
    }

    /** `NoIntrospection` (validationRules/NoIntrospection.ts) */
    private static function noIntrospection(): ValidationRule
    {
        return new CustomValidationRule('NoIntrospection', static fn (ValidationContext $context): array => [
            'Field' => static function (Node $node) use ($context): void {
                if ($node instanceof FieldNode && ($node->name->value === '__schema' || $node->name->value === '__type')) {
                    $context->reportError(new Error(
                        'GraphQL introspection is not allowed by Apollo Server, but the query contained __schema or __type. To enable introspection, pass introspection: true to ApolloServer in production',
                        [$node],
                        null,
                        null,
                        null,
                        null,
                        ['validationErrorCode' => 'INTROSPECTION_DISABLED'],
                    ));
                }
            },
        ]);
    }

    public function start(): void
    {
        // Instead of waiting for the first operation execution against the schema
        // to find out if it's a valid schema or not, check right now.
        $this->schema->assertValid();

        $server = ['logger' => $this->logger()];
        foreach ($this->plugins as $plugin) {
            $serverWillStart = $plugin['serverWillStart'] ?? null;
            if (!is_callable($serverWillStart)) {
                continue;
            }
            $listener = $serverWillStart($server);
            $renderLandingPage = is_array($listener) ? ($listener['renderLandingPage'] ?? null) : null;
            if (is_callable($renderLandingPage)) {
                if ($this->landingPage !== null) {
                    throw new \RuntimeException('Only one plugin can implement renderLandingPage.');
                }
                $landingPage = $renderLandingPage();
                if (is_array($landingPage) && (is_string($landingPage['html'] ?? null) || is_callable($landingPage['html'] ?? null))) {
                    $this->landingPage = ['html' => $landingPage['html']];
                }
            }
        }

        $this->started = true;
    }

    public function stop(): void
    {
        $this->stopped = true;
    }

    /** @return array<string, callable> */
    private function logger(): array
    {
        $logger = $this->config['logger'] ?? null;

        return is_array($logger) ? array_filter($logger, 'is_callable') : [];
    }

    // ------------------------------------------------------------------------------------------
    // HTTP

    /**
     * @param HTTPGraphQLRequest $httpGraphQLRequest
     * @param callable(): mixed $context the context thunk
     * @return HTTPGraphQLResponse
     */
    public function executeHTTPGraphQLRequest(array $httpGraphQLRequest, callable $context): array
    {
        try {
            if (!$this->started) {
                return $this->errorResponse(new \RuntimeException('You need to call `server.start()` before using your Apollo Server.'), $httpGraphQLRequest);
            }
            if ($this->stopped) {
                return $this->errorResponse(new \RuntimeException("Cannot execute GraphQL operations after the server has stopped.'"), $httpGraphQLRequest);
            }

            if ($this->landingPage !== null && $this->prefersHTML($httpGraphQLRequest)) {
                $html = $this->landingPage['html'];
                if (!is_string($html)) {
                    try {
                        $html = (string) $html();
                    } catch (\Throwable $error) {
                        return $this->errorResponse($error, $httpGraphQLRequest);
                    }
                }

                return ['status' => null, 'headers' => ['content-type' => 'text/html'], 'body' => $html];
            }

            // If enabled, check to ensure that this request was preflighted before doing
            // anything real (such as running the context function).
            if ($this->csrfPreventionRequestHeaders !== null) {
                self::preventCsrf($httpGraphQLRequest['headers'], $this->csrfPreventionRequestHeaders);
            }

            try {
                $contextValue = $context();
            } catch (\Throwable $error) {
                // If some random function threw, add a helpful prefix when converting
                // to GraphQLError. If it was already a GraphQLError, trust that the
                // message was chosen thoughtfully and leave off the prefix.
                return $this->errorResponse(Errors::ensureGraphQLError($error, 'Context creation failed: '), $httpGraphQLRequest);
            }

            return $this->runPotentiallyBatchedHttpQuery($httpGraphQLRequest, $contextValue);
        } catch (\Throwable $maybeError) {
            return $this->errorResponse($maybeError, $httpGraphQLRequest);
        }
    }

    /**
     * @param HTTPGraphQLRequest $requestHead
     * @return HTTPGraphQLResponse
     */
    private function errorResponse(\Throwable $error, array $requestHead): array
    {
        ['formattedErrors' => $formattedErrors, 'httpFromErrors' => $httpFromErrors] = Errors::normalizeAndFormatErrors([$error], [
            'includeStacktraceInErrorResponses' => $this->includeStacktraceInErrorResponses,
            'formatError' => $this->formatError,
        ]);

        return [
            'status' => $httpFromErrors['status'] ?? 500,
            'headers' => [
                ...$httpFromErrors['headers'],
                // Note that we may change the default to 'application/graphql-response+json'
                'content-type' => self::chooseContentTypeForSingleResultResponse($requestHead) ?? self::APPLICATION_JSON,
            ],
            'body' => $this->stringifyResult(['errors' => $formattedErrors]),
        ];
    }

    /** @param HTTPGraphQLRequest $request */
    private function prefersHTML(array $request): bool
    {
        $acceptHeader = $request['headers']['accept'] ?? null;

        return $request['method'] === 'GET'
            && is_string($acceptHeader) && $acceptHeader !== ''
            && Negotiator::mediaType($acceptHeader, [
                // We need it to actively prefer text/html over less browser-y types;
                // eg, `accept: */*' should still go for JSON. Negotiator does tiebreak
                // by the order in the list we provide, so we put text/html last.
                self::APPLICATION_JSON,
                self::APPLICATION_GRAPHQL_RESPONSE_JSON,
                self::MULTIPART_MIXED_EXPERIMENTAL,
                self::MULTIPART_MIXED_NO_DEFER_SPEC,
                self::TEXT_HTML,
            ]) === self::TEXT_HTML;
    }

    /** @param array{headers: array<string, string>} $head */
    public static function chooseContentTypeForSingleResultResponse(array $head): ?string
    {
        $acceptHeader = $head['headers']['accept'] ?? null;
        if ($acceptHeader === null || $acceptHeader === '') {
            return self::APPLICATION_JSON;
        }

        return Negotiator::mediaType($acceptHeader, [
            self::APPLICATION_JSON,
            self::APPLICATION_GRAPHQL_RESPONSE_JSON,
            self::APPLICATION_JSON_GRAPHQL_CALLBACK,
        ]);
    }

    /**
     * preventCsrf.ts
     *
     * @param array<string, string> $headers
     * @param list<string> $csrfPreventionRequestHeaders
     */
    private static function preventCsrf(array $headers, array $csrfPreventionRequestHeaders): void
    {
        $contentType = $headers['content-type'] ?? null;

        if ($contentType !== null) {
            $essence = self::mimeEssence($contentType);
            if ($essence === null) {
                // If we can't parse the MIME type, assume that it's something funky that will cause a preflight.
                return;
            }
            if (!in_array($essence, self::NON_PREFLIGHTED_CONTENT_TYPES, true)) {
                // We managed to parse a MIME type that was not one of the
                // CORS-safelisted ones. A browser would preflight it.
                return;
            }
        }

        foreach ($csrfPreventionRequestHeaders as $header) {
            $value = $headers[$header] ?? null;
            if ($value !== null && $value !== '') {
                return;
            }
        }

        throw Errors::badRequestError(
            'This operation has been blocked as a potential Cross-Site Request Forgery '
            . "(CSRF). Please either specify a 'content-type' header (with a type that "
            . 'is not one of ' . implode(', ', self::NON_PREFLIGHTED_CONTENT_TYPES) . ') or provide '
            . 'a non-empty value for one of the following headers: ' . implode(', ', $csrfPreventionRequestHeaders) . "\n",
        );
    }

    /** whatwg-mimetype `MIMEType.parse(contentType).essence` */
    private static function mimeEssence(string $contentType): ?string
    {
        if (preg_match('/^\s*([!#$%&\'*+.^_`|~0-9A-Za-z-]+)\/([!#$%&\'*+.^_`|~0-9A-Za-z-]+)\s*(;.*)?$/s', $contentType, $match) !== 1) {
            return null;
        }

        return strtolower($match[1] . '/' . $match[2]);
    }

    /**
     * httpBatching.ts
     *
     * @param HTTPGraphQLRequest $httpGraphQLRequest
     * @return HTTPGraphQLResponse
     */
    private function runPotentiallyBatchedHttpQuery(array $httpGraphQLRequest, mixed $contextValue): array
    {
        $body = $httpGraphQLRequest['body'];
        if (!($httpGraphQLRequest['method'] === 'POST' && is_array($body) && array_is_list($body) && $body !== [])) {
            return $this->runHttpQuery($httpGraphQLRequest, $contextValue, false);
        }

        if (!$this->allowBatchedHttpRequests) {
            throw Errors::badRequestError('Operation batching disabled.');
        }

        $sharedResponseHTTPGraphQLHead = ['status' => null, 'headers' => []];
        $responseBodies = [];
        foreach ($body as $bodyPiece) {
            $singleRequest = [...$httpGraphQLRequest, 'body' => $bodyPiece];
            $response = $this->runHttpQuery($singleRequest, $contextValue, true);
            // the operations share one response head
            $sharedResponseHTTPGraphQLHead = self::mergeHTTPGraphQLHead($sharedResponseHTTPGraphQLHead, ['status' => $response['status'], 'headers' => $response['headers']]);
            $responseBodies[] = $response['body'];
        }

        return [
            'status' => $sharedResponseHTTPGraphQLHead['status'],
            'headers' => $sharedResponseHTTPGraphQLHead['headers'],
            'body' => '[' . implode(',', $responseBodies) . ']',
        ];
    }

    /** @param array<string, mixed> $o */
    private static function fieldIfString(array $o, string $fieldName): ?string
    {
        $value = $o[$fieldName] ?? null;

        return is_string($value) ? $value : null;
    }

    private static function isStringRecord(mixed $o): bool
    {
        return is_array($o) && ($o === [] || !array_is_list($o));
    }

    /**
     * `new URLSearchParams(search).getAll(name)`
     *
     * @return array<string, list<string>>
     */
    private static function searchParams(string $search): array
    {
        $search = ltrim($search, '?');
        $params = [];
        if ($search === '') {
            return $params;
        }
        foreach (explode('&', $search) as $pair) {
            if ($pair === '') {
                continue;
            }
            $parts = explode('=', $pair, 2);
            $name = urldecode($parts[0]);
            $value = urldecode($parts[1] ?? '');
            $params[$name][] = $value;
        }

        return $params;
    }

    /** @param array<string, list<string>> $searchParams */
    private static function searchParamIfSpecifiedOnce(array $searchParams, string $paramName): ?string
    {
        $values = $searchParams[$paramName] ?? [];

        return match (count($values)) {
            0 => null,
            1 => $values[0],
            default => throw Errors::badRequestError("The '{$paramName}' search parameter may only be specified once."),
        };
    }

    /**
     * @param array<string, list<string>> $searchParams
     * @return array<string, mixed>|null
     */
    private static function jsonParsedSearchParamIfSpecifiedOnce(array $searchParams, string $fieldName): ?array
    {
        $value = self::searchParamIfSpecifiedOnce($searchParams, $fieldName);
        if ($value === null) {
            return null;
        }

        try {
            $hopefullyRecord = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw Errors::badRequestError("The {$fieldName} search parameter contains invalid JSON.");
        }

        if (!self::isStringRecord($hopefullyRecord) && !(is_array($hopefullyRecord) && $hopefullyRecord === [] && str_starts_with(ltrim($value), '{'))) {
            throw Errors::badRequestError("The {$fieldName} search parameter should contain a JSON-encoded object.");
        }

        /** @var array<string, mixed> $hopefullyRecord */
        return $hopefullyRecord;
    }

    private static function ensureQueryIsStringOrMissing(mixed $query): void
    {
        if ($query === null || $query === '' || $query === false || $query === 0 || is_string($query)) {
            return;
        }

        if (is_array($query) && ($query['kind'] ?? null) === 'Document') {
            throw Errors::badRequestError(
                "GraphQL queries must be strings. It looks like you're sending the "
                . 'internal graphql-js representation of a parsed query in your '
                . 'request instead of a request in the GraphQL query language. You '
                . 'can convert an AST to a string using the `print` function from '
                . '`graphql`, or use a client like `apollo-client` which converts '
                . 'the internal representation to a string for you.',
            );
        }

        throw Errors::badRequestError('GraphQL queries must be strings.');
    }

    /**
     * runHttpQuery.ts
     *
     * @param HTTPGraphQLRequest $httpRequest
     * @return HTTPGraphQLResponse
     */
    private function runHttpQuery(array $httpRequest, mixed $contextValue, bool $requestIsBatched): array
    {
        switch ($httpRequest['method']) {
            case 'POST':
                $body = $httpRequest['body'];
                if (!is_array($body) || $body === [] || array_is_list($body)) {
                    throw Errors::badRequestError('POST body missing, invalid Content-Type, or JSON object has no keys.');
                }

                self::ensureQueryIsStringOrMissing($body['query'] ?? null);

                if (is_string($body['variables'] ?? null)) {
                    throw Errors::badRequestError('`variables` in a POST body should be provided as an object, not a recursively JSON-encoded string.');
                }

                if (is_string($body['extensions'] ?? null)) {
                    throw Errors::badRequestError('`extensions` in a POST body should be provided as an object, not a recursively JSON-encoded string.');
                }

                if (array_key_exists('extensions', $body) && $body['extensions'] !== null && !self::isStringRecord($body['extensions'])) {
                    throw Errors::badRequestError('`extensions` in a POST body must be an object if provided.');
                }

                if (array_key_exists('variables', $body) && $body['variables'] !== null && !self::isStringRecord($body['variables'])) {
                    throw Errors::badRequestError('`variables` in a POST body must be an object if provided.');
                }

                if (array_key_exists('operationName', $body) && $body['operationName'] !== null && !is_string($body['operationName'])) {
                    throw Errors::badRequestError('`operationName` in a POST body must be a string if provided.');
                }

                $graphQLRequest = [
                    'query' => self::fieldIfString($body, 'query'),
                    'operationName' => self::fieldIfString($body, 'operationName'),
                    'variables' => self::isStringRecord($body['variables'] ?? null) ? $body['variables'] : null,
                    'extensions' => self::isStringRecord($body['extensions'] ?? null) ? $body['extensions'] : null,
                    'http' => $httpRequest,
                ];
                break;

            case 'GET':
                $searchParams = self::searchParams($httpRequest['search']);

                $graphQLRequest = [
                    'query' => self::searchParamIfSpecifiedOnce($searchParams, 'query'),
                    'operationName' => self::searchParamIfSpecifiedOnce($searchParams, 'operationName'),
                    'variables' => self::jsonParsedSearchParamIfSpecifiedOnce($searchParams, 'variables'),
                    'extensions' => self::jsonParsedSearchParamIfSpecifiedOnce($searchParams, 'extensions'),
                    'http' => $httpRequest,
                ];
                break;

            default:
                throw Errors::badRequestError('Apollo Server supports only GET/POST requests.', ['status' => 405, 'headers' => ['allow' => 'GET, POST']]);
        }

        $graphQLResponse = $this->processGraphQLRequest($graphQLRequest, $contextValue, $requestIsBatched);

        $head = $graphQLResponse['http'];

        if (!isset($head['headers']['content-type'])) {
            $contentType = self::chooseContentTypeForSingleResultResponse($httpRequest);
            if ($contentType === null) {
                throw Errors::badRequestError(
                    "An 'accept' header was provided for this request which does not accept "
                    . self::APPLICATION_JSON . ' or ' . self::APPLICATION_GRAPHQL_RESPONSE_JSON,
                    ['status' => 406],
                );
            }
            $head['headers']['content-type'] = $contentType;
        }

        return [
            'status' => $head['status'],
            'headers' => $head['headers'],
            'body' => $this->stringifyResult(self::orderExecutionResultFields($graphQLResponse['body'])),
        ];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private static function orderExecutionResultFields(array $result): array
    {
        $ordered = [];
        foreach (['errors', 'data', 'extensions'] as $key) {
            if (array_key_exists($key, $result)) {
                $ordered[$key] = $result[$key];
            }
        }

        return $ordered;
    }

    /** @param array<string, mixed> $value */
    private function stringifyResult(array $value): string
    {
        $stringify = $this->config['stringifyResult'] ?? null;
        if (is_callable($stringify)) {
            return (string) $stringify($value);
        }

        // prettyJSONStringify
        return self::jsonStringify($value) . "\n";
    }

    /** `JSON.stringify` */
    public static function jsonStringify(mixed $value): string
    {
        return (string) json_encode(
            self::toJson($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    private static function toJson(mixed $value): mixed
    {
        if ($value instanceof \JsonSerializable) {
            return self::toJson($value->jsonSerialize());
        }
        if ($value instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($value)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        }
        if (is_array($value)) {
            return array_map(self::toJson(...), $value);
        }
        if (is_float($value) && (is_nan($value) || is_infinite($value))) {
            return null;
        }

        return $value;
    }

    // ------------------------------------------------------------------------------------------
    // Request pipeline

    /**
     * requestPipeline.ts `processGraphQLRequest()`
     *
     * @param array{query: string|null, operationName: string|null, variables: array<string, mixed>|null, extensions: array<string, mixed>|null, http: HTTPGraphQLRequest} $request
     * @return array{http: HTTPGraphQLHead, body: array<string, mixed>}
     */
    private function processGraphQLRequest(array $request, mixed $contextValue, bool $requestIsBatched): array
    {
        $responseHttp = ['status' => null, 'headers' => []];

        // We clone the context because there are some assumptions that every operation
        // execution has a brand new context object.
        $contextValue = is_object($contextValue) ? clone $contextValue : $contextValue;

        $requestContext = [
            'request' => $request,
            'contextValue' => $contextValue,
            'schema' => $this->schema,
            'requestIsBatched' => $requestIsBatched,
        ];

        $requestListeners = [];
        foreach ($this->plugins as $plugin) {
            $requestDidStart = $plugin['requestDidStart'] ?? null;
            if (is_callable($requestDidStart)) {
                $listener = $requestDidStart($requestContext);
                if (is_array($listener)) {
                    $requestListeners[] = $listener;
                }
            }
        }


        $query = $request['query'];
        $extensions = $request['extensions'] ?? [];
        $persistedQuery = is_array($extensions['persistedQuery'] ?? null) ? $extensions['persistedQuery'] : null;

        if ($persistedQuery !== null) {
            if (!$this->persistedQueriesEnabled) {
                return $this->sendErrorResponse($responseHttp, [Errors::persistedQueryNotSupportedError()]);
            }
            if (($persistedQuery['version'] ?? null) !== 1) {
                return $this->sendErrorResponse($responseHttp, [new Error('Unsupported persisted query version', null, null, null, null, null, ['http' => ['status' => 400]])]);
            }

            $queryHash = (string) ($persistedQuery['sha256Hash'] ?? '');

            if ($query === null) {
                $query = self::$persistedQueriesCache['apq:' . $queryHash] ?? null;
                if ($query === null) {
                    return $this->sendErrorResponse($responseHttp, [Errors::persistedQueryNotFoundError()]);
                }
            } else {
                if ($queryHash !== hash('sha256', $query)) {
                    return $this->sendErrorResponse($responseHttp, [new Error('provided sha does not match query', null, null, null, null, null, ['http' => ['status' => 400]])]);
                }
                self::$persistedQueriesCache['apq:' . $queryHash] = $query;
            }
        } elseif ($query === null || $query === '') {
            return $this->sendErrorResponse($responseHttp, [
                Errors::badRequestError('GraphQL operations must contain a non-empty `query` or a `persistedQuery` extension.'),
            ]);
        }

        try {
            $document = Parser::parse($query);
        } catch (SyntaxError $syntaxError) {
            return $this->sendErrorResponse($responseHttp, [Errors::syntaxError($syntaxError)]);
        }

        if (!$this->dangerouslyDisableValidation) {
            $validationErrors = DocumentValidator::validate($this->schema, $document, [...array_values(DocumentValidator::defaultRules()), ...$this->validationRules]);

            if ($validationErrors !== []) {
                return $this->sendErrorResponse($responseHttp, array_map(static fn (Error $error): Error => Errors::validationError($error), $validationErrors));
            }
        }

        $operationName = $request['operationName'];
        $operation = AST::getOperationAST($document, $operationName);

        if (($request['http']['method'] ?? null) === 'GET' && $operation !== null && $operation->operation !== 'query') {
            return $this->sendErrorResponse($responseHttp, [
                Errors::badRequestError(
                    "GET requests only support query operations, not {$operation->operation} operations",
                    ['status' => 405, 'headers' => ['allow' => 'POST']],
                ),
            ]);
        }

        $executionListeners = [];
        foreach ($requestListeners as $listener) {
            $executionDidStart = $listener['executionDidStart'] ?? null;
            if (is_callable($executionDidStart)) {
                $executionListener = $executionDidStart($requestContext);
                if (is_array($executionListener)) {
                    $executionListeners[] = $executionListener;
                }
            }
        }
        $executionListeners = array_reverse($executionListeners);

        $willResolveField = [];
        foreach ($executionListeners as $listener) {
            if (is_callable($listener['willResolveField'] ?? null)) {
                $willResolveField[] = $listener['willResolveField'];
            }
        }

        try {
            $result = $this->execute($document, $operation, $request, $contextValue, $willResolveField);
        } catch (\Throwable $executionError) {
            return $this->sendErrorResponse($responseHttp, [Errors::ensureGraphQLError($executionError)]);
        }

        $errors = $result['errors'];

        if ($operation === null) {
            if ($errors === []) {
                throw new \RuntimeException('Unexpected error: Apollo Server did not resolve an operation but execute did not return errors');
            }

            return $this->sendErrorResponse($responseHttp, [Errors::operationResolutionError($errors[0])]);
        }

        $resultErrors = array_values(array_map(static function (Error $e): Error {
            if (self::isBadUserInputGraphQLError($e) && (($e->getExtensions() ?? [])['code'] ?? null) === null) {
                return Errors::userInputError($e);
            }

            return $e;
        }, $errors));

        $body = [];
        $httpFromErrors = ['status' => null, 'headers' => []];
        if ($resultErrors !== []) {
            ['formattedErrors' => $formattedErrors, 'httpFromErrors' => $httpFromErrors] = $this->formatErrors($resultErrors);
            $body['errors'] = $formattedErrors;
        }

        if ($this->status400ForVariableCoercionErrors && $resultErrors !== [] && !$result['hasData'] && $httpFromErrors['status'] === null) {
            $httpFromErrors['status'] = 400;
        }

        $responseHttp = self::mergeHTTPGraphQLHead($responseHttp, $httpFromErrors);

        if ($result['hasData']) {
            $body['data'] = $result['data'];
        }

        return ['http' => $responseHttp, 'body' => $body];
    }

    /**
     * @param HTTPGraphQLHead $responseHttp
     * @param list<\Throwable> $errors
     * @return array{http: HTTPGraphQLHead, body: array<string, mixed>}
     */
    private function sendErrorResponse(array $responseHttp, array $errors): array
    {
        ['formattedErrors' => $formattedErrors, 'httpFromErrors' => $httpFromErrors] = $this->formatErrors($errors);
        $responseHttp = self::mergeHTTPGraphQLHead($responseHttp, $httpFromErrors);
        if ($responseHttp['status'] === null) {
            $responseHttp['status'] = 500;
        }

        return ['http' => $responseHttp, 'body' => ['errors' => $formattedErrors]];
    }

    private static function isBadUserInputGraphQLError(Error $error): bool
    {
        $nodes = $error->getNodes() ?? [];
        if (count($nodes) !== 1 || !$nodes[0] instanceof VariableDefinitionNode) {
            return false;
        }

        $name = $nodes[0]->variable->name->value;
        $message = $error->getMessage();

        return str_starts_with($message, "Variable \"\${$name}\" got invalid value ")
            || str_starts_with($message, "Variable \"\${$name}\" of required type ")
            || str_starts_with($message, "Variable \"\${$name}\" of non-null type ");
    }

    /**
     * @param list<\Throwable> $errors
     * @return array{formattedErrors: list<array<string, mixed>>, httpFromErrors: HTTPGraphQLHead}
     */
    private function formatErrors(array $errors): array
    {
        return Errors::normalizeAndFormatErrors($errors, [
            'formatError' => $this->formatError,
            'includeStacktraceInErrorResponses' => $this->includeStacktraceInErrorResponses,
        ]);
    }

    /**
     * @param HTTPGraphQLHead $target
     * @param HTTPGraphQLHead $source
     * @return HTTPGraphQLHead
     */
    private static function mergeHTTPGraphQLHead(array $target, array $source): array
    {
        if ($source['status'] !== null && $source['status'] !== 0) {
            $target['status'] = $source['status'];
        }
        foreach ($source['headers'] as $name => $value) {
            $target['headers'][$name] = $value;
        }

        return $target;
    }

    /**
     * Runs the operation. `hasData` is false when graphql-js would leave `data` undefined
     * (variable coercion or operation resolution errors: nothing was executed).
     *
     * @param array{operationName: string|null, variables: array<string, mixed>|null} $request
     * @param list<callable> $willResolveField
     * @return array{hasData: bool, data: mixed, errors: list<Error>}
     */
    private function execute(DocumentNode $document, ?OperationDefinitionNode $operation, array $request, mixed $contextValue, array $willResolveField): array
    {
        if ($operation !== null) {
            [$coercionErrors] = Values::getVariableValues($this->schema, $operation->variableDefinitions, $request['variables'] ?? []);
            if ($coercionErrors !== null && $coercionErrors !== []) {
                return ['hasData' => false, 'data' => null, 'errors' => array_values($coercionErrors)];
            }
        }

        $schema = $willResolveField === [] ? $this->schema : $this->instrumentedSchema($willResolveField);

        $rootValue = $this->config['rootValue'] ?? null;
        if (is_callable($rootValue)) {
            $rootValue = $rootValue($document);
        }
        $fieldResolver = $this->config['fieldResolver'] ?? null;

        $result = Executor::execute(
            $schema,
            $document,
            $rootValue,
            $contextValue,
            $request['variables'] ?? [],
            $request['operationName'],
            is_callable($fieldResolver) ? $fieldResolver : null,
        );

        return [
            'hasData' => $operation !== null,
            'data' => $result->data,
            'errors' => array_values($result->errors),
        ];
    }

    /** @var array<int, true> schemas whose resolvers already dispatch `willResolveField` */
    private static array $instrumented = [];

    /** @var list<callable> */
    private static array $currentWillResolveField = [];

    /**
     * `enablePluginsForSchemaResolvers`: every field resolver first calls the execution
     * listeners' `willResolveField` hooks.
     *
     * @param list<callable> $willResolveField
     */
    private function instrumentedSchema(array $willResolveField): Schema
    {
        self::$currentWillResolveField = $willResolveField;

        $id = spl_object_id($this->schema);
        if (isset(self::$instrumented[$id])) {
            return $this->schema;
        }
        self::$instrumented[$id] = true;

        foreach ($this->schema->getTypeMap() as $typeName => $type) {
            if (!$type instanceof ObjectType || str_starts_with($typeName, '__')) {
                continue;
            }
            foreach ($type->getFields() as $field) {
                $resolve = $field->resolveFn ?? $this->config['fieldResolver'] ?? [Executor::class, 'defaultFieldResolver'];
                if (!is_callable($resolve)) {
                    continue;
                }
                $field->resolveFn = static function (mixed $source, mixed $args, mixed $contextValue, ResolveInfo $info) use ($resolve): mixed {
                    foreach (self::$currentWillResolveField as $hook) {
                        $hook(['source' => $source, 'args' => $args, 'contextValue' => $contextValue, 'info' => $info]);
                    }

                    return $resolve($source, $args, $contextValue, $info);
                };
            }
        }

        return $this->schema;
    }
}
