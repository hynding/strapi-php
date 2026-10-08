<?php

declare(strict_types=1);

namespace Strapi\Utils\Yup;

/**
 * `this` inside a yup test function: `path`, `parent`, `options` (`options['parent']`,
 * `options['context']`...), `originalValue`, `schema`, `createError()` and `resolve()`.
 */
final class TestContext
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public readonly string $path,
        public readonly mixed $parent,
        public readonly ?string $type,
        public readonly array $options,
        public readonly mixed $originalValue,
        public readonly mixed $value,
        public readonly Yup $schema,
        public readonly ?string $label = null,
        public readonly mixed $from = [],
        private readonly ?TestConfig $config = null,
    ) {
    }

    /** Resolve a `ref()` against the value being tested, its parent and the context. */
    public function resolve(mixed $item): mixed
    {
        return $item instanceof Reference ? $item->getValue($this->value, $this->parent, $this->options['context'] ?? Undefined::value()) : $item;
    }

    /**
     * `this.createError({ path, message, params, type })`.
     *
     * @param array{path?: string|null, message?: string|\Closure|null, params?: array<string, mixed>, type?: string|null} $overrides
     */
    public function createError(array $overrides = []): YupError
    {
        $overridePath = $overrides['path'] ?? null;
        $params = [
            'value' => $this->value,
            'originalValue' => $this->originalValue,
            'label' => $this->label,
            'path' => $overridePath !== null && $overridePath !== '' ? $overridePath : $this->path,
            ...($this->config->params ?? []),
            ...($overrides['params'] ?? []),
        ];
        $params = array_map($this->resolve(...), $params);

        $message = $overrides['message'] ?? null;
        if ($message === null || $message === '') {
            $message = $this->config->message ?? Locale::MIXED_DEFAULT;
        }

        $path = is_string($params['path']) ? $params['path'] : null;
        $error = new YupError(YupError::formatError($message, $params), $this->value, $path, $overrides['type'] ?? $this->type);
        $error->params = $params;

        return $error;
    }
}
