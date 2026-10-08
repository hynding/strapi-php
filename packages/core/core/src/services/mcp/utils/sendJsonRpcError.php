<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Utils;

use Strapi\Core\Services\Server\Context;

/**
 * Port of services/mcp/utils/sendJsonRpcError.ts. Upstream writes to the raw `res` unless its
 * headers were already sent; here the response is the Context's, and "already sent" is a response
 * already written by the handler (an explicit status).
 */
final class SendJsonRpcError
{
    /** @param key-of<JsonRpcErrors::JSON_RPC_ERRORS> $errorKey */
    public static function sendJsonRpcError(Context $ctx, string $errorKey, ?string $customMessage = null): void
    {
        if ($ctx->hasExplicitStatus()) {
            return;
        }

        ['code' => $code, 'message' => $message, 'httpStatus' => $httpStatus] = JsonRpcErrors::JSON_RPC_ERRORS[$errorKey];

        $ctx->setStatus($httpStatus);
        $ctx->set('Content-Type', 'application/json');
        $ctx->setBody((string) json_encode([
            'jsonrpc' => '2.0',
            'error' => ['code' => $code, 'message' => $customMessage ?? $message],
            'id' => null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
