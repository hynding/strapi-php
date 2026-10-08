<?php

declare(strict_types=1);

namespace Strapi\Utils\Yup;

/** The options of one yup test (`validate.OPTIONS` in yup's `createValidation`). */
final readonly class TestConfig
{
    /**
     * @param string|\Closure(array<string, mixed>): string $message
     * @param \Closure(mixed, TestContext): mixed $test
     * @param array<string, mixed> $params
     */
    public function __construct(
        public ?string $name,
        public string|\Closure $message,
        public \Closure $test,
        public bool $exclusive = false,
        public array $params = [],
    ) {
    }
}
