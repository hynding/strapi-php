<?php

declare(strict_types=1);

/** Mirrors config/index.js: defaults from default.json (read by both runtimes), same validation. */
return [
    'default' => json_decode((string) file_get_contents(__DIR__ . '/default.json'), true, flags: JSON_THROW_ON_ERROR),
    'validator' => static function (array $config): void {
        foreach (['reconcileMigrations', 'recordBoots'] as $key) {
            if (!is_bool($config[$key] ?? null)) {
                throw new \InvalidArgumentException("{$key} must be a boolean");
            }
        }
    },
];
