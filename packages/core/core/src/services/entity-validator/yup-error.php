<?php

declare(strict_types=1);

namespace Strapi\Core\Services\EntityValidator;

/** A single yup validation error created by a test (`this.createError({ path, message })`). */
final class YupError extends \RuntimeException
{
    public function __construct(string $message, public readonly ?string $path = null)
    {
        parent::__construct($message);
    }
}
