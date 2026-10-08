<?php

declare(strict_types=1);

namespace Strapi\Admin;

use Strapi\Admin\Services\Constants;
use Strapi\Admin\Services\Token;
use Strapi\Admin\Shared\Utils\SessionAuth;
use Strapi\Admin\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Database\Lifecycles\Event;
use Strapi\Utils\Primitives\Objects;

/** Port of server/src/bootstrap.ts. */
final class Bootstrap
{
    private const DEFAULT_ADMIN_AUTH_SETTINGS = [
        'providers' => [
            'autoRegister' => false,
            'defaultRole' => null,
            'ssoLockedRoles' => null,
        ],
    ];

    private function registerPermissionActions(Strapi $strapi): void
    {
        $adminActions = require __DIR__ . '/config/admin-actions.php';
        Utils::getService($strapi, 'permission')->actionProvider->registerMany($adminActions['actions']);
    }

    private function registerAdminConditions(Strapi $strapi): void
    {
        $adminConditions = require __DIR__ . '/config/admin-conditions.php';
        Utils::getService($strapi, 'permission')->conditionProvider->registerMany($adminConditions['conditions']);
    }

    private function registerModelHooks(Strapi $strapi): void
    {
        $metrics = Utils::getService($strapi, 'metrics');
        $sendDidChangeInterfaceLanguage = static function () use ($metrics): void {
            $metrics->sendDidChangeInterfaceLanguage();
        };

        $strapi->db()->lifecycles->subscribe([
            'models' => ['admin::user'],
            'afterCreate' => $sendDidChangeInterfaceLanguage,
            'afterDelete' => $sendDidChangeInterfaceLanguage,
            'beforeDelete' => static function (Event $event) use ($strapi): void {
                // Delete all admin API tokens owned by this user before the user row is removed
                Utils::getService($strapi, 'api-token-admin')->deleteTokensForUser($event->params['where']['id'] ?? null);
            },
            'afterUpdate' => static function (Event $event) use ($strapi, $sendDidChangeInterfaceLanguage): void {
                $data = $event->params['data'] ?? null;
                if (is_array($data) && !empty($data['preferedLanguage'])) {
                    $sendDidChangeInterfaceLanguage();
                }
                if (is_array($data) && array_key_exists('roles', $data)) {
                    // We re-sync token permissions for all owner users with their role when the user is updated
                    $result = $event->result;
                    Utils::getService($strapi, 'api-token-admin')->syncPermissionsForUser(is_array($result) ? ($result['id'] ?? null) : null);
                }
            },
        ]);

        $strapi->db()->lifecycles->subscribe([
            'models' => ['admin::role'],
            // We re-sync token permissions for all owner users with this role when the role is deleted
            'beforeDelete' => static function (Event $event) use ($strapi): void {
                $users = $strapi->db()->query('admin::user')->findMany([
                    'where' => ['roles' => ['id' => $event->params['where']['id'] ?? null]],
                    'select' => ['id'],
                ]);
                $event->state['affectedUserIds'] = array_map(static fn (array $u): mixed => $u['id'] ?? null, $users);
            },
            'afterDelete' => static function (Event $event) use ($strapi): void {
                $affected = $event->state['affectedUserIds'] ?? [];
                foreach (is_array($affected) ? $affected : [] as $userId) {
                    Utils::getService($strapi, 'api-token-admin')->syncPermissionsForUser($userId);
                }
            },
        ]);
    }

    private function syncAuthSettings(Strapi $strapi): void
    {
        $adminStore = $strapi->store()->scoped(['type' => 'core', 'name' => 'admin']);
        $adminAuthSettings = $adminStore->get(['key' => 'auth']);
        $newAuthSettings = Objects::merge(self::DEFAULT_ADMIN_AUTH_SETTINGS, is_array($adminAuthSettings) ? $adminAuthSettings : []);

        $roleExists = Utils::getService($strapi, 'role')->exists([
            'id' => $newAuthSettings['providers']['defaultRole'] ?? null,
        ]);

        // Reset the default SSO role if it has been deleted manually
        if (!$roleExists) {
            $newAuthSettings['providers']['defaultRole'] = null;
        }

        $adminStore->set(['key' => 'auth', 'value' => $newAuthSettings]);
    }

    private function syncAPITokensPermissions(Strapi $strapi): void
    {
        $validPermissions = $strapi->contentAPI()->permissions->providers['action']->keys();
        $permissionsInDB = array_map(
            static fn (array $permission): mixed => $permission['action'] ?? null,
            $strapi->db()->query('admin::api-token-permission')->findMany()
        );

        $unknownPermissions = array_values(array_unique(array_diff($permissionsInDB, $validPermissions)));

        if ($unknownPermissions !== []) {
            $strapi->db()
                ->query('admin::api-token-permission')
                ->deleteMany(['where' => ['action' => ['$in' => $unknownPermissions]]]);
        }
    }

