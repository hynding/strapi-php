<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Utils\OauthConnect;

use Strapi\Core\Middlewares\Session;
use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Utils\ProviderHttp;
use Strapi\Plugin\UsersPermissions\Utils\TrimGrantSessionResponse;
use Strapi\Plugin\UsersPermissions\Utils\Utils;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ApplicationError;

/**
 * Port of server/src/utils/oauth-connect/index.js: the OAuth 1/2 "connect" flow that replaced
 * `grant`. Koa's `ctx.session` (koa-session) is core's `strapi::session` state
 * ({@see Session::STATE_KEY}); `ctx.state.oauthConnect` is the `oauthConnect` state key.
 */
final class OauthConnect
{
    public const CONNECT_PREFIX = '/connect';

    /** @return array{provider: string, isCallback: bool}|null */
    public static function parseConnectPath(string $requestPath, string $apiPrefix): ?array
    {
        $prefix = $apiPrefix . self::CONNECT_PREFIX . '/';
        if (!str_starts_with($requestPath, $prefix)) {
            return null;
        }

        $remainder = substr($requestPath, strlen($prefix));
        $segments = array_values(array_filter(explode('/', $remainder), static fn (string $s): bool => $s !== ''));
        $provider = $segments[0] ?? null;

        if ($provider === null) {
            return null;
        }

        return [
            'provider' => $provider,
            'isCallback' => ($segments[1] ?? null) === 'callback',
        ];
    }

