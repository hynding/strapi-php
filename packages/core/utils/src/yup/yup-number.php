<?php

declare(strict_types=1);

namespace Strapi\Utils\Yup;

/** `yup.number()` */
class YupNumber extends Yup
{
    protected string $type = 'number';

    protected function typeCheck(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && !is_nan($value));
    }

    protected function typeTransform(mixed $value): mixed
    {
        $parsed = $value;
        if (is_string($parsed)) {
            $parsed = (string) preg_replace('/\s/u', '', $parsed);
            if ($parsed === '') {
                return NAN;
            }
            $parsed = self::jsToNumber($parsed);
        }
        if ($this->isType($parsed)) {
            return $parsed;
        }

        return self::parseFloat(Yup::jsString($parsed));
    }

    /** JS `+string` (whitespace already stripped). */
    public static function jsToNumber(string $value): int|float
    {
        if (preg_match('/^[+-]?\d+$/', $value) === 1) {
            $int = filter_var($value, FILTER_VALIDATE_INT);

            return $int !== false ? $int : (float) $value;
        }
        if (preg_match('/^[+-]?(\d+\.?\d*|\.\d+)(e[+-]?\d+)?$/i', $value) === 1) {
            return (float) $value;
        }
        if (preg_match('/^([+-]?)Infinity$/', $value, $m) === 1) {
            return $m[1] === '-' ? -INF : INF;
        }
        if (preg_match('/^0x([0-9a-f]+)$/i', $value, $m) === 1) {
            return hexdec($m[1]);
        }
        if (preg_match('/^0o([0-7]+)$/i', $value, $m) === 1) {
            return octdec($m[1]);
        }
        if (preg_match('/^0b([01]+)$/i', $value, $m) === 1) {
            return bindec($m[1]);
        }

        return NAN;
    }

    /** JS `parseFloat(string)`. */
    public static function parseFloat(string $value): int|float
    {
        if (preg_match('/^[\s\x{FEFF}\x{A0}]*([+-]?(?:Infinity|(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?))/u', $value, $m) !== 1) {
            return NAN;
        }
        $num = $m[1];
        if (str_ends_with($num, 'Infinity')) {
            return str_starts_with($num, '-') ? -INF : INF;
        }

        return self::jsToNumber($num);
    }

    private function compareTest(string $name, string $param, int|float|Reference $limit, string|\Closure $message, \Closure $compare): static
    {
        return $this->test([
            'message' => $message,
            'name' => $name,
            'exclusive' => true,
            'params' => [$param => $limit],
            'test' => static function (mixed $value, TestContext $ctx) use ($limit, $compare): bool {
                if (Yup::isAbsent($value)) {
                    return true;
                }
                $resolved = $ctx->resolve($limit);

                return (is_int($value) || is_float($value)) && (is_int($resolved) || is_float($resolved)) && $compare($value, $resolved);
            },
        ]);
    }

    public function min(int|float|Reference $min, string|\Closure $message = Locale::NUMBER_MIN): static
    {
        return $this->compareTest('min', 'min', $min, $message, static fn (int|float $v, int|float $l): bool => $v >= $l);
    }

    public function max(int|float|Reference $max, string|\Closure $message = Locale::NUMBER_MAX): static
    {
        return $this->compareTest('max', 'max', $max, $message, static fn (int|float $v, int|float $l): bool => $v <= $l);
    }

    public function lessThan(int|float|Reference $less, string|\Closure $message = Locale::NUMBER_LESS_THAN): static
    {
        return $this->compareTest('max', 'less', $less, $message, static fn (int|float $v, int|float $l): bool => $v < $l);
    }

    public function moreThan(int|float|Reference $more, string|\Closure $message = Locale::NUMBER_MORE_THAN): static
    {
        return $this->compareTest('min', 'more', $more, $message, static fn (int|float $v, int|float $l): bool => $v > $l);
    }

    public function positive(string|\Closure $message = Locale::NUMBER_POSITIVE): static
    {
        return $this->moreThan(0, $message);
    }

    public function negative(string|\Closure $message = Locale::NUMBER_NEGATIVE): static
    {
        return $this->lessThan(0, $message);
    }

    public function integer(string|\Closure $message = Locale::NUMBER_INTEGER): static
    {
        return $this->test([
            'name' => 'integer',
            'message' => $message,
            'test' => static fn (mixed $value): bool => Yup::isAbsent($value) || is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value),
        ]);
    }

    /** `value | 0` */
    public function truncate(): static
    {
        return $this->transform(static fn (mixed $value): mixed => !Yup::isAbsent($value) && (is_int($value) || is_float($value))
            ? (is_finite((float) $value) ? (int) $value : 0)
            : $value);
    }

    public function round(?string $method = null): static
    {
        $method = $method !== null && $method !== '' ? strtolower($method) : 'round';
        if ($method === 'trunc') {
            return $this->truncate();
        }
        if (!in_array($method, ['ceil', 'floor', 'round'], true)) {
            throw new \TypeError('Only valid options for round() are: ceil, floor, round, trunc');
        }

        return $this->transform(static function (mixed $value) use ($method): mixed {
            if (Yup::isAbsent($value) || !(is_int($value) || is_float($value))) {
                return $value;
            }

            // JS Math.round rounds .5 up (towards +Infinity)
            return match ($method) {
                'ceil' => ceil($value),
                'floor' => floor($value),
                default => floor($value + 0.5),
            };
        });
    }
}
