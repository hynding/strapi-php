<?php

declare(strict_types=1);

return [
    'default' => json_decode((string) file_get_contents(__DIR__ . '/default.json'), true, flags: JSON_THROW_ON_ERROR),
    'validator' => static function (array $config): void {
        $maxActive = $config['maxActive'] ?? null;
        if (!is_int($maxActive) || $maxActive < 1) {
            throw new \InvalidArgumentException('maxActive must be a positive integer');
        }
    },
];