    /**
     * Resolve OAuth endpoint config from built-ins, falling back to store-defined
     * endpoints so custom providers registered via providers-registry still work.
     *
     * @param array<string, mixed> $storedConfig
     * @return array<string, mixed>|null
     */
    public static function buildProviderConfig(string $providerName, array $storedConfig, string $redirectUri): ?array
    {
        $defaults = Providers::PROVIDERS[$providerName] ?? null;
        $endpoints = $defaults ?? [
            'oauth' => $storedConfig['oauth'] ?? null,
            'authorize_url' => $storedConfig['authorize_url'] ?? null,
            'access_url' => $storedConfig['access_url'] ?? null,
            'request_url' => $storedConfig['request_url'] ?? null,
            'scope_delimiter' => $storedConfig['scope_delimiter'] ?? null,
            'token_endpoint_auth_method' => $storedConfig['token_endpoint_auth_method'] ?? null,
        ];

        $filled = static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== false;
        if (($endpoints['oauth'] ?? null) === 1) {
            if (!$filled($endpoints['request_url'] ?? null) || !$filled($endpoints['authorize_url'] ?? null) || !$filled($endpoints['access_url'] ?? null)) {
                return null;
            }
        } elseif (!$filled($endpoints['authorize_url'] ?? null) || !$filled($endpoints['access_url'] ?? null)) {
            return null;
        }

        // `undefined` values of the spread are dropped (JSON-style)
        return array_filter([
            'name' => $providerName,
            ...$endpoints,
            'key' => $storedConfig['key'] ?? null,
            'secret' => $storedConfig['secret'] ?? null,
            'scope' => $storedConfig['scope'] ?? null,
            'subdomain' => $storedConfig['subdomain'] ?? null,
            'callback' => $storedConfig['callback'] ?? null,
            'redirect_uri' => $redirectUri,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /** @param array<string, mixed> $payload */
    public static function redirectWithPayload(Context $ctx, string $callbackUrl, array $payload): void
    {
        $params = [];
        $set = static function (string $key, string $value) use (&$params): void {
            foreach ($params as $i => [$k]) {
                if ($k === $key) {
                    $params[$i] = [$key, $value];

                    return;
                }
            }
            $params[] = [$key, $value];
        };

        foreach ($payload as $key => $value) {
            if ($key === 'raw') {
                foreach (is_array($value) ? $value : [] as $rawKey => $rawValue) {
                    $set("raw[{$rawKey}]", ProviderHttp::stringify($rawValue));
                }
                continue;
            }
            if ($value !== null) {
                $set((string) $key, ProviderHttp::stringify($value));
            }
        }

        // `url.search = params.toString()`
        $hashPos = strpos($callbackUrl, '#');
        $hash = $hashPos === false ? '' : substr($callbackUrl, $hashPos);
        $base = $hashPos === false ? $callbackUrl : substr($callbackUrl, 0, $hashPos);
        $queryPos = strpos($base, '?');
        $base = $queryPos === false ? $base : substr($base, 0, $queryPos);
        $search = ProviderHttp::serializeSearchParams($params);

        $ctx->redirect($base . ($search !== '' ? '?' . $search : '') . $hash);
    }

    /** @return array<string, mixed>|null koa-session's `ctx.session` (null when the session middleware is not mounted) */
    public static function session(Context $ctx): ?array
    {
        if (!$ctx->state()->has(Session::STATE_KEY)) {
            return null;
        }
        $session = $ctx->state()->get(Session::STATE_KEY);

        return is_array($session) ? $session : [];
    }

    /** @param array<string, mixed> $grant `ctx.session.grant = grant` */
    public static function setGrant(Context $ctx, array $grant): void
    {
        $session = self::session($ctx) ?? [];
        $session['grant'] = $grant;
        $ctx->state()->set(Session::STATE_KEY, $session);
    }

    /** @return array<string, mixed>|null */
    public static function grant(Context $ctx): ?array
    {
        $grant = self::session($ctx)['grant'] ?? null;

        return is_array($grant) ? $grant : null;
    }

    /** @return array<string, mixed> */
    private static function preserveGrantDynamic(Context $ctx): array
    {
        $dynamic = self::grant($ctx)['dynamic'] ?? null;

        return $dynamic !== null && $dynamic !== [] ? ['dynamic' => $dynamic] : [];
    }

    /**
     * @param array<string, mixed> $provider
     * @param array<string, mixed> $tokenResponse
     */
    private static function redirectWithSessionResponse(Context $ctx, string $callbackUrl, array $provider, array $tokenResponse): void
    {
        $grantResponse = Oauth2::tokensToQueryPayload($provider, $tokenResponse);
        self::setGrant($ctx, [
            'response' => TrimGrantSessionResponse::trimGrantSessionResponse($grantResponse, isset($provider['name']) ? (string) $provider['name'] : null),
        ]);

        self::redirectWithPayload($ctx, $callbackUrl, []);
    }

    /**
     * @param array<string, mixed> $provider
     * @param array{provider: string, isCallback: bool} $parsed
     */
    private static function startOAuth1Flow(Context $ctx, array $provider, array $parsed, string $redirectUri): void
    {
        $requestToken = Oauth1::requestToken([
            'requestUrl' => (string) $provider['request_url'],
            'redirectUri' => $redirectUri,
            'consumerKey' => $provider['key'] ?? null,
            'clientCredential' => $provider['secret'] ?? null,
        ]);

        self::setGrant($ctx, [
            ...self::preserveGrantDynamic($ctx),
            'provider' => $parsed['provider'],
            'request' => $requestToken,
        ]);

        $authorizeUrl = $provider['authorize_url'] . '?oauth_token=' . rawurlencode($requestToken['oauth_token'] ?? 'undefined');
        $ctx->redirect($authorizeUrl);
    }

    /**
     * @param array<string, mixed> $provider
     * @param array{provider: string, isCallback: bool} $parsed
     */
    private static function startOAuth2Flow(Context $ctx, array $provider, array $parsed, string $redirectUri): void
    {
        $state = Oauth2::generateState();
        self::setGrant($ctx, [
            ...self::preserveGrantDynamic($ctx),
            'provider' => $parsed['provider'],
            'state' => $state,
        ]);

        $authorizeUrl = Oauth2::buildAuthorizeUrl($provider, [
            'key' => $provider['key'] ?? null,
            'redirectUri' => $redirectUri,
            'scope' => $provider['scope'] ?? null,
            'subdomain' => $provider['subdomain'] ?? null,
        ]);

        $authorizeUrl .= '&state=' . rawurlencode($state);
        $ctx->redirect($authorizeUrl);
    }

    /** @param array<string, mixed> $provider */
    private static function handleOAuth1Callback(Context $ctx, array $provider, string $callbackUrl): void
    {
        $session = self::grant($ctx) ?? [];
        $query = $ctx->query();
        $oauthToken = $query['oauth_token'] ?? null;
        $oauthVerifier = $query['oauth_verifier'] ?? null;
        $requestToken = is_array($session['request'] ?? null) ? $session['request'] : null;

        if (!isset($requestToken['oauth_token']) || $requestToken['oauth_token'] === '' || $oauthToken !== $requestToken['oauth_token']) {
            throw new \RuntimeException('OAuth1 token mismatch');
        }

        $tokenResponse = Oauth1::accessToken([
            'accessUrl' => (string) $provider['access_url'],
            'consumerKey' => $provider['key'] ?? null,
            'clientCredential' => $provider['secret'] ?? null,
            'oauthToken' => $oauthToken,
            'oauthVerifier' => $oauthVerifier,
            'oauthTokenCredential' => $requestToken['oauth_token_secret'] ?? null,
        ]);

        self::redirectWithSessionResponse($ctx, $callbackUrl, $provider, $tokenResponse);
    }

    /** @param array<string, mixed> $provider */
    private static function handleOAuth2Callback(Context $ctx, array $provider, string $callbackUrl, string $redirectUri): void
    {
        $session = self::grant($ctx) ?? [];
        $query = $ctx->query();
        $code = $query['code'] ?? null;
        $queryState = $query['state'] ?? null;
        $error = $query['error'] ?? null;
        $errorDescription = $query['error_description'] ?? null;

        if ($error !== null && $error !== '') {
            self::setGrant($ctx, []);
            self::redirectWithPayload($ctx, $callbackUrl, [
                'error' => $error,
                'error_description' => $errorDescription,
            ]);

            return;
        }

        if ($code === null || $code === '') {
            throw new \RuntimeException('OAuth2 missing code parameter');
        }

        // Reject when session state is missing (not only on mismatch) to close login-CSRF.
        if (!isset($session['state']) || $session['state'] === '' || $queryState !== $session['state']) {
            throw new \RuntimeException('OAuth2 state mismatch');
        }

        $tokenResponse = Oauth2::exchangeAuthorizationCode($provider, [
            'key' => $provider['key'] ?? null,
            'secret' => $provider['secret'] ?? null,
            'redirectUri' => $redirectUri,
            'code' => $code,
            'subdomain' => $provider['subdomain'] ?? null,
        ]);

        self::redirectWithSessionResponse($ctx, $callbackUrl, $provider, $tokenResponse);
    }

    /** @return \Closure(Context, callable): mixed */
    public static function createOAuthConnectMiddleware(Strapi $strapi): \Closure
    {
        return static function (Context $ctx, callable $next) use ($strapi): mixed {
            $apiPrefix = (string) $strapi->config()->get('api.rest.prefix');
            $requestPath = explode('?', $ctx->url())[0];
            $parsed = self::parseConnectPath($requestPath, $apiPrefix);

            if ($parsed === null) {
                return $next();
            }

            if (self::session($ctx) === null) {
                $ctx->throw(400, 'OAuth connect requires session middleware');
            }

            $storedProviders = $strapi->store()->get(['type' => 'plugin', 'name' => 'users-permissions', 'key' => 'grant']);

            $storedConfig = is_array($storedProviders) ? ($storedProviders[$parsed['provider']] ?? null) : null;
            if (!is_array($storedConfig) || !($storedConfig['enabled'] ?? false)) {
                throw new ApplicationError('This provider is disabled');
            }

            $redirectUri = Utils::getService($strapi, 'providers')->buildRedirectUri($parsed['provider']);

            $oauthConnect = $ctx->state()->get('oauthConnect');
            $callbackOverride = (is_array($oauthConnect) ? ($oauthConnect['callback'] ?? null) : null) ?? (self::grant($ctx)['dynamic']['callback'] ?? null);
            $effectiveConfig = $callbackOverride !== null && $callbackOverride !== '' && $callbackOverride !== false
                ? [...$storedConfig, 'callback' => $callbackOverride]
                : $storedConfig;

            $provider = self::buildProviderConfig($parsed['provider'], $effectiveConfig, $redirectUri);

            if ($provider === null) {
                throw new ApplicationError('Unknown OAuth provider');
            }

            if (self::grant($ctx) === null) {
                self::setGrant($ctx, []);
            }

            if (!$parsed['isCallback']) {
                if (($provider['oauth'] ?? null) === 1) {
                    self::startOAuth1Flow($ctx, $provider, $parsed, $redirectUri);

                    return null;
                }
                self::startOAuth2Flow($ctx, $provider, $parsed, $redirectUri);

                return null;
            }

            $callbackUrl = $effectiveConfig['callback'] ?? null;
            if (!is_string($callbackUrl) || $callbackUrl === '') {
                throw new ApplicationError('Provider callback URL is not configured');
            }

            try {
                if (($provider['oauth'] ?? null) === 1) {
                    self::handleOAuth1Callback($ctx, $provider, $callbackUrl);

                    return null;
                }
                self::handleOAuth2Callback($ctx, $provider, $callbackUrl, $redirectUri);

                return null;
            } catch (\Throwable $err) {
                self::setGrant($ctx, []);
                self::redirectWithPayload($ctx, $callbackUrl, [
                    'error' => 'oauth_error',
                    'error_description' => $err->getMessage(),
                ]);

                return null;
            }
        };
    }
}
