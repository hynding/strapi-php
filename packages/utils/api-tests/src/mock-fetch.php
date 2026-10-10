<?php

declare(strict_types=1);

namespace Strapi\ApiTests;

use Strapi\Core\Utils\Fetch;

/**
 * Upstream's `withMockedFetch(mockFn, fn)` (packages/utils/api-tests/mock-fetch.js) replaces the
 * global `fetch` of the process the instance runs in. Here the instance is the PHP worker:
 * lib/mock-fetch.js installs the test's `mockFn` as the {@see Fetch::intercept()} handler while `fn`
 * runs, so `strapi.fetch` (the upload plugin's URL import, webhooks) asks it first. The function
 * answers with the mocked Response's status, headers and base64 body, or null for the network.
 */
final class MockFetch
{
    public static function install(Callback $mockFn): bool
    {
        Fetch::intercept(static function (string $url, array $options) use ($mockFn): ?array {
            $response = $mockFn($url, $options);
            if (!is_array($response) || !is_int($response['status'] ?? null)) {
                return null;
            }

            $headers = is_array($response['headers'] ?? null) ? $response['headers'] : [];
            $body = base64_decode(is_string($response['body'] ?? null) ? $response['body'] : '', true);

            return [
                'status' => $response['status'],
                'statusText' => is_string($response['statusText'] ?? null) ? $response['statusText'] : '',
                'headers' => array_map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '', $headers),
                'body' => $body === false ? '' : $body,
            ];
        });

        return true;
    }

    public static function uninstall(): bool
    {
        Fetch::intercept(null);

        return true;
    }
}
