<?php

declare(strict_types=1);

namespace Strapi\Database\Migrations;

use Strapi\Database\Database;

/** Port of packages/core/database/src/migrations/users.ts: migrations in `settings.migrations.dir`. */
final class Users
{
    private Runner $runner;

    public function __construct(private readonly Database $db)
    {
        $dir = (string) ($db->config['settings']['migrations']['dir'] ?? '');
        if ($dir !== '' && !is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }

        $this->runner = new Runner(
            new Storage($db, 'strapi_migrations'),
            static function (mixed $message) use ($db): void {
                Logger::log($db->logger, 'info', $message);
            },
            static function () use ($db, $dir): array {
                $filepaths = $dir !== '' ? Discover::discoverMigrationFiles($dir) : [];

                return Resolver::resolveMigrationFiles($filepaths, $db);
            },
        );
    }

    public function shouldRun(): bool
    {
        return $this->runner->pending() !== [] && ($this->db->config['settings']['runMigrations'] ?? false) === true;
    }

    public function up(): void
    {
        $this->runner->up();
    }

    public function down(): void
    {
        $this->runner->down();
    }
}
