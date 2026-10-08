<?php

declare(strict_types=1);

namespace Strapi\Utils\Zod;

/**
 * Not an upstream file: the subset of npm zod 4.4.3 Strapi uses.
 *
 * A check attached to a schema (zod's `$ZodCheck*`): length and range bounds, string formats,
 * regexes, `overwrite` (trim/case) and custom refinements. Checks run after the schema's type
 * parse, in order; a check without `when` is skipped once an aborting issue exists, a check with
 * `when` (the length checks) decides for itself, exactly as in zod.
 *
 * `$def` keeps the check's parameters (`check`, `minimum`, `maximum`, `format`, `pattern`, ...)
 * for introspection and `toJSONSchema()`.
 */
final class ZodCheck
{
    /**
     * @param \Closure(ParsePayload, ZodCheck): void $fn
     * @param array<string, mixed> $def
     */
    public function __construct(
        private readonly \Closure $fn,
        public readonly array $def,
        private readonly ?\Closure $error = null,
        public readonly bool $abort = false,
        public readonly ?\Closure $when = null,
    ) {
    }

    public function kind(): string
    {
        return (string) ($this->def['check'] ?? 'custom');
    }

    public function errorMap(): ?\Closure
    {
        return $this->error;
    }

    public function run(ParsePayload $payload): void
    {
        ($this->fn)($payload, $this);
    }

    // --- length --------------------------------------------------------------------------------

    /** @param string|array<string, mixed>|null $params */
    public static function minLength(int $minimum, string|array|null $params = null): self
    {
        $p = Util::normalizeParams($params);

        return new self(static function (ParsePayload $payload, ZodCheck $check) use ($minimum): void {
            $length = Util::length($payload->value);
            if ($length === null || $length >= $minimum) {
                return;
            }
            $payload->issues[] = [
                'origin' => self::lengthableOrigin($payload->value),
                'code' => 'too_small',
                'minimum' => $minimum,
                'inclusive' => true,
                'input' => $payload->value,
                'inst' => $check,
                'continue' => !$check->abort,
            ];
        }, ['check' => 'min_length', 'minimum' => $minimum], $p['error'], (bool) $p['abort'], $p['when'] ?? self::hasLength(...));
    }

    /** @param string|array<string, mixed>|null $params */
    public static function maxLength(int $maximum, string|array|null $params = null): self
    {
        $p = Util::normalizeParams($params);

        return new self(static function (ParsePayload $payload, ZodCheck $check) use ($maximum): void {
            $length = Util::length($payload->value);
            if ($length === null || $length <= $maximum) {
                return;
            }
            $payload->issues[] = [
                'origin' => self::lengthableOrigin($payload->value),
                'code' => 'too_big',
                'maximum' => $maximum,
                'inclusive' => true,
                'input' => $payload->value,
                'inst' => $check,
                'continue' => !$check->abort,
            ];
        }, ['check' => 'max_length', 'maximum' => $maximum], $p['error'], (bool) $p['abort'], $p['when'] ?? self::hasLength(...));
    }

    /** @param string|array<string, mixed>|null $params */
    public static function length(int $length, string|array|null $params = null): self
    {
        $p = Util::normalizeParams($params);

        return new self(static function (ParsePayload $payload, ZodCheck $check) use ($length): void {
            $actual = Util::length($payload->value);
            if ($actual === null || $actual === $length) {
                return;
            }
            $issue = ['origin' => self::lengthableOrigin($payload->value)];
            if ($actual > $length) {
                $issue += ['code' => 'too_big', 'maximum' => $length];
            } else {
                $issue += ['code' => 'too_small', 'minimum' => $length];
            }
            $payload->issues[] = $issue + [
                'inclusive' => true,
                'exact' => true,
                'input' => $payload->value,
                'inst' => $check,
                'continue' => !$check->abort,
            ];
        }, ['check' => 'length_equals', 'length' => $length], $p['error'], (bool) $p['abort'], $p['when'] ?? self::hasLength(...));
    }

    // --- strings -------------------------------------------------------------------------------

    /**
     * A PCRE pattern with delimiters, e.g. `'/^[a-z]+$/i'` for JavaScript's `/^[a-z]+$/i`; the
     * issue's `pattern` is the pattern string as given (JavaScript's `regex.toString()`).
     *
     * @param string|array<string, mixed>|null $params
     */
    public static function regex(string $pattern, string|array|null $params = null): self
    {
        $p = Util::normalizeParams($params);

        return new self(static function (ParsePayload $payload, ZodCheck $check) use ($pattern): void {
            if (is_string($payload->value) && preg_match($pattern, $payload->value) === 1) {
                return;
            }
            $payload->issues[] = [
                'origin' => 'string',
                'code' => 'invalid_format',
                'format' => 'regex',
                'input' => $payload->value,
                'pattern' => $pattern,
                'inst' => $check,
                'continue' => !$check->abort,
            ];
        }, ['check' => 'string_format', 'format' => 'regex', 'pattern' => $pattern], $p['error'], (bool) $p['abort'], $p['when']);
    }

