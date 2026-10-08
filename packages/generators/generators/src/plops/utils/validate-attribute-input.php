<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops\Utils;

/** Port of src/plops/utils/validate-attribute-input.ts. */
final class ValidateAttributeInput
{
    public static function validateAttributeInput(mixed $input): bool|string
    {
        if (!is_string($input) || $input === '') {
            return 'You must provide an input';
        }

        return preg_match('/^[A-Za-z-|_]+$/', $input) === 1 ? true : "Please use only letters, '-', '_',  and no spaces";
    }
}
