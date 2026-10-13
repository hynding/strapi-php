<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync\Services;

use Strapi\Core\Configuration\Configuration;
use Strapi\Core\Strapi;
use Strapi\Database\Migrations\Storage as MigrationStorage;
use StrapiPlugin\RuntimeSync\Engine\Migrations;
use StrapiPlugin\RuntimeSync\PreflightAbort;

/**
 * Mirrors services/preflight.js. What this boot would do to the database, computed between
 * `db.init()` and `db.schema.sync()` (the `beforeSync` hook), without writing anything; then the
 * boot marker written after the sync.
 *
 * @phpstan-type DatabaseReport array{
 *   client: string, schemaHash: string, storedHash: ?string, storedAt: ?string, hashMatches: ?bool,
 *   status: 'NEW'|'UNCHANGED'|'CHANGED', tables: array{added: list<string>, updated: list<string>, removed: list<string>},
 *   migrations: array{pending: list<string>, twinsToLog: list<string>, executed: list<string>}
 * }
 * @phpstan-type Boot array{runtime: string, version: string, packageVersion: string, engine: string, time: string, schemaHash: string, plugins: list<string>, contentTypes: int}
 */
final class Preflight
{
    public const STORE = ['type' => 'plugin', 'name' => 'runtime-sync', 'key' => 'boots'];

    /** @var DatabaseReport|null */
    private ?array $database = null;

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return array{kind: string, version: string, packageVersion: string, engine: string} */
    public function runtime(): array
    {
        return [
            'kind' => 'php',
            'version' => (string) $this->strapi->config()->get('info.strapi', Configuration::version()),
            'packageVersion' => Configuration::version(),
            'engine' => 'PHP ' . PHP_VERSION,
        ];
    }

    /**
     * The database part of this boot's report, captured by `beforeSync`; null before that.
     *
     * @return DatabaseReport|null
     */
    public function database(): ?array
    {
        return $this->database;
    }

    public function isPreflight(): bool
    {
        return getenv('STRAPI_RUNTIME_SYNC_PREFLIGHT') === '1';
    }

    /** `strapi::content-types.beforeSync`: capture, reconcile the migration log, abort when asked. */
    public function beforeSync(): void
    {
        $report = $this->capture();
        $this->database = $report;

        if ($this->isPreflight()) {
            throw new PreflightAbort(['runtime' => $this->runtime(), 'database' => $report, 'boots' => $this->boots()]);
        }

        if ($this->strapi->plugin('runtime-sync')->config('reconcileMigrations') === true && $report['migrations']['twinsToLog'] !== []) {
            $storage = new MigrationStorage($this->strapi->db(), 'strapi_migrations');
            foreach ($report['migrations']['twinsToLog'] as $name) {
                $storage->logMigration($name);
            }
            $this->strapi->log()->info('[runtime-sync] migrations already run on the other runtime, logged: ' . implode(', ', $report['migrations']['twinsToLog']));
        }
    }

    /** `strapi::content-types.afterSync`: record this boot for the other runtime and the admin page. */
    public function afterSync(): void
    {
        if ($this->strapi->plugin('runtime-sync')->config('recordBoots') !== true) {
            return;
        }
        $db = $this->strapi->db();
        $boots = $this->boots();
        $boots['php'] = [
            ...$this->runtime(),
            'runtime' => 'php',
            'time' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'schemaHash' => $db->schema->schemaStorage->hashSchema($db->schema->getSchema()),
            'plugins' => $this->pluginNames(),
            'contentTypes' => count($this->strapi->contentTypes()),
        ];
        unset($boots['php']['kind']);
        $this->strapi->store()->set([...self::STORE, 'value' => $boots]);
    }

    /**
     * The boot markers of both runtimes, from the core store (empty before the first boot).
     *
     * @return array<string, array<string, mixed>> keyed by runtime, each a Boot
     */
    public function boots(): array
    {
        $db = $this->strapi->db();
        if (!$db->getSchemaConnection()->tablesExist(['strapi_core_store_settings'])) {
            return [];
        }
        $value = $this->strapi->store()->get(self::STORE);

        $boots = [];
        foreach (is_array($value) ? $value : [] as $runtime => $boot) {
            if (is_array($boot)) {
                $boots[(string) $runtime] = $boot;
            }
        }

        return $boots;
    }

    /** @return list<string> */
    public function pluginNames(): array
    {
        $names = array_map(strval(...), array_keys($this->strapi->get('plugins')->getAll()));
        sort($names, SORT_STRING);

        return $names;
    }

    /** The schema's `time` column as ISO 8601: SQLite stores the epoch in milliseconds, the others a datetime string. */
    public static function toIso(mixed $time): ?string
    {
        if ($time instanceof \DateTimeInterface) {
            return \DateTimeImmutable::createFromInterface($time)->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
        }
        if (is_int($time) || (is_string($time) && ctype_digit($time))) {
            return gmdate('Y-m-d\TH:i:s', intdiv((int) $time, 1000)) . sprintf('.%03dZ', ((int) $time) % 1000);
        }
        try {
            return is_string($time) ? (new \DateTimeImmutable($time, new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return DatabaseReport */
    private function capture(): array
    {
        $db = $this->strapi->db();
        $schema = $db->schema->getSchema();
        $storage = $db->schema->schemaStorage;
        $hash = $storage->hashSchema($schema);

        $stored = $db->getSchemaConnection()->tablesExist(['strapi_database_schema']) ? $storage->read() : null;
        $tables = ['added' => [], 'updated' => [], 'removed' => []];
        $status = 'NEW';
        if ($stored !== null) {
            ['status' => $diffStatus, 'diff' => $diff] = $db->schema->schemaDiff->diff([
                'previousSchema' => $stored['schema'],
                'databaseSchema' => $db->dialect->schemaInspector->getSchema(),
                'userSchema' => $schema,
            ]);
            $status = $diffStatus === 'CHANGED' ? 'CHANGED' : 'UNCHANGED';
            $tables = [
                'added' => array_map(static fn (array $t): string => (string) $t['name'], $diff['tables']['added'] ?? []),
                'updated' => array_map(static fn (array $t): string => (string) $t['name'], $diff['tables']['updated'] ?? []),
                'removed' => array_map(static fn (array $t): string => (string) $t['name'], $diff['tables']['removed'] ?? []),
            ];
        }

        $dir = (string) ($db->config['settings']['migrations']['dir'] ?? '');
        $files = $dir !== '' && is_dir($dir) ? array_values(array_filter(scandir($dir) ?: [], static fn (string $f): bool => $f !== '.' && $f !== '..' && is_file($dir . '/' . $f))) : [];
        $executed = $db->getSchemaConnection()->tablesExist(['strapi_migrations']) ? (new MigrationStorage($db, 'strapi_migrations'))->executed() : [];

        return [
            'client' => (string) $this->strapi->config()->get('database.connection.client', 'sqlite'),
            'schemaHash' => $hash,
            'storedHash' => $stored['hash'] ?? null,
            'storedAt' => isset($stored['time']) ? self::toIso($stored['time']) : null,
            'hashMatches' => $stored === null ? null : $stored['hash'] === $hash,
            'status' => $status,
            'tables' => $tables,
            'migrations' => [
                'pending' => Migrations::pending($executed, Migrations::discoverable($files, 'php')),
                'twinsToLog' => Migrations::twinsToLog($executed, $files),
                'executed' => $executed,
            ],
        ];
    }
}
