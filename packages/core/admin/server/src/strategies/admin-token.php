<?php

declare(strict_types=1);

namespace Strapi\Admin\Strategies;

use Strapi\Admin\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\UnauthorizedError;

/**
 * Port of server/src/strategies/admin-token.ts: authenticates an admin API token (`kind: 'admin'`)
 * as its owner, with the token's own (ceiling-clamped) ability. {@see self::strategy()} builds the
 * `{ name, authenticate, verify }` strategy for `strapi.get('auth').register('admin', ...)`.
 */
final class AdminToken
{
    public const NAME = 'admin-token';

    /**
     * Authenticate an admin token. Rejects tokens with kind !== 'admin'.
     *
     * @return array<string, mixed>
     */
    public static function authenticate(Context $ctx, Strapi $strapi): array
    {
        $apiTokenService = Utils::getService($strapi, 'api-token-admin');
        $token = ApiTokenUtils::extractToken($ctx);

        if ($token === null) {
            return ['authenticated' => false];
        }

        $authResult = $apiTokenService->authenticateAdminToken($token);

        if ($authResult['authenticated'] === true) {
            $ctx->state()->set('userAbility', $authResult['ability']);
            $ctx->state()->set('user', $authResult['user']);
        }

        return $authResult;
    }

    /**
     * Re-check presence and expiry at verify time.
     * Authorization is handled by isAuthenticatedAdmin + hasPermissions policies.
     *
     * @param array<string, mixed> $auth
     */
    public static function verify(array $auth, mixed $config = []): void
    {
        $apiToken = $auth['credentials'] ?? null;

        if (!is_array($apiToken)) {
            throw new UnauthorizedError('Token not found');
        }

        $expiryError = ApiTokenUtils::checkExpiry($apiToken);
        if ($expiryError !== null) {
            throw $expiryError;
        }
    }

    /** @return array{name: string, authenticate: \Closure(Context): array<string, mixed>, verify: \Closure(array<string, mixed>, mixed=): void} */
    public static function strategy(Strapi $strapi): array
    {
        return [
            'name' => self::NAME,
            'authenticate' => static fn (Context $ctx): array => self::authenticate($ctx, $strapi),
            'verify' => static fn (array $auth, mixed $config = []): null => self::verify($auth, $config),
        ];
    }
}
