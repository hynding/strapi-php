<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Sdk;

use Strapi\Core\Services\Server\Context;

/**
 * Not an upstream file: the stateless (`sessionIdGenerator: undefined`) POST path of
 * `NodeStreamableHTTPServerTransport` / `WebStandardStreamableHTTPServerTransport` of
 * `@modelcontextprotocol/node` 2.0, writing to a Strapi `Context` instead of `res`.
 *
 * - `Accept` must list `application/json` and `text/event-stream` (406), the body must be JSON
 *   (415 on another `Content-Type`), a JSON-RPC message or a batch of them (400 `-32700`);
 * - one `initialize` per batch (400 `-32600`); other requests check `mcp-protocol-version` (400);
 * - only notifications/responses: `202` with no body;
 * - requests: `200` `text/event-stream`, one `event: message` per response, in order (the
 *   stream is closed once every response is written, as the SDK does with no event store).
 */
final class StreamableHttpTransport
{
    private ?McpServer $server = null;

    private bool $initialized = false;

    /** No session: a stateless transport */
    public ?string $sessionId = null;

    public function connect(McpServer $server): void
    {
        $this->server = $server;
    }

    public function handleRequest(Context $ctx, mixed $parsedBody): void
    {
        $accept = (string) $ctx->header('accept');
        if (!str_contains($accept, 'application/json') || !str_contains($accept, 'text/event-stream')) {
            self::jsonError($ctx, 406, -32000, 'Not Acceptable: Client must accept both application/json and text/event-stream');

            return;
        }

        $contentType = strtolower(trim(explode(';', (string) $ctx->header('content-type'))[0]));
        if ($contentType !== 'application/json') {
            self::jsonError($ctx, 415, -32000, 'Unsupported Media Type: Content-Type must be application/json');

            return;
        }

        $messages = is_array($parsedBody) && array_is_list($parsedBody) && $parsedBody !== [] ? $parsedBody : [$parsedBody];
        foreach ($messages as $message) {
            if (!self::isJsonRpcMessage($message)) {
                self::jsonError($ctx, 400, -32700, 'Parse error: Invalid JSON-RPC message');

                return;
            }
        }
        /** @var list<array<string, mixed>> $messages */

        $initializeRequests = array_filter($messages, static fn (array $m): bool => ($m['method'] ?? null) === 'initialize' && array_key_exists('id', $m));
        if ($initializeRequests !== []) {
            if (count($messages) > 1) {
                self::jsonError($ctx, 400, -32600, 'Invalid Request: Only one initialization request is allowed');

                return;
            }
            $this->initialized = true;
        } else {
            $protocolVersion = $ctx->header('mcp-protocol-version');
            if ($protocolVersion !== null && $protocolVersion !== '' && !in_array($protocolVersion, McpServer::SUPPORTED_PROTOCOL_VERSIONS, true)) {
                self::jsonError($ctx, 400, -32000, "Bad Request: Unsupported protocol version: {$protocolVersion} (supported versions: " . implode(', ', McpServer::SUPPORTED_PROTOCOL_VERSIONS) . ')');

                return;
            }
        }

        $server = $this->server ?? throw new \LogicException('Transport is not connected to a server');
        $requestInfo = ['headers' => self::headers($ctx), 'url' => $ctx->url()];

        $hasRequest = false;
        foreach ($messages as $message) {
            $hasRequest = $hasRequest || (isset($message['method']) && array_key_exists('id', $message));
        }

        if (!$hasRequest) {
            foreach ($messages as $message) {
                $server->handleMessage($message, $requestInfo);
            }
            $ctx->setStatus(202);

            return;
        }

        $events = '';
        foreach ($messages as $message) {
            $response = $server->handleMessage($message, $requestInfo);
            if ($response !== null) {
                $events .= "event: message\ndata: " . self::encode($response) . "\n\n";
            }
        }

        $ctx->setStatus(200);
        $ctx->set('Content-Type', 'text/event-stream');
        $ctx->set('Cache-Control', 'no-cache, no-transform');
        $ctx->set('Connection', 'keep-alive');
        $ctx->set('X-Accel-Buffering', 'no');
        $ctx->setType('text/event-stream');
        $ctx->setBody($events);
    }

    public function isInitialized(): bool
    {
        return $this->initialized;
    }

    private static function isJsonRpcMessage(mixed $message): bool
    {
        if (!is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0') {
            return false;
        }
        $id = $message['id'] ?? null;
        $validId = !array_key_exists('id', $message) || is_string($id) || is_int($id);
        if (isset($message['method'])) {
            return is_string($message['method']) && $validId && (!isset($message['params']) || is_array($message['params']));
        }

        // a response
        return array_key_exists('id', $message) && $validId && (array_key_exists('result', $message) || is_array($message['error'] ?? null));
    }

    /** @return array<string, string> lower-cased header names */
    private static function headers(Context $ctx): array
    {
        $headers = [];
        foreach ($ctx->request()->getHeaders() as $name => $values) {
            $headers[strtolower((string) $name)] = implode(', ', $values);
        }

        return $headers;
    }

    private static function jsonError(Context $ctx, int $status, int $code, string $message): void
    {
        $ctx->setStatus($status);
        $ctx->set('Content-Type', 'application/json');
        $ctx->setBody(self::encode(['jsonrpc' => '2.0', 'error' => ['code' => $code, 'message' => $message], 'id' => null]));
    }

    public static function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
