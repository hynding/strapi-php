<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Cli;

use Strapi\Upgrade\Modules\Error\AbortedError;
use Strapi\Upgrade\Modules\Format\Chalk;
use Strapi\Upgrade\Modules\Logger\Logger;

/**
 * Port of packages/utils/upgrade/src/cli/errors.ts. Returns the exit code where upstream calls
 * `process.exit()`.
 */
final class Errors
{
    /** @param resource|null $stderr */
    public static function handleError(\Throwable $err, bool $isSilent, $stderr = null): int
    {
        // If the upgrade process has been aborted, exit silently
        if ($err instanceof AbortedError) {
            return 0;
        }

        if (!$isSilent) {
            fwrite($stderr ?? \STDERR, Chalk::red("[ERROR]\t[" . Logger::nowAsISO() . ']') . ' ' . $err->getMessage() . PHP_EOL);
        }

        return 1;
    }
}
