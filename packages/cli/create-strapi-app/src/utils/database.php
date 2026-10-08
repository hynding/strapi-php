<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Utils;

use Strapi\CreateStrapiApp\Prompts;
use Strapi\CreateStrapiApp\Types;

/**
 * Port of packages/cli/create-strapi-app/src/utils/database.ts.
 *
 * Same prompts, flags and validation. The client's Node driver (`mysql2`, `pg`, `better-sqlite3`)
 * becomes the PDO extension Doctrine DBAL uses, required in the generated `composer.json`
 * (`ext-pdo_mysql`, `ext-pdo_pgsql`, `ext-pdo_sqlite`) so Composer reports a missing one at install.
 *
 * @phpstan-import-type Options from Types
 * @phpstan-import-type Scope from Types
 * @phpstan-import-type DatabaseInfo from Types
 * @phpstan-import-type DBClient from Types
 */
final class Database
{
    private const DB_OPTIONS = ['dbclient', 'dbhost', 'dbport', 'dbname', 'dbusername', 'dbpassword'];

    private const VALID_CLIENTS = ['sqlite', 'mysql', 'postgres'];

    /** @var DatabaseInfo */
    public const DEFAULT_CONFIG = [
        'client' => 'sqlite',
        'connection' => [
            'filename' => '.tmp/data.db',
        ],
    ];

    public const SQL_CLIENT_MODULE = [
        'mysql' => ['ext-pdo_mysql' => '*'],
        'postgres' => ['ext-pdo_pgsql' => '*'],
        'sqlite' => ['ext-pdo_sqlite' => '*'],
    ];

    private const DEFAULT_PORTS = [
        'postgres' => '5432',
        'mysql' => '3306',
        'sqlite' => null,
    ];

    /** @return DatabaseInfo */
    private static function dbPrompt(Prompts $prompts): array
    {
        $useDefault = $prompts->confirm('Do you want to use the default database (sqlite) ?', true);

        if ($useDefault) {
            return self::DEFAULT_CONFIG;
        }

        /** @var DBClient $client */
        $client = $prompts->select('Choose your default database client', ['sqlite', 'postgres', 'mysql'], 'sqlite');

        $connection = [];
        if ($client === 'sqlite') {
            $connection['filename'] = $prompts->input('Filename:', '.tmp/data.db');
        } else {
            $connection['database'] = $prompts->input('Database name:', 'strapi', static function (?string $value): ?string {
                if ($value !== null && str_contains($value, '.')) {
                    throw new \RuntimeException('The database name can\'t contain a "."');
                }

                return $value;
            });
            $connection['host'] = $prompts->input('Host:', '127.0.0.1');
            $connection['port'] = $prompts->input('Port:', self::DEFAULT_PORTS[$client]);
            $connection['username'] = $prompts->input('Username:');
            $connection['password'] = $prompts->password('Password:');
            $connection['ssl'] = $prompts->confirm('Enable SSL connection:', false);
        }

        return [
            'client' => $client,
            'connection' => $connection,
        ];
    }

    /**
     * @param Options $options
     * @return DatabaseInfo
     */
    public static function getDatabaseInfos(array $options, Logger $logger, ?Prompts $prompts = null): array
    {
        if ($options['skipDb']) {
            return self::DEFAULT_CONFIG;
        }

        $dbclient = $options['dbclient'];

        if ($dbclient !== null && $dbclient !== '' && !in_array($dbclient, self::VALID_CLIENTS, true)) {
            $logger->fatal("Invalid --dbclient: {$dbclient}, expected one of " . implode(', ', self::VALID_CLIENTS));
        }

        // commander: `key in options` is true only for the flags that were passed
        $matchingArgs = array_values(array_filter(self::DB_OPTIONS, static fn (string $key): bool => $options[$key] !== null));
        $missingArgs = array_values(array_filter(self::DB_OPTIONS, static fn (string $key): bool => $options[$key] === null));

        if (count($matchingArgs) > 0 && count($matchingArgs) !== count(self::DB_OPTIONS) && $dbclient !== 'sqlite') {
            $logger->fatal('Required database arguments are missing: ' . implode(', ', $missingArgs) . '.');
        }

        $hasDBOptions = count($matchingArgs) > 0;

        if (!$hasDBOptions) {
            if ($options['quickstart'] || $options['nonInteractive'] || $prompts === null) {
                return self::DEFAULT_CONFIG;
            }

            return self::dbPrompt($prompts);
        }

        if ($dbclient === null || $dbclient === '') {
            $logger->fatal('Please specify the database client');
        }

        /** @var DBClient $dbclient */
        $database = [
            'client' => $dbclient,
            'connection' => [
                'host' => $options['dbhost'],
                'port' => $options['dbport'],
                'database' => $options['dbname'],
                'username' => $options['dbusername'],
                'password' => $options['dbpassword'],
                'filename' => $options['dbfile'],
            ],
        ];

        if ($options['dbssl'] !== null) {
            $database['connection']['ssl'] = $options['dbssl'] === 'true';
        }

        return $database;
    }

    /**
     * @param Scope $scope
     * @return Scope
     */
    public static function addDatabaseDependencies(array $scope): array
    {
        $scope['composerDependencies'] = [
            ...$scope['composerDependencies'],
            ...self::SQL_CLIENT_MODULE[$scope['database']['client']],
        ];

        return $scope;
    }
}
