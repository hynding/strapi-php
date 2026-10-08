<?php

declare(strict_types=1);

namespace Strapi\Admin\Strategies;

use Strapi\Admin\Utils\Utils;
use Strapi\Core\Core;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ForbiddenError;
use Strapi\Utils\Errors\UnauthorizedError;

/**
 * Port of server/src/strategies/data-transfer.ts: the transfer token strategy used by the
 * `/transfer/runner/{push,pull}` routes. {@see self::strategy()} builds the
 * `{ name, authenticate, verify }` strategy those routes carry in `config.auth.strategies`.
 */
final class DataTransfer
{
    public const NAME = 'data-transfer';

    private static function extractToken(Context $ctx): ?string
    {
        return ApiTokenUtils::extractToken($ctx);
    }

    /**
     * Authenticate the validity of the token
     *
     * @return array<string, mixed>
     */
    public static function authenticate(Context $ctx, Strapi $strapi): array
    {
        $tokenService = Utils::getService($strapi, 'transfer')->token;
        $token = self::extractToken($ctx);

        if ($token === null || $token === '') {
            return ['authenticated' => false];
        }

        $transferToken = $tokenService->getBy(['accessKey' => $tokenService->hash($token)]);

        // Check if the token exists
        if ($transferToken === null) {
            return ['authenticated' => false];
        }

        // Check if the token has expired
        $currentDate = new \DateTimeImmutable();

        if (($transferToken['expiresAt'] ?? null) !== null) {
            $expirationDate = ApiTokenUtils::toDate($transferToken['expiresAt']);

            if ($expirationDate !== null && $expirationDate < $currentDate) {
                return ['authenticated' => false, 'error' => new UnauthorizedError('Token expired')];
            }
        }

        // Update token metadata if the token has not been used in the last hour.
        // upstream: differenceInHours(now, parseISO(lastUsedAt)) is NaN for a token never used,
        // so nothing is written then; and the row written is `admin::api-token`'s, as upstream.
        $lastUsedAt = is_string($transferToken['lastUsedAt'] ?? null) ? ApiTokenUtils::toDate($transferToken['lastUsedAt']) : null;
        if ($lastUsedAt !== null && (int) (($currentDate->getTimestamp() - $lastUsedAt->getTimestamp()) / 3600) >= 1) {
            $strapi->db()->query('admin::api-token')->update([
                'where' => ['id' => $transferToken['id'] ?? null],
                'data' => ['lastUsedAt' => $currentDate],
            ]);
        }

        // Generate an ability based on the token permissions
        $permissions = is_array($transferToken['permissions'] ?? null) ? array_values($transferToken['permissions']) : [];
        $ability = Utils::getService($strapi, 'transfer')->permission->engine->generateAbility(
            array_map(static fn (mixed $action): array => ['action' => (string) $action], $permissions)
        );

        return ['authenticated' => true, 'ability' => $ability, 'credentials' => $transferToken];
    }

    /**
     * Verify the token has the required abilities for the requested scope
     *
     * @param array<string, mixed> $auth
     */
    public static function verify(array $auth, mixed $config = []): void
    {
        $transferToken = $auth['credentials'] ?? null;
        $ability = $auth['ability'] ?? null;

        if (!is_array($transferToken) || $transferToken === []) {
            throw new UnauthorizedError('Token not found');
        }

        $currentDate = new \DateTimeImmutable();

        if (($transferToken['expiresAt'] ?? null) !== null) {
            $expirationDate = ApiTokenUtils::toDate($transferToken['expiresAt']);
            // token has expired
            if ($expirationDate !== null && $expirationDate < $currentDate) {
                throw new UnauthorizedError('Token expired');
            }
        }

        if (!is_object($ability) || !method_exists($ability, 'can')) {
            throw new ForbiddenError();
        }

        $scope = is_array($config) ? ($config['scope'] ?? []) : [];
        // lodash castArray
        $scopes = is_array($scope) ? array_values($scope) : [$scope];

        foreach ($scopes as $s) {
            if ($ability->can((string) $s) !== true) {
                throw new ForbiddenError();
            }
        }
    }

    /**
     * The strategy for a route definition (`routes/transfer.ts` imports the module itself), built
     * before any instance exists: it reads the running instance when a request comes in, as
     * upstream's module reads the global `strapi`.
     *
     * @return array{name: string, authenticate: \Closure(Context): array<string, mixed>, verify: \Closure(array<string, mixed>, mixed=): void}
     */
    public static function routeStrategy(): array
    {
        $instance = static fn (): Strapi => Core::instance() ?? throw new \RuntimeException('Strapi is not initialized');

        return [
            'name' => self::NAME,
            'authenticate' => static fn (Context $ctx): array => self::authenticate($ctx, $instance()),
            'verify' => static fn (array $auth, mixed $config = []): null => self::verify($auth, $config),
        ];
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
