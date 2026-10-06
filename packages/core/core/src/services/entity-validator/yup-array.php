<?php

declare(strict_types=1);

namespace Strapi\Core\Services\EntityValidator;

/** `yup.array()` */
class YupArray extends Yup
{
    protected string $type = 'array';

    protected ?Yup $innerType = null;

    public function of(Yup $schema): static
    {
        $clone = clone $this;
        $clone->innerType = $schema;

        return $clone;
    }

    protected function cast(mixed $value): mixed
    {
        if (is_string($value) && json_validate($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded) && array_is_list($decoded)) {
                return $decoded;
            }
        }

        return $value;
    }

    protected function typeCheck(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    protected function runInner(mixed $value, string $path, bool $strict, array &$errors): mixed
    {
        if (!is_array($value) || $this->innerType === null) {
            return $value;
        }

        $out = [];
        foreach ($value as $index => $item) {
            $out[] = $this->innerType->run($item, Yup::joinPath($path, (int) $index), $strict, $errors, $item);
        }

        return $out;
    }

    public function min(int $min, string $message = '${path} field must have at least ${min} items'): static
    {
        return $this->test('min', Yup::interpolate($message, ['min' => $min, 'path' => '${path}']), static fn (mixed $v): bool => $v === null || $v instanceof Undefined || !is_array($v) || count($v) >= $min);
    }

    public function max(int $max, string $message = '${path} field must have less than or equal to ${max} items'): static
    {
        return $this->test('max', Yup::interpolate($message, ['max' => $max, 'path' => '${path}']), static fn (mixed $v): bool => $v === null || $v instanceof Undefined || !is_array($v) || count($v) <= $max);
    }
}
