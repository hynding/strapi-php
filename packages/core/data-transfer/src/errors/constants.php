<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Errors;

/**
 * Port of src/errors/constants.ts: `SeverityKind` (the `Severity` type is a string here).
 */
final class Constants
{
    public const string FATAL = 'fatal';

    public const string ERROR = 'error';

    public const string SILLY = 'silly';

    /** upstream `SeverityKind` */
    public const array SEVERITY_KIND = [
        'FATAL' => self::FATAL,
        'ERROR' => self::ERROR,
        'SILLY' => self::SILLY,
    ];
}
