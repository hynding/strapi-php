<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Strategies;

use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Utils\Utils;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ForbiddenError;
use Strapi\Utils\Errors\UnauthorizedError;

/**
 * Port of server/src/strategies/users-permissions.js: `{ name, authenticate, verify }`.
 * Upstream's module reads the global `strapi`; {@see self::strategy()} builds the strategy array
 * for `strapi.get('auth').register('content-api', ...)`.
 */
final class UsersPermissions
{
    public const NAME = 'users-permissions';

    /** @return array<string, mixed> */
    private static function getAdvancedSettings(Strapi $strapi): array
    {
        $settings = $strapi->store()(['type' => 'plugin', 'name' => 'users-permissions'])->get(['key' => 'advanced']);

        return is_array($settings) ? $settings : [];
    }

    /** @return array{authenticated?: bool, credentials?: mixed, ability?: mixed, error?: string} */
    public static function authenticate(Context $ctx, Strapi $strapi): array
    {
        try {
            $token = Utils::getService($strapi, 'jwt')->getToken($ctx);

            if ($token !== null) {
                $id = $token['id'] ?? null;
                $sessionId = $token['sessionId'] ?? null;

                // Invalid token
                if (!array_key_exists('id', $token)) {
                    return ['authenticated' => false];
                }

                $user = Utils::getService($strapi, 'user')->fetchAuthenticatedUser($id);

                // No user associated to the token
                if ($user === null) {
                    return ['error' => 'Invalid credentials'];
                }

                $advancedSettings = self::getAdvancedSettings($strapi);

                // User not confirmed
                if (($advancedSettings['email_confirmation'] ?? false) && !($user['confirmed'] ?? false)) {
                    return ['error' => 'Invalid credentials'];
                }

                // User blocked
                if ($user['blocked'] ?? false) {
                    return ['error' => 'Invalid credentials'];
                }

                // Fetch user's permissions
                $roleId = is_array($user['role'] ?? null) ? ($user['role']['id'] ?? null) : null;
                if ($roleId === null) {
                    // `user.role.id` on a user without role throws upstream (caught below)
                    throw new \TypeError("Cannot read properties of null (reading 'id')");
                }
                $permissionService = Utils::getService($strapi, 'permission');
                $permissions = array_values(array_map(
                    $permissionService->toContentAPIPermission(...),
                    $permissionService->findRolePermissions($roleId),
                ));

                // Generate an ability (content API engine) based on the given permissions
                $ability = $strapi->contentAPI()->permissions->engine->generateAbility($permissions);

                $ctx->state()->set('user', $user);
                // Expose the session backing this request (refresh mode only) so endpoints
                // can flag the "current" session when listing active sessions.
                if ($sessionId !== null && $sessionId !== '') {
                    $ctx->state()->set('session', ['id' => $sessionId]);
                }

                return [
                    'authenticated' => true,
                    'credentials' => $user,
                    'ability' => $ability,
                ];
            }

            $permissionService = Utils::getService($strapi, 'permission');
            $publicPermissions = array_values(array_map(
                $permissionService->toContentAPIPermission(...),
                $permissionService->findPublicPermissions(),
            ));

            if ($publicPermissions === []) {
                return ['authenticated' => false];
            }

            $ability = $strapi->contentAPI()->permissions->engine->generateAbility($publicPermissions);

            return [
                'authenticated' => true,
                'credentials' => null,
                'ability' => $ability,
            ];
        } catch (\Throwable) {
            return ['authenticated' => false];
        }
    }

    /**
     * @param array<string, mixed> $auth
     * @param mixed $config the route `config.auth`
     */
    public static function verify(array $auth, mixed $config): void
    {
        $user = $auth['credentials'] ?? null;
        $ability = $auth['ability'] ?? null;
        $scope = is_array($config) ? ($config['scope'] ?? null) : null;

        if ($scope === null || $scope === '' || $scope === false || $scope === 0) {
            if ($user === null || $user === false) {
                // A non authenticated user cannot access routes that do not have a scope
                throw new UnauthorizedError();
            }

            // An authenticated user can access non scoped routes
            return;
        }

        // If no ability have been generated, then consider auth is missing
        if ($ability === null) {
            throw new UnauthorizedError();
        }

        $scopes = is_array($scope) ? $scope : [$scope];
        foreach ($scopes as $s) {
            if (!$ability->can($s)) {
                throw new ForbiddenError();
            }
        }
    }

    /** @return array{name: string, authenticate: \Closure(Context): array<string, mixed>, verify: \Closure(array<string, mixed>, mixed): void} */
    public static function strategy(Strapi $strapi): array
    {
        return [
            'name' => self::NAME,
            'authenticate' => static fn (Context $ctx): array => self::authenticate($ctx, $strapi),
            'verify' => static function (array $auth, mixed $config): void {
                self::verify($auth, $config);
            },
        ];
    }
}
