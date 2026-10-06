<?php

declare(strict_types=1);

namespace Strapi\Core\Services\DocumentService;

use Strapi\Core\Strapi;

/** Port of services/document-service/common.ts (`wrapInTransaction`). */
final class Common
{
    /** @return \Closure(mixed ...$args): mixed */
    public static function wrapInTransaction(Strapi $strapi, callable $fn): \Closure
    {
        return static fn (mixed ...$args): mixed => $strapi->db()->transaction(static fn (): mixed => $fn(...$args));
    }
}
