<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * zod's `ParsePayload`: the value being parsed plus the issues collected so far. It is also the
 * `ctx` handed to `superRefine()` and `transform()` callbacks (zod's `RefinementCtx`), where
 * `$ctx->addIssue([...])` / `$ctx->addIssue('message')` reports an issue and `$ctx->value` is the
 * value under validation.
 *
 * Issues are plain arrays in zod's shape. While parsing they may carry three internal keys that
 * finalization strips: `inst` (the schema or check whose `error` param builds the message),
 * `continue` (true when later checks may still run) and `input`.
 */
final class ParsePayload
{
    /** @var list<array<string, mixed>> */
    public array $issues = [];

    public bool $aborted = false;

    /** Set by transforms: an absent optional input may swallow the pipe's issues. */
    public bool $fallback = false;

    /** @var (\Closure(array<string, mixed>|string): void)|null */
    public ?\Closure $addIssueHandler = null;

    public function __construct(public mixed $value)
    {
    }

    /** @param array<string, mixed>|string $issue */
    public function addIssue(array|string $issue): void
    {
        if ($this->addIssueHandler === null) {
            throw new \LogicException('addIssue() is only available inside superRefine() and transform() callbacks');
        }
        ($this->addIssueHandler)($issue);
    }

    /** zod's `util.aborted`: an issue from `$startIndex` on that does not allow further checks. */
    public function isAborted(int $startIndex = 0): bool
    {
        if ($this->aborted) {
            return true;
        }
        for ($i = $startIndex, $n = count($this->issues); $i < $n; $i++) {
            if (($this->issues[$i]['continue'] ?? null) !== true) {
                return true;
            }
        }

        return false;
    }

    /** zod's `util.explicitlyAborted`: an issue explicitly marked `continue: false`. */
    public function isExplicitlyAborted(int $startIndex = 0): bool
    {
        if ($this->aborted) {
            return true;
        }
        for ($i = $startIndex, $n = count($this->issues); $i < $n; $i++) {
            if (($this->issues[$i]['continue'] ?? null) === false) {
                return true;
            }
        }

        return false;
    }
}
