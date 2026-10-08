<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Utils\Websocket;

/**
 * Not an upstream file: `ws`'s `unexpected-response` event — the server answered the upgrade
 * request with something other than `101 Switching Protocols`.
 */
final class UnexpectedResponseError extends \RuntimeException
{
    public function __construct(public readonly int $statusCode, public readonly string $body = '')
    {
        parent::__construct("Unexpected server response: {$statusCode}");
    }
}
