<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/utils/fetch.ts: `strapi.fetch(url, options)`, a tiny HTTP client
 * on `stream_context` (no Guzzle). Options mirror `fetch()`: `method`, `headers`, `body`, `timeout`
 * (seconds, default 10). Honors `server.proxy.fetch` / `server.proxy.global`.
 *
 * @phpstan-type FetchResponse array{ok: bool, status: int, headers: array<string, string>, body: string}
 */
final class Fetch
{
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
     * @param array{method?: string, headers?: array<string, string>, body?: string|null, timeout?: int|float} $options
     * @return FetchResponse
     */
    public function __invoke(string $url, array $options = []): array
    {
        if ($this->logs) {
            $this->strapi->log()->debug("Making request for {$url}");
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
}
