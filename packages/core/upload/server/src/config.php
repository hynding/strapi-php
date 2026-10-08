<?php

declare(strict_types=1);

/** Port of server/src/config.ts: the plugin's default config and its validator. */
return [
    'default' => [
        'enabled' => true,
        'provider' => 'local',
        'sizeLimit' => 1000000000, // 1GB
        'actionOptions' => [],
        'sharp' => [
            'cache' => false,
            'concurrency' => 1,
        ],
        'concurrentUploadSize' => 1,
        'concurrentUploadRequests' => 1,
    ],
    'validator' => static function (array $config): void {
        // `undefined` (an absent key) is accepted; anything else, null included, must be an integer >= 1
        $assertPositiveInteger = static function (string $key) use ($config): void {
            if (!array_key_exists($key, $config)) {
                return;
            }
            $value = $config[$key];
            if (!(is_int($value) || (is_float($value) && floor($value) === $value)) || $value < 1) {
                throw new \RuntimeException("upload plugin config: \"{$key}\" must be an integer greater than or equal to 1");
            }
        };

        // Server-side ceiling: files processed in parallel within one request.
        $assertPositiveInteger('concurrentUploadSize');
        // Client-side parallelism: upload requests the admin fires at once.
        $assertPositiveInteger('concurrentUploadRequests');
    },
];
