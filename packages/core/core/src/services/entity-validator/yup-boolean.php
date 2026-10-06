<?php

declare(strict_types=1);

namespace Strapi\Core\Services\EntityValidator;

/** `yup.boolean()` */
class YupBoolean extends Yup
{
    protected string $type = 'boolean';

    protected function cast(mixed $value): mixed
    {
        if (is_string($value)) {
            if (preg_match('/^(true|1)$/i', $value) === 1) {
                return true;
            }
            if (preg_match('/^(false|0)$/i', $value) === 1) {
                return false;
            }
        }

        return $value;
    }

    protected function typeCheck(mixed $value): bool
    {
        return is_bool($value);
    }
}
