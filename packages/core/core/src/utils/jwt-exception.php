<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

/** Thrown by Jwt::decode. `getCode()` says why, mirroring jsonwebtoken's error names. */
final class JwtException extends \UnexpectedValueException
{
    public const MALFORMED = 1;          // JsonWebTokenError
    public const INVALID_SIGNATURE = 2;  // JsonWebTokenError: invalid signature
    public const EXPIRED = 3;            // TokenExpiredError
    public const BEFORE_VALID = 4;       // NotBeforeError
}
