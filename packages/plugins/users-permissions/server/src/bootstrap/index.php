<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Bootstrap;

use Strapi\Core\Services\ScopedCoreStore;
use Strapi\Core\Services\SessionManager;
use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Services\Constants;
use Strapi\Plugin\UsersPermissions\Utils\Utils;

/**
 * Port of server/src/bootstrap/index.js.
 *
 * An asynchronous bootstrap function that runs before
 * your application gets started.
 *
 * This gives you an opportunity to set up your data model,
 * run jobs, or perform some special logic.
 */
final class Bootstrap
{
    private static function getSessionManager(Strapi $strapi): ?SessionManager
    {
        return $strapi->has('sessionManager') ? $strapi->sessionManager() : null;
    }

    /**
     * lodash `_.merge` (deep, arrays merged by index, `undefined` sources skipped).
     *
     * @param array<array-key, mixed> $target
     * @param array<array-key, mixed> $source
     * @return array<array-key, mixed>
     */
    private static function merge(array $target, array $source): array
    {
        foreach ($source as $key => $value) {
            if (is_array($value) && is_array($target[$key] ?? null)) {
                $target[$key] = self::merge($target[$key], $value);
            } elseif ($value !== null || !array_key_exists($key, $target)) {
                $target[$key] = $value;
            }
        }

        return $target;
    }

    private static function initGrant(Strapi $strapi, ScopedCoreStore $pluginStore): void
    {
        $allProviders = Utils::getService($strapi, 'providers-registry')->getAll();

        $grantConfig = [];
        foreach ($allProviders as $name => $provider) {
            $grantConfig[$name] = [
                'icon' => $provider['icon'] ?? null,
                'enabled' => $provider['enabled'] ?? null,
                ...(is_array($provider['grantConfig'] ?? null) ? $provider['grantConfig'] : []),
            ];
            // `undefined` keys are not stored
            $grantConfig[$name] = array_filter($grantConfig[$name], static fn (mixed $v): bool => $v !== null);
        }

        $prevGrantConfig = $pluginStore->get(['key' => 'grant']);
        $prevGrantConfig = is_array($prevGrantConfig) ? $prevGrantConfig : [];

        if ($prevGrantConfig === [] || $prevGrantConfig != $grantConfig) {
            // merge with the previous provider config.
            foreach (array_keys($grantConfig) as $key) {
                if (array_key_exists($key, $prevGrantConfig)) {
                    $grantConfig[$key] = self::merge($grantConfig[$key], is_array($prevGrantConfig[$key]) ? $prevGrantConfig[$key] : []);
                }
            }
            $pluginStore->set(['key' => 'grant', 'value' => $grantConfig]);
        }
    }

    private static function initEmails(ScopedCoreStore $pluginStore): void
    {
        if (!$pluginStore->get(['key' => 'email'])) {
            $value = [
                'reset_password' => [
                    'display' => 'Email.template.reset_password',
                    'icon' => 'sync',
                    'options' => [
                        'from' => [
                            'name' => 'Administration Panel',
                            'email' => 'no-reply@strapi.io',
                        ],
                        'response_email' => '',
                        'object' => 'Reset password',
                        'message' => <<<'HTML'
                            <p>We heard that you lost your password. Sorry about that!</p>

                            <p>But don’t worry! You can use the following link to reset your password:</p>
                            <p><%= URL %>?code=<%= TOKEN %></p>

                            <p>Thanks.</p>
                            HTML,
                    ],
                ],
                'email_confirmation' => [
                    'display' => 'Email.template.email_confirmation',
                    'icon' => 'check-square',
                    'options' => [
                        'from' => [
                            'name' => 'Administration Panel',
                            'email' => 'no-reply@strapi.io',
                        ],
                        'response_email' => '',
                        'object' => 'Account confirmation',
                        'message' => <<<'HTML'
                            <p>Thank you for registering!</p>

                            <p>You have to confirm your email address. Please click on the link below.</p>

                            <p><%= URL %>?confirmation=<%= CODE %></p>

                            <p>Thanks.</p>
                            HTML,
                    ],
                ],
            ];

            $pluginStore->set(['key' => 'email', 'value' => $value]);
        }
    }

