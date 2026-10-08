<?php

declare(strict_types=1);

namespace Strapi\Utils\Yup;

/**
 * Port of `StrapiIDSchema` (packages/core/utils/src/yup.ts): `yup.strapiID()`, a mixed schema of
 * type `strapiID` accepting strings and non-negative integers, without casting.
 */
class StrapiIdSchema extends Yup
{
    protected string $type = 'strapiID';

    protected function typeCheck(mixed $value): bool
    {
        if (is_string($value)) {
            return true;
        }

        return (is_int($value) && $value >= 0)
            || (is_float($value) && is_finite($value) && floor($value) === $value && $value >= 0);
    }
}
