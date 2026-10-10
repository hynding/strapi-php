<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of packages/core/core/src/utils/fetch.ts: `strapi.fetch(url, options)`, a tiny HTTP client
 * on `stream_context` (no Guzzle). Options mirror `fetch()`: `method`, `headers`, `body`, `timeout`
 * (seconds, default 10). Honors `server.proxy.fetch` / `server.proxy.global`.
 *
 * `__invoke()` reads the whole body; `open()` hands back the response stream for callers that
 * stream it (the upload plugin's URL import). Both go through {@see intercept()}'s handler first.
 *
 * @phpstan-type FetchResponse array{ok: bool, status: int, headers: array<string, string>, body: string}
 * @phpstan-type FetchStream array{stream: resource, status: int, statusText: string, headers: array<string, string>, url: string}
 * @phpstan-type InterceptedResponse array{status: int, statusText?: string, headers?: array<string, string>, body?: string}
 */
final class Fetch
{
    /** @var (\Closure(string, array<string, mixed>): (InterceptedResponse|null))|null */
    private static ?\Closure $interceptor = null;

    private ?string $proxy = null;

    public function __construct(private readonly Strapi $strapi, private readonly bool $logs = true)
    {
        $proxy = $strapi->config()->get('server.proxy.fetch') ?: $strapi->config()->get('server.proxy.global');
        if (is_string($proxy) && $proxy !== '') {
            if ($logs) {
                $strapi->log()->info("Using proxy for Fetch requests: {$proxy}");
            }
            $this->proxy = $proxy;
        }
    }

    public static function createStrapiFetch(Strapi $strapi, bool $logs = true): self
    {
        return new self($strapi, $logs);
    }

    /**
     * Answers requests instead of the network, for every `strapi.fetch` of the process: what replacing
     * the global `fetch` does upstream (a test's `withMockedFetch`, packages/utils/api-tests). The
     * handler gets the URL and the options and returns a response
     * (`['status' => 200, 'statusText' => 'OK', 'headers' => [...], 'body' => '...']`), or null to let
     * the request through. `intercept(null)` removes it.
     *
     * @param (callable(string, array<string, mixed>): (InterceptedResponse|null))|null $handler
     */
    public static function intercept(?callable $handler): void
    {
        self::$interceptor = $handler === null ? null : $handler(...);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{status: int, statusText: string, headers: array<string, string>, body: string}|null
     */
    private static function intercepted(string $url, array $options): ?array
    {
        $response = self::$interceptor === null ? null : (self::$interceptor)($url, $options);
        if (!is_array($response)) {
            return null;
        }

        $headers = [];
        foreach ($response['headers'] ?? [] as $name => $value) {
            $headers[strtolower((string) $name)] = (string) $value;
        }

        return [
            'status' => (int) $response['status'],
            'statusText' => (string) ($response['statusText'] ?? ''),
            'headers' => $headers,
            'body' => (string) ($response['body'] ?? ''),
        ];
    }

    /**
     * @param array{method?: string, headers?: array<string, string>, body?: string|null, timeout?: int|float} $options
     * @return FetchResponse
     */
    public function __invoke(string $url, array $options = []): array
    {
        if ($this->logs) {
            $this->strapi->log()->debug("Making request for {$url}");
        }

        $intercepted = self::intercepted($url, $options);
        if ($intercepted !== null) {
            $status = $intercepted['status'];

            return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'headers' => $intercepted['headers'], 'body' => $intercepted['body']];
        }

        $method = strtoupper($options['method'] ?? 'GET');
        $headerLines = [];
        foreach ($options['headers'] ?? [] as $name => $value) {
            $headerLines[] = "{$name}: {$value}";
        }

        $http = [
            'method' => $method,
            'header' => implode("\r\n", $headerLines),
            'timeout' => (float) ($options['timeout'] ?? 10),
            'ignore_errors' => true,
            'follow_location' => 1,
        ];
        if (isset($options['body'])) {
            $http['content'] = (string) $options['body'];
        }
        if ($this->proxy !== null) {
            $http['proxy'] = str_replace(['http://', 'https://'], 'tcp://', $this->proxy);
            $http['request_fulluri'] = true;
        }

        $context = stream_context_create(['http' => $http, 'https' => $http]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            $error = error_get_last();

            throw new \RuntimeException('fetch failed: ' . ($error['message'] ?? 'unknown error'));
        }

        // populated by the http wrapper on every completed request
        /** @var list<string> $responseHeaders */
        $responseHeaders = $http_response_header;
        $status = 0;
        $headers = [];
        foreach ($responseHeaders as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m) === 1) {
                $status = (int) $m[1];
                $headers = [];
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
            }
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'headers' => $headers, 'body' => $body];
    }

    /**
     * Opens `$url` with a GET (following redirects) and reads the response head; the body is left to
     * read from `stream`. Timeouts (option `timeout`, seconds, default 10) are an ApplicationError
     * "Request timed out while fetching URL: ...".
     *
     * @param array{timeout?: int|float} $options
     * @return FetchStream
     */
    public function open(string $url, array $options = []): array
    {
        if ($this->logs) {
            $this->strapi->log()->debug("Making request for {$url}");
        }

        $timeout = (float) ($options['timeout'] ?? 10);

        $intercepted = self::intercepted($url, ['method' => 'GET', ...$options]);
        if ($intercepted !== null) {
            $stream = fopen('php://temp', 'r+b');
            if ($stream === false) {
                throw new \RuntimeException('fetch failed: cannot buffer the response');
            }
            fwrite($stream, $intercepted['body']);
            rewind($stream);

            return ['stream' => $stream, 'status' => $intercepted['status'], 'statusText' => $intercepted['statusText'], 'headers' => $intercepted['headers'], 'url' => $url];
        }

        $http = [
            'method' => 'GET',
            'timeout' => $timeout,
            'ignore_errors' => true,
            'follow_location' => 1,
            'max_redirects' => 20,
        ];
        if ($this->proxy !== null) {
            $http['proxy'] = str_replace(['http://', 'https://'], 'tcp://', $this->proxy);
            $http['request_fulluri'] = true;
        }

        $context = stream_context_create(['http' => $http, 'https' => $http]);
        $stream = @fopen($url, 'rb', false, $context);
        if ($stream === false) {
            $error = error_get_last();
            $message = $error['message'] ?? 'fetch failed';
            if (str_contains($message, 'timed out')) {
                throw new ApplicationError("Request timed out while fetching URL: {$url}");
            }

            throw new \RuntimeException('fetch failed: ' . preg_replace('/^fopen\([^)]*\): /', '', $message));
        }

        $meta = stream_get_meta_data($stream);
        /** @var list<string> $lines */
        $lines = is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [];

        $status = 0;
        $statusText = '';
        $headers = [];
        $finalUrl = $url;
        foreach ($lines as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})\s*(.*)$~', $line, $m) === 1) {
                $status = (int) $m[1];
                $statusText = trim($m[2]);
                $headers = [];
                continue;
            }
            $pos = strpos($line, ':');
            if ($pos !== false) {
                $name = strtolower(trim(substr($line, 0, $pos)));
                $value = trim(substr($line, $pos + 1));
                $headers[$name] = $value;
                if ($name === 'location' && $status >= 300 && $status < 400) {
                    $finalUrl = self::resolveUrl($finalUrl, $value);
                }
            }
        }

        stream_set_timeout($stream, (int) $timeout);

        return ['stream' => $stream, 'status' => $status, 'statusText' => $statusText, 'headers' => $headers, 'url' => $finalUrl];
    }

    private static function resolveUrl(string $base, string $location): string
    {
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $location;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $dir = rtrim(dirname($parts['path'] ?? '/'), '/');

        return "{$origin}{$dir}/{$location}";
    }
}