    /**
     * A named string format (`email`, `uuid`, `datetime`, ...) validated by `$pattern`.
     *
     * @param string|array<string, mixed>|null $params
     */
    public static function stringFormat(string $format, string $pattern, string|array|null $params = null): self
    {
        $p = Util::normalizeParams($params);

        return new self(static function (ParsePayload $payload, ZodCheck $check) use ($format, $pattern): void {
            if (is_string($payload->value) && preg_match($pattern, $payload->value) === 1) {
                return;
            }
            $payload->issues[] = [
                'origin' => 'string',
                'code' => 'invalid_format',
                'format' => $format,
                'input' => $payload->value,
                'pattern' => $pattern,
                'inst' => $check,
                'continue' => !$check->abort,
            ];
        }, ['check' => 'string_format', 'format' => $format, 'pattern' => $pattern], $p['error'], (bool) $p['abort'], $p['when']);
    }

    /**
     * `startsWith` / `endsWith` / `includes`.
     *
     * @param 'starts_with'|'ends_with'|'includes' $format
     * @param string|array<string, mixed>|null $params
     */
    public static function substring(string $format, string $needle, string|array|null $params = null): self
    {
        $p = Util::normalizeParams($params);
        $field = ['starts_with' => 'prefix', 'ends_with' => 'suffix', 'includes' => 'includes'][$format];

        return new self(static function (ParsePayload $payload, ZodCheck $check) use ($format, $needle, $field): void {
            $value = $payload->value;
            if (is_string($value)) {
                $ok = match ($format) {
                    'starts_with' => str_starts_with($value, $needle),
                    'ends_with' => str_ends_with($value, $needle),
                    'includes' => str_contains($value, $needle),
                };
                if ($ok) {
                    return;
                }
            }
            $payload->issues[] = [
                'origin' => 'string',
                'code' => 'invalid_format',
                'format' => $format,
                'input' => $value,
                $field => $needle,
                'inst' => $check,
                'continue' => !$check->abort,
            ];
        }, ['check' => 'string_format', 'format' => $format, $field => $needle], $p['error'], (bool) $p['abort'], $p['when']);
    }

    /** `trim()`, `toLowerCase()`, `toUpperCase()`: rewrite the value in place. */
    public static function overwrite(\Closure $transform): self
    {
        return new self(static function (ParsePayload $payload) use ($transform): void {
            $payload->value = $transform($payload->value);
        }, ['check' => 'overwrite']);
    }

    // --- numbers -------------------------------------------------------------------------------

    /** @param string|array<string, mixed>|null $params */
    public static function greaterThan(int|float $value, bool $inclusive, string|array|null $params = null): self
    {
        $p = Util::normalizeParams($params);

        return new self(static function (ParsePayload $payload, ZodCheck $check) use ($value, $inclusive): void {
            $input = $payload->value;
            if ((is_int($input) || is_float($input)) && ($inclusive ? $input >= $value : $input > $value)) {
                return;
            }
            $payload->issues[] = [
                'origin' => 'number',
                'code' => 'too_small',
                'minimum' => $value,
                'input' => $input,
                'inclusive' => $inclusive,
                'inst' => $check,
                'continue' => !$check->abort,
            ];
        }, ['check' => 'greater_than', 'value' => $value, 'inclusive' => $inclusive], $p['error'], (bool) $p['abort'], $p['when']);
    }

    /** @param string|array<string, mixed>|null $params */
    public static function lessThan(int|float $value, bool $inclusive, string|array|null $params = null): self
    {
        $p = Util::normalizeParams($params);

        return new self(static function (ParsePayload $payload, ZodCheck $check) use ($value, $inclusive): void {
            $input = $payload->value;
            if ((is_int($input) || is_float($input)) && ($inclusive ? $input <= $value : $input < $value)) {
                return;
            }
            $payload->issues[] = [
                'origin' => 'number',
                'code' => 'too_big',
                'maximum' => $value,
                'input' => $input,
                'inclusive' => $inclusive,
                'inst' => $check,
                'continue' => !$check->abort,
            ];
        }, ['check' => 'less_than', 'value' => $value, 'inclusive' => $inclusive], $p['error'], (bool) $p['abort'], $p['when']);
    }

    /** @param string|array<string, mixed>|null $params */
    public static function multipleOf(int|float $divisor, string|array|null $params = null): self
    {
        $p = Util::normalizeParams($params);

        return new self(static function (ParsePayload $payload, ZodCheck $check) use ($divisor): void {
            $input = $payload->value;
            if (is_int($input) || is_float($input)) {
                if (is_int($input) && is_int($divisor)) {
                    $ok = $divisor !== 0 && $input % $divisor === 0;
                } else {
                    // zod's floatSafeRemainder
                    $decimals = static function (int|float $n): int {
                        $s = Util::numberToString($n);
                        $dot = strpos($s, '.');

                        return $dot === false ? 0 : strlen($s) - $dot - 1;
                    };
                    $dec = max($decimals($input), $decimals($divisor));
                    $ok = round($input * 10 ** $dec) % round($divisor * 10 ** $dec) === 0;
                }
                if ($ok) {
                    return;
                }
            }
            $payload->issues[] = [
                'origin' => 'number',
                'code' => 'not_multiple_of',
                'divisor' => $divisor,
                'input' => $input,
                'inst' => $check,
                'continue' => !$check->abort,
            ];
        }, ['check' => 'multiple_of', 'value' => $divisor], $p['error'], (bool) $p['abort'], $p['when']);
    }

