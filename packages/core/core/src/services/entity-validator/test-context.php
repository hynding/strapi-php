<?php

declare(strict_types=1);

namespace Strapi\Core\Services\EntityValidator;

/** `this` inside a yup test function: `createError`, `path`, `parent`, `originalValue`. */
final class TestContext
{
    public function __construct(
        public readonly string $path,
        public readonly mixed $value,
        public readonly mixed $originalValue,
        public readonly Yup $schema,
    ) {
    }

    /** @param array{path?: string, message?: string, params?: array<string, mixed>} $options */
    public function createError(array $options = []): YupError
    {
        $path = $options['path'] ?? $this->path;
        $message = Yup::interpolate($options['message'] ?? '${path} is invalid', ['path' => $path === '' ? 'this' : $path, 'value' => $this->value, ...($options['params'] ?? [])]);

        return new YupError($message, $path);
    }
}
