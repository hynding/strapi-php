<?php

declare(strict_types=1);

namespace Strapi\Core\Middlewares;

use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/middlewares/security.ts together with the helmet headers it
 * configures (koa-helmet 7 / helmet 7 defaults). Options use helmet's names:
 * `contentSecurityPolicy` (`useDefaults`, `directives`), `hsts` (`maxAge`, `includeSubDomains`, `preload`),
 * `frameguard` (`action`), `crossOriginEmbedderPolicy`, `crossOriginOpenerPolicy`,
 * `crossOriginResourcePolicy`, `originAgentCluster`, `referrerPolicy`, `xssFilter`, `noSniff`,
 * `dnsPrefetchControl`, `ieNoOpen`, `permittedCrossDomainPolicies`, `hidePoweredBy` — `false` disables one.
 */
final class Security
{
    /** `CSP_DEFAULTS` of @strapi/utils security.ts */
    public const CSP_DEFAULTS = [
        'connect-src' => ["'self'", 'https:'],
        'img-src' => ["'self'", 'data:', 'blob:', 'https://market-assets.strapi.io'],
        'media-src' => ["'self'", 'data:', 'blob:'],
    ];

    /** helmet's `useDefaults` content security policy. */
    public const HELMET_CSP_DEFAULTS = [
        'default-src' => ["'self'"],
        'base-uri' => ["'self'"],
        'font-src' => ["'self'", 'https:', 'data:'],
        'form-action' => ["'self'"],
        'frame-ancestors' => ["'self'"],
        'img-src' => ["'self'", 'data:'],
        'object-src' => ["'none'"],
        'script-src' => ["'self'"],
        'script-src-attr' => ["'none'"],
        'style-src' => ["'self'", 'https:', "'unsafe-inline'"],
        'upgrade-insecure-requests' => [],
    ];

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'crossOriginEmbedderPolicy' => false,
            'crossOriginOpenerPolicy' => false,
            'crossOriginResourcePolicy' => false,
            'originAgentCluster' => false,
            'contentSecurityPolicy' => [
                'useDefaults' => true,
                'directives' => [
                    ...self::CSP_DEFAULTS,
                    'upgradeInsecureRequests' => null,
                ],
            ],
            'xssFilter' => false,
            'hsts' => ['maxAge' => 31536000, 'includeSubDomains' => true],
            'frameguard' => ['action' => 'sameorigin'],
        ];
    }

    /**
     * lodash mergeWith concatenating arrays.
     *
     * @param array<string, mixed> $existing
     * @param array<string, mixed> $new
     * @return array<string, mixed>
     */
    private static function mergeConfig(array $existing, array $new): array
    {
        foreach ($new as $key => $value) {
            if (is_array($value) && isset($existing[$key]) && is_array($existing[$key])) {
                $existing[$key] = array_is_list($value) && array_is_list($existing[$key])
                    ? [...$existing[$key], ...$value]
                    : self::mergeConfig($existing[$key], $value);
            } else {
                $existing[$key] = $value;
            }
        }

        return $existing;
    }

    /**
     * lodash defaultsDeep(dst, src): missing keys of $config filled from $defaults recursively.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private static function defaultsDeep(array $config, array $defaults): array
    {
        foreach ($defaults as $key => $value) {
            if (!array_key_exists($key, $config) || $config[$key] === null) {
                $config[$key] = $value;
            } elseif (is_array($config[$key]) && is_array($value) && !array_is_list($value)) {
                $config[$key] = self::defaultsDeep($config[$key], $value);
            }
        }

        return $config;
    }

    /** @param array<string, mixed> $config */
    public function __invoke(array $config, Strapi $strapi): callable
    {
        $baseConfig = self::defaultsDeep($config, self::defaults());

        return static function (Context $ctx, callable $next) use ($baseConfig, $strapi): void {
            $helmetConfig = $baseConfig;
            $specialPaths = ['/documentation'];

            $directives = [
                'script-src' => ["'self'", "'unsafe-inline'", 'cdn.jsdelivr.net'],
                'img-src' => ["'self'", 'data:', 'cdn.jsdelivr.net', 'strapi.io'],
                'manifest-src' => [],
                'frame-src' => [],
            ];

            // if apollo graphql playground is enabled, add exceptions for it
            if ($strapi->hasPlugin('graphql')) {
                $graphql = $strapi->plugin('graphql');
                try {
                    $utils = $graphql->service('utils');
                    $playground = is_object($utils) && method_exists($utils, 'playground') ? $utils->playground() : null;
                    if (is_object($playground) && method_exists($playground, 'isEnabled') && $playground->isEnabled()) {
                        $specialPaths[] = (string) $graphql->config('endpoint');

                        $directives['script-src'][] = "https: 'unsafe-inline'";
                        $directives['img-src'][] = "'apollo-server-landing-page.cdn.apollographql.com'";
                        $directives['manifest-src'][] = "'self'";
                        $directives['manifest-src'][] = 'apollo-server-landing-page.cdn.apollographql.com';
                        $directives['frame-src'][] = "'self'";
                        $directives['frame-src'][] = 'sandbox.embed.apollographql.com';
                    }
                } catch (\Throwable) {
                    // no utils service
                }
            }

            if ($ctx->method() === 'GET') {
                foreach ($specialPaths as $str) {
                    if (str_starts_with($ctx->path(), $str)) {
                        $helmetConfig = self::mergeConfig($helmetConfig, [
                            'crossOriginEmbedderPolicy' => false,
                            'contentSecurityPolicy' => ['directives' => $directives],
                        ]);
                        break;
                    }
                }
            }

            /**
             * These are for vite's watch mode so it can accurately connect to the HMR websocket.
             * It only applies in development, and only on GET requests that are part of the admin route.
             */
            if (
                in_array($strapi->config()->get('environment'), ['development', 'test'], true)
                && $ctx->method() === 'GET'
                && str_starts_with($ctx->path(), (string) $strapi->config()->get('admin.path', '/admin'))
            ) {
                $helmetConfig = self::mergeConfig($helmetConfig, [
                    'contentSecurityPolicy' => [
                        'directives' => [
                            'script-src' => ["'self'", "'unsafe-inline'"],
                            'connect-src' => ["'self'", 'http:', 'https:', 'ws:'],
                        ],
                    ],
                ]);
            }

            self::applyHelmet($ctx, $helmetConfig);

            $next();
        };
    }

    /** helmet(options)(ctx, next): set the headers. @param array<string, mixed> $options */
    public static function applyHelmet(Context $ctx, array $options): void
    {
        $csp = $options['contentSecurityPolicy'] ?? [];
        if ($csp !== false) {
            $csp = is_array($csp) ? $csp : [];
            $ctx->setHeader('Content-Security-Policy', self::buildCsp($csp));
        }

        if (($options['crossOriginEmbedderPolicy'] ?? false) !== false) {
            $policy = is_array($options['crossOriginEmbedderPolicy']) ? ($options['crossOriginEmbedderPolicy']['policy'] ?? 'require-corp') : 'require-corp';
            $ctx->setHeader('Cross-Origin-Embedder-Policy', (string) $policy);
        }
        if (($options['crossOriginOpenerPolicy'] ?? false) !== false) {
            $policy = is_array($options['crossOriginOpenerPolicy']) ? ($options['crossOriginOpenerPolicy']['policy'] ?? 'same-origin') : 'same-origin';
            $ctx->setHeader('Cross-Origin-Opener-Policy', (string) $policy);
        }
        if (($options['crossOriginResourcePolicy'] ?? false) !== false) {
            $policy = is_array($options['crossOriginResourcePolicy']) ? ($options['crossOriginResourcePolicy']['policy'] ?? 'same-origin') : 'same-origin';
            $ctx->setHeader('Cross-Origin-Resource-Policy', (string) $policy);
        }
        if (($options['originAgentCluster'] ?? false) !== false) {
            $ctx->setHeader('Origin-Agent-Cluster', '?1');
        }

        $referrer = $options['referrerPolicy'] ?? [];
        if ($referrer !== false) {
            $policy = is_array($referrer) ? ($referrer['policy'] ?? 'no-referrer') : 'no-referrer';
            $ctx->setHeader('Referrer-Policy', is_array($policy) ? implode(',', $policy) : (string) $policy);
        }

        $hsts = $options['hsts'] ?? [];
        if ($hsts !== false) {
            $hsts = is_array($hsts) ? $hsts : [];
            $maxAge = (int) ($hsts['maxAge'] ?? 15552000);
            $value = "max-age={$maxAge}";
            if (($hsts['includeSubDomains'] ?? true) !== false) {
                $value .= '; includeSubDomains';
            }
            if (($hsts['preload'] ?? false) === true) {
                $value .= '; preload';
            }
            $ctx->setHeader('Strict-Transport-Security', $value);
        }

        if (($options['noSniff'] ?? true) !== false) {
            $ctx->setHeader('X-Content-Type-Options', 'nosniff');
        }

        if (($options['dnsPrefetchControl'] ?? true) !== false) {
            $allow = is_array($options['dnsPrefetchControl'] ?? null) && ($options['dnsPrefetchControl']['allow'] ?? false) === true;
            $ctx->setHeader('X-DNS-Prefetch-Control', $allow ? 'on' : 'off');
        }

        if (($options['ieNoOpen'] ?? true) !== false) {
            $ctx->setHeader('X-Download-Options', 'noopen');
        }

        $frameguard = $options['frameguard'] ?? [];
        if ($frameguard !== false) {
            $action = is_array($frameguard) ? ($frameguard['action'] ?? 'sameorigin') : 'sameorigin';
            $ctx->setHeader('X-Frame-Options', strtoupper((string) $action));
        }

        if (($options['permittedCrossDomainPolicies'] ?? true) !== false) {
            $permitted = is_array($options['permittedCrossDomainPolicies'] ?? null) ? ($options['permittedCrossDomainPolicies']['permittedPolicies'] ?? 'none') : 'none';
            $ctx->setHeader('X-Permitted-Cross-Domain-Policies', (string) $permitted);
        }

        if (($options['hidePoweredBy'] ?? true) !== false) {
            $ctx->removeHeader('X-Powered-By');
        }

        if (($options['xssFilter'] ?? true) !== false) {
            $ctx->setHeader('X-XSS-Protection', '0');
        }
    }

    /** @param array<string, mixed> $csp */
    public static function buildCsp(array $csp): string
    {
        $useDefaults = ($csp['useDefaults'] ?? true) !== false;
        $directives = [];
        if ($useDefaults) {
            $directives = self::HELMET_CSP_DEFAULTS;
        }
        foreach ($csp['directives'] ?? [] as $name => $value) {
            $dashed = self::dashify((string) $name);
            if ($value === null) {
                unset($directives[$dashed]);
                continue;
            }
            $directives[$dashed] = is_array($value) ? array_values(array_map('strval', $value)) : [(string) $value];
        }

        $parts = [];
        foreach ($directives as $name => $values) {
            $parts[] = $values === [] ? $name : $name . ' ' . implode(' ', $values);
        }

        return implode(';', $parts);
    }

    private static function dashify(string $name): string
    {
        return strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1-$2', $name));
    }
}
