<?php

declare(strict_types=1);

namespace Strapi\Admin\Strategies;

use Strapi\Admin\Services\Constants;
use Strapi\Admin\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ForbiddenError;
use Strapi\Utils\Errors\UnauthorizedError;

/**
 * Port of server/src/strategies/content-api-token.ts: the content API's API token strategy
 * (read-only, full-access and custom tokens). {@see self::strategy()} builds the
 * `{ name, authenticate, verify }` strategy for `strapi.get('auth').register('content-api', ...)`.
 */
final class ContentApiToken
{
    public const NAME = 'content-api-token';

    private static function isReadScope(string $scope): bool
    {
        return str_ends_with($scope, 'find') || str_ends_with($scope, 'findOne');
    }

    /**
     * Authenticate a content-api token. Rejects tokens with kind !== 'content-api'.
     *
     * @return array<string, mixed>
     */
    public static function authenticate(Context $ctx, Strapi $strapi): array
    {
        $apiTokenService = Utils::getService($strapi, 'api-token-content-api');
        $token = ApiTokenUtils::extractToken($ctx);

        if ($token === null) {
            return ['authenticated' => false];
        }

        $apiToken = $apiTokenService->getByAccessKey($apiTokenService->hash($token));

        if ($apiToken === null) {
            return ['authenticated' => false];
        }

        // Defensive kind check — only handle content-api tokens.
        // null kind is allowed: tokens created before the kind field was introduced are implicitly content-api.
        if (($apiToken['kind'] ?? null) !== 'content-api' && ($apiToken['kind'] ?? null) !== null) {
            return ['authenticated' => false];
        }

        $expiryError = ApiTokenUtils::checkExpiry($apiToken);
        if ($expiryError !== null) {
            return ['authenticated' => false, 'error' => $expiryError];
        }

        ApiTokenUtils::updateLastUsedAt($strapi, $apiToken);

        if (($apiToken['type'] ?? null) === Constants::API_TOKEN_TYPE['CUSTOM']) {
            $permissions = is_array($apiToken['permissions'] ?? null) ? $apiToken['permissions'] : [];
            $ability = $strapi->contentAPI()->permissions->engine->generateAbility(
                array_map(static fn (mixed $action): array => ['action' => (string) $action], array_values($permissions))
            );

            return ['authenticated' => true, 'ability' => $ability, 'credentials' => $apiToken];
        }

        return ['authenticated' => true, 'credentials' => $apiToken];
    }

    /**
     * Verify the token has the required abilities for the requested scope.
     *
     * @param array<string, mixed> $auth
     */
    public static function verify(array $auth, mixed $config = []): void
    {
        $apiToken = $auth['credentials'] ?? null;
        $ability = $auth['ability'] ?? null;

        if (!is_array($apiToken)) {
            throw new UnauthorizedError('Token not found');
        }

        $expiryError = ApiTokenUtils::checkExpiry($apiToken);
        if ($expiryError !== null) {
            throw $expiryError;
        }

        $type = $apiToken['type'] ?? null;
        $scope = is_array($config) ? ($config['scope'] ?? null) : null;
        // lodash castArray
        $scopes = is_array($scope) ? array_values($scope) : [$scope];

        // Full access
        if ($type === Constants::API_TOKEN_TYPE['FULL_ACCESS']) {
            return;
        }

        // Read only
        if ($type === Constants::API_TOKEN_TYPE['READ_ONLY']) {
            $allRead = true;
            foreach ($scopes as $s) {
                if (!is_string($s) || !self::isReadScope($s)) {
                    $allRead = false;
                    break;
                }
            }

            if (!empty($scope) && $allRead) {
                return;
            }
        }

        // Custom
        elseif ($type === Constants::API_TOKEN_TYPE['CUSTOM']) {
            if (!is_object($ability) || !method_exists($ability, 'can')) {
                throw new ForbiddenError();
            }

            $isAllowed = true;
            foreach ($scopes as $s) {
                if ($ability->can((string) $s) !== true) {
                    $isAllowed = false;
                    break;
                }
            }

            if ($isAllowed === true) {
                return;
            }
        }

        throw new ForbiddenError();
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
