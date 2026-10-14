<?php

declare(strict_types=1);

namespace Strapi\Database\Tests;

use PHPUnit\Framework\TestCase;
use Strapi\Database\Connection;

/**
 * PHP port: Connection::toDbalParams() maps the knex-shaped `connection` block of
 * config/database.php to Doctrine DBAL connection parameters.
 */
final class ConnectionTest extends TestCase
{
    public function testMysqlDatabaseBecomesDbname(): void
    {
        $params = Connection::toDbalParams([
            'client' => 'mysql',
            'connection' => [
                'host' => 'mysql.example.com',
                'port' => '3306',
                'database' => 'sitlab_dev',
                'user' => 'sitlab',
                'password' => 'secret',
            ],
        ]);

        self::assertSame([
            'driver' => 'pdo_mysql',
            'host' => 'mysql.example.com',
            'port' => 3306,
            'user' => 'sitlab',
            'password' => 'secret',
            'dbname' => 'sitlab_dev',
        ], $params);
    }

    public function testPostgresDatabaseBecomesDbname(): void
    {
        $params = Connection::toDbalParams([
            'client' => 'postgres',
            'connection' => [
                'host' => 'localhost',
                'port' => 5432,
                'database' => 'strapi',
                'user' => 'strapi',
                'password' => 'strapi',
                'schema' => 'public',
            ],
        ]);

        self::assertSame('strapi', $params['dbname'] ?? null);
        self::assertArrayNotHasKey('database', $params);
        self::assertSame('public', $params['schema'] ?? null);
    }

    public function testEmptyDatabaseIsLeftOut(): void
    {
        $params = Connection::toDbalParams(['client' => 'mysql', 'connection' => ['host' => 'localhost', 'database' => '']]);

        self::assertArrayNotHasKey('dbname', $params);
    }
}
