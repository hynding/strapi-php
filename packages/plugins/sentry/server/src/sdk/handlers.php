<?php

declare(strict_types=1);

namespace Strapi\Plugin\Sentry\Sdk;

use Strapi\Types\Core\Context;

/**
 * Not an upstream file (part of the `@sentry/node` replacement): `Sentry.Handlers.parseRequest`
 * (`addRequestDataToEvent` in `@sentry/utils` 7.x) with its default includes — the request
 * `cookies`, `data`, `headers`, `method`, `query_string` and `url`; `transaction` when the
 * `transaction` option is not false; the client `ip` only with `ip: true`. The Koa request has no
 * `user`, so upstream never adds one here; neither does this port.
 */
final class Handlers
{
    public const array DEFAULT_REQUEST_INCLUDES = ['cookies', 'data', 'headers', 'method', 'query_string', 'url'];

    /**
     * @param array<string, mixed> $event
     * @param array{transaction?: bool|string, ip?: bool, request?: bool|list<string>} $options
     * @return array<string, mixed>
     */
    public static function parseRequest(array $event, Context $ctx, array $options = []): array
    {
        $request = $ctx->request();
        $includeRequest = $options['request'] ?? true;
        $include = $includeRequest === true ? self::DEFAULT_REQUEST_INCLUDES : ($includeRequest === false ? [] : $includeRequest);

        $method = strtoupper($request->getMethod());
        $uri = $request->getUri();
        $host = $request->getHeaderLine('Host') !== '' ? $request->getHeaderLine('Host') : $uri->getHost() . ($uri->getPort() !== null ? ':' . $uri->getPort() : '');
        $scheme = $uri->getScheme() !== '' ? $uri->getScheme() : 'http';
        $query = $uri->getQuery();
        $path = $uri->getPath() !== '' ? $uri->getPath() : '/';

        $requestData = [];
        foreach ($include as $key) {
            switch ($key) {
                case 'headers':
                    $headers = [];
                    foreach ($request->getHeaders() as $name => $values) {
                        $headers[strtolower((string) $name)] = implode(', ', $values);
                    }
                    $requestData['headers'] = $headers;
                    break;
                case 'method':
                    $requestData['method'] = $method;
                    break;
                case 'url':
                    $requestData['url'] = "{$scheme}://{$host}{$path}" . ($query !== '' ? "?{$query}" : '');
                    break;
                case 'cookies':
                    $cookies = $request->getCookieParams();
                    if ($cookies === [] && $request->getHeaderLine('Cookie') !== '') {
                        foreach (explode(';', $request->getHeaderLine('Cookie')) as $pair) {
                            [$name, $value] = array_pad(explode('=', trim($pair), 2), 2, '');
                            if ($name !== '') {
                                $cookies[$name] = urldecode($value);
                            }
                        }
                    }
                    $requestData['cookies'] = $cookies;
                    break;
                case 'query_string':
                    $requestData['query_string'] = $query;
                    break;
                case 'data':
                    if ($method === 'GET' || $method === 'HEAD') {
                        break;
                    }
                    $body = $ctx->requestBody();
                    if (is_string($body) && $body !== '') {
                        $requestData['data'] = $body;
                    } elseif (is_array($body) || is_object($body)) {
                        $requestData['data'] = (string) json_encode($body);
                    }
                    break;
            }
        }

        $event['request'] = [...($event['request'] ?? []), ...$requestData];

        $transaction = $options['transaction'] ?? true;
        if ($transaction !== false && !isset($event['transaction'])) {
            $event['transaction'] = $transaction === 'handler' ? $path : "{$method} {$path}";
        }

        if (($options['ip'] ?? false) === true) {
            $ip = $ctx->ip();
            if ($ip !== '') {
                $event['user'] = [...($event['user'] ?? []), 'ip_address' => $ip];
            }
        }

        return $event;
    }
}
