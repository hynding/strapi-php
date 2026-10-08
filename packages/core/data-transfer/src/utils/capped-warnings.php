<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils;

/**
 * Port of src/utils/capped-warnings.ts: `createCappedWarningReporter(onWarning, limit)` returns
 * this reporter.
 */
final class CappedWarnings
{
    public const int DEFAULT_DETAILED_WARNING_LIMIT = 10;

    private int $emitted = 0;

    /** @param (callable(string): mixed)|null $onWarning */
    private function __construct(private readonly mixed $onWarning, private readonly int $limit)
    {
    }

    /**
     * Emits per-item warning messages up to `limit`, then a one-time suppression
     * notice. Callers should still emit an unconditional end-of-stage summary.
     *
     * @param (callable(string): mixed)|null $onWarning
     */
    public static function createCappedWarningReporter(?callable $onWarning = null, int $limit = self::DEFAULT_DETAILED_WARNING_LIMIT): self
    {
        return new self($onWarning, $limit);
    }

    public function warn(string $message): void
    {
        $onWarning = $this->onWarning;
        if ($onWarning === null) {
            return;
        }

        if ($this->emitted >= $this->limit) {
            return;
        }

        $onWarning($message);
        ++$this->emitted;

        if ($this->emitted === $this->limit) {
            $onWarning("Further detailed warnings suppressed after {$this->limit} messages. See the stage summary for totals.");
        }
    }
}
