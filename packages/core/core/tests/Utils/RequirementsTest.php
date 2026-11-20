<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\Core\Utils\Requirements;
use Strapi\Types\Core\StrapiDirectories;

final class RequirementsTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/strapi-requirements-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/public/uploads', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (['/public/uploads', '/public', '/.tmp', '/.strapi', ''] as $dir) {
            @chmod($this->root . $dir, 0o755);
        }
        foreach (['/public/uploads', '/public', '/.tmp', '/.strapi', ''] as $dir) {
            @rmdir($this->root . $dir);
        }
    }

    /** A host with everything, as a web server runs it. */
    private const array HEALTHY = [
        'sapi' => 'fpm-fcgi',
        'phpVersion' => '8.3.6',
        'extensions' => ['Core', 'ctype', 'json', 'mbstring', 'openssl', 'PDO', 'pdo_sqlite', 'pdo_mysql', 'intl', 'gd', 'fileinfo', 'zlib', 'Zend OPcache'],
        'env' => ['NODE_ENV' => 'production', 'APP_KEYS' => 'a,b', 'JWT_SECRET' => 'secret'],
    ];

    /**
     * @param array<string, string|false> $ini
     * @param array<string, mixed> $platform
     * @return list<array{level: 'error'|'warning', name: string, message: string}>
     */
    private function check(?string $client = 'sqlite', array $platform = [], array $ini = []): array
    {
        $ini += ['opcache.enable' => '1', 'opcache.enable_cli' => '0', 'opcache.jit' => 'disable', 'opcache.jit_buffer_size' => '0', 'enable_post_data_reading' => '0'];

        return Requirements::check(StrapiDirectories::fromRoot($this->root), $client, [
            ...self::HEALTHY,
            'ini' => static fn (string $name): string|false => $ini[$name] ?? false,
            ...$platform,
        ]);
    }

    /**
     * @param list<array{level: string, name: string, message: string}> $findings
     * @return array<string, string>
     */
    private static function names(array $findings): array
    {
        return array_column($findings, 'level', 'name');
    }

    public function testAHealthyHostHasNoFinding(): void
    {
        self::assertSame([], $this->check());
        self::assertFalse(Requirements::hasErrors([]));
    }

    public function testThePhpVersionAndRequiredExtensionsAreErrors(): void
    {
        $findings = $this->check('mysql', ['phpVersion' => '8.2.20', 'extensions' => ['json', 'PDO', 'pdo_sqlite', 'intl', 'gd', 'fileinfo', 'zlib', 'Zend OPcache']]);

        self::assertSame(['php' => 'error', 'ext-ctype' => 'error', 'ext-mbstring' => 'error', 'ext-openssl' => 'error', 'ext-pdo_mysql' => 'error'], self::names($findings));
        self::assertTrue(Requirements::hasErrors($findings));
        self::assertStringContainsString('the mysql database client needs it', Requirements::format($findings));
    }

    public function testEachDatabaseClientNeedsItsDriver(): void
    {
        foreach (['sqlite', 'mysql', 'mysql2', 'mariadb'] as $client) {
            self::assertSame([], $this->check($client), $client);
        }
        foreach (['postgres', 'postgresql', 'pg'] as $client) {
            self::assertSame(['ext-pdo_pgsql' => 'error'], self::names($this->check($client)), $client);
        }
        self::assertSame(['database' => 'error'], self::names($this->check('oracle')));
        self::assertSame(['database' => 'error'], self::names($this->check('better-sqlite3')), 'what the database package cannot connect');
        self::assertSame([], $this->check(null), 'no client known, no driver checked');
    }

    public function testRecommendedExtensionsAreWarnings(): void
    {
        $findings = $this->check(platform: ['extensions' => ['ctype', 'json', 'mbstring', 'openssl', 'PDO', 'pdo_sqlite', 'Zend OPcache']]);

        self::assertSame(['ext-intl' => 'warning', 'ext-gd' => 'warning', 'ext-fileinfo' => 'warning', 'ext-zlib' => 'warning'], self::names($findings));
        self::assertFalse(Requirements::hasErrors($findings));
    }

    public function testDirectoriesMustBeWritableOrCreatable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('root writes everywhere');
        }
        chmod($this->root . '/public/uploads', 0o555);
        chmod($this->root, 0o555);

        self::assertSame(['.tmp' => 'error', 'uploads' => 'warning', '.strapi' => 'warning'], self::names($this->check()));

        chmod($this->root, 0o755);
        self::assertSame(['uploads' => 'warning'], self::names($this->check()), '.tmp and .strapi can be created');
    }

    public function testWebServerSettings(): void
    {
        self::assertSame(['opcache' => 'warning'], self::names($this->check(ini: ['opcache.enable' => '0'])));
        self::assertSame(['opcache' => 'warning'], self::names($this->check(platform: ['extensions' => ['ctype', 'json', 'mbstring', 'openssl', 'PDO', 'pdo_sqlite', 'intl', 'gd', 'fileinfo', 'zlib']])));
        self::assertSame(['opcache.jit' => 'warning'], self::names($this->check(ini: ['opcache.jit' => '1235', 'opcache.jit_buffer_size' => '64M'])));
        self::assertSame(['opcache.jit' => 'warning'], self::names($this->check(ini: ['opcache.jit' => 'tracing', 'opcache.jit_buffer_size' => '128M'])));
        self::assertSame([], $this->check(ini: ['opcache.jit' => 'tracing', 'opcache.jit_buffer_size' => '0']), 'no buffer, no JIT');
        self::assertSame(['enable_post_data_reading' => 'warning'], self::names($this->check(ini: ['enable_post_data_reading' => 'On'])));
    }

    public function testTheCommandLineIsNotAWebServer(): void
    {
        // opcache.enable_cli is off: neither "OPcache is off" nor a JIT warning
        self::assertSame([], $this->check(platform: ['sapi' => 'cli', 'env' => []], ini: ['opcache.jit' => '1235', 'opcache.jit_buffer_size' => '64M', 'enable_post_data_reading' => '1']));
        self::assertSame(['opcache.jit' => 'warning'], self::names($this->check(platform: ['sapi' => 'cli'], ini: ['opcache.enable_cli' => '1', 'opcache.jit' => '1235', 'opcache.jit_buffer_size' => '64M'])));
        // PHP's built-in server follows opcache.enable_cli too
        self::assertSame(['opcache' => 'warning'], self::names($this->check(platform: ['sapi' => 'cli-server'])));
        self::assertSame([], $this->check(platform: ['sapi' => 'cli-server'], ini: ['opcache.enable' => '0', 'opcache.enable_cli' => '1']));
        // FrankenPHP's worker mode boots once
        self::assertSame([], $this->check(platform: ['sapi' => 'frankenphp', 'frankenphpWorker' => true, 'env' => []]));
    }

    public function testNodeEnvAndExampleSecrets(): void
    {
        self::assertSame(['NODE_ENV' => 'warning'], self::names($this->check(platform: ['env' => []])));
        self::assertStringContainsString('NODE_ENV is "development"', Requirements::format($this->check(platform: ['env' => ['NODE_ENV' => 'development']])));
        self::assertSame([], $this->check(platform: ['env' => ['NODE_ENV' => 'staging']]), 'staging settles like production');
        self::assertSame([], $this->check(platform: ['sapi' => 'cli-server', 'env' => []], ini: ['opcache.enable_cli' => '1']), '`strapi develop` runs in development');

        $findings = $this->check(platform: ['env' => ['NODE_ENV' => 'production', 'APP_KEYS' => 'toBeModified1,toBeModified2', 'JWT_SECRET' => 'tobemodified', 'ADMIN_JWT_SECRET' => 'x']]);
        self::assertSame(['secrets' => 'warning'], self::names($findings));
        self::assertSame(['secrets' => 'warning'], self::names($this->check(platform: ['env' => ['NODE_ENV' => 'staging', 'JWT_SECRET' => 'tobemodified']])), 'staging is deployed too');
        self::assertSame([], $this->check(platform: ['sapi' => 'cli', 'env' => ['NODE_ENV' => 'test', 'JWT_SECRET' => 'tobemodified']]));
        self::assertStringStartsWith('[warning] APP_KEYS, JWT_SECRET still have the example values', Requirements::format($findings));
    }

    public function testErrorsComeFirst(): void
    {
        $findings = $this->check('postgres', ['extensions' => ['ctype', 'json', 'mbstring', 'openssl', 'PDO', 'Zend OPcache']]);

        self::assertSame('error', $findings[0]['level']);
        self::assertSame('ext-pdo_pgsql', $findings[0]['name']);
    }

    public function testTheRunningPhp(): void
    {
        $findings = Requirements::check(StrapiDirectories::fromRoot($this->root), 'sqlite');

        self::assertFalse(Requirements::hasErrors($findings), Requirements::format($findings));
    }
}
