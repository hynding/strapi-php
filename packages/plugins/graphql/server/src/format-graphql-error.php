<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql;

use GraphQL\Error\Error as GraphQLError;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Errors\ForbiddenError;
use Strapi\Utils\Errors\HttpError;
use Strapi\Utils\Errors\UnauthorizedError;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Primitives\Strings;

/**
 * Port of server/src/format-graphql-error.ts: Apollo's `formatError` handler. Formatted errors
 * are `GraphQLError.toJSON()` arrays (`message`, `locations`, `path`, `extensions`).
 */
final class FormatGraphqlError
{
    private static function formatToCode(string $name): string
    {
        return 'STRAPI_' . strtoupper(Strings::snakeCase($name));
    }

    /**
     * `pick(['name', 'message', 'details'])(error)`
     *
     * @return array{error: array<string, mixed>}
     */
    private static function formatErrorToExtension(\Throwable $error): array
    {
        $picked = [];
        $picked['name'] = $error instanceof ApplicationError ? $error->name : (new \ReflectionClass($error))->getShortName();
        if ($picked['name'] === 'Exception' || $picked['name'] === 'RuntimeException' || $picked['name'] === 'LogicException') {
            $picked['name'] = 'Error';
        }
        $picked['message'] = $error->getMessage();
        if ($error instanceof ApplicationError) {
            $picked['details'] = $error->details === [] ? new \stdClass() : $error->details;
        } elseif (property_exists($error, 'details')) {
            $picked['details'] = $error->details;
        }

        return ['error' => $picked];
    }

    /**
     * @param array<string, mixed> $formattedError
     * @return array<string, mixed>
     */
    private static function createFormattedError(array $formattedError, string $message, string $code, \Throwable $originalError): array
    {
        $extensions = is_array($formattedError['extensions'] ?? null) ? $formattedError['extensions'] : [];

        // `new GraphQLError(message, { ...formattedError, extensions })` keeps the path and drops
        // the locations (they are computed from nodes, which a formatted error does not have)
        $error = ['message' => $message];
        if (array_key_exists('path', $formattedError)) {
            $error['path'] = $formattedError['path'];
        }
        $error['extensions'] = [
            ...$extensions,
            ...self::formatErrorToExtension($originalError),
            'code' => $code,
        ];

        return $error;
    }

    /** @apollo/server `unwrapResolverError` */
    private static function unwrapResolverError(mixed $error): mixed
    {
        if ($error instanceof GraphQLError && $error->getPath() !== null && $error->getPrevious() !== null) {
            return self::unwrapResolverError($error->getPrevious());
        }

        return $error;
    }

    /**
     * The handler for Apollo Server v4's formatError config option
     *
     * Intercepts specific Strapi error types to send custom error response codes in the GraphQL response
     *
     * @param array<string, mixed> $formattedError
     * @return array<string, mixed>
     */
    public static function formatGraphqlError(array $formattedError, mixed $error, ?Strapi $strapi = null): array
    {
        $originalError = self::unwrapResolverError($error);

        // If this error doesn't have an associated originalError, it
        if (!$originalError instanceof \Throwable) {
            return $formattedError;
        }

        $message = $originalError->getMessage();
        $name = $originalError instanceof ApplicationError ? $originalError->name : 'UNKNOWN';

        if ($originalError instanceof ForbiddenError || $originalError instanceof UnauthorizedError) {
            return self::createFormattedError($formattedError, $message, 'FORBIDDEN', $originalError);
        }

        if ($originalError instanceof ValidationError) {
            return self::createFormattedError($formattedError, $message, 'BAD_USER_INPUT', $originalError);
        }

        if ($originalError instanceof ApplicationError || $originalError instanceof HttpError) {
            $errorName = self::formatToCode($name);

            return self::createFormattedError($formattedError, $message, $errorName, $originalError);
        }

        if ($originalError instanceof GraphQLError) {
            return $formattedError;
        }

        // else if originalError doesn't appear to be from Strapi or GraphQL..

        // Log the error
        $strapi?->log()->error($originalError->getMessage(), ['exception' => $originalError]);

        // Create a generic 500 to send so we don't risk leaking any data
        return self::createFormattedError(
            ['message' => 'Internal Server Error'],
            'Internal Server Error',
            'INTERNAL_SERVER_ERROR',
            $originalError,
        );
    }
}
