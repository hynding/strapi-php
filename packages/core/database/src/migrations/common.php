<?php

declare(strict_types=1);

namespace Strapi\Database\Migrations;

use Doctrine\DBAL\Connection;
use Strapi\Database\Database;

/**
 * Port of packages/core/database/src/migrations/common.ts.
 *
 * A migration is `['name' => string, 'up' => callable(Connection, Database): void, 'down' => callable(Connection, Database): void]`.
 *
 * @phpstan-type Migration array{name: string, up: callable(Connection, Database): void, down: callable(Connection, Database): void}
 * @phpstan-type RunnableMigration array{name: string, path?: string, up: callable(): void, down: callable(): void}
 */
final class Common
{
    /**
     * Wraps a migration function so it runs inside `db.transaction()`.
     *
     * @param callable(Connection, Database): void $fn
     *
     * @return callable(): void
     */
    public static function wrapTransaction(Database $db, callable $fn): callable
    {
        return static function () use ($db, $fn): void {
            $db->transaction(static function (array $ctx) use ($db, $fn): void {
                $fn($ctx['trx'], $db);
            });
        };
    }
}
