<?php

declare(strict_types=1);

namespace Strapi\Admin\Migrations\Database;

use Doctrine\DBAL\Connection;
use Strapi\Database\Database;

/**
 * Port of server/src/migrations/database/migrate-prefered-language-dk-to-da.ts.
 *
 * Migrates persisted admin UI language from the legacy Danish code `dk` to ISO 639-1 `da`.
 * {@see self::migration()} is the migration array `strapi.db.migrations.internal.register()` takes.
 */
final class MigratePreferedLanguageDkToDa
{
    private const ADMIN_USERS_TABLE = 'admin_users';
    private const PREFERED_LANGUAGE_COLUMN = 'prefered_language';

    public const NAME = 'admin::migrate-prefered-language-dk-to-da';

    /** @return array{name: string, up: callable(Connection, Database): void, down: callable(Connection, Database): void} */
    public static function migration(): array
    {
        return [
            'name' => self::NAME,
            'up' => self::up(...),
            'down' => self::down(...),
        ];
    }

    public static function up(Connection $trx, Database $db): void
    {
        $schemaManager = $db->getSchemaConnection($trx);
        $table = $db->getSchemaName() !== null ? $db->getSchemaName() . '.' . self::ADMIN_USERS_TABLE : self::ADMIN_USERS_TABLE;

        if (!$schemaManager->tablesExist([$table])) {
            return;
        }

        $hasColumn = false;
        foreach ($schemaManager->listTableColumns($table) as $column) {
            if (strtolower($column->getName()) === self::PREFERED_LANGUAGE_COLUMN) {
                $hasColumn = true;
                break;
            }
        }

        if (!$hasColumn) {
            return;
        }

        $trx->createQueryBuilder()
            ->update($table)
            ->set(self::PREFERED_LANGUAGE_COLUMN, ':da')
            ->where(self::PREFERED_LANGUAGE_COLUMN . ' = :dk')
            ->setParameter('da', 'da')
            ->setParameter('dk', 'dk')
            ->executeStatement();
    }

    public static function down(Connection $trx, Database $db): void
    {
        throw new \RuntimeException('not implemented');
    }
}
