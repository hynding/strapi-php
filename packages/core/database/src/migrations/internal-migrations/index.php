<?php

declare(strict_types=1);

namespace Strapi\Database\Migrations\InternalMigrations;

/**
 * Port of packages/core/database/src/migrations/internal-migrations/index.ts.
 *
 * The 5.0.0 migrations upgrade a v4 database to v5. This port only runs on databases created by
 * Strapi 5 (Node or PHP), where they are no-ops or already logged in `strapi_migrations_internal`,
 * so they are registered under their upstream names as no-ops to keep the log table identical.
 * TODO: port the v4 → v5 data migrations (identifier renames, document ids) if upgrading v4
 * databases from PHP becomes a goal.
 *
 * @phpstan-import-type Migration from \Strapi\Database\Migrations\Common
 */
final class InternalMigrations
{
    public const NAMES = [
        '5.0.0-01-convert-identifiers-long-than-max-length',
        '5.0.0-02-created-document-id',
        '5.0.0-03-created-locale',
        '5.0.0-04-created-published-at',
        '5.0.0-05-drop-slug-fields-index',
        '5.0.0-06-add-document-id-indexes',
    ];

    /** @return list<Migration> */
    public static function all(): array
    {
        return array_map(static fn (string $name): array => [
            'name' => $name,
            'up' => static function (): void {
                // no-op (see class comment)
            },
            'down' => static function (): void {
                throw new \RuntimeException('not implemented');
            },
        ], self::NAMES);
    }
}
