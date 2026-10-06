<?php

declare(strict_types=1);

use Strapi\Utils\EnvHelper;

return static function (EnvHelper $env): array {
    $sqlite = [
        'client' => 'sqlite',
        'connection' => [
            'filename' => $env('DATABASE_FILENAME', '.tmp/data.db'),
        ],
        'useNullAsDefault' => true,
    ];

    $postgres = [
        'client' => 'postgres',
        'connection' => [
            'database' => $env('DATABASE_NAME', 'strapi'),
            'user' => $env('DATABASE_USERNAME', 'strapi'),
            'password' => $env('DATABASE_PASSWORD', 'strapi'),
            'port' => $env->int('DATABASE_PORT', 5432),
            'host' => $env('DATABASE_HOST', 'localhost'),
        ],
    ];

    $mysql = [
        'client' => 'mysql',
        'connection' => [
            'database' => $env('DATABASE_NAME', 'strapi'),
            'user' => $env('DATABASE_USERNAME', 'strapi'),
            'password' => $env('DATABASE_PASSWORD', 'strapi'),
            'port' => $env->int('DATABASE_PORT', 3306),
            'host' => $env('DATABASE_HOST', 'localhost'),
        ],
    ];

    $mariadb = [
        'client' => 'mysql',
        'connection' => [
            'database' => $env('DATABASE_NAME', 'strapi'),
            'user' => $env('DATABASE_USERNAME', 'strapi'),
            'password' => $env('DATABASE_PASSWORD', 'strapi'),
            'port' => $env->int('DATABASE_PORT', 3307),
            'host' => $env('DATABASE_HOST', 'localhost'),
        ],
    ];

    $db = ['mysql' => $mysql, 'sqlite' => $sqlite, 'postgres' => $postgres, 'mariadb' => $mariadb];

    $client = $env('DB', $env('DATABASE_CLIENT', 'sqlite'));

    return [
        'connection' => $db[$client] ?? $db['sqlite'],
    ];
};
