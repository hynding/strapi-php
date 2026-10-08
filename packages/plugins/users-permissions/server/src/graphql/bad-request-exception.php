<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Graphql;

/**
 * The `Error` utils.js `checkBadRequest()` throws, with `code` and `data` (PHP-port addition: JS
 * attaches them to a plain `Error`).
 */
final class BadRequestException extends \Exception
{
    public function __construct(string $message, public readonly mixed $statusCode, public readonly mixed $data)
    {
        parent::__construct($message);
    }
}
