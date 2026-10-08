<?php

declare(strict_types=1);

namespace Strapi\Database\Migrations;

use Doctrine\DBAL\Connection;
use Strapi\Database\Database;

/**
 * Port of packages/core/database/src/migrations/resolver.ts.
 *
 * A `.php` migration file returns `['up' => fn(Connection $trx, Database $db) => ..., 'down' => ...]`;
 * a `.sql` file is executed as-is (no down).
 *
 * @phpstan-import-type RunnableMigration from Common
 */
final class Resolver
{
    /** @return RunnableMigration */
    public static function migrationResolver(string $name, ?string $path, Database $db): array
    {
        if ($path === null) {
            throw new \InvalidArgumentException("Migration {$name} has no path");
        }

        if (preg_match('/\.sql$/', $path) === 1) {
            $sql = (string) file_get_contents($path);

            return [
                'name' => $name,
                'path' => $path,
                'up' => Common::wrapTransaction($db, static function (Connection $trx) use ($sql): void {
                    $trx->executeStatement($sql);
                }),
                'down' => static function (): void {
                    throw new \RuntimeException('Down migration is not supported for sql files');
                },
            ];
        }

        $migration = require $path;
        if (!is_array($migration) || !isset($migration['up'])) {
            throw new \RuntimeException("Migration file {$path} must return ['up' => callable, 'down' => callable]");
        }

        return [
            'name' => $name,
            'path' => $path,
            'up' => Common::wrapTransaction($db, $migration['up']),
            'down' => Common::wrapTransaction($db, $migration['down'] ?? static function (): void {
                throw new \RuntimeException('not implemented');
            }),
        ];
    }

    /**
     * @param list<string> $filepaths
     *
     * @return list<RunnableMigration>
     */
    public static function resolveMigrationFiles(array $filepaths, Database $db): array
    {
        return array_map(static fn (string $path): array => self::migrationResolver(basename($path), $path, $db), $filepaths);
    }
}
