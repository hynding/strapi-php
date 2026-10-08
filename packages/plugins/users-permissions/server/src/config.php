<?php

declare(strict_types=1);

use Strapi\Plugin\UsersPermissions\Services\Constants;
use Strapi\Utils\EnvHelper;

/** Port of server/src/config.js: `{ default: ({ env }) => ({...}), validator() {} }`. */
return [
    'default' => static fn (EnvHelper $env): array => [
        'jwtSecret' => $env('JWT_SECRET'),
        'jwt' => [
            'expiresIn' => '30d',
        ],
        /*
         * JWT management mode for the Content API authentication
         * - "legacy-support": use plugin JWTs (backward compatible)
         * - "refresh": use SessionManager (access/refresh tokens)
         */
        'jwtManagement' => 'legacy-support',
        'sessions' => [
            'accessTokenLifespan' => Constants::DEFAULT_ACCESS_TOKEN_LIFESPAN,
            'maxRefreshTokenLifespan' => Constants::DEFAULT_MAX_REFRESH_TOKEN_LIFESPAN,
            'idleRefreshTokenLifespan' => Constants::DEFAULT_IDLE_REFRESH_TOKEN_LIFESPAN,
            'maxSessionLifespan' => Constants::DEFAULT_MAX_SESSION_LIFESPAN,
            'idleSessionLifespan' => Constants::DEFAULT_IDLE_SESSION_LIFESPAN,
            'httpOnly' => false,
        ],
        'ratelimit' => [
            'interval' => 60000,
            'max' => 10,
        ],
        'layout' => [
            'user' => [
                'actions' => [
                    'create' => 'contentManagerUser.create', // Use the User plugin's controller.
                    'update' => 'contentManagerUser.update',
                ],
            ],
        ],
        'callback' => [
            'validate' => static function (mixed $callback, mixed $provider): void {
                $uCallback = is_string($callback) ? parse_url($callback) : false;
                $providerCallback = is_array($provider) ? ($provider['callback'] ?? null) : null;
                $uProviderCallback = is_string($providerCallback) ? parse_url($providerCallback) : false;

                // `new URL()` throws on relative URLs: an absolute URL has a scheme and (for http) a host
                $isAbsolute = static fn (mixed $u): bool => is_array($u) && isset($u['scheme']) && (isset($u['host']) || !in_array(strtolower($u['scheme']), ['http', 'https'], true));
                if (!$isAbsolute($uCallback) || !$isAbsolute($uProviderCallback)) {
                    throw new \RuntimeException('The callback is not a valid URL');
                }
                /** @var array<string, mixed> $uCallback */
                /** @var array<string, mixed> $uProviderCallback */

                // `URL.origin`: scheme://host[:port], the default port omitted
                $origin = static function (array $u): string {
                    $scheme = strtolower((string) $u['scheme']);
                    $port = isset($u['port']) && !(($scheme === 'http' && (int) $u['port'] === 80) || ($scheme === 'https' && (int) $u['port'] === 443)) ? ':' . $u['port'] : '';

                    return $scheme . '://' . strtolower((string) ($u['host'] ?? '')) . $port;
                };

                // Make sure the different origin matches
                if ($origin($uCallback) !== $origin($uProviderCallback)) {
                    throw new \RuntimeException("Forbidden callback provided: origins don't match. Please verify your config.");
                }

                // Make sure the different pathname matches
                $pathname = static fn (array $u): string => isset($u['path']) && $u['path'] !== '' ? (string) $u['path'] : '/';
                if ($pathname($uCallback) !== $pathname($uProviderCallback)) {
                    throw new \RuntimeException("Forbidden callback provided: pathname don't match. Please verify your config.");
                }

                // NOTE: We're not checking the search parameters on purpose to allow passing different states
            },
        ],
    ],
    'validator' => static function (): void {
    },
];
