<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * `z.number()`: a PHP int or a finite float (NaN and ±INF are rejected, as in zod v4).
 * `min`/`max` are zod's `gte`/`lte`; `int()` is the `safeint` format.
 */
class ZodNumber extends ZodType
{
    protected bool $coerce = false;

    /** @param string|array<string, mixed>|null $params */
    public function __construct(string|array|null $params = null, bool $coerce = false)
    {
        $this->withParams($params);
        $this->coerce = $coerce;
    }

    public function type(): string
    {
        return 'number';
    }

    protected function parseType(ParsePayload $payload): ParsePayload
    {
        if ($this->coerce) {
            $payload->value = Util::jsNumber($payload->value);
        }
        $input = $payload->value;
        if (is_int($input) || (is_float($input) && is_finite($input))) {
            return $payload;
        }
        $issue = ['expected' => 'number', 'code' => 'invalid_type', 'input' => $input];
        if (is_float($input)) {
            $issue['received'] = is_nan($input) ? 'NaN' : 'Infinity';
        }

        return $this->issue($payload, $issue);
    }

    /** @return array<string, mixed> */
    public function def(): array
    {
        return parent::def() + ['coerce' => $this->coerce];
    }

    public function isInt(): bool
    {
        foreach ($this->checks as $check) {
            if ($check->kind() === 'number_format') {
                return true;
            }
        }

        return false;
    }

    /** @param string|array<string, mixed>|null $params */
    public function gt(int|float $value, string|array|null $params = null): static
    {
        return $this->check(ZodCheck::greaterThan($value, false, $params));
    }

    /** @param string|array<string, mixed>|null $params */
    public function gte(int|float $value, string|array|null $params = null): static
    {
        return $this->check(ZodCheck::greaterThan($value, true, $params));
    }

    /** @param string|array<string, mixed>|null $params */
    public function min(int|float $value, string|array|null $params = null): static
    {
        return $this->gte($value, $params);
    }

    /** @param string|array<string, mixed>|null $params */
    public function lt(int|float $value, string|array|null $params = null): static
    {
        return $this->check(ZodCheck::lessThan($value, false, $params));
    }

    /** @param string|array<string, mixed>|null $params */
    public function lte(int|float $value, string|array|null $params = null): static
    {
        return $this->check(ZodCheck::lessThan($value, true, $params));
    }

    /** @param string|array<string, mixed>|null $params */
    public function max(int|float $value, string|array|null $params = null): static
    {
        return $this->lte($value, $params);
    }

    /** @param string|array<string, mixed>|null $params */
    public function int(string|array|null $params = null): static
    {
        return $this->check(ZodCheck::int($params));
    }

    /** @param string|array<string, mixed>|null $params */
    public function safe(string|array|null $params = null): static
    {
        return $this->int($params);
    }

    /** @param string|array<string, mixed>|null $params */
    public function positive(string|array|null $params = null): static
    {
        return $this->gt(0, $params);
    }

    /** @param string|array<string, mixed>|null $params */
    public function nonnegative(string|array|null $params = null): static
    {
        return $this->gte(0, $params);
    }

    /** @param string|array<string, mixed>|null $params */
    public function negative(string|array|null $params = null): static
    {
        return $this->lt(0, $params);
    }

    /** @param string|array<string, mixed>|null $params */
    public function nonpositive(string|array|null $params = null): static
    {
        return $this->lte(0, $params);
    }

    /** @param string|array<string, mixed>|null $params */
    public function multipleOf(int|float $value, string|array|null $params = null): static
    {
        return $this->check(ZodCheck::multipleOf($value, $params));
    }

    /** @param string|array<string, mixed>|null $params */
    public function step(int|float $value, string|array|null $params = null): static
    {
        return $this->multipleOf($value, $params);
    }

    public function finite(): static
    {
        return $this;
    }
}
