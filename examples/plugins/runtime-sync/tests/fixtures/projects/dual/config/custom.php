<?php

declare(strict_types=1);

use Strapi\Utils\EnvHelper;

// A hand-written config file: data, env calls, a path and an expression the translator cannot follow.
$sizes = ['small' => 100, 'large' => 1000];

return static function (EnvHelper $env): array {
    return [
        'title' => 'My ' . $env('SITE_NAME', 'site'),
        'uploadsDir' => dirname(__DIR__) . '/' . $env('UPLOADS_DIR', 'public/uploads'),
        'configDir' => __DIR__,
        'limits' => ['perPage' => $env->int('PER_PAGE', 25), 'ratio' => 1.5, 'enabled' => $env->bool('FEATURE', true), 'tags' => $env->array('TAGS', ['a', 'b'])],
        'mode' => $env->oneOf('MODE', ['fast', 'slow'], 'fast'),
        'meta' => $env->json('META'),
        'started' => $env->date('STARTED_AT'),
        'sizes' => $sizes,
        'empty' => [],
        'nothing' => null,
        'computed' => strtoupper((string) $env('MODE', 'fast')),
    ];
};
