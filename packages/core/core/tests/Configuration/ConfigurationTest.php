<?php

declare(strict_types=1);

namespace Strapi\Core\Tests\Configuration;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use Strapi\Core\Configuration\Configuration;

final class ConfigurationTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/strapi-config-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        putenv('ENV_PATH');
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/.env');
        @rmdir($this->dir);
    }

    #[RunInSeparateProcess]
    public function testNodeEnvFromDotEnvIsKept(): void
    {
        putenv('NODE_ENV');
        unset($_ENV['NODE_ENV'], $_SERVER['NODE_ENV']);
        file_put_contents($this->dir . '/.env', "NODE_ENV=production\nSOME_KEY=from-file\n");

        $env = Configuration::loadEnv($this->dir);

        self::assertSame('production', $env('NODE_ENV'));
        self::assertSame('production', getenv('NODE_ENV'));
        self::assertSame('from-file', getenv('SOME_KEY'));
    }

    #[RunInSeparateProcess]
    public function testNodeEnvDefaultsToDevelopment(): void
    {
        putenv('NODE_ENV');
        unset($_ENV['NODE_ENV'], $_SERVER['NODE_ENV']);
        file_put_contents($this->dir . '/.env', "SOME_KEY=x\n");

        $env = Configuration::loadEnv($this->dir);

        self::assertSame('development', $env('NODE_ENV'));
        self::assertSame('development', getenv('NODE_ENV'));
    }

    #[RunInSeparateProcess]
    public function testProcessEnvironmentWinsOverDotEnv(): void
    {
        putenv('NODE_ENV=test');
        file_put_contents($this->dir . '/.env', "NODE_ENV=production\n");

        $env = Configuration::loadEnv($this->dir);

        self::assertSame('test', $env('NODE_ENV'));
        self::assertSame('test', getenv('NODE_ENV'));
    }
}
