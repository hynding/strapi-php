<?php

declare(strict_types=1);

use Strapi\Database\Connection;
use Strapi\Utils\EnvHelper;

return static function (EnvHelper $env): array {
    $client = $env('DATABASE_CLIENT', 'sqlite');

    if (!Connection::isDatabaseClientKind($client)) {
        throw new \RuntimeException("Unsupported DATABASE_CLIENT: {$client}. Use \"postgres\", \"mysql\", or \"sqlite\".");
    }

    $ssl = static fn (): array|false => $env->bool('DATABASE_SSL', false) ? [
        'key' => $env('DATABASE_SSL_KEY'),
        'cert' => $env('DATABASE_SSL_CERT'),
        'ca' => $env('DATABASE_SSL_CA'),
        'capath' => $env('DATABASE_SSL_CAPATH'),
        'cipher' => $env('DATABASE_SSL_CIPHER'),
        'rejectUnauthorized' => $env->bool('DATABASE_SSL_REJECT_UNAUTHORIZED', true),
    ] : false;

    $connections = [
        'mysql' => [
            'client' => 'mysql',
            'connection' => [
                'host' => $env('DATABASE_HOST', 'localhost'),
                'port' => $env->int('DATABASE_PORT', 3306),
                'database' => $env('DATABASE_NAME', 'strapi'),
                'user' => $env('DATABASE_USERNAME', 'strapi'),
                'password' => $env('DATABASE_PASSWORD', 'strapi'),
                'ssl' => $ssl(),
            ],
            'pool' => ['min' => $env->int('DATABASE_POOL_MIN', 2), 'max' => $env->int('DATABASE_POOL_MAX', 10)],
        ],
        'postgres' => [
            'client' => 'postgres',
            'connection' => [
                'connectionString' => $env('DATABASE_URL'),
                'host' => $env('DATABASE_HOST', 'localhost'),
                'port' => $env->int('DATABASE_PORT', 5432),
                'database' => $env('DATABASE_NAME', 'strapi'),
                'user' => $env('DATABASE_USERNAME', 'strapi'),
                'password' => $env('DATABASE_PASSWORD', 'strapi'),
                'ssl' => $ssl(),
                'schema' => $env('DATABASE_SCHEMA', 'public'),
            ],
            'pool' => ['min' => $env->int('DATABASE_POOL_MIN', 2), 'max' => $env->int('DATABASE_POOL_MAX', 10)],
        ],
        'sqlite' => [
            'client' => 'sqlite',
            'connection' => [
                'filename' => dirname(__DIR__) . '/' . $env('DATABASE_FILENAME', '.tmp/data.db'),
            ],
            'useNullAsDefault' => true,
        ],
    ];

    return [
        'connection' => [
            ...$connections[$client],
            'acquireConnectionTimeout' => $env->int('DATABASE_CONNECTION_TIMEOUT', 60000),
        ],
    ];
};
