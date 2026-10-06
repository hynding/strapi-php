<?php

declare(strict_types=1);

namespace Strapi\Core\Services\EntityValidator;

/** `yup.number()` */
class YupNumber extends Yup
{
    protected string $type = 'number';

    protected function cast(mixed $value): mixed
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return NAN;
            }
            if (is_numeric($trimmed)) {
                $num = $trimmed + 0;

                return $num;
            }

            return NAN;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return $value;
    }

    protected function typeCheck(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && !(is_float($value) && is_nan($value));
    }

    public function integer(string $message = '${path} must be an integer'): static
    {
        return $this->test('integer', $message, static fn (mixed $v): bool => $v === null || $v instanceof Undefined || !is_numeric($v) || (is_float($v) ? floor($v) === $v : is_int($v)));
    }

    public function min(int|float $min, string $message = '${path} must be greater than or equal to ${min}'): static
    {
        return $this->test('min', Yup::interpolate($message, ['min' => $min, 'path' => '${path}']), static fn (mixed $v): bool => $v === null || $v instanceof Undefined || !is_numeric($v) || $v >= $min);
    }

    public function max(int|float $max, string $message = '${path} must be less than or equal to ${max}'): static
    {
        return $this->test('max', Yup::interpolate($message, ['max' => $max, 'path' => '${path}']), static fn (mixed $v): bool => $v === null || $v instanceof Undefined || !is_numeric($v) || $v <= $max);
    }
}
