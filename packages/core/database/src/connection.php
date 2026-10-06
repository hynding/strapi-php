<?php

declare(strict_types=1);

namespace Strapi\Database;

use Doctrine\DBAL\Connection as DbalConnection;
use Doctrine\DBAL\DriverManager;

/**
 * Port of packages/core/database/src/connection.ts: maps the Strapi database config
 * (`{ client: 'sqlite'|'mysql'|'postgres', connection: {...} }`) to Doctrine DBAL params.
 */
final class Connection
{
    public const CLIENT_MAP = [
        'sqlite' => 'pdo_sqlite',
        'mysql' => 'pdo_mysql',
        'mysql2' => 'pdo_mysql',
        'mariadb' => 'pdo_mysql',
        'postgres' => 'pdo_pgsql',
        'postgresql' => 'pdo_pgsql',
        'pg' => 'pdo_pgsql',
    ];

    public static function isDatabaseClientKind(mixed $client): bool
    {
        return is_string($client) && isset(self::CLIENT_MAP[$client]);
    }

    /**
     * @param array<string, mixed> $userConfig the `connection` block of the database config
     *
     * @return array<string, mixed> DBAL params
     */
    public static function toDbalParams(array $userConfig): array
    {
        $client = $userConfig['client'] ?? null;
        if (!self::isDatabaseClientKind($client)) {
            throw new \InvalidArgumentException('Unsupported database client ' . (is_scalar($client) ? (string) $client : gettype($client)));
        }

        $connection = $userConfig['connection'] ?? [];
        if (!is_array($connection)) {
            throw new \InvalidArgumentException('connection.connection must be an array');
        }

        $params = ['driver' => self::CLIENT_MAP[$client]];

        if (isset($connection['driverOptions']) && is_array($connection['driverOptions'])) {
            $params['driverOptions'] = $connection['driverOptions'];
        }

        if ($client === 'sqlite') {
            $filename = $connection['filename'] ?? ':memory:';
            if ($filename === ':memory:' || $filename === '') {
                $params['memory'] = true;
            } else {
                $params['path'] = $filename;
            }

            return $params;
        }

        foreach (['host', 'port', 'user', 'password', 'database', 'charset'] as $key) {
            if (isset($connection[$key]) && $connection[$key] !== '') {
                $params[$key] = $key === 'port' ? (int) $connection[$key] : $connection[$key];
            }
        }
        if (isset($connection['socketPath'])) {
            $params['unix_socket'] = $connection['socketPath'];
        }
        if (isset($connection['schema']) && $client !== 'mysql') {
            // Strapi's postgres `schema`; applied through SET search_path in the dialect
            $params['schema'] = $connection['schema'];
        }

        $ssl = $connection['ssl'] ?? null;
        if ($ssl !== null && $ssl !== false) {
            $params['driverOptions'] ??= [];
            if ($params['driver'] === 'pdo_mysql') {
                if (is_array($ssl)) {
                    $map = ['ca' => \PDO::MYSQL_ATTR_SSL_CA, 'cert' => \PDO::MYSQL_ATTR_SSL_CERT, 'key' => \PDO::MYSQL_ATTR_SSL_KEY, 'capath' => \PDO::MYSQL_ATTR_SSL_CAPATH, 'cipher' => \PDO::MYSQL_ATTR_SSL_CIPHER];
                    foreach ($map as $from => $to) {
                        if (isset($ssl[$from])) {
                            $params['driverOptions'][$to] = $ssl[$from];
                        }
                    }
                    if (array_key_exists('rejectUnauthorized', $ssl) && defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                        $params['driverOptions'][\PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = (bool) $ssl['rejectUnauthorized'];
                    }
                }
            } else {
                $params['sslmode'] = is_array($ssl) && ($ssl['rejectUnauthorized'] ?? true) === false ? 'require' : 'verify-full';
                if (is_array($ssl)) {
                    foreach (['ca' => 'sslrootcert', 'cert' => 'sslcert', 'key' => 'sslkey'] as $from => $to) {
                        if (isset($ssl[$from])) {
                            $params[$to] = $ssl[$from];
                        }
                    }
                }
            }
        }

        return $params;
    }

    /** @param array<string, mixed> $userConfig */
    public static function createConnection(array $userConfig): DbalConnection
    {
        return DriverManager::getConnection(self::toDbalParams($userConfig));
    }
}
