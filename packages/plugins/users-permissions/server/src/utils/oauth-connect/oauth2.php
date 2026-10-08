<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Utils\OauthConnect;

use Strapi\Plugin\UsersPermissions\Utils\ProviderHttp;

/** Port of server/src/utils/oauth-connect/oauth2.js. */
final class Oauth2
{
    public static function formatScope(mixed $scope, ?string $delimiter = ','): ?string
    {
        $delimiter ??= ',';
        if (is_array($scope)) {
            $joined = implode($delimiter, array_map(ProviderHttp::stringify(...), array_filter($scope, static fn (mixed $s): bool => $s !== null && $s !== '' && $s !== false && $s !== 0)));

            return $joined !== '' ? $joined : null;
        }

        return $scope !== null && $scope !== '' && $scope !== false && $scope !== 0 ? ProviderHttp::stringify($scope) : null;
    }

    public static function substituteSubdomain(string $url, mixed $subdomain): string
    {
        // String.prototype.replace with a string pattern replaces the first occurrence only
        if ($subdomain === null || $subdomain === '' || $subdomain === false) {
            return $url;
        }
        $pos = strpos($url, '[subdomain]');

        return $pos === false ? $url : substr_replace($url, ProviderHttp::stringify($subdomain), $pos, strlen('[subdomain]'));
    }

    /**
     * @param array<string, mixed> $provider
     * @param array{key?: mixed, redirectUri?: mixed, scope?: mixed, subdomain?: mixed} $options
     */
    public static function buildAuthorizeUrl(array $provider, array $options): string
    {
        $authorizeUrl = self::substituteSubdomain((string) ($provider['authorize_url'] ?? ''), $options['subdomain'] ?? null);
        $key = ProviderHttp::stringify($options['key'] ?? null);
        $params = [
            ['client_id', $key],
            ['response_type', 'code'],
            ['redirect_uri', ProviderHttp::stringify($options['redirectUri'] ?? null)],
        ];

        $delimiter = $provider['scope_delimiter'] ?? null;
        $formattedScope = self::formatScope($options['scope'] ?? null, is_string($delimiter) ? $delimiter : null);
        if ($formattedScope !== null) {
            $params[] = ['scope', $formattedScope];
        }

        if (($provider['name'] ?? null) === 'instagram' && preg_match('/^\d+$/', $key) === 1) {
            $params = array_values(array_filter($params, static fn (array $p): bool => $p[0] !== 'client_id'));
            $params[] = ['app_id', $key];
            if ($formattedScope !== null) {
                $params = array_map(static fn (array $p): array => $p[0] === 'scope' ? ['scope', str_replace(' ', ',', $formattedScope)] : $p, $params);
            }
        }

        return $authorizeUrl . '?' . ProviderHttp::serializeSearchParams($params);
    }

    /**
     * @param array{ok: bool, status: int, headers: array<string, string>, body: string} $response
     * @return array<string, mixed>
     */
    public static function parseTokenResponse(array $response): array
    {
        $contentType = $response['headers']['content-type'] ?? '';
        if (str_contains($contentType, 'application/json')) {
            $json = ProviderHttp::json($response);

            return is_array($json) ? $json : [];
        }

        $out = [];
        foreach (ProviderHttp::parseSearchParams($response['body']) as [$k, $v]) {
            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $provider
     * @param array{key?: mixed, secret?: mixed, redirectUri?: mixed, code?: mixed, subdomain?: mixed} $options
     * @return array<string, mixed>
     */
    public static function exchangeAuthorizationCode(array $provider, array $options): array
    {
        $accessUrl = self::substituteSubdomain((string) ($provider['access_url'] ?? ''), $options['subdomain'] ?? null);
        $key = ProviderHttp::stringify($options['key'] ?? null);
        $secret = ProviderHttp::stringify($options['secret'] ?? null);

        $body = [
            'grant_type' => 'authorization_code',
            'code' => ProviderHttp::stringify($options['code'] ?? null),
            'redirect_uri' => ProviderHttp::stringify($options['redirectUri'] ?? null),
            'client_id' => $key,
            'client_secret' => $secret,
        ];

        $headers = ['Content-Type' => 'application/x-www-form-urlencoded'];

        if (($provider['token_endpoint_auth_method'] ?? null) === 'client_secret_basic') {
            $credentials = base64_encode("{$key}:{$secret}");
            $headers['Authorization'] = "Basic {$credentials}";
            unset($body['client_id'], $body['client_secret']);
        }

        if (($provider['name'] ?? null) === 'instagram' && preg_match('/^\d+$/', $key) === 1) {
            unset($body['client_id'], $body['client_secret']);
            $body['app_id'] = $key;
            $body['app_secret'] = $secret;
        }

        $pairs = [];
        foreach ($body as $k => $v) {
            $pairs[] = [(string) $k, $v];
        }

        $response = ProviderHttp::fetch($accessUrl, ['method' => 'POST', 'headers' => $headers, 'body' => ProviderHttp::serializeSearchParams($pairs)]);
        $output = self::parseTokenResponse($response);

        if (!$response['ok']) {
            $message = $output['error_description'] ?? $output['error'] ?? null;
            throw new \RuntimeException(is_scalar($message) && (string) $message !== '' ? (string) $message : "Token exchange failed ({$response['status']})");
        }

        return $output;
    }

    /**
     * @param array<string, mixed> $provider
     * @param array<string, mixed> $tokenResponse
     * @return array<string, mixed>
     */
    public static function tokensToQueryPayload(array $provider, array $tokenResponse): array
    {
        $data = ['raw' => $tokenResponse];
        $truthy = static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== false && $v !== 0;

        if (($provider['oauth'] ?? null) === 1) {
            if ($truthy($tokenResponse['oauth_token'] ?? null)) {
                $data['access_token'] = $tokenResponse['oauth_token'];
            }
            if ($truthy($tokenResponse['oauth_token_secret'] ?? null)) {
                $data['access_secret'] = $tokenResponse['oauth_token_secret'];
            }

            return $data;
        }

        if ($truthy($tokenResponse['id_token'] ?? null)) {
            $data['id_token'] = $tokenResponse['id_token'];
        }
        if ($truthy($tokenResponse['access_token'] ?? null)) {
            $data['access_token'] = $tokenResponse['access_token'];
        }
        if ($truthy($tokenResponse['refresh_token'] ?? null)) {
            $data['refresh_token'] = $tokenResponse['refresh_token'];
        }

        return $data;
    }

    public static function generateState(): string
    {
        return bin2hex(random_bytes(20));
    }
}
