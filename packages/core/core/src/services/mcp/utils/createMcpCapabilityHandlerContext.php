<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp\Utils;

/**
 * Port of services/mcp/utils/createMcpCapabilityHandlerContext.ts: translates the MCP SDK's
 * request context into Strapi's public handler context
 * (`{ signal?, requestId, sessionId?, authInfo?, _meta?, requestInfo?: { headers, url } }`).
 * There is no AbortSignal in synchronous PHP: `signal` is left out.
 */
final class CreateMcpCapabilityHandlerContext
{
    /**
     * @param array<string, mixed> $context the SDK `ServerContext` (sdk/mcp-server.php)
     * @return array<string, mixed>
     */
    public static function createMcpCapabilityHandlerContext(array $context): array
    {
        $mcpReq = is_array($context['mcpReq'] ?? null) ? $context['mcpReq'] : [];
        $meta = $mcpReq['_meta'] ?? null;
        $envelope = $mcpReq['envelope'] ?? null;
        $http = is_array($context['http'] ?? null) ? $context['http'] : [];
        $request = is_array($http['req'] ?? null) ? $http['req'] : null;

        $out = ['requestId' => $mcpReq['id'] ?? null];
        if (($context['sessionId'] ?? null) !== null) {
            $out['sessionId'] = $context['sessionId'];
        }
        if (array_key_exists('authInfo', $http)) {
            $out['authInfo'] = $http['authInfo'];
        }
        if ($meta !== null || $envelope !== null) {
            $out['_meta'] = [...(is_array($meta) ? $meta : []), ...(is_array($envelope) ? $envelope : [])];
        }
        if ($request !== null) {
            $out['requestInfo'] = ['headers' => $request['headers'] ?? [], 'url' => $request['url'] ?? null];
        }

        return $out;
    }
}
