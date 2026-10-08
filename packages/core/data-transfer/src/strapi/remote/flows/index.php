<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Remote\Flows;

/**
 * Port of src/strapi/remote/flows/index.ts: `createFlow(flow)` returns this transfer flow
 * (`has`, `can`, `cannot`, `set`, `get`). A step is `['kind' => 'action', 'action' => string]`
 * or `['kind' => 'transfer', 'stage' => string, 'locked' => bool?]`.
 *
 * @phpstan-type Step array{kind: string, action?: string, stage?: string, locked?: bool}
 */
final class Flows
{
    /** @var Step|null */
    private ?array $step = null;

    /** @param list<Step> $flow */
    private function __construct(private readonly array $flow)
    {
    }

    /** @return list<Step> */
    public static function defaultTransferFlow(): array
    {
        /** @var list<Step> $flow */
        $flow = require __DIR__ . '/default.php';

        return $flow;
    }

    /** @param list<Step> $flow */
    public static function createFlow(array $flow): self
    {
        return new self($flow);
    }

    /**
     * Equality check between two steps
     *
     * @param Step $stepA
     * @param Step $stepB
     */
    private static function stepEqual(array $stepA, array $stepB): bool
    {
        if ($stepA['kind'] === 'action' && $stepB['kind'] === 'action') {
            return ($stepA['action'] ?? null) === ($stepB['action'] ?? null);
        }

        if ($stepA['kind'] === 'transfer' && $stepB['kind'] === 'transfer') {
            return ($stepA['stage'] ?? null) === ($stepB['stage'] ?? null);
        }

        return false;
    }

    /**
     * Find the index for a given step
     *
     * @param Step $step
     */
    private function findStepIndex(array $step): int
    {
        foreach ($this->flow as $index => $flowStep) {
            if (self::stepEqual($step, $flowStep)) {
                return $index;
            }
        }

        return -1;
    }

    /** @param Step $step */
    public function has(array $step): bool
    {
        return $this->findStepIndex($step) !== -1;
    }

    /** @param Step $step */
    public function can(array $step): bool
    {
        if ($this->step === null) {
            return true;
        }

        $indexesDifference = $this->findStepIndex($step) - $this->findStepIndex($this->step);

        // It's possible to send multiple time the same transfer step in a row
        if ($indexesDifference === 0 && $step['kind'] === 'transfer') {
            return true;
        }

        return $indexesDifference > 0;
    }

    /** @param Step $step */
    public function cannot(array $step): bool
    {
        return !$this->can($step);
    }

    /** @param Step $step */
    public function set(array $step): self
    {
        $canSwitch = $this->can($step);

        if (!$canSwitch) {
            throw new \RuntimeException('Impossible to proceed to the given step');
        }

        $this->step = $step;

        return $this;
    }

    /** @return Step|null */
    public function get(): ?array
    {
        return $this->step;
    }
}
