<?php

declare(strict_types=1);

namespace Strapi\Database\Dialects\Mysql;

use Doctrine\DBAL\Connection;
use Strapi\Database\Database;

/** Port of packages/core/database/src/dialects/mysql/database-inspector.ts. */
final class DatabaseInspector
{
    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{database: string|null, version: string|null} */
    public function getInformation(?Connection $connection = null): array
    {
        try {
            $version = (string) ($connection ?? $this->db->connection)->fetchOne('SELECT version() as version');
            $versionSplit = explode('-', $version);
            $databaseName = $versionSplit[1] ?? null;

            return [
                'database' => $databaseName !== null && strtolower($databaseName) === 'mariadb' ? Constants::MARIADB : Constants::MYSQL,
                'version' => $versionSplit[0],
            ];
        } catch (\Throwable) {
            return ['database' => null, 'version' => null];
        }
    }
}
