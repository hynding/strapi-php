<?php

declare(strict_types=1);

namespace Strapi\Utils;

/**
 * Port of packages/core/utils/src/env-helper.ts.
 *
 * Upstream reads `process.env`; here the caller passes the variables (typically after loading
 * `.env`), so config files can do `$env('HOST', '0.0.0.0')`, `$env->int('PORT', 1337)` and so on.
 * A key counts as "defined" when it exists in the array, even with an empty value, exactly
 * like `_.has(process.env, key)`.
 */
final class EnvHelper
{
    /** @param array<string, string> $vars */
    public function __construct(private array $vars = [])
    {
    }

    /** Build from the live process environment (getenv() merged with $_ENV and $_SERVER strings). */
    public static function fromProcess(): self
    {
        /** @var array<string, string> $vars */
        $vars = array_filter(getenv(), 'is_string');
        foreach ([$_ENV, $_SERVER] as $source) {
            foreach ($source as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $vars[$key] = $value;
                }
            }
        }

        return new self($vars);
    }

    /** @return array<string, string> */
    public function all(): array
    {
        return $this->vars;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->vars);
    }

    /**
     * `env('KEY')` / `env('KEY', default)`.
     *
     * @template T
     * @param T $defaultValue
     * @return string|T
     */
    public function __invoke(string $key, mixed $defaultValue = null): mixed
    {
        return $this->has($key) ? $this->vars[$key] : $defaultValue;
    }

    /**
     * @template T
     * @param T $defaultValue
     * @return string|T
     */
    public function get(string $key, mixed $defaultValue = null): mixed
    {
        return $this($key, $defaultValue);
    }

    private function raw(string $key): string
    {
        return $this->vars[$key] ?? '';
    }

    /**
     * parseInt(value, 10) semantics: leading integer, NAN when none.
     *
     * @return int|float|null  float only for NAN
     */
    public function int(string $key, ?int $defaultValue = null): int|float|null
    {
        if (!$this->has($key)) {
            return $defaultValue;
        }

        $value = trim($this->raw($key));
        if (preg_match('/^[+-]?\d+/', $value, $m) !== 1) {
            return NAN;
        }

        return (int) $m[0];
    }

    /** parseFloat semantics: leading float, NAN when none. */
    public function float(string $key, ?float $defaultValue = null): ?float
    {
        if (!$this->has($key)) {
            return $defaultValue;
        }

        $value = trim($this->raw($key));
        if (preg_match('/^[+-]?(\d+\.?\d*(e[+-]?\d+)?|\.\d+(e[+-]?\d+)?|Infinity)/i', $value, $m) !== 1) {
            return NAN;
        }
        if (stripos($m[0], 'infinity') !== false) {
            return str_starts_with($m[0], '-') ? -INF : INF;
        }

        return (float) $m[0];
    }

    /** Only the literal string "true" is true, as upstream. */
    public function bool(string $key, ?bool $defaultValue = null): ?bool
    {
        if (!$this->has($key)) {
            return $defaultValue;
        }

        return $this->raw($key) === 'true';
    }

    /**
     * @throws \RuntimeException when the value is not valid JSON
     */
    public function json(string $key, mixed $defaultValue = null): mixed
    {
        if (!$this->has($key)) {
            return $defaultValue;
        }

        try {
            return json_decode($this->raw($key), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('Invalid json environment variable %s: %s', $key, $e->getMessage()), 0, $e);
        }
    }

    /**
     * Splits on commas, strips optional surrounding brackets, trims spaces then double quotes.
     *
     * @param list<string>|null $defaultValue
     * @return list<string>|null
     */
    public function array(string $key, ?array $defaultValue = null): ?array
    {
        if (!$this->has($key)) {
            return $defaultValue;
        }

        $value = $this->raw($key);

        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            $value = substr($value, 1, -1);
        }

        return array_map(static fn (string $v): string => trim(trim($v, ' '), '"'), explode(',', $value));
    }

    /**
     * `new Date(value)` semantics: an unparseable value yields an "invalid date", represented
     * here by null when the key exists but cannot be parsed (there is no NaN DateTime in PHP).
     */
    public function date(string $key, ?\DateTimeInterface $defaultValue = null): ?\DateTimeInterface
    {
        if (!$this->has($key)) {
            return $defaultValue;
        }

        $value = $this->raw($key);

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param list<string> $expectedValues
     */
    public function oneOf(string $key, ?array $expectedValues = null, ?string $defaultValue = null): ?string
    {
        if ($expectedValues === null) {
            throw new \InvalidArgumentException('env.oneOf requires expectedValues');
        }

        if ($defaultValue !== null && $defaultValue !== '' && !in_array($defaultValue, $expectedValues, true)) {
            throw new \InvalidArgumentException('env.oneOf requires defaultValue to be included in expectedValues');
        }

        $rawValue = $this($key, $defaultValue);
        if (is_string($rawValue) && in_array($rawValue, $expectedValues, true)) {
            return $rawValue;
        }

        return $defaultValue;
    }
}