    private static function initAdvancedOptions(ScopedCoreStore $pluginStore): void
    {
        if (!$pluginStore->get(['key' => 'advanced'])) {
            $value = [
                'unique_email' => true,
                'allow_register' => true,
                'email_confirmation' => false,
                'email_reset_password' => null,
                'email_confirmation_redirection' => null,
                'default_role' => 'authenticated',
            ];

            $pluginStore->set(['key' => 'advanced', 'value' => $value]);
        }
    }

    /** `a || b` */
    private static function or(mixed $value, mixed $fallback): mixed
    {
        return $value !== null && $value !== false && $value !== '' && $value !== 0 ? $value : $fallback;
    }

    public function __invoke(Strapi $strapi): void
    {
        $pluginStore = $strapi->store()(['type' => 'plugin', 'name' => 'users-permissions']);

        self::initGrant($strapi, $pluginStore);
        self::initEmails($pluginStore);
        self::initAdvancedOptions($pluginStore);

        Utils::adminPermissionService($strapi)->actionProvider->registerMany(UsersPermissionsActions::ACTIONS);

        Utils::getService($strapi, 'users-permissions')->initialize();

        // Define users-permissions origin configuration for sessionManager
        $upConfig = $strapi->config()->get('plugin::users-permissions');
        $upConfig = is_array($upConfig) ? $upConfig : [];
        $sessions = is_array($upConfig['sessions'] ?? null) ? $upConfig['sessions'] : [];
        $jwt = is_array($upConfig['jwt'] ?? null) ? $upConfig['jwt'] : [];
        $sessionManager = self::getSessionManager($strapi);

        if ($sessionManager !== null) {
            $sessionManager->defineOrigin('users-permissions', [
                'jwtSecret' => self::or($upConfig['jwtSecret'] ?? null, $strapi->config()->get('admin.auth.secret')),
                'accessTokenLifespan' => self::or($sessions['accessTokenLifespan'] ?? null, Constants::DEFAULT_ACCESS_TOKEN_LIFESPAN),
                'maxRefreshTokenLifespan' => self::or($sessions['maxRefreshTokenLifespan'] ?? null, Constants::DEFAULT_MAX_REFRESH_TOKEN_LIFESPAN),
                'idleRefreshTokenLifespan' => self::or($sessions['idleRefreshTokenLifespan'] ?? null, Constants::DEFAULT_IDLE_REFRESH_TOKEN_LIFESPAN),
                'maxSessionLifespan' => self::or($sessions['maxSessionLifespan'] ?? null, Constants::DEFAULT_MAX_SESSION_LIFESPAN),
                'idleSessionLifespan' => self::or($sessions['idleSessionLifespan'] ?? null, Constants::DEFAULT_IDLE_SESSION_LIFESPAN),
                'algorithm' => $jwt['algorithm'] ?? null,
                'jwtOptions' => $jwt,
            ]);
        }

        if (!$strapi->config()->get('plugin::users-permissions.jwtSecret')) {
            if (getenv('NODE_ENV') !== 'development') {
                throw new \RuntimeException(
                    "Missing jwtSecret. Please, set configuration variable \"jwtSecret\" for the users-permissions plugin in config/plugins.js (ex: you can generate one using Node with `crypto.randomBytes(16).toString('base64')`).\n"
                    . 'For security reasons, prefer storing the secret in an environment variable and read it in config/plugins.js. See https://docs.strapi.io/developer-docs/latest/setup-deployment-guides/configurations/optional/environment.html#configuration-using-environment-variables.'
                );
            }

            $jwtSecret = base64_encode(random_bytes(16));

            $strapi->config()->set('plugin::users-permissions.jwtSecret', $jwtSecret);

            if (getenv('JWT_SECRET') === false || getenv('JWT_SECRET') === '') {
                $envPath = getenv('ENV_PATH');
                $envPath = is_string($envPath) && $envPath !== '' ? $envPath : '.env';
                $strapi->fs()->appendFile($envPath, "JWT_SECRET={$jwtSecret}\n");
                $strapi->log()->info(
                    "The Users & Permissions plugin automatically generated a jwt secret and stored it in {$envPath} under the name JWT_SECRET."
                );
            }
        }
    }
}
