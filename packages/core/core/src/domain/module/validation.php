<?php

declare(strict_types=1);

namespace Strapi\Core\Domain\Module;

use Strapi\Utils\Errors\YupValidationError;

/** Port of packages/core/core/src/domain/module/validation.ts (`validateModule`). */
final class Validation
{
    private const KNOWN_KEYS = [
        'bootstrap', 'destroy', 'register', 'config', 'routes', 'controllers', 'services', 'policies', 'middlewares', 'contentTypes',
    ];

    /** @param array<string, mixed> $data */
    public static function validateModule(array $data): void
    {
        $errors = [];

        foreach (['bootstrap', 'destroy', 'register'] as $fn) {
            if (array_key_exists($fn, $data) && $data[$fn] !== null && !is_callable($data[$fn])) {
                $errors[] = ['path' => [$fn], 'message' => "{$fn} is not a function", 'name' => 'ValidationError', 'value' => $data[$fn]];
            }
        }

        foreach (['config', 'controllers', 'services', 'policies', 'middlewares', 'contentTypes', 'routes'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && !is_array($data[$key]) && !is_object($data[$key])) {
                $errors[] = ['path' => [$key], 'message' => "{$key} must be a `object` type", 'name' => 'ValidationError', 'value' => $data[$key]];
            }
        }

        $unknown = array_diff(array_keys($data), self::KNOWN_KEYS);
        if ($unknown !== []) {
            $errors[] = ['path' => [], 'message' => 'this field has unspecified keys: ' . implode(', ', $unknown), 'name' => 'ValidationError', 'value' => $data];
        }

        if ($errors !== []) {
            throw new YupValidationError($errors);
        }
    }
}
