<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Utils\OauthConnect;

use Strapi\Plugin\UsersPermissions\Utils\ProviderHttp;

/** Port of server/src/utils/oauth-connect/oauth1.js. */
final class Oauth1
{
    /** `encodeURIComponent` plus `!'()*` (RFC 3986). */
    public static function encode(string $str): string
    {
        return rawurlencode($str);
    }

    /**
     * OAuth 1.0 (RFC 5849) request signature — HMAC-SHA1 is required by the protocol.
     * This is not password storage or user-credential hashing (those use bcrypt).
     */
    private static function signRfc5849BaseString(string $signatureBaseString, string $signingMaterial): string
    {
        return base64_encode(hash_hmac('sha1', $signatureBaseString, $signingMaterial, true));
    }

    /**
     * @param array{method: string, url: string, params?: array<string, mixed>, consumerKey: mixed, clientCredential: mixed, token?: mixed, tokenCredential?: mixed} $options
     */
    public static function buildOAuth1Header(array $options): string
    {
        $token = $options['token'] ?? null;
        $requestParameters = [
            'oauth_consumer_key' => ProviderHttp::stringify($options['consumerKey']),
            'oauth_nonce' => bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => (string) time(),
            'oauth_version' => '1.0',
            ...($token !== null && $token !== '' ? ['oauth_token' => ProviderHttp::stringify($token)] : []),
        ];
        foreach ($options['params'] ?? [] as $key => $value) {
            $requestParameters[(string) $key] = ProviderHttp::stringify($value);
        }

        $keys = array_map('strval', array_keys($requestParameters));
        sort($keys, SORT_STRING);
        $paramString = implode('&', array_map(static fn (string $key): string => self::encode($key) . '=' . self::encode($requestParameters[$key]), $keys));

        $signatureBaseString = implode('&', [strtoupper($options['method']), self::encode($options['url']), self::encode($paramString)]);
        $tokenCredential = $options['tokenCredential'] ?? null;
        $signingMaterial = self::encode(ProviderHttp::stringify($options['clientCredential'])) . '&' . self::encode($tokenCredential !== null ? ProviderHttp::stringify($tokenCredential) : '');
        $signature = self::signRfc5849BaseString($signatureBaseString, $signingMaterial);

        $headerParameters = [...$requestParameters, 'oauth_signature' => $signature];
        $headerKeys = array_map('strval', array_keys($headerParameters));
        sort($headerKeys, SORT_STRING);

        return 'OAuth ' . implode(', ', array_map(static fn (string $key): string => self::encode($key) . '="' . self::encode($headerParameters[$key]) . '"', $headerKeys));
    }

    /**
     * @param array{method: string, url: string, consumerKey: mixed, clientCredential: mixed, token?: mixed, tokenCredential?: mixed, params?: array<string, mixed>} $options
     * @return array<string, string>
     */
    private static function oauth1Request(array $options): array
    {
        $authorization = self::buildOAuth1Header($options);

        $response = ProviderHttp::fetch($options['url'], [
            'method' => $options['method'],
            'headers' => ['Authorization' => $authorization],
        ]);

        $text = $response['body'];
        if (!$response['ok']) {
            throw new \RuntimeException($text !== '' ? $text : "OAuth1 request failed ({$response['status']})");
        }

        $out = [];
        foreach (ProviderHttp::parseSearchParams($text) as [$k, $v]) {
            $out[$k] = $v;
        }

        return $out;
    }

    /**
     * @param array{requestUrl: string, redirectUri: string, consumerKey: mixed, clientCredential: mixed} $options
     * @return array<string, string>
     */
    public static function requestToken(array $options): array
    {
        return self::oauth1Request([
            'method' => 'POST',
            'url' => $options['requestUrl'],
            'consumerKey' => $options['consumerKey'],
            'clientCredential' => $options['clientCredential'],
            'params' => ['oauth_callback' => $options['redirectUri']],
        ]);
    }

    /**
     * @param array{accessUrl: string, consumerKey: mixed, clientCredential: mixed, oauthToken: mixed, oauthVerifier: mixed, oauthTokenCredential: mixed} $options
     * @return array<string, string>
     */
    public static function accessToken(array $options): array
    {
        return self::oauth1Request([
            'method' => 'POST',
            'url' => $options['accessUrl'],
            'consumerKey' => $options['consumerKey'],
            'clientCredential' => $options['clientCredential'],
            'token' => $options['oauthToken'],
            'tokenCredential' => $options['oauthTokenCredential'],
            'params' => ['oauth_verifier' => $options['oauthVerifier']],
        ]);
    }

    /**
     * @param array{url: string, accessToken: mixed, accessCredential: mixed, consumerKey: mixed, clientCredential: mixed, qs?: array<string, mixed>} $options
     * @return array{body: mixed}
     */
    public static function twitterGet(array $options): array
    {
        $signedParams = [];
        foreach ($options['qs'] ?? [] as $key => $value) {
            if ($value !== null) {
                // RFC 5849 §3.4.1: base-string URI excludes the query; params are signed separately.
                $signedParams[(string) $key] = ProviderHttp::stringify($value);
            }
        }
        $target = ProviderHttp::setSearchParams($options['url'], $signedParams);

        $parts = parse_url($options['url']);
        $originAndPath = (is_array($parts) ? (($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '') . ($parts['path'] ?? '/')) : $options['url']);

        $authorization = self::buildOAuth1Header([
            'method' => 'GET',
            'url' => $originAndPath,
            'params' => $signedParams,
            'consumerKey' => $options['consumerKey'],
            'clientCredential' => $options['clientCredential'],
            'token' => $options['accessToken'],
            'tokenCredential' => $options['accessCredential'],
        ]);

        $response = ProviderHttp::fetch($target, ['headers' => ['Authorization' => $authorization]]);

        $body = ProviderHttp::json($response);
        if (!$response['ok']) {
            $message = is_array($body) ? ($body['errors'][0]['message'] ?? null) : null;
            throw new \RuntimeException(is_string($message) && $message !== '' ? $message : "Twitter API error ({$response['status']})");
        }

        return ['body' => $body];
    }
}
