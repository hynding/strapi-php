<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Error;

/**
 * Port of packages/utils/upgrade/src/modules/error/utils.ts. The error classes it declares live
 * one per file next to it (unexpected-error.php, npm-candidate-not-found-error.php,
 * aborted-error.php).
 */
final class Utils
{
    public static function unknownToError(mixed $e): \Throwable
    {
        if ($e instanceof \Throwable) {
            return $e;
        }

        if (is_string($e)) {
            return new \RuntimeException($e);
        }

        return new UnexpectedError();
    }
}
