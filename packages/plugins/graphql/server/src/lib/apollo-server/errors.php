<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\ApolloServer;

use GraphQL\Error\Error;

/**
 * @apollo/server 4 error classes (internalErrorClasses.ts) and error normalization
 * (errorNormalize.ts) on webonyx `GraphQL\Error\Error`s: the Apollo error code goes to
 * `extensions.code`, the HTTP status / headers to `extensions.http` (removed when formatting).
 */
final class Errors
{
    public const string INTERNAL_SERVER_ERROR = 'INTERNAL_SERVER_ERROR';

    public const string GRAPHQL_PARSE_FAILED = 'GRAPHQL_PARSE_FAILED';

    public const string GRAPHQL_VALIDATION_FAILED = 'GRAPHQL_VALIDATION_FAILED';

    public const string PERSISTED_QUERY_NOT_FOUND = 'PERSISTED_QUERY_NOT_FOUND';

    public const string PERSISTED_QUERY_NOT_SUPPORTED = 'PERSISTED_QUERY_NOT_SUPPORTED';

    public const string BAD_USER_INPUT = 'BAD_USER_INPUT';

    public const string OPERATION_RESOLUTION_FAILURE = 'OPERATION_RESOLUTION_FAILURE';

    public const string BAD_REQUEST = 'BAD_REQUEST';

    /**
     * @param array<string, mixed> $http `{ status?, headers? }`
     * @param array<string, mixed> $extensions
     */
    public static function badRequestError(string $message, array $http = ['status' => 400], array $extensions = []): Error
    {
        return new Error($message, null, null, null, null, null, ['http' => $http, ...$extensions, 'code' => self::BAD_REQUEST]);
    }

    public static function syntaxError(Error $graphqlError): Error
    {
        return new Error(
            $graphqlError->getMessage(),
            null,
            $graphqlError->getSource(),
            $graphqlError->getPositions(),
            null,
            $graphqlError,
            ['http' => ['status' => 400], ...($graphqlError->getExtensions() ?? []), 'code' => self::GRAPHQL_PARSE_FAILED],
        );
    }

    public static function validationError(Error $graphqlError): Error
    {
        return new Error(
            $graphqlError->getMessage(),
            $graphqlError->getNodes(),
            null,
            null,
            null,
            $graphqlError->getPrevious() ?? $graphqlError,
            ['http' => ['status' => 400], ...($graphqlError->getExtensions() ?? []), 'code' => self::GRAPHQL_VALIDATION_FAILED],
        );
    }

    public static function userInputError(Error $graphqlError): Error
    {
        return new Error(
            $graphqlError->getMessage(),
            $graphqlError->getNodes(),
            null,
            null,
            null,
            $graphqlError->getPrevious() ?? $graphqlError,
            [...($graphqlError->getExtensions() ?? []), 'code' => self::BAD_USER_INPUT],
        );
    }

    public static function operationResolutionError(Error $graphqlError): Error
    {
        return new Error(
            $graphqlError->getMessage(),
            $graphqlError->getNodes(),
            null,
            null,
            null,
            $graphqlError->getPrevious() ?? $graphqlError,
            ['http' => ['status' => 400], ...($graphqlError->getExtensions() ?? []), 'code' => self::OPERATION_RESOLUTION_FAILURE],
        );
    }

    public static function persistedQueryNotFoundError(): Error
    {
        return new Error('PersistedQueryNotFound', null, null, null, null, null, [
            'http' => ['status' => 200, 'headers' => ['cache-control' => 'private, no-cache, must-revalidate']],
            'code' => self::PERSISTED_QUERY_NOT_FOUND,
        ]);
    }

    public static function persistedQueryNotSupportedError(): Error
    {
        return new Error('PersistedQueryNotSupported', null, null, null, null, null, [
            'http' => ['status' => 200, 'headers' => ['cache-control' => 'private, no-cache, must-revalidate']],
            'code' => self::PERSISTED_QUERY_NOT_SUPPORTED,
        ]);
    }

