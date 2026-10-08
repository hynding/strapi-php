<?php

declare(strict_types=1);

namespace Strapi\Generators\Plops\Utils;

/** Port of src/plops/utils/validate-file-name-input.ts. */
final class ValidateFileNameInput
{
    public static function validateFileNameInput(mixed $input): bool|string
    {
        if (!is_string($input) || $input === '') {
            return 'You must provide an input';
        }

        return preg_match('/^[A-Za-z-_0-9]+$/', $input) === 1 ? true : "Please use only letters and number, '-' or '_' and no spaces";
    }
}
