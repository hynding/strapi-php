<?php

declare(strict_types=1);

namespace Strapi\Utils\Yup;

/** `yup.boolean()` / `yup.bool()` */
class YupBoolean extends Yup
{
    protected string $type = 'boolean';

    protected function typeCheck(mixed $value): bool
    {
        return is_bool($value);
    }

    protected function typeTransform(mixed $value): mixed
    {
        if (!$this->isType($value)) {
            $str = Yup::jsString($value);
            if (preg_match('/^(true|1)$/i', $str) === 1) {
                return true;
            }
            if (preg_match('/^(false|0)$/i', $str) === 1) {
                return false;
            }
        }

        return $value;
    }

    public function isTrue(string|\Closure $message = Locale::BOOLEAN_IS_VALUE): static
    {
        return $this->test([
            'message' => $message,
            'name' => 'is-value',
            'exclusive' => true,
            'params' => ['value' => 'true'],
            'test' => static fn (mixed $value): bool => Yup::isAbsent($value) || $value === true,
        ]);
    }

    public function isFalse(string|\Closure $message = Locale::BOOLEAN_IS_VALUE): static
    {
        return $this->test([
            'message' => $message,
            'name' => 'is-value',
            'exclusive' => true,
            'params' => ['value' => 'false'],
            'test' => static fn (mixed $value): bool => Yup::isAbsent($value) || $value === false,
        ]);
    }
}
