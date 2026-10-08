<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `a.pipe(b)`, and what `.transform()` and `z.preprocess()` build: parse with `in`, then feed
 * the result to `out` unless `in` reported issues.
 */
class ZodPipe extends ZodType
{
    public function __construct(protected ZodType $in, protected ZodType $out)
    {
    }

    public function type(): string
    {
        return 'pipe';
    }

    public function in(): ZodType
    {
        return $this->in;
    }

    public function out(): ZodType
    {
        return $this->out;
    }

    /** @return array<string, mixed> */
    public function def(): array
    {
        return parent::def() + ['in' => $this->in, 'out' => $this->out];
    }

    public function isOptionalIn(): bool
    {
        return $this->in->isOptionalIn();
    }

    public function isOptionalOut(): bool
    {
        return $this->out->isOptionalOut();
    }

    /** @return list<mixed>|null */
    public function values(): ?array
    {
        return $this->in->values();
    }

    /** @return array<string, list<mixed>>|null */
    public function propValues(): ?array
    {
        return $this->in->propValues();
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        $left = $this->in->run($payload);
        if ($left->issues !== []) {
            $left->aborted = true;

            return $left;
        }

        return $this->out->run($left);
    }
}
