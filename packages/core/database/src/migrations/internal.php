<?php

declare(strict_types=1);

namespace Strapi\Database\Migrations;

use Strapi\Database\Database;
use Strapi\Database\Migrations\InternalMigrations\InternalMigrations;

/**
 * Port of packages/core/database/src/migrations/internal.ts: the built-in migrations tracked in
 * `strapi_migrations_internal`.
 *
 * @phpstan-import-type Migration from Common
 */
final class Internal
{
    /** @var list<Migration> */
    private array $migrations;

    private Runner $runner;

    public function __construct(private readonly Database $db)
    {
        $this->migrations = InternalMigrations::all();

        $this->runner = new Runner(
            new Storage($db, 'strapi_migrations_internal'),
            static function (mixed $message) use ($db): void {
                Logger::log($db->logger, 'info', $message);
            },
            fn (): array => array_map(fn (array $migration): array => [
                'name' => $migration['name'],
                'up' => Common::wrapTransaction($this->db, $migration['up']),
                'down' => Common::wrapTransaction($this->db, $migration['down']),
            ], $this->migrations),
        );
    }

    /** @param Migration $migration */
    public function register(array $migration): void
    {
        $this->migrations[] = $migration;
    }

    public function shouldRun(): bool
    {
        return $this->runner->pending() !== [];
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