    /**
     * Ensures the creation of default API tokens during the app creation: when there are no users
     * and no API tokens, creates the "Read Only" and "Full Access" tokens.
     */
    private function createDefaultAPITokensIfNeeded(Strapi $strapi): void
    {
        $userService = Utils::getService($strapi, 'user');
        $apiTokenService = Utils::getService($strapi, 'api-token-content-api');

        $usersCount = $userService->count();
        $apiTokenCount = $apiTokenService->countAll();

        if ($usersCount === 0 && $apiTokenCount === 0) {
            foreach (Constants::DEFAULT_API_TOKENS as $token) {
                $apiTokenService->create($token);
            }
        }
    }

    /** A lifespan from config (seconds; numeric strings accepted like JS arithmetic would). */
    private static function seconds(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public function __invoke(Strapi $strapi): void
    {
        // Get the merged token options (includes defaults merged with user config)
        ['options' => $options] = Utils::getService($strapi, 'token')->getTokenOptions();
        $legacyMaxRefreshFallback = Token::expiresInToSeconds($options['expiresIn'] ?? null) ?? SessionAuth::DEFAULT_MAX_REFRESH_TOKEN_LIFESPAN;
        $legacyMaxSessionFallback = Token::expiresInToSeconds($options['expiresIn'] ?? null) ?? SessionAuth::DEFAULT_MAX_SESSION_LIFESPAN;

        // Warn only when the user set legacy admin.auth.options.expiresIn. Merged JWT options always
        // include the default expiresIn ('30d'), so reading merged options alone is a false positive.
        $hasLegacyExpires = Token::hasUserConfiguredAuthOptionsExpiresIn($strapi->config()->get('admin.auth.options'));
        $hasNewMaxRefresh = $strapi->config()->get('admin.auth.sessions.maxRefreshTokenLifespan') !== null;
        $hasNewMaxSession = $strapi->config()->get('admin.auth.sessions.maxSessionLifespan') !== null;

        if ($hasLegacyExpires && (!$hasNewMaxRefresh || !$hasNewMaxSession)) {
            $strapi->log()->warning(
                'admin.auth.options.expiresIn is deprecated and will be removed in Strapi 6. Please configure admin.auth.sessions.maxRefreshTokenLifespan and admin.auth.sessions.maxSessionLifespan.'
            );
        }

        $config = $strapi->config();
        $jwtSecret = $config->get('admin.auth.secret');
        $algorithm = $options['algorithm'] ?? null;
        $strapi->sessionManager()->defineOrigin('admin', [
            ...(is_string($jwtSecret) ? ['jwtSecret' => $jwtSecret] : []),
            'accessTokenLifespan' => self::seconds($config->get('admin.auth.sessions.accessTokenLifespan', 30 * 60)),
            'maxRefreshTokenLifespan' => self::seconds($config->get('admin.auth.sessions.maxRefreshTokenLifespan', $legacyMaxRefreshFallback)),
            'idleRefreshTokenLifespan' => self::seconds($config->get('admin.auth.sessions.idleRefreshTokenLifespan', SessionAuth::DEFAULT_IDLE_REFRESH_TOKEN_LIFESPAN)),
            'maxSessionLifespan' => self::seconds($config->get('admin.auth.sessions.maxSessionLifespan', $legacyMaxSessionFallback)),
            'idleSessionLifespan' => self::seconds($config->get('admin.auth.sessions.idleSessionLifespan', SessionAuth::DEFAULT_IDLE_SESSION_LIFESPAN)),
            ...(is_string($algorithm) ? ['algorithm' => $algorithm] : []),
            // Pass through all JWT options (includes privateKey, publicKey, and any other options)
            'jwtOptions' => $options,
        ]);

        $isProduction = SessionAuth::isProduction();
        $adminCookieSecure = $config->get('admin.auth.cookie.secure');
        if ($isProduction && $adminCookieSecure === false) {
            $strapi->log()->warning(
                'Server is in production mode, but admin.auth.cookie.secure has been set to false. This is not recommended and will allow cookies to be sent over insecure connections.'
            );
        }

        $this->registerAdminConditions($strapi);
        $this->registerPermissionActions($strapi);
        $this->registerModelHooks($strapi);

        $permissionService = Utils::getService($strapi, 'permission');
        $userService = Utils::getService($strapi, 'user');
        $roleService = Utils::getService($strapi, 'role');
        $apiTokenService = Utils::getService($strapi, 'api-token-content-api');
        $transferService = Utils::getService($strapi, 'transfer');
        $tokenService = Utils::getService($strapi, 'token');

        $roleService->createRolesIfNoneExist();
        $roleService->resetSuperAdminPermissions();
        $roleService->displayWarningIfNoSuperAdmin();

        $permissionService->cleanPermissionsInDatabase();

        $userService->displayWarningIfUsersDontHaveRole();

        $this->syncAuthSettings($strapi);
        $this->syncAPITokensPermissions($strapi);

        Utils::getService($strapi, 'metrics')->sendUpdateProjectInformation($strapi);
        Utils::getService($strapi, 'metrics')->startCron($strapi);

        $apiTokenService->checkSaltIsDefined();
        $transferService->token->checkSaltIsDefined();
        $tokenService->checkSecretIsDefined();

        $this->createDefaultAPITokensIfNeeded($strapi);
    }
}
