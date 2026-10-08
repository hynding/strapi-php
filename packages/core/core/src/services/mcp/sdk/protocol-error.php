<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Sdk;

/**
 * Not an upstream file: `ProtocolError` of `@modelcontextprotocol/server` 2.0, the error a request
 * handler throws to answer with a JSON-RPC error (`{ code, message, data? }`).
 */
final class ProtocolError extends \RuntimeException
{
    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;
    public const RESOURCE_NOT_FOUND = -32002;

    public function __construct(public readonly int $errorCode, string $message, public readonly mixed $data = null)
    {
        parent::__construct($message);
    }
}
