<?php

declare(strict_types=1);

namespace Strapi\Database\Migrations;

/**
 * Port of packages/core/database/src/migrations/runner.ts.
 *
 * @phpstan-import-type RunnableMigration from Common
 */
final class Runner
{
    /** The spellings of one migration across runtimes: `name.php` here, `name.js` (or TypeScript) on Node Strapi. */
    private const array RUNTIME_EXTENSIONS = ['php', 'js', 'ts', 'mjs', 'cjs'];

    /** @var callable(): list<RunnableMigration> */
    private $getMigrations;

    /** @var callable(mixed): void */
    private $logger;

    /**
     * @param callable(): list<RunnableMigration> $getMigrations
     * @param callable(mixed): void $logger receives `{event, name, durationSeconds?}` arrays
     */
    public function __construct(private readonly Storage $storage, callable $logger, callable $getMigrations)
    {
        $this->logger = $logger;
        $this->getMigrations = $getMigrations;
    }

    /** @return list<array{name: string, path?: string}> */
    public function pending(): array
    {
        return array_map(self::meta(...), $this->getPendingMigrations());
    }

    /** @return list<array{name: string, path?: string}> */
    public function up(): array
    {
        $toBeApplied = $this->getPendingMigrations();

        foreach ($toBeApplied as $migration) {
            $start = microtime(true);
            ($this->logger)(['event' => 'migrating', 'name' => $migration['name']]);

            try {
                $migration['up']();
            } catch (\Throwable $error) {
                throw self::wrapMigrationError($migration['name'], 'up', $error);
            }

            $this->storage->logMigration($migration['name']);
            ($this->logger)(['event' => 'migrated', 'name' => $migration['name'], 'durationSeconds' => microtime(true) - $start]);
        }

        return array_map(self::meta(...), $toBeApplied);
    }

    /**
     * Reverts the last executed migration.
     *
     * @return list<array{name: string, path?: string}>
     */
    public function down(): array
    {
        $executedReversed = array_reverse($this->getExecutedMigrations());
        $toBeReverted = array_slice($executedReversed, 0, 1);

        foreach ($toBeReverted as $migration) {
            $start = microtime(true);
            ($this->logger)(['event' => 'reverting', 'name' => $migration['name']]);

            try {
                $migration['down']();
            } catch (\Throwable $error) {
                throw self::wrapMigrationError($migration['name'], 'down', $error);
            }

            $this->storage->unlogMigration($migration['name']);
            ($this->logger)(['event' => 'reverted', 'name' => $migration['name'], 'durationSeconds' => round(microtime(true) - $start, 3)]);
        }

        return array_map(self::meta(...), $toBeReverted);
    }

    /** @return list<RunnableMigration> */
    private function getPendingMigrations(): array
    {
        $executed = array_flip($this->storage->executed());

        return array_values(array_filter(($this->getMigrations)(), static fn (array $m): bool => !self::isExecuted($executed, $m['name'])));
    }

    /**
     * PHP port: a migration counts as executed under any runtime's spelling of its name. Node
     * Strapi logs `2024.01.01T00.00.00.add-column.js` where this port logs `….php`; on a database
     * both runtimes share, the twin that has already run on the other runtime must not run again.
     *
     * @param array<string, int> $executed logged names, as keys
     */
    private static function isExecuted(array $executed, string $name): bool
    {
        if (isset($executed[$name])) {
            return true;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        if (!in_array($extension, self::RUNTIME_EXTENSIONS, true)) {
            return false;
        }

        $stem = substr($name, 0, -strlen($extension));
        foreach (self::RUNTIME_EXTENSIONS as $twin) {
            if (isset($executed[$stem . $twin])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<RunnableMigration> */
    private function getExecutedMigrations(): array
    {
        $executed = array_flip($this->storage->executed());

        return array_values(array_filter(($this->getMigrations)(), static fn (array $m): bool => isset($executed[$m['name']])));
    }

    /**
     * @param RunnableMigration $migration
     *
     * @return array{name: string, path?: string}
     */
    private static function meta(array $migration): array
    {
        $meta = ['name' => $migration['name']];
        if (isset($migration['path'])) {
            $meta['path'] = $migration['path'];
        }

        return $meta;
    }

    private static function wrapMigrationError(string $name, string $direction, \Throwable $cause): \RuntimeException
    {
        return new \RuntimeException("Migration {$name} ({$direction}) failed: Original error: {$cause->getMessage()}", 0, $cause);
    }
}
