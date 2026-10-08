<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Lib\ApolloServer;

use Strapi\Types\Core\Context;

/**
 * @as-integrations/koa 1.1 `koaMiddleware(server, { context })`: turns the Koa-style request into
 * an `HTTPGraphQLRequest`, runs it and writes the response.
 */
final class KoaMiddleware
{
    /**
     * @param array{context?: callable(array{ctx: Context}): mixed} $options
     * @return \Closure(Context, callable): void
     */
    public static function create(ApolloServer $server, array $options = []): \Closure
    {
        $context = $options['context'] ?? static fn (): array => [];

        return static function (Context $ctx, callable $next) use ($server, $context): void {
            $body = $ctx->requestBody();
            if ($body === null) {
                // The json koa-bodyparser *always* sets ctx.request.body to {} if it's unset (even
                // if the Content-Type doesn't match), so if it isn't set, you probably
                // forgot to set up koa-bodyparser.
                $ctx->setStatus(500);
                $ctx->setBody(
                    '`ctx.request.body` is not set; this probably means you forgot to set up the '
                    . '`koa-bodyparser` middleware before the Apollo Server middleware.',
                );

                return;
            }

            $incomingHeaders = [];
            foreach ($ctx->request()->getHeaders() as $key => $values) {
                // Node/Koa headers can be an array or a single value. We join
                // multi-valued headers with `, ` just like the Fetch API's `Headers` does.
                $incomingHeaders[strtolower((string) $key)] = implode(', ', $values);
            }

            $query = $ctx->request()->getUri()->getQuery();

            $httpGraphQLRequest = [
                'method' => strtoupper($ctx->method()),
                'headers' => $incomingHeaders,
                'search' => $query !== '' ? "?{$query}" : '',
                'body' => $body,
            ];

            ['body' => $responseBody, 'headers' => $headers, 'status' => $status] = $server->executeHTTPGraphQLRequest(
                $httpGraphQLRequest,
                static fn (): mixed => $context(['ctx' => $ctx]),
            );

            $ctx->setBody($responseBody);

            if ($status !== null) {
                $ctx->setStatus($status);
            }
            foreach ($headers as $key => $value) {
                $ctx->setHeader($key, $value);
            }
        };
    }
}
