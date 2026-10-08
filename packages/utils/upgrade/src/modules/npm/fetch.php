<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Npm;

/**
 * PHP-only: the `fetch(url, { dispatcher: proxyAgent })` upstream's npm package uses — a GET on
 * a stream context, through `HTTP_PROXY` / `HTTPS_PROXY` when set (upstream's rule; `file://`
 * URLs are read directly, which is how a registry or Packagist mirror can be faked).
 *
 * @phpstan-import-type FetchResponse from Types
 */
final class Fetch
{
    /** @return FetchResponse */
    public function __invoke(string $url): array
    {
        if (str_starts_with($url, 'file://')) {
            $body = @file_get_contents($url);

            return $body === false ? ['ok' => false, 'status' => 404, 'body' => ''] : ['ok' => true, 'status' => 200, 'body' => $body];
        }

        $http = [
            'method' => 'GET',
            'header' => "Accept: application/json\r\nUser-Agent: strapi-upgrade",
            'timeout' => 60.0,
            'ignore_errors' => true,
            'follow_location' => 1,
        ];

        $proxy = getenv('HTTP_PROXY') ?: getenv('HTTPS_PROXY') ?: getenv('http_proxy') ?: getenv('https_proxy');
        if (is_string($proxy) && $proxy !== '') {
            $parts = parse_url($proxy);
            if (is_array($parts) && isset($parts['host'])) {
                $http['proxy'] = 'tcp://' . $parts['host'] . ':' . ($parts['port'] ?? 80);
                $http['request_fulluri'] = true;
                if (isset($parts['user'])) {
                    $http['header'] .= "\r\nProxy-Authorization: Basic " . base64_encode(urldecode($parts['user']) . ':' . urldecode($parts['pass'] ?? ''));
                }
            }
        }

        $context = stream_context_create(['http' => $http]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return ['ok' => false, 'status' => 0, 'body' => ''];
        }

        /** @var list<string> $headers */
        $headers = $http_response_header;
        $status = 0;
        foreach ($headers as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $body];
    }
}
