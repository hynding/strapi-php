<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Utils\Primitives\Objects;
use Strapi\Utils\Yup\Undefined;
use Strapi\Utils\Yup\YupError;

/**
 * Port of packages/core/utils/src/format-yup-error.ts.
 *
 * @phpstan-import-type FormattedError from \Strapi\Utils\Errors\YupValidationError
 */
final class FormatYupError
{
    /** @return FormattedError */
    private static function formatYupInnerError(YupError $yupError): array
    {
        $formatted = [
            'path' => array_map('strval', Objects::toPath($yupError->path ?? '')),
            'message' => $yupError->getMessage(),
            'name' => $yupError->name,
        ];

        // an `undefined` value is dropped from the JSON upstream sends
        if (!$yupError->value instanceof Undefined) {
            $formatted['value'] = $yupError->value;
        }

        return $formatted;
    }

    /** @return array{errors: list<FormattedError>, message: string} */
    public static function formatYupErrors(YupError $yupError): array
    {
        return [
            'errors' => $yupError->inner === []
                ? [self::formatYupInnerError($yupError)]
                : array_map(self::formatYupInnerError(...), $yupError->inner),
            'message' => $yupError->getMessage(),
        ];
    }
}
