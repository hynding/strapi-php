<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Utils;

/**
 * Port of server/src/utils/provider-http.js. Upstream calls the global `fetch`; here requests go
 * through core's `strapi.fetch` ({@see \Strapi\Core\Utils\Fetch}), installed by the plugin's
 * register phase. Tests replace {@see self::$fetch} as upstream's tests replace `global.fetch`.
 *
 * A fetch function is `callable(string $url, array{method?: string, headers?: array<string, string>, body?: string}): array{ok: bool, status: int, headers: array<string, string>, body: string}`.
 */
final class ProviderHttp
{
    /** @var callable|null the fetch function in use (tests) */
    public static $fetch = null;

    /** @var callable|null core's `strapi.fetch`, set by the plugin's register phase */
    public static $defaultFetch = null;

    /**
     * @param array{method?: string, headers?: array<string, string>, body?: string} $options
     * @return array{ok: bool, status: int, headers: array<string, string>, body: string}
     */
    public static function fetch(string $url, array $options = []): array
    {
        $fetch = self::$fetch ?? self::$defaultFetch;
        if ($fetch === null) {
            throw new \RuntimeException('fetch is not available');
        }

        $response = $fetch($url, $options);
        if (!is_array($response)) {
            throw new \RuntimeException('fetch failed');
        }

        $headers = [];
        foreach (is_array($response['headers'] ?? null) ? $response['headers'] : [] as $name => $value) {
            $headers[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        $status = (int) ($response['status'] ?? 0);

        return [
            'ok' => (bool) ($response['ok'] ?? ($status >= 200 && $status < 300)),
            'status' => $status,
            'headers' => $headers,
            'body' => (string) ($response['body'] ?? ''),
        ];
    }

    /**
     * `response.json()`.
     *
     * @param array{body: string} $response
     */
    public static function json(array $response): mixed
    {
        try {
            return json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @param array{method?: string, headers?: array<string, string>, body?: string} $options
     * @return array{body: mixed}
     */
    public static function fetchJson(string $url, array $options = []): array
    {
        $response = self::fetch($url, $options);
        $contentType = $response['headers']['content-type'] ?? '';

        if (str_contains($contentType, 'application/json')) {
            $body = self::json($response);
        } else {
            $text = $response['body'];
            try {
                $body = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $body = $text;
            }
        }

        if (!$response['ok']) {
            $message = is_array($body)
                ? ($body['error_description'] ?? $body['error'] ?? $body['message'] ?? null)
                : $body;
            throw new \RuntimeException(is_scalar($message) && (string) $message !== '' ? (string) $message : "HTTP {$response['status']}");
        }

        return ['body' => $body];
    }

    /**
     * @param array{headers?: array<string, string>, qs?: array<string, mixed>} $options
     * @return array{body: mixed}
     */
    public static function bearerGet(string $url, mixed $accessToken, array $options = []): array
    {
        $target = $url;
        $qs = $options['qs'] ?? [];
        if ($qs !== []) {
            $target = self::setSearchParams($url, $qs);
        }

        return self::fetchJson($target, [
            'headers' => [
                'Authorization' => 'Bearer ' . self::stringify($accessToken),
                ...($options['headers'] ?? []),
            ],
        ]);
    }

    /**
     * `new URL(url)` + `searchParams.set(key, String(value))` for each non-null value.
     *
     * @param array<string, mixed> $params
     */
    public static function setSearchParams(string $url, array $params): string
    {
        $hashPos = strpos($url, '#');
        $hash = $hashPos === false ? '' : substr($url, $hashPos);
        $base = $hashPos === false ? $url : substr($url, 0, $hashPos);
        $queryPos = strpos($base, '?');
        $query = $queryPos === false ? '' : substr($base, $queryPos + 1);
        $base = $queryPos === false ? $base : substr($base, 0, $queryPos);

        $pairs = self::parseSearchParams($query);
        foreach ($params as $key => $value) {
            if ($value === null) {
                continue;
            }
            $key = (string) $key;
            $found = false;
            $next = [];
            foreach ($pairs as [$k, $v]) {
                if ($k === $key) {
                    if (!$found) {
                        $next[] = [$k, self::stringify($value)];
                        $found = true;
                    }
                    continue;
                }
                $next[] = [$k, $v];
            }
            if (!$found) {
                $next[] = [$key, self::stringify($value)];
            }
            $pairs = $next;
        }

        $search = self::serializeSearchParams($pairs);

        return $base . ($search !== '' ? '?' . $search : '') . $hash;
    }

    /** @return list<array{0: string, 1: string}> `new URLSearchParams(text)` entries */
    public static function parseSearchParams(string $query): array
    {
        $query = ltrim($query, '?');
        $pairs = [];
        foreach ($query === '' ? [] : explode('&', $query) as $part) {
            if ($part === '') {
                continue;
            }
            $eq = strpos($part, '=');
            $name = $eq === false ? $part : substr($part, 0, $eq);
            $value = $eq === false ? '' : substr($part, $eq + 1);
            $pairs[] = [urldecode($name), urldecode($value)];
        }

        return $pairs;
    }

    /**
     * `URLSearchParams.toString()` (application/x-www-form-urlencoded serialization).
     *
     * @param list<array{0: string, 1: string}> $pairs
     */
    public static function serializeSearchParams(array $pairs): string
    {
        $encode = static fn (string $s): string => str_replace(['%2A', '%7E'], ['*', '%7E'], urlencode($s));

        return implode('&', array_map(static fn (array $p): string => $encode($p[0]) . '=' . $encode($p[1]), $pairs));
    }

    /** `String(value)` */
    public static function stringify(mixed $value): string
    {
        return match (true) {
            $value === true => 'true',
            $value === false => 'false',
            $value === null => 'null',
            is_array($value) => implode(',', array_map(self::stringify(...), $value)),
            is_scalar($value) => (string) $value,
            default => '',
        };
    }
}
