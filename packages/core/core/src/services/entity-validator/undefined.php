<?php

declare(strict_types=1);

namespace Strapi\Core\Services\EntityValidator;

/** JS `undefined` (a missing key), distinct from `null`, for the yup port. */
final class Undefined
{
    private static ?self $instance = null;

    private function __construct()
    {
    }

    public static function value(): self
    {
        return self::$instance ??= new self();
    }
}
