<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Strapi\CreateStrapiApp\Tests\ScopeFactory;
use Strapi\CreateStrapiApp\Utils\DotEnv;

final class DotEnvTest extends TestCase
{
    public function testGeneratesFreshSecretsLikeUpstream(): void
    {
        $env = DotEnv::generateDotEnv(ScopeFactory::scope());

        self::assertStringStartsWith("\n# Server\nHOST=0.0.0.0\nPORT=1337\n", $env);

        preg_match_all('/^(APP_KEYS|API_TOKEN_SALT|ADMIN_JWT_SECRET|JWT_SECRET|TRANSFER_TOKEN_SALT|ENCRYPTION_KEY)=(.*)$/m', $env, $m, PREG_SET_ORDER);
        self::assertCount(6, $m);

        $secrets = [];
        foreach ($m as [, $name, $value]) {
            $parts = $name === 'APP_KEYS' ? explode(',', $value) : [$value];
            self::assertCount($name === 'APP_KEYS' ? 4 : 1, $parts);
            foreach ($parts as $secret) {
                // crypto.randomBytes(16).toString('base64')
                self::assertMatchesRegularExpression('#^[A-Za-z0-9+/]{22}==$#', $secret);
                self::assertSame(16, strlen((string) base64_decode($secret, true)));
                $secrets[] = $secret;
            }
        }
        self::assertCount(9, array_unique($secrets));

        // a second run gets new secrets
        self::assertNotSame($env, DotEnv::generateDotEnv(ScopeFactory::scope()));
    }

    public function testWritesTheDatabaseConnection(): void
    {
        $sqlite = DotEnv::generateDotEnv(ScopeFactory::scope());
        self::assertStringContainsString("DATABASE_CLIENT=sqlite\nDATABASE_HOST=\nDATABASE_PORT=\nDATABASE_NAME=\nDATABASE_USERNAME=\nDATABASE_PASSWORD=\nDATABASE_SSL=false\nDATABASE_FILENAME=.tmp/data.db\n", $sqlite);

        $postgres = DotEnv::generateDotEnv(ScopeFactory::scope(['database' => [
            'client' => 'postgres',
            'connection' => ['host' => 'db', 'port' => '5433', 'database' => 'app', 'username' => 'u', 'password' => 'p', 'ssl' => true],
        ]]));
        self::assertStringContainsString("DATABASE_CLIENT=postgres\nDATABASE_HOST=db\nDATABASE_PORT=5433\nDATABASE_NAME=app\nDATABASE_USERNAME=u\nDATABASE_PASSWORD=p\nDATABASE_SSL=true\nDATABASE_FILENAME=\n", $postgres);
    }
}
