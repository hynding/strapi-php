<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.transform(fn)`: replaces the value with `fn($value, $ctx)`; `$ctx->addIssue()` reports
 * issues (they abort, as in zod). Return {@see ZodTransform::NEVER} after adding an issue.
 */
class ZodTransform extends ZodType
{
    /** zod's `z.NEVER`: the value to return from a transform that reported an issue. */
    public const Undefined NEVER = Undefined::Value;

    /** @param \Closure(mixed, ParsePayload): mixed $transform */
    public function __construct(protected \Closure $transform)
    {
    }

    public function type(): string
    {
        return 'transform';
    }

    /** @return array<string, mixed> */
    public function def(): array
    {
        return parent::def() + ['transform' => $this->transform];
    }

    public function isOptionalIn(): bool
    {
        return true;
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        $previous = $payload->addIssueHandler;
        $payload->addIssueHandler = ZodCheck::issueAdder($payload, $this, null);
        try {
            $payload->value = ($this->transform)($payload->value, $payload);
        } finally {
            $payload->addIssueHandler = $previous;
        }
        $payload->fallback = true;

        return $payload;
    }
}
