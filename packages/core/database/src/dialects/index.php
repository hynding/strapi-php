<?php

declare(strict_types=1);

namespace Strapi\Database\Dialects;

use Strapi\Database\Database;
use Strapi\Database\Dialects\Mysql\Mysql;
use Strapi\Database\Dialects\Postgresql\Postgresql;
use Strapi\Database\Dialects\Sqlite\Sqlite;

/** Port of packages/core/database/src/dialects/index.ts (`getDialect`). */
final class Dialects
{
    /** @return 'postgres'|'mysql'|'sqlite' */
    public static function getDialectName(mixed $client): string
    {
        return match ($client) {
            'postgres', 'postgresql', 'pg' => 'postgres',
            'mysql', 'mysql2', 'mariadb' => 'mysql',
            'sqlite', 'sqlite3', 'better-sqlite3' => 'sqlite',
            default => throw new \InvalidArgumentException('Unknown dialect ' . (is_scalar($client) ? (string) $client : gettype($client))),
        };
    }

    public static function getDialect(Database $db): Dialect
    {
        $name = self::getDialectName($db->config['connection']['client'] ?? null);

        return match ($name) {
            'postgres' => new Postgresql($db),
            'mysql' => new Mysql($db),
            'sqlite' => new Sqlite($db),
        };
    }
}
