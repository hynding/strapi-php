<?php

declare(strict_types=1);

namespace Strapi\Core\Services\Mcp;

use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;

/**
 * Port of services/mcp/authentication.ts (`createMcpAdminTokenAuthenticator`): MCP requests
 * authenticate with an admin token (`Authorization: Bearer <accessKey>`, `kind: 'admin'`),
 * checked by the admin package's `api-token-admin.authenticateAdminToken()`.
 *
 * @phpstan-type McpAdminTokenAuthResult array{authenticated: false, reason: 'missing_token'|'invalid_token', error?: \Throwable}|array{authenticated: true, credentials: array{id: mixed}, user: array{id: mixed}, ability: \Strapi\Permissions\Engine\Abilities\Ability}
 */
final class Authentication
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public static function createMcpAdminTokenAuthenticator(Strapi $strapi): self
    {
        return new self($strapi);
    }

    public static function extractBearerToken(Context $ctx): ?string
    {
        $authorization = $ctx->header('authorization');

        if ($authorization === null) {
            return null;
        }

        $parts = preg_split('/\s+/', $authorization) ?: [];

        if (strtolower((string) ($parts[0] ?? '')) !== 'bearer' || count($parts) !== 2) {
            return null;
        }

        return $parts[1];
    }

    /** @return McpAdminTokenAuthResult */
    public function authenticate(Context $ctx): array
    {
        $token = self::extractBearerToken($ctx);

        if ($token === null) {
            return ['authenticated' => false, 'reason' => 'missing_token'];
        }

        $apiTokenService = $this->strapi->service('admin::api-token-admin');
        if (!method_exists($apiTokenService, 'authenticateAdminToken')) {
            throw new \LogicException('[MCP] admin::api-token-admin has no authenticateAdminToken()');
        }
        $authResult = $apiTokenService->authenticateAdminToken($token);
        if (!is_array($authResult)) {
            return ['authenticated' => false, 'reason' => 'invalid_token'];
        }

        if (($authResult['authenticated'] ?? false) !== true || !(($authResult['ability'] ?? null) instanceof \Strapi\Permissions\Engine\Abilities\Ability)) {
            $error = $authResult['error'] ?? null;

            return ['authenticated' => false, 'reason' => 'invalid_token', ...($error instanceof \Throwable ? ['error' => $error] : [])];
        }

        return [
            'authenticated' => true,
            'credentials' => ['id' => $authResult['credentials']['id'] ?? null],
            'user' => ['id' => $authResult['user']['id'] ?? null],
            'ability' => $authResult['ability'],
        ];
    }
}
