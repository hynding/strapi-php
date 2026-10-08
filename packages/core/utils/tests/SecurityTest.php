<?php

declare(strict_types=1);

namespace Strapi\Utils\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Utils\Security;

/** Port of __tests__/security.test.ts. */
final class SecurityTest extends TestCase
{
    /**
     * @param array<string, mixed> $directives
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private static function csp(array $directives, array $extra = []): array
    {
        return ['contentSecurityPolicy' => [...$extra, 'directives' => $directives]];
    }

    public function testReplacesStringMiddlewareWithObjectConfiguration(): void
    {
        $newConfig = ['name' => 'strapi::security', 'config' => self::csp(['img-src' => ['example.com']])];

        $result = Security::extendMiddlewareConfiguration(['strapi::logger', 'strapi::security', 'strapi::cors'], $newConfig);

        self::assertSame([
            'strapi::logger',
            ['name' => 'strapi::security', 'config' => self::csp(['img-src' => ['example.com']])],
            'strapi::cors',
        ], $result);
    }

    public function testDoesNotModifyOtherStringMiddlewares(): void
    {
        $result = Security::extendMiddlewareConfiguration(
            ['strapi::logger', 'strapi::security', 'strapi::cors'],
            ['name' => 'strapi::security', 'config' => ['test' => 'value']],
        );

        self::assertSame('strapi::logger', $result[0]);
        self::assertSame('strapi::cors', $result[2]);
    }

    public function testMergesConfigurationsWithArrayConcatenation(): void
    {
        $middlewares = [[
            'name' => 'strapi::security',
            'config' => self::csp(['img-src' => ["'self'", 'data:'], 'script-src' => ["'self'"]]),
        ]];
        $newConfig = [
            'name' => 'strapi::security',
            'config' => self::csp(['img-src' => ['example.com', 'another.com'], 'media-src' => ['media.com']]),
        ];

        $result = Security::extendMiddlewareConfiguration($middlewares, $newConfig);

        self::assertSame([
            'name' => 'strapi::security',
            'config' => self::csp([
                'img-src' => ["'self'", 'data:', 'example.com', 'another.com'],
                'script-src' => ["'self'"],
                'media-src' => ['media.com'],
            ]),
        ], $result[0]);
    }

    public function testMergesDeepNestedObjects(): void
    {
        $middlewares = [[
            'name' => 'strapi::security',
            'config' => [
                'contentSecurityPolicy' => ['useDefaults' => true, 'directives' => ['frame-src' => ["'self'"]]],
                'hsts' => ['maxAge' => 31536000],
            ],
        ]];
        $newConfig = [
            'name' => 'strapi::security',
            'config' => [
                'contentSecurityPolicy' => ['directives' => ['img-src' => ['example.com']]],
                'xssFilter' => false,
            ],
        ];

        $result = Security::extendMiddlewareConfiguration($middlewares, $newConfig);

        self::assertSame([
            'name' => 'strapi::security',
            'config' => [
                'contentSecurityPolicy' => [
                    'useDefaults' => true,
                    'directives' => ['frame-src' => ["'self'"], 'img-src' => ['example.com']],
                ],
                'hsts' => ['maxAge' => 31536000],
                'xssFilter' => false,
            ],
        ], $result[0]);
    }

    public function testHandlesEmptyArraysCorrectly(): void
    {
        $result = Security::extendMiddlewareConfiguration(
            [['name' => 'strapi::security', 'config' => self::csp(['img-src' => []])]],
            ['name' => 'strapi::security', 'config' => self::csp(['img-src' => ['example.com']])],
        );

        self::assertSame(['name' => 'strapi::security', 'config' => self::csp(['img-src' => ['example.com']])], $result[0]);
    }

    public function testDoesNotMutateOriginalArrays(): void
    {
        $middlewares = [['name' => 'strapi::security', 'config' => self::csp(['img-src' => ["'self'", 'data:']])]];
        $copy = $middlewares;

        Security::extendMiddlewareConfiguration($middlewares, ['name' => 'strapi::security', 'config' => self::csp(['img-src' => ['example.com']])]);

        self::assertSame($copy, $middlewares);
    }

    public function testReturnsMiddlewaresUnchangedWhenNameDoesNotMatch(): void
    {
        $middlewares = ['strapi::logger', ['name' => 'strapi::cors', 'config' => ['origin' => true]]];

        $result = Security::extendMiddlewareConfiguration($middlewares, ['name' => 'strapi::security', 'config' => ['test' => 'value']]);

        self::assertSame($middlewares, $result);
    }

    public function testHandlesEmptyMiddlewaresArray(): void
    {
        self::assertSame([], Security::extendMiddlewareConfiguration([], ['name' => 'strapi::security', 'config' => ['test' => 'value']]));
    }

    public function testHandlesMiddlewareWithNoConfig(): void
    {
        $result = Security::extendMiddlewareConfiguration(
            [['name' => 'strapi::security']],
            ['name' => 'strapi::security', 'config' => self::csp(['img-src' => ['example.com']])],
        );

        self::assertSame(['name' => 'strapi::security', 'config' => self::csp(['img-src' => ['example.com']])], $result[0]);
    }

    public function testHandlesMiddlewareWithUndefinedName(): void
    {
        $result = Security::extendMiddlewareConfiguration(
            [['config' => ['test' => 'value']]],
            ['name' => 'strapi::security', 'config' => ['newTest' => 'newValue']],
        );

        self::assertSame(['config' => ['test' => 'value']], $result[0]);
    }

    public function testHandlesTypicalCspExtensionForAiFeatures(): void
    {
        $middlewares = [[
            'name' => 'strapi::security',
            'config' => self::csp(['frame-src' => ["'self'"], 'script-src' => ["'self'", "'unsafe-inline'"]], ['useDefaults' => true]),
        ]];
        $s3Domains = ['strapi-ai-staging.s3.us-east-1.amazonaws.com', 'strapi-ai-production.s3.us-east-1.amazonaws.com'];
        $newConfig = [
            'name' => 'strapi::security',
            'config' => self::csp([
                'img-src' => [...Security::CSP_DEFAULTS['img-src'], ...$s3Domains],
                'media-src' => [...Security::CSP_DEFAULTS['media-src'], ...$s3Domains],
            ]),
        ];

        $result = Security::extendMiddlewareConfiguration($middlewares, $newConfig);
        self::assertIsArray($result[0]);
        /** @var array{config: array{contentSecurityPolicy: array{directives: array<string, list<string>>}}} $first */
        $first = $result[0];
        $directives = $first['config']['contentSecurityPolicy']['directives'];

        self::assertSame(["'self'", 'data:', 'blob:', 'https://market-assets.strapi.io', ...$s3Domains], $directives['img-src']);
        self::assertSame(["'self'", 'data:', 'blob:', ...$s3Domains], $directives['media-src']);
        self::assertSame(["'self'", "'unsafe-inline'"], $directives['script-src']);
    }

    public function testHandlesPreviewFrameSrcConfiguration(): void
    {
        $result = Security::extendMiddlewareConfiguration(
            [['name' => 'strapi::security', 'config' => self::csp(['frame-src' => ["'self'"]])]],
            ['name' => 'strapi::security', 'config' => self::csp(['frame-src' => ['https://preview.example.com', 'https://staging.example.com', "'self'"]])],
        );

        self::assertSame(
            ['name' => 'strapi::security', 'config' => self::csp(['frame-src' => ["'self'", 'https://preview.example.com', 'https://staging.example.com']])],
            $result[0],
        );
    }

    public function testCspDefaults(): void
    {
        self::assertSame(["'self'", 'https:'], Security::CSP_DEFAULTS['connect-src']);
    }
}
