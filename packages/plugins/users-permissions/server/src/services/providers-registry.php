<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Services;

use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Utils\OauthConnect\Oauth1;
use Strapi\Plugin\UsersPermissions\Utils\ProviderHttp;
use Strapi\Plugin\UsersPermissions\Utils\UrlJoin;
use Strapi\Plugin\UsersPermissions\Utils\VerifyJwtWithJwks;

/**
 * Port of server/src/services/providers-registry.js.
 *
 * A provider is `['enabled' => bool, 'icon' => string, 'grantConfig' => array, 'authCallback' => callable]`
 * where `authCallback(array{accessToken?: mixed, query?: mixed, providers?: array, grantResponse?: array}): array{username: mixed, email: mixed}`
 * (upstream's `async authCallback({ accessToken, query, providers, grantResponse })`).
 *
 * @phpstan-type AuthProvider array<string, mixed>
 */
final class ProvidersRegistry
{
    /** @var array<string, AuthProvider> */
    private array $authProviders;

    public function __construct(private readonly Strapi $strapi)
    {
        $apiPrefix = (string) $this->strapi->config()->get('api.rest.prefix');
        $baseURL = UrlJoin::join((string) $this->strapi->config()->get('server.url', ''), $apiPrefix, 'auth');

        $this->authProviders = $this->initProviders($baseURL);
    }

    /**
     * `x?.y` / truthiness helpers over decoded JSON.
     */
    private static function truthy(mixed $value): bool
    {
        return $value !== null && $value !== false && $value !== '' && $value !== 0 && $value !== 0.0;
    }

