<?php

declare(strict_types=1);

use Strapi\Utils\EnvHelper;

// create-strapi-app templates/vanilla/config/database.ts (SQLite; the suite's other databases
// come from docker-compose.test.yml through DATABASE_CLIENT)
return static function (EnvHelper $env): array {
    $client = $env('DATABASE_CLIENT', 'sqlite');

    $connections = [
        'mysql' => [
            'client' => 'mysql',
            'connection' => [
                'host' => $env('DATABASE_HOST', 'localhost'),
                'port' => $env->int('DATABASE_PORT', 3306),
                'database' => $env('DATABASE_NAME', 'strapi'),
                'user' => $env('DATABASE_USERNAME', 'strapi'),
                'password' => $env('DATABASE_PASSWORD', 'strapi'),
            ],
        ],
        'postgres' => [
            'client' => 'postgres',
            'connection' => [
                'host' => $env('DATABASE_HOST', 'localhost'),
                'port' => $env->int('DATABASE_PORT', 5432),
                'database' => $env('DATABASE_NAME', 'strapi'),
                'user' => $env('DATABASE_USERNAME', 'strapi'),
                'password' => $env('DATABASE_PASSWORD', 'strapi'),
                'schema' => $env('DATABASE_SCHEMA', 'public'),
            ],
        ],
        'sqlite' => [
            'client' => 'sqlite',
            'connection' => [
                'filename' => dirname(__DIR__) . '/' . $env('DATABASE_FILENAME', '.tmp/data.db'),
            ],
            'useNullAsDefault' => true,
        ],
    ];

    return ['connection' => $connections[$client] ?? $connections['sqlite']];
};
