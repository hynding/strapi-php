<?php

declare(strict_types=1);

namespace Strapi\Generators\Tests;

use Strapi\Generators\Inquirer;

/**
 * Upstream's `makeInquirer(...answers)`: `prompt` resolves the queued answers in order and
 * records the questions it was called with; `warn` records upstream's `console.warn` calls.
 */
final class FakeInquirer implements Inquirer
{
    /** @var list<list<array<string, mixed>>> */
    public array $calls = [];

    /** @var list<string> */
    public array $warnings = [];

    /** @var list<array<string, mixed>> */
    private array $answers;

    /** @param array<string, mixed> ...$answers */
    public function __construct(array ...$answers)
    {
        $this->answers = array_values($answers);
    }

    public function prompt(array $questions): array
    {
        $this->calls[] = $questions;

        return array_shift($this->answers) ?? [];
    }

    public function warn(string $message): void
    {
        $this->warnings[] = $message;
    }
}
