<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops\Utils;

/** Port of src/plops/utils/validate-input.ts. */
final class ValidateInput
{
    public static function validateInput(mixed $input): bool|string
    {
        if (!is_string($input) || $input === '') {
            return 'You must provide an input';
        }

        return preg_match('/^[A-Za-z-]+$/', $input) === 1 ? true : "Please use only letters, '-' and no spaces";
    }
}
