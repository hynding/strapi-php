<?php

declare(strict_types=1);

namespace Strapi\Admin\Controllers;

use Strapi\Admin\Shared\Utils\SessionAuth;
use Strapi\Admin\Utils\NormalizeEmail;
use Strapi\Admin\Utils\Utils;
use Strapi\Admin\Validation\User as UserValidation;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

/** Port of server/src/controllers/authenticated-user.ts (`admin::authenticated-user`). */
final class AuthenticatedUser
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return array<string, mixed> */
    private static function stateUser(Context $ctx): array
    {
        $user = $ctx->state()->get('user');

        return is_array($user) ? $user : [];
    }

    public function getMe(Context $ctx): mixed
    {
        $userInfo = Utils::getService($this->strapi, 'user')->sanitizeUser(self::stateUser($ctx));

        $ctx->setBody(['data' => $userInfo]);

        return null;
    }

    public function updateMe(Context $ctx): mixed
    {
        $data = NormalizeEmail::normalizeEmail($ctx->requestBody());

        UserValidation::validateProfileUpdateInput($data);

        $data = is_array($data) ? $data : [];
        $userService = Utils::getService($this->strapi, 'user');
        $authServer = Utils::getService($this->strapi, 'auth');
        $user = self::stateUser($ctx);

        $currentPassword = $data['currentPassword'] ?? null;
        $userInfo = $data;
        unset($userInfo['currentPassword']);

        $hasCurrentPassword = is_string($currentPassword) && $currentPassword !== '';
        $hasNewPassword = is_string($userInfo['password'] ?? null) && $userInfo['password'] !== '';
        $isChangingPassword = $hasCurrentPassword && $hasNewPassword;

        if ($hasCurrentPassword && $hasNewPassword) {
            $isValid = $authServer->validatePassword($currentPassword, (string) ($user['password'] ?? ''));

            if (!$isValid) {
                $ctx->badRequest('ValidationError', [
                    'currentPassword' => ['Invalid credentials'],
                ]);

                return null;
            }
        }

        if (array_key_exists('email', $userInfo)) {
            $emailAlreadyTaken = $userService->exists([
                'id' => ['$ne' => $user['id'] ?? null],
                'email' => $userInfo['email'],
            ]);

            if ($emailAlreadyTaken === true) {
                $ctx->badRequest('ValidationError', [
                    'email' => ['Email already taken'],
                ]);

                return null;
            }
        }

        // Invalidate all sessions when password changes for security. This must run only once the
        // update is going to be persisted, so a rejected request (e.g. duplicate email) does not log
        // the user out without applying any change.
        if ($isChangingPassword) {
            $sessionManager = SessionAuth::getSessionManager($this->strapi);
            if ($sessionManager !== null && $sessionManager->hasOrigin('admin')) {
                $sessionManager('admin')->invalidateRefreshToken((string) ($user['id'] ?? ''));
            }
        }

        $updatedUser = $userService->updateById($user['id'] ?? null, $userInfo);

        $ctx->setBody([
            'data' => $userService->sanitizeUser(is_array($updatedUser) ? $updatedUser : []),
        ]);

        return null;
    }

    public function getOwnPermissions(Context $ctx): mixed
    {
        $permissionService = Utils::getService($this->strapi, 'permission');
        $user = self::stateUser($ctx);

        $userPermissions = $permissionService->findUserPermissions($user);

        $ctx->setBody([
            'data' => array_map(static fn (mixed $permission): mixed => $permissionService->sanitizePermission($permission), array_values($userPermissions)),
        ]);

        return null;
    }
}