    /**
     * `.int()`: zod's `safeint` number format.
     *
     * @param string|array<string, mixed>|null $params
     */
    public static function int(string|array|null $params = null): self
    {
        $p = Util::normalizeParams($params);

        return new self(static function (ParsePayload $payload, ZodCheck $check): void {
            $input = $payload->value;
            $isInteger = is_int($input) || (is_float($input) && is_finite($input) && floor($input) === $input);
            if (!$isInteger) {
                $payload->issues[] = [
                    'expected' => 'int',
                    'format' => 'safeint',
                    'code' => 'invalid_type',
                    'continue' => false,
                    'input' => $input,
                    'inst' => $check,
                ];

                return;
            }
            if (abs($input) > 9007199254740991) {
                $payload->issues[] = $input > 0
                    ? ['input' => $input, 'code' => 'too_big', 'maximum' => 9007199254740991, 'note' => 'Integers must be within the safe integer range.', 'inst' => $check, 'origin' => 'int', 'inclusive' => true, 'continue' => !$check->abort]
                    : ['input' => $input, 'code' => 'too_small', 'minimum' => -9007199254740991, 'note' => 'Integers must be within the safe integer range.', 'inst' => $check, 'origin' => 'int', 'inclusive' => true, 'continue' => !$check->abort];
            }
        }, ['check' => 'number_format', 'format' => 'safeint'], $p['error'], (bool) $p['abort'], $p['when']);
    }

    // --- refinements ---------------------------------------------------------------------------

    /**
     * `.refine(fn, params)`: a custom issue when `fn($value)` is falsy.
     *
     * @param string|array<string, mixed>|null $params
     */
    public static function refine(\Closure $fn, string|array|null $params = null, bool $abortByDefault = false): self
    {
        $p = Util::normalizeParams($params);
        $path = $p['path'] ?? [];
        $extra = $p['params'];

        return new self(static function (ParsePayload $payload, ZodCheck $check) use ($fn, $path, $extra): void {
            $input = $payload->value;
            if (Util::jsTruthy($fn($input))) {
                return;
            }
            $issue = [
                'code' => 'custom',
                'input' => $input,
                'inst' => $check,
                'path' => $path,
                'continue' => !$check->abort,
            ];
            if ($extra !== null) {
                $issue['params'] = $extra;
            }
            $payload->issues[] = $issue;
        }, ['check' => 'custom'], $p['error'], $p['abort'] ?? $abortByDefault, $p['when']);
    }

    /**
     * `.superRefine(fn)`: `fn($value, $ctx)` reports issues through `$ctx->addIssue()`.
     *
     * @param string|array<string, mixed>|null $params
     */
    public static function superRefine(\Closure $fn, string|array|null $params = null): self
    {
        $p = Util::normalizeParams($params);

        return new self(static function (ParsePayload $payload, ZodCheck $check) use ($fn): void {
            $previous = $payload->addIssueHandler;
            $payload->addIssueHandler = self::issueAdder($payload, $check, !$check->abort);
            try {
                $fn($payload->value, $payload);
            } finally {
                $payload->addIssueHandler = $previous;
            }
        }, ['check' => 'custom'], $p['error'], (bool) $p['abort'], $p['when']);
    }

    /**
     * The `ctx.addIssue` zod installs for superRefine and transform callbacks.
     *
     * @return \Closure(array<string, mixed>|string): void
     */
    public static function issueAdder(ParsePayload $payload, ZodCheck|ZodType $inst, ?bool $continue): \Closure
    {
        return static function (array|string $issue) use ($payload, $inst, $continue): void {
            if (is_string($issue)) {
                $payload->issues[] = ['message' => $issue, 'code' => 'custom', 'input' => $payload->value, 'inst' => $inst];

                return;
            }
            if (!empty($issue['fatal'])) {
                $issue['continue'] = false;
            }
            $issue['code'] ??= 'custom';
            if (!array_key_exists('input', $issue)) {
                $issue['input'] = $payload->value;
            }
            $issue['inst'] ??= $inst;
            if ($continue !== null && !array_key_exists('continue', $issue)) {
                $issue['continue'] = $continue;
            }
            if (isset($issue['path']) && is_array($issue['path'])) {
                $issue['path'] = array_values($issue['path']);
            }
            $payload->issues[] = $issue;
        };
    }

    // --- helpers -------------------------------------------------------------------------------

    private static function hasLength(ParsePayload $payload): bool
    {
        return Util::length($payload->value) !== null;
    }

    private static function lengthableOrigin(mixed $value): string
    {
        return match (true) {
            is_string($value) => 'string',
            is_array($value) => 'array',
            default => 'unknown',
        };
    }
}