    /** @return array<string, mixed> */
    private static function arr(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @return array<string, AuthProvider> */
    private function initProviders(string $baseURL): array
    {
        $strapi = $this->strapi;

        return [
            'email' => [
                'enabled' => true,
                'icon' => 'envelope',
                'grantConfig' => [],
            ],
            'discord' => [
                'enabled' => false,
                'icon' => 'discord',
                'grantConfig' => [
                    'key' => '',
                    'secret' => '',
                    'callbackUrl' => "{$baseURL}/discord/callback",
                    'scope' => ['identify', 'email'],
                ],
                'authCallback' => static function (array $args): array {
                    $body = self::arr(ProviderHttp::bearerGet('https://discord.com/api/users/@me', $args['accessToken'] ?? null)['body']);
                    if (($body['verified'] ?? null) !== true) {
                        throw new \RuntimeException('Email not verified by Discord');
                    }
                    $username = self::truthy($body['discriminator'] ?? null) && ($body['discriminator'] ?? null) !== '0'
                        ? ProviderHttp::stringify($body['username'] ?? null) . '#' . ProviderHttp::stringify($body['discriminator'])
                        : ($body['username'] ?? null);

                    return [
                        'username' => $username,
                        'email' => $body['email'] ?? null,
                    ];
                },
            ],
            'facebook' => [
                'enabled' => false,
                'icon' => 'facebook-square',
                'grantConfig' => [
                    'key' => '',
                    'secret' => '',
                    'callbackUrl' => "{$baseURL}/facebook/callback",
                    'scope' => ['email'],
                ],
                'authCallback' => static function (array $args): array {
                    $body = self::arr(ProviderHttp::bearerGet('https://graph.facebook.com/me', $args['accessToken'] ?? null, [
                        'qs' => ['fields' => 'name,email'],
                    ])['body']);
                    if (!self::truthy($body['email'] ?? null)) {
                        throw new \RuntimeException('Email not verified by Facebook');
                    }

                    return [
                        'username' => $body['name'] ?? null,
                        'email' => $body['email'],
                    ];
                },
            ],
            'google' => [
                'enabled' => false,
                'icon' => 'google',
                'grantConfig' => [
                    'key' => '',
                    'secret' => '',
                    'callbackUrl' => "{$baseURL}/google/callback",
                    'scope' => ['email'],
                ],
                'authCallback' => static function (array $args): array {
                    $body = self::arr(ProviderHttp::fetchJson(
                        'https://oauth2.googleapis.com/tokeninfo?access_token=' . rawurlencode(ProviderHttp::stringify($args['accessToken'] ?? null))
                    )['body']);
                    $verified = $body['verified_email'] ?? $body['email_verified'] ?? null;
                    if ($verified !== true && $verified !== 'true') {
                        throw new \RuntimeException('Email not verified by Google');
                    }

                    return [
                        'username' => explode('@', ProviderHttp::stringify($body['email'] ?? null))[0],
                        'email' => $body['email'] ?? null,
                    ];
                },
            ],
            'github' => [
                'enabled' => false,
                'icon' => 'github',
                'grantConfig' => [
                    'key' => '',
                    'secret' => '',
                    'callbackUrl' => "{$baseURL}/github/callback",
                    'scope' => ['user', 'user:email'],
                ],
                'authCallback' => static function (array $args): array {
                    $userBody = self::arr(ProviderHttp::bearerGet('https://api.github.com/user', $args['accessToken'] ?? null, [
                        'headers' => ['user-agent' => 'strapi'],
                    ])['body']);

                    $emailBody = ProviderHttp::bearerGet('https://api.github.com/user/emails', $args['accessToken'] ?? null, [
                        'headers' => ['user-agent' => 'strapi'],
                    ])['body'];
                    $emails = is_array($emailBody) && array_is_list($emailBody) ? $emailBody : null;

                    if (self::truthy($userBody['email'] ?? null)) {
                        $verifiedPublicEmail = null;
                        foreach ($emails ?? [] as $entry) {
                            if (is_array($entry) && ($entry['email'] ?? null) === $userBody['email'] && ($entry['verified'] ?? null) === true) {
                                $verifiedPublicEmail = $entry;
                                break;
                            }
                        }

                        if ($verifiedPublicEmail === null) {
                            throw new \RuntimeException('Email not verified by GitHub');
                        }

                        return [
                            'username' => $userBody['login'] ?? null,
                            'email' => $userBody['email'],
                        ];
                    }

                    $primaryEmail = null;
                    foreach ($emails ?? [] as $entry) {
                        if (is_array($entry) && ($entry['primary'] ?? null) === true) {
                            $primaryEmail = $entry;
                            break;
                        }
                    }

                    if ($primaryEmail === null || ($primaryEmail['verified'] ?? null) !== true) {
                        throw new \RuntimeException('Email not verified by GitHub');
                    }

                    return [
                        'username' => $userBody['login'] ?? null,
                        'email' => $primaryEmail['email'] ?? null,
                    ];
                },
            ],
            'microsoft' => [
                'enabled' => false,
                'icon' => 'windows',
                'grantConfig' => [
                    'key' => '',
                    'secret' => '',
                    'callbackUrl' => "{$baseURL}/microsoft/callback",
                    'scope' => ['user.read'],
                ],
                'authCallback' => static function (array $args): array {
                    $body = self::arr(ProviderHttp::bearerGet('https://graph.microsoft.com/v1.0/me', $args['accessToken'] ?? null)['body']);

                    return [
                        'username' => $body['userPrincipalName'] ?? null,
                        'email' => $body['userPrincipalName'] ?? null,
                    ];
                },
            ],

            'twitter' => [
                'enabled' => false,
                'icon' => 'twitter',
                'grantConfig' => [
                    'key' => '',
                    'secret' => '',
                    'callbackUrl' => "{$baseURL}/twitter/callback",
                ],
                'authCallback' => static function (array $args): array {
                    $grantResponse = self::arr($args['grantResponse'] ?? null);
                    $accessSecret = $grantResponse['access_secret'] ?? null;
                    $screenName = self::arr($grantResponse['raw'] ?? null)['screen_name'] ?? null;

                    if (!self::truthy($accessSecret)) {
                        throw new \RuntimeException('Twitter authentication requires a completed OAuth session');
                    }

                    $providers = self::arr($args['providers'] ?? null);
                    $twitter = self::arr($providers['twitter'] ?? null);
                    $body = self::arr(Oauth1::twitterGet([
                        'url' => 'https://api.twitter.com/1.1/account/verify_credentials.json',
                        'accessToken' => $args['accessToken'] ?? null,
                        'accessCredential' => $accessSecret,
                        'consumerKey' => $twitter['key'] ?? null,
                        'clientCredential' => $twitter['secret'] ?? null,
                        'qs' => ['include_email' => 'true', 'screen_name' => $screenName],
                    ])['body']);

                    if (!self::truthy($body['email'] ?? null)) {
                        throw new \RuntimeException('Email not verified by Twitter');
                    }

                    return [
                        'username' => $body['screen_name'] ?? null,
                        'email' => $body['email'],
                    ];
                },
            ],
            'instagram' => [
                'enabled' => false,
                'icon' => 'instagram',
                'grantConfig' => [
                    'key' => '',
                    'secret' => '',
                    'callbackUrl' => "{$baseURL}/instagram/callback",
                    'scope' => ['user_profile'],
                ],
                'authCallback' => static function (array $args): array {
                    $body = self::arr(ProviderHttp::bearerGet('https://graph.instagram.com/me', $args['accessToken'] ?? null, [
                        'qs' => ['fields' => 'id,username'],
                    ])['body']);

                    return [
                        'username' => $body['username'] ?? null,
                        'email' => ProviderHttp::stringify($body['username'] ?? 'undefined') . '@strapi.io',
                    ];
                },
            ],
            'vk' => [
                'enabled' => false,
                'icon' => 'vk',
                'grantConfig' => [
                    'key' => '',
                    'secret' => '',
                    'callbackUrl' => "{$baseURL}/vk/callback",
                    'scope' => ['email'],
                ],
                'authCallback' => static function (array $args): array {
                    $raw = self::arr(self::arr($args['grantResponse'] ?? null)['raw'] ?? null);
                    $email = $raw['email'] ?? null;
                    $userId = $raw['user_id'] ?? null;

                    if (!self::truthy($email) || !self::truthy($userId)) {
                        throw new \RuntimeException('VK authentication requires a completed OAuth session');
                    }

                    $body = self::arr(ProviderHttp::bearerGet('https://api.vk.com/method/users.get', $args['accessToken'] ?? null, [
                        'qs' => ['user_ids' => $userId, 'v' => '5.122'],
                    ])['body']);

                    $first = self::arr($body['response'] ?? null)[0] ?? null;
                    if (!self::truthy($first)) {
                        throw new \RuntimeException('Invalid VK access token');
                    }
                    $first = self::arr($first);

                    return [
                        'username' => ProviderHttp::stringify($first['last_name'] ?? 'undefined') . ' ' . ProviderHttp::stringify($first['first_name'] ?? 'undefined'),
                        'email' => $email,
                    ];
                },
            ],

            'twitch' => [
                'enabled' => false,
                'icon' => 'twitch',
                'grantConfig' => [
                    'key' => '',
                    'secret' => '',
                    'callbackUrl' => "{$baseURL}/twitch/callback",
                    'scope' => ['user:read:email'],
                ],
                'authCallback' => static function (array $args): array {
                    $providers = self::arr($args['providers'] ?? null);
                    $body = self::arr(ProviderHttp::bearerGet('https://api.twitch.tv/helix/users', $args['accessToken'] ?? null, [
                        'headers' => [
                            'Client-Id' => ProviderHttp::stringify(self::arr($providers['twitch'] ?? null)['key'] ?? 'undefined'),
                        ],
                    ])['body']);
                    $first = self::arr(self::arr($body['data'] ?? null)[0] ?? null);
                    $email = $first['email'] ?? null;
                    if (!self::truthy($email)) {
                        throw new \RuntimeException('Email not verified by Twitch');
                    }

                    return [
                        'username' => $first['login'] ?? null,
                        'email' => $email,
                    ];
                },
            ],

            'linkedin' => [
                'enabled' => false,
                'icon' => 'linkedin',
                'grantConfig' => [
                    'key' => '',
                    'secret' => '',
                    'callbackUrl' => "{$baseURL}/linkedin/callback",
                    'scope' => ['r_liteprofile', 'r_emailaddress'],
                ],
                'authCallback' => static function (array $args): array {
                    $profileBody = self::arr(ProviderHttp::bearerGet('https://api.linkedin.com/v2/me', $args['accessToken'] ?? null)['body']);
                    $emailBody = self::arr(ProviderHttp::bearerGet(
                        'https://api.linkedin.com/v2/emailAddress?q=members&projection=(elements*(handle~))',
                        $args['accessToken'] ?? null
                    )['body']);

                    if (!is_array($emailBody['elements'] ?? null)) {
                        // `emailBody.elements[0]` on undefined throws a TypeError upstream
                        throw new \TypeError("Cannot read properties of undefined (reading '0')");
                    }
                    $email = self::arr(self::arr($emailBody['elements'][0] ?? null)['handle~'] ?? null);

                    if (!self::truthy($email['emailAddress'] ?? null)) {
                        throw new \RuntimeException('Email not verified by LinkedIn');
                    }

                    return [
                        'username' => $profileBody['localizedFirstName'] ?? null,
                        'email' => $email['emailAddress'],
                    ];
                },
            ],

            'cognito' => [
                'enabled' => false,
                'icon' => 'aws',
                'grantConfig' => [
                    'key' => '',
                    'secret' => '',
                    'subdomain' => 'my.subdomain.com',
                    'callback' => "{$baseURL}/cognito/callback",
                    'scope' => ['email', 'openid', 'profile'],
                ],
                'authCallback' => static function (array $args): array {
                    $providers = self::arr($args['providers'] ?? null);
                    $jwksUrl = self::arr($providers['cognito'] ?? null)['jwksurl'] ?? null;
                    if (!is_string($jwksUrl) || parse_url($jwksUrl, PHP_URL_SCHEME) === null) {
                        // `new URL(undefined)` throws
                        throw new \TypeError('Invalid URL');
                    }
                    $idToken = self::arr($args['grantResponse'] ?? null)['id_token'] ?? null;

                    if (!self::truthy($idToken)) {
                        throw new \RuntimeException('Cognito authentication requires a completed OAuth session');
                    }

                    $tokenPayload = VerifyJwtWithJwks::verifyJwtWithJwks(ProviderHttp::stringify($idToken), $jwksUrl);
                    if (($tokenPayload['email_verified'] ?? null) !== true) {
                        throw new \RuntimeException('Email not verified by Cognito');
                    }

                    return [
                        'username' => $tokenPayload['cognito:username'] ?? null,
                        'email' => $tokenPayload['email'] ?? null,
                    ];
                },
            ],

            'reddit' => [
                'enabled' => false,
                'icon' => 'reddit',
                'grantConfig' => [
                    'key' => '',
                    'secret' => '',
                    'callback' => "{$baseURL}/reddit/callback",
                    'scope' => ['identity'],
                ],
                'authCallback' => static function (array $args): array {
                    $body = self::arr(ProviderHttp::bearerGet('https://oauth.reddit.com/api/v1/me', $args['accessToken'] ?? null, [
                        'headers' => ['user-agent' => 'strapi'],
                    ])['body']);

                    return [
                        'username' => $body['name'] ?? null,
                        'email' => ProviderHttp::stringify($body['name'] ?? 'undefined') . '@strapi.io',
                    ];
                },
            ],

            'auth0' => [
                'enabled' => false,
                'icon' => '',
                'grantConfig' => [
                    'key' => '',
                    'secret' => '',
                    'subdomain' => 'my-tenant.eu',
                    'callback' => "{$baseURL}/auth0/callback",
                    'scope' => ['openid', 'email', 'profile'],
                ],
                'authCallback' => static function (array $args): array {
                    $providers = self::arr($args['providers'] ?? null);
                    $subdomain = ProviderHttp::stringify(self::arr($providers['auth0'] ?? null)['subdomain'] ?? 'undefined');
                    $body = self::arr(ProviderHttp::bearerGet("https://{$subdomain}.auth0.com/userinfo", $args['accessToken'] ?? null)['body']);
                    if (self::truthy($body['email'] ?? null) && ($body['email_verified'] ?? null) !== true) {
                        throw new \RuntimeException('Email not verified by Auth0');
                    }
                    $username = null;
                    foreach (['username', 'nickname', 'name'] as $field) {
                        if (self::truthy($body[$field] ?? null)) {
                            $username = $body[$field];
                            break;
                        }
                    }
                    $username ??= explode('@', ProviderHttp::stringify($body['email'] ?? null))[0];
                    $email = self::truthy($body['email'] ?? null)
                        ? $body['email']
                        : preg_replace('/\s+/', '.', ProviderHttp::stringify($username)) . '@strapi.io';

                    return [
                        'username' => $username,
                        'email' => $email,
                    ];
                },
            ],

            'cas' => [
                'enabled' => false,
                'icon' => 'book',
                'grantConfig' => [
                    'key' => '',
                    'secret' => '',
                    'callback' => "{$baseURL}/cas/callback",
                    'scope' => ['openid email'],
                    'subdomain' => 'my.subdomain.com/cas',
                ],
                'authCallback' => static function (array $args) use ($strapi): array {
                    $providers = self::arr($args['providers'] ?? null);
                    $subdomain = ProviderHttp::stringify(self::arr($providers['cas'] ?? null)['subdomain'] ?? 'undefined');
                    $body = self::arr(ProviderHttp::bearerGet("https://{$subdomain}/oidc/profile", $args['accessToken'] ?? null)['body']);
                    $first = static function (array $source, array $keys): mixed {
                        $value = null;
                        foreach ($keys as $key) {
                            $value = $source[$key] ?? null;
                            if (self::truthy($value)) {
                                return $value;
                            }
                        }

                        return $value;
                    };
                    $hasAttributes = self::truthy($body['attributes'] ?? null);
                    $attributes = self::arr($body['attributes'] ?? null);
                    $username = $hasAttributes
                        ? (self::truthy($attributes['strapiusername'] ?? null) ? $attributes['strapiusername'] : $first($body, ['id', 'sub']))
                        : $first($body, ['strapiusername', 'id', 'sub']);
                    $email = $hasAttributes
                        ? $first($attributes, ['strapiemail', 'email'])
                        : $first($body, ['strapiemail', 'email']);
                    $emailVerified = $body['email_verified'] ?? ($attributes['email_verified'] ?? null);
                    if (!self::truthy($username) || !self::truthy($email)) {
                        $strapi->log()->warning('CAS Response Body did not contain required attributes: ' . json_encode($body, JSON_UNESCAPED_SLASHES));
                    }
                    if (self::truthy($email) && $emailVerified !== true) {
                        throw new \RuntimeException('Email not verified by CAS');
                    }

                    return [
                        'username' => $username,
                        'email' => $email,
                    ];
                },
            ],

            'patreon' => [
                'enabled' => false,
                'icon' => '',
                'grantConfig' => [
                    'key' => '',
                    'secret' => '',
                    'callback' => "{$baseURL}/patreon/callback",
                    'scope' => ['identity', 'identity[email]'],
                ],
                'authCallback' => static function (array $args): array {
                    $body = self::arr(ProviderHttp::bearerGet(
                        'https://www.patreon.com/api/oauth2/v2/identity?fields[user]=full_name,email,is_email_verified',
                        $args['accessToken'] ?? null
                    )['body']);
                    $patreonData = self::arr(self::arr($body['data'] ?? null)['attributes'] ?? null);
                    if (($patreonData['is_email_verified'] ?? null) !== true) {
                        throw new \RuntimeException('Email not verified by Patreon');
                    }

                    return [
                        'username' => $patreonData['full_name'] ?? null,
                        'email' => $patreonData['email'] ?? null,
                    ];
                },
            ],
            'keycloak' => [
                'enabled' => false,
                'icon' => '',
                'grantConfig' => [
                    'key' => '',
                    'secret' => '',
                    'subdomain' => 'myKeycloakProvider.com/realms/myrealm',
                    'callback' => "{$baseURL}/keycloak/callback",
                    'scope' => ['openid', 'email', 'profile'],
                ],
                'authCallback' => static function (array $args): array {
                    $providers = self::arr($args['providers'] ?? null);
                    $subdomain = ProviderHttp::stringify(self::arr($providers['keycloak'] ?? null)['subdomain'] ?? 'undefined');
                    $body = self::arr(ProviderHttp::bearerGet(
                        "https://{$subdomain}/protocol/openid-connect/userinfo",
                        $args['accessToken'] ?? null
                    )['body']);
                    if (($body['email_verified'] ?? null) !== true) {
                        throw new \RuntimeException('Email not verified by Keycloak');
                    }

                    return [
                        'username' => $body['preferred_username'] ?? null,
                        'email' => $body['email'] ?? null,
                    ];
                },
            ],
        ];
    }

    /** @return array<string, AuthProvider> */
    public function getAll(): array
    {
        return $this->authProviders;
    }

    /** @return AuthProvider|null */
    public function get(string $name): ?array
    {
        return $this->authProviders[$name] ?? null;
    }

    /** @param AuthProvider $config */
    public function add(string $name, array $config): void
    {
        $this->authProviders[$name] = $config;
    }

    public function remove(string $name): void
    {
        unset($this->authProviders[$name]);
    }

    /**
     * @param array{provider: string, accessToken?: mixed, query?: mixed, providers?: mixed, grantResponse?: mixed} $params
     * @return array<string, mixed>
     */
    public function run(array $params): array
    {
        $authProvider = $this->authProviders[$params['provider']] ?? null;

        if ($authProvider === null) {
            throw new \AssertionError('Unknown auth provider');
        }

        $authCallback = $authProvider['authCallback'] ?? null;
        if (!is_callable($authCallback)) {
            throw new \TypeError('authProvider.authCallback is not a function');
        }

        $result = $authCallback([
            'accessToken' => $params['accessToken'] ?? null,
            'query' => $params['query'] ?? null,
            'providers' => $params['providers'] ?? null,
            'grantResponse' => $params['grantResponse'] ?? null,
        ]);

        return is_array($result) ? $result : [];
    }
}
