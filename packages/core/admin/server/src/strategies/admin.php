<?php

declare(strict_types=1);

namespace Strapi\Admin\Strategies;

use Strapi\Admin\Utils\Utils;
use Strapi\Core\Services\SessionManager;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;

/**
 * Port of server/src/strategies/admin.ts: the admin session strategy. A `Bearer` access token
 * issued by the session manager (origin `admin`), backed by an active session, for an active user.
 *
 * Upstream's module exports `{ name, authenticate }` and reads the global `strapi`;
 * {@see self::strategy()} builds that strategy array for `strapi.get('auth').register('admin', ...)`.
 */
final class Admin
{
    public const NAME = 'admin';

    private static function getSessionManager(Strapi $strapi): ?SessionManager
    {
        return $strapi->has('sessionManager') ? $strapi->sessionManager() : null;
    }

    /** @return array{authenticated: bool, credentials?: array<string, mixed>, ability?: mixed} */
    public static function authenticate(Context $ctx, Strapi $strapi): array
    {
        $authorization = $ctx->header('Authorization');

        if ($authorization === null || $authorization === '') {
            return ['authenticated' => false];
        }

        $parts = preg_split('/\s+/', $authorization) ?: [];

        if (strtolower($parts[0] ?? '') !== 'bearer' || count($parts) !== 2) {
            return ['authenticated' => false];
        }

        $token = $parts[1];

        // Validate access tokens via session manager and require an active session
        $manager = self::getSessionManager($strapi);
        if ($manager === null) {
            return ['authenticated' => false];
        }

        $result = $manager('admin')->validateAccessToken($token);
        if (!$result['isValid'] || !is_array($result['payload'])) {
            return ['authenticated' => false];
        }

        $sessionId = (string) ($result['payload']['sessionId'] ?? '');
        $isActive = $manager('admin')->isSessionActive($sessionId);
        if (!$isActive) {
            return ['authenticated' => false];
        }

        $rawUserId = $result['payload']['userId'] ?? null;
        $userId = is_string($rawUserId) && preg_match('/^-?(0|[1-9]\d*)$/', $rawUserId) === 1 && (string) (int) $rawUserId === $rawUserId
            ? (int) $rawUserId
            : $rawUserId;

        $user = $strapi->db()->query('admin::user')->findOne(['where' => ['id' => $userId], 'populate' => ['roles']]);

        if ($user === null || ($user['isActive'] ?? null) !== true) {
            return ['authenticated' => false];
        }

        $userAbility = Utils::getService($strapi, 'permission')->engine->generateUserAbility($user);

        // TODO: use the ability from ctx.state.auth instead of
        // ctx.state.userAbility, and remove the assign below
        $ctx->state()->set('userAbility', $userAbility);
        $ctx->state()->set('user', $user);
        // Expose the session backing this request so endpoints can flag the "current" session.
        $ctx->state()->set('session', ['id' => $sessionId]);

        return [
            'authenticated' => true,
            'credentials' => $user,
            'ability' => $userAbility,
        ];
    }

    /** @return array{name: string, authenticate: \Closure(Context): array<string, mixed>} */
    public static function strategy(Strapi $strapi): array
    {
        return [
            'name' => self::NAME,
            'authenticate' => static fn (Context $ctx): array => self::authenticate($ctx, $strapi),
        ];
    }
}
