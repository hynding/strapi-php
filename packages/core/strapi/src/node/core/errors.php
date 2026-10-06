<?php

declare(strict_types=1);

namespace Strapi\Cli\Node\Core;

use Strapi\Cli\Cli\Utils\Logger;

/** Port of packages/core/strapi/src/node/core/errors.ts. */
final class Errors
{
    public static function isError(mixed $err): bool
    {
        return $err instanceof \Throwable;
    }

    /** Logs the error and returns the exit code (upstream calls `process.exit(1)`). */
    public static function handleUnexpectedError(mixed $err, ?Logger $logger = null): int
    {
        $message = $err instanceof \Throwable ? $err->getMessage() : (string) json_encode($err);
        if ($logger !== null) {
            $logger->error($message);
            if ($err instanceof \Throwable && $logger->isDebug()) {
                $logger->error($err->getTraceAsString());
            }
        } else {
            fwrite(STDERR, $message . "\n");
        }

        return 1;
    }
}
