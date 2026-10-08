<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Utils;

use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Services;

/**
 * Port of server/src/utils/index.js: `getService`, `isUsernameTaken`, `findValidUsername`
 * (`sanitize` is {@see \Strapi\Plugin\UsersPermissions\Utils\Sanitize\Sanitizers}).
 *
 * Upstream reads the global `strapi`; the PHP functions take it as their first argument.
 */
final class Utils
{
    public const MAX_USERNAME_ATTEMPTS = 10;

    /**
     * `crypto.randomInt(min, max)` (max exclusive); replaceable in tests like upstream's jest.mock('crypto').
     *
     * @var (\Closure(int, int): int)|null
     */
    public static ?\Closure $randomInt = null;

    /** @var (\Closure(): string)|null `crypto.randomUUID()`; replaceable in tests */
    public static ?\Closure $randomUUID = null;

    /**
     * `strapi.plugin('users-permissions').service(name)`. The conditional return type is for static
     * analysis; at runtime any registered (or replaced) service object is returned.
     *
     * @return ($name is 'jwt' ? Services\Jwt : ($name is 'providers' ? Services\Providers : ($name is 'providers-registry' ? Services\ProvidersRegistry : ($name is 'role' ? Services\Role : ($name is 'user' ? Services\User : ($name is 'users-permissions' ? Services\UsersPermissions : ($name is 'permission' ? Services\Permission : object)))))))
     */
    public static function getService(Strapi $strapi, string $name): object
    {
        return $strapi->plugin('users-permissions')->service($name);
    }

    /**
     * `strapi.plugin('email').service('email').send(options)`: the email plugin is reached by
     * upstream names (its service may be replaced, e.g. by a test spy).
     *
     * @param array<string, mixed> $options
     */
    public static function sendEmail(Strapi $strapi, array $options): void
    {
        $send = [$strapi->plugin('email')->service('email'), 'send'];
        if (!is_callable($send)) {
            throw new \RuntimeException('The email service has no send method');
        }

        $send($options);
    }

    /** `strapi.service('admin::permission')` */
    public static function adminPermissionService(Strapi $strapi): \Strapi\Admin\Services\Permission
    {
        $service = $strapi->service('admin::permission');
        if (!$service instanceof \Strapi\Admin\Services\Permission) {
            throw new \RuntimeException('The admin::permission service is not available');
        }

        return $service;
    }

    /** `strapi.service('plugin::content-manager.document-manager')` */
    public static function documentManager(Strapi $strapi): \Strapi\ContentManager\Services\DocumentManager
    {
        $service = $strapi->service('plugin::content-manager.document-manager');
        if (!$service instanceof \Strapi\ContentManager\Services\DocumentManager) {
            throw new \RuntimeException('The content-manager document-manager service is not available');
        }

        return $service;
    }

    public static function isUsernameTaken(Strapi $strapi, string $username): bool
    {
        $user = $strapi->db()->query('plugin::users-permissions.user')->findOne(['where' => ['username' => $username]]);

        return $user !== null;
    }

    public static function findValidUsername(Strapi $strapi, string $basename): string
    {
        $attribute = $strapi->getModel('plugin::users-permissions.user')?->attribute('username');
        $minLength = is_array($attribute) && is_numeric($attribute['minLength'] ?? null) ? (int) $attribute['minLength'] : 3;
        $tryBasenameFirst = mb_strlen($basename) >= $minLength;

        $attempt = 0;
        do {
            $candidate = $attempt === 0 && $tryBasenameFirst ? $basename : $basename . self::randomInt(1000, 9999);
            $taken = self::isUsernameTaken($strapi, $candidate);
            $attempt += 1;
        } while ($taken && $attempt <= self::MAX_USERNAME_ATTEMPTS);

        return $taken ? self::randomUUID() : $candidate;
    }

    private static function randomInt(int $min, int $max): int
    {
        return self::$randomInt !== null ? (self::$randomInt)($min, $max) : random_int($min, $max - 1);
    }

    public static function randomUUID(): string
    {
        if (self::$randomUUID !== null) {
            return (self::$randomUUID)();
        }

        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
        $hex = bin2hex($bytes);

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20));
    }
}
