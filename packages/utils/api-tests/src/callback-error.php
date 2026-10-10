<?php

declare(strict_types=1);

namespace Strapi\ApiTests;

/**
 * What a function of the test process threw ({@see Callback}). `token` names the thrown value in the
 * test process, so the bridge's error response lets it rethrow that very value when the error
 * reaches the test (`wrapInTransaction` throws a Symbol through `strapi.db.transaction()` and
 * catches it by identity; a failed `expect()` keeps its message).
 */
final class CallbackError extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $token = null)
    {
        parent::__construct($message);
    }

    /** The CallbackError an exception was caused by, if any. */
    public static function in(\Throwable $e): ?self
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof self) {
                return $cause;
            }
        }

        return null;
    }
}
