<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils;

/**
 * Port of src/utils/diagnostic.ts: the diagnostic reporter (`createDiagnosticReporter()`).
 *
 * A diagnostic is `['kind' => 'error'|'warning'|'info', 'details' => ['message' => string,
 * 'createdAt' => string (ISO date), ...]]`: errors add `name`, `severity`, `error`; warnings and
 * infos add `origin` (and `params` for infos).
 *
 * @phpstan-type DiagnosticArray array{kind: string, details: array<string, mixed>}
 */
final class Diagnostic
{
    /** @var list<array<string, mixed>> */
    private array $stack = [];

    /** @var array<string, list<callable(array<string, mixed>): mixed>> */
    private array $listeners = [];

    private function __construct(private readonly int $stackSize)
    {
    }

    /** @param array{stackSize?: int} $options */
    public static function createDiagnosticReporter(array $options = []): self
    {
        return new self($options['stackSize'] ?? -1);
    }

    /** `new Date()` for a diagnostic's `createdAt`. */
    public static function now(): string
    {
        $now = \DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', microtime(true)));

        return $now !== false ? $now->format('Y-m-d\TH:i:s.v\Z') : gmdate('Y-m-d\TH:i:s.000\Z');
    }

    public function size(): int
    {
        return count($this->stack);
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        return $this->stack;
    }

    /** @param array<string, mixed> $diagnostic */
    public function report(array $diagnostic): self
    {
        if (!self::isDiagnosticValid($diagnostic)) {
            return $this;
        }

        $this->emit('diagnostic', $diagnostic);
        $this->emit('diagnostic.' . $diagnostic['kind'], $diagnostic);

        if ($this->stackSize !== -1 && count($this->stack) >= $this->stackSize) {
            array_shift($this->stack);
        }

        $this->stack[] = $diagnostic;

        return $this;
    }

    /** @param callable(array<string, mixed>): mixed $listener */
    public function onDiagnostic(callable $listener): self
    {
        $this->listeners['diagnostic'][] = $listener;

        return $this;
    }

    /** @param callable(array<string, mixed>): mixed $listener */
    public function on(string $kind, callable $listener): self
    {
        $this->listeners['diagnostic.' . $kind][] = $listener;

        return $this;
    }

    /** @param array<string, mixed> $diagnostic */
    private static function isDiagnosticValid(array $diagnostic): bool
    {
        $details = $diagnostic['details'] ?? null;

        return !empty($diagnostic['kind']) && is_array($details) && !empty($details['message']);
    }

    /** @param array<string, mixed> $diagnostic */
    private function emit(string $event, array $diagnostic): void
    {
        foreach ($this->listeners[$event] ?? [] as $listener) {
            $listener($diagnostic);
        }
    }
}
