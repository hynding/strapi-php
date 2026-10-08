<?php

declare(strict_types=1);

namespace Strapi\Admin\Controllers;

use Strapi\Admin\Shared\Utils\SessionAuth;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Sessions;

/** Port of server/src/controllers/authenticated-session.ts (`admin::authenticated-session`). */
final class AuthenticatedSession
{
    private const ORIGIN = 'admin';

    public function __construct(private readonly Strapi $strapi)
    {
    }

    private static function getCurrentSessionId(Context $ctx): ?string
    {
        $session = $ctx->state()->get('session');

        return is_array($session) && is_string($session['id'] ?? null) ? $session['id'] : null;
    }

    private static function userId(Context $ctx): string
    {
        $user = $ctx->state()->get('user');

        return (string) (is_array($user) ? ($user['id'] ?? '') : '');
    }

    public function list(Context $ctx): mixed
    {
        $sessionManager = SessionAuth::getSessionManager($this->strapi);
        if ($sessionManager === null) {
            $ctx->internalServerError();

            return null;
        }

        $userId = self::userId($ctx);
        $currentSessionId = self::getCurrentSessionId($ctx);
        $sessions = $sessionManager(self::ORIGIN)->listSessions($userId);

        $data = Sessions::sortSessionsForDisplay(
            array_map(static fn (array $session): array => Sessions::sanitizeSessionEntry($session, $currentSessionId), $sessions)
        );

        $ctx->setBody(['data' => $data]);

        return null;
    }

    public function revoke(Context $ctx): mixed
    {
        $sessionManager = SessionAuth::getSessionManager($this->strapi);
        if ($sessionManager === null) {
            $ctx->internalServerError();

            return null;
        }

        $userId = self::userId($ctx);
        $sessionId = (string) $ctx->param('sessionId');

        $revoked = $sessionManager(self::ORIGIN)->revokeSessionById($userId, $sessionId);
        if (!$revoked) {
            $ctx->notFound('Session not found');

            return null;
        }

        $ctx->setBody(['data' => new \stdClass()]);

        return null;
    }

    public function revokeAll(Context $ctx): mixed
    {
        $sessionManager = SessionAuth::getSessionManager($this->strapi);
        if ($sessionManager === null) {
            $ctx->internalServerError();

            return null;
        }

        $userId = self::userId($ctx);
        $keepCurrentParam = $ctx->query()['keepCurrent'] ?? null;
        $keepCurrent = $keepCurrentParam === 'true' || $keepCurrentParam === '1';

        if ($keepCurrent) {
            $currentSessionId = self::getCurrentSessionId($ctx);
            $sessions = $sessionManager(self::ORIGIN)->listSessions($userId);

            foreach ($sessions as $session) {
                if ($session['sessionId'] !== $currentSessionId) {
                    $sessionManager(self::ORIGIN)->revokeSessionById($userId, $session['sessionId']);
                }
            }
        } else {
            $sessionManager(self::ORIGIN)->invalidateRefreshToken($userId);
        }

        $ctx->setBody(['data' => new \stdClass()]);

        return null;
    }
}