    /** `ensureGraphQLError()` */
    public static function ensureGraphQLError(\Throwable $error, string $messagePrefixIfNotGraphQLError = ''): Error
    {
        return $error instanceof Error
            ? $error
            : new Error($messagePrefixIfNotGraphQLError . $error->getMessage(), null, null, null, null, $error);
    }

    /**
     * `GraphQLError.prototype.toJSON()`: `{ message, locations?, path?, extensions? }`.
     *
     * @return array<string, mixed>
     */
    public static function toJSON(Error $error): array
    {
        $formatted = ['message' => $error->getMessage()];

        $locations = $error->getLocations();
        if ($locations !== []) {
            $formatted['locations'] = array_map(static fn ($location): array => ['line' => $location->line, 'column' => $location->column], $locations);
        }

        $path = $error->getPath();
        if ($path !== null) {
            $formatted['path'] = $path;
        }

        $extensions = $error->getExtensions();
        if ($extensions !== null && $extensions !== []) {
            $formatted['extensions'] = $extensions;
        }

        return $formatted;
    }

    /**
     * errorNormalize.ts `normalizeAndFormatErrors()`.
     *
     * @param list<\Throwable> $errors
     * @param array{formatError?: callable|null, includeStacktraceInErrorResponses?: bool} $options
     * @return array{formattedErrors: list<array<string, mixed>>, httpFromErrors: array{status: int|null, headers: array<string, string>}}
     */
    public static function normalizeAndFormatErrors(array $errors, array $options = []): array
    {
        $formatError = $options['formatError'] ?? null;
        $includeStacktrace = $options['includeStacktraceInErrorResponses'] ?? false;
        $httpFromErrors = ['status' => null, 'headers' => []];

        $enrichError = static function (\Throwable $maybeError) use (&$httpFromErrors, $includeStacktrace): array {
            $graphqlError = self::ensureGraphQLError($maybeError);

            $extensions = [
                ...($graphqlError->getExtensions() ?? []),
                'code' => $graphqlError->getExtensions()['code'] ?? self::INTERNAL_SERVER_ERROR,
            ];

            $http = $extensions['http'] ?? null;
            if (is_array($http) && (!array_key_exists('status', $http) || is_int($http['status'])) && (!array_key_exists('headers', $http) || is_array($http['headers']))) {
                if (is_int($http['status'] ?? null)) {
                    $httpFromErrors['status'] = $http['status'];
                }
                foreach (is_array($http['headers'] ?? null) ? $http['headers'] : [] as $name => $value) {
                    $httpFromErrors['headers'][strtolower((string) $name)] = (string) $value;
                }
                unset($extensions['http']);
            }

            if ($includeStacktrace) {
                $source = $graphqlError->getPrevious() ?? $graphqlError;
                $extensions['stacktrace'] = [
                    ($source instanceof Error ? 'GraphQLError' : (new \ReflectionClass($source))->getShortName()) . ': ' . $source->getMessage(),
                    ...array_map(static fn (string $line): string => '    at ' . $line, explode("\n", $source->getTraceAsString())),
                ];
            }

            return [...self::toJSON($graphqlError), 'extensions' => $extensions];
        };

        $formattedErrors = [];
        foreach ($errors as $error) {
            try {
                $enriched = $enrichError($error);
                $formattedErrors[] = $formatError !== null ? $formatError($enriched, $error) : $enriched;
            } catch (\Throwable $formattingError) {
                if ($includeStacktrace) {
                    $formattedErrors[] = $enrichError($formattingError);
                } else {
                    $formattedErrors[] = [
                        'message' => 'Internal server error',
                        'extensions' => ['code' => self::INTERNAL_SERVER_ERROR],
                    ];
                }
            }
        }

        return ['formattedErrors' => $formattedErrors, 'httpFromErrors' => $httpFromErrors];
    }
}
