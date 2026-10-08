<?php

declare(strict_types=1);

namespace Strapi\Admin\Controllers;

use Strapi\Admin\Shared\Utils\SessionAuth;
use Strapi\Admin\Utils\Utils;
use Strapi\Admin\Validation\Authentication\ForgotPassword;
use Strapi\Admin\Validation\Authentication\Login;
use Strapi\Admin\Validation\Authentication\Register;
use Strapi\Admin\Validation\Authentication\ResetPassword;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Errors\ValidationError;

/** Port of server/src/controllers/authentication.ts (`admin::authentication`). */
final class Authentication
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * `compose([validate, passport.authenticate('local'), issue session])`.
     */
    public function login(Context $ctx, ?callable $next = null): mixed
    {
        $body = $ctx->requestBody();
        Login::validateLoginSessionInput($body ?? []);

        [$err, $user] = $result = Utils::getService($this->strapi, 'passport')->authenticate('local', $ctx);
        $info = $result[2] ?? null;

        if ($err !== null) {
            $this->strapi->eventHub()->emit('admin.auth.error', ['error' => $err, 'provider' => 'local']);
            // if this is a recognized error, allow it to bubble up to user
            if ($err instanceof ApplicationError && ($err->details['code'] ?? null) === 'LOGIN_NOT_ALLOWED') {
                throw $err;
            }

            // for all other errors throw a generic error to prevent leaking info
            $ctx->notImplemented();

            return null;
        }

        if (!is_array($user)) {
            $message = is_array($info) && is_string($info['message'] ?? null) ? $info['message'] : '';
            $this->strapi->eventHub()->emit('admin.auth.error', [
                'error' => new \RuntimeException($message),
                'provider' => 'local',
            ]);

            throw new ApplicationError($message);
        }

        $ctx->state()->set('user', $user);

        $sanitizedUser = Utils::getService($this->strapi, 'user')->sanitizeUser($user);
        $this->strapi->eventHub()->emit('admin.auth.success', ['user' => $sanitizedUser, 'provider' => 'local']);

        try {
            $sessionManager = SessionAuth::getSessionManager($this->strapi);
            if ($sessionManager === null) {
                $ctx->internalServerError();

                return null;
            }
            $userId = (string) $user['id'];
            ['deviceId' => $deviceId, 'rememberMe' => $rememberMe] = SessionAuth::extractDeviceParams($body);

            ['token' => $refreshToken, 'absoluteExpiresAt' => $absoluteExpiresAt] = $sessionManager('admin')->generateRefreshToken($userId, $deviceId, [
                'type' => $rememberMe ? 'refresh' : 'session',
                'metadata' => SessionAuth::buildSessionMetadataFromContext($ctx),
            ]);

            $cookieOptions = SessionAuth::buildCookieOptionsWithExpiry(
                $this->strapi,
                $rememberMe ? 'refresh' : 'session',
                $absoluteExpiresAt,
                $ctx->secure()
            );
            $ctx->cookies()->set(SessionAuth::REFRESH_COOKIE_NAME, $refreshToken, $cookieOptions);

            $accessResult = $sessionManager('admin')->generateAccessToken($refreshToken);
            if (isset($accessResult['error'])) {
                $ctx->internalServerError();

                return null;
            }

            $accessToken = $accessResult['token'];

            $ctx->setBody([
                'data' => [
                    'token' => $accessToken,
                    'accessToken' => $accessToken,
                    'user' => Utils::getService($this->strapi, 'user')->sanitizeUser($ctx->state()->get('user')),
                ],
            ]);
        } catch (\Throwable $error) {
            $this->strapi->log()->error('Failed to create admin refresh session', ['error' => $error]);
            $ctx->internalServerError();
        }

        return null;
    }

    public function registrationInfo(Context $ctx): mixed
    {
        $query = $ctx->query();
        Register::validateRegistrationInfoQuery($query);

        $registrationInfo = Utils::getService($this->strapi, 'user')->findRegistrationInfo((string) $query['registrationToken']);

        if ($registrationInfo === null) {
            throw new ValidationError('Invalid registrationToken');
        }

        $ctx->setBody(['data' => $registrationInfo]);

        return null;
    }

    /**
     * Issues a refresh session + access token for `$user` and writes the login response body
     * (shared by register and registerAdmin, whose upstream bodies are identical).
     *
     * @param array<string, mixed>|null $user
     */
    private function respondWithNewSession(Context $ctx, ?array $user, string $logMessage): void
    {
        try {
            if ($user === null) {
                throw new \RuntimeException('No user to open a session for');
            }
            $sessionManager = SessionAuth::getSessionManager($this->strapi);
            if ($sessionManager === null) {
                $ctx->internalServerError();

                return;
            }
            $userId = (string) $user['id'];
            ['deviceId' => $deviceId, 'rememberMe' => $rememberMe] = SessionAuth::extractDeviceParams($ctx->requestBody());

            ['token' => $refreshToken, 'absoluteExpiresAt' => $absoluteExpiresAt] = $sessionManager('admin')->generateRefreshToken($userId, $deviceId, [
                'type' => $rememberMe ? 'refresh' : 'session',
                'metadata' => SessionAuth::buildSessionMetadataFromContext($ctx),
            ]);

            $cookieOptions = SessionAuth::buildCookieOptionsWithExpiry(
                $this->strapi,
                $rememberMe ? 'refresh' : 'session',
                $absoluteExpiresAt,
                $ctx->secure()
            );
            $ctx->cookies()->set(SessionAuth::REFRESH_COOKIE_NAME, $refreshToken, $cookieOptions);

            $accessResult = $sessionManager('admin')->generateAccessToken($refreshToken);
            if (isset($accessResult['error'])) {
                $ctx->internalServerError();

                return;
            }

            $accessToken = $accessResult['token'];

            $ctx->setBody([
                'data' => [
                    'token' => $accessToken,
                    'accessToken' => $accessToken,
                    'user' => Utils::getService($this->strapi, 'user')->sanitizeUser($user),
                ],
            ]);
        } catch (\Throwable $error) {
            $this->strapi->log()->error($logMessage, ['error' => $error]);
            $ctx->internalServerError();
        }
    }

    public function register(Context $ctx): mixed
    {
        $input = $ctx->requestBody();

        Register::validateRegistrationInput($input);

        $user = Utils::getService($this->strapi, 'user')->register(is_array($input) ? $input : []);

        $this->respondWithNewSession($ctx, $user, 'Failed to create admin refresh session during register');

        return null;
    }

    public function registerAdmin(Context $ctx): mixed
    {
        $input = $ctx->requestBody();

        Register::validateAdminRegistrationInput($input);

        $user = Utils::getService($this->strapi, 'user')->createFirstAdmin(is_array($input) ? $input : []);

        $this->strapi->telemetry()->send('didCreateFirstAdmin');

        $this->respondWithNewSession($ctx, $user, 'Failed to create admin refresh session during register-admin');

        return null;
    }

    public function forgotPassword(Context $ctx): mixed
    {
        $input = $ctx->requestBody();

        ForgotPassword::validateForgotPasswordInput($input);

        // Not awaited upstream: the response must not reveal whether the email exists. Only a
        // rejection of the returned promise is caught there (the async service turns every
        // throw into one), so here any error thrown by the service is logged.
        $authService = Utils::getService($this->strapi, 'auth');
        try {
            $authService->forgotPassword(is_array($input) ? $input : []);
        } catch (\Throwable $error) {
            $this->strapi->log()->error('Failed to process the forgot-password request', ['error' => $error]);
        }

        $ctx->setStatus(204);

        return null;
    }

    public function resetPassword(Context $ctx): mixed
    {
        $input = $ctx->requestBody();

        ResetPassword::validateResetPasswordInput($input);

        $user = Utils::getService($this->strapi, 'auth')->resetPassword(is_array($input) ? $input : []);

        // Issue a new admin refresh session and access token after password reset.
        try {
            if ($user === null) {
                throw new \RuntimeException('No user to open a session for');
            }
            $sessionManager = SessionAuth::getSessionManager($this->strapi);
            if ($sessionManager === null) {
                $ctx->internalServerError();

                return null;
            }

            $userId = (string) $user['id'];
            $deviceId = SessionAuth::generateDeviceId();

            // Invalidate all existing sessions before creating a new one
            $sessionManager('admin')->invalidateRefreshToken($userId);

            ['token' => $refreshToken, 'absoluteExpiresAt' => $absoluteExpiresAt] = $sessionManager('admin')->generateRefreshToken($userId, $deviceId, [
                'type' => 'session',
                'metadata' => SessionAuth::buildSessionMetadataFromContext($ctx),
            ]);

            // No rememberMe flow here; expire with session by default (session cookie)
            $cookieOptions = SessionAuth::buildCookieOptionsWithExpiry($this->strapi, 'session', $absoluteExpiresAt, $ctx->secure());
            $ctx->cookies()->set(SessionAuth::REFRESH_COOKIE_NAME, $refreshToken, $cookieOptions);

            $accessResult = $sessionManager('admin')->generateAccessToken($refreshToken);
            if (isset($accessResult['error'])) {
                $ctx->internalServerError();

                return null;
            }

            $ctx->setBody([
                'data' => [
                    'token' => $accessResult['token'],
                    'user' => Utils::getService($this->strapi, 'user')->sanitizeUser($user),
                ],
            ]);
        } catch (\Throwable $err) {
            $this->strapi->log()->error('Failed to create admin refresh session during reset-password', ['error' => $err]);
            $ctx->internalServerError();
        }

        return null;
    }

    public function accessToken(Context $ctx): mixed
    {
        $refreshToken = $ctx->cookies()->get(SessionAuth::REFRESH_COOKIE_NAME);

        if ($refreshToken === null || $refreshToken === '') {
            $ctx->unauthorized('Missing refresh token');

            return null;
        }

        try {
            $sessionManager = SessionAuth::getSessionManager($this->strapi);
            if ($sessionManager === null) {
                $ctx->internalServerError();

                return null;
            }

            // Single-use renewal: rotate on access exchange, then create access token
            // from the new refresh token
            $rotation = $sessionManager('admin')->rotateRefreshToken($refreshToken);
            if (isset($rotation['error'])) {
                $ctx->unauthorized('Invalid refresh token');

                return null;
            }

            $result = $sessionManager('admin')->generateAccessToken($rotation['token']);
            if (isset($result['error'])) {
                $ctx->unauthorized('Invalid refresh token');

                return null;
            }

            // Preserve session-vs-remember mode using rotation.type and rotation.absoluteExpiresAt
            $opts = SessionAuth::buildCookieOptionsWithExpiry($this->strapi, $rotation['type'], $rotation['absoluteExpiresAt'], $ctx->secure());

            $ctx->cookies()->set(SessionAuth::REFRESH_COOKIE_NAME, $rotation['token'], $opts);
            $ctx->setBody(['data' => ['token' => $result['token']]]);
        } catch (\Throwable $err) {
            $this->strapi->log()->error('Failed to generate access token from refresh token', ['error' => $err]);
            $ctx->internalServerError();
        }

        return null;
    }

    public function logout(Context $ctx): mixed
    {
        $user = $ctx->state()->get('user');
        $sanitizedUser = Utils::getService($this->strapi, 'user')->sanitizeUser(is_array($user) ? $user : []);
        $this->strapi->eventHub()->emit('admin.logout', ['user' => $sanitizedUser]);

        // Clear cookie regardless of token validity
        $ctx->cookies()->set(SessionAuth::REFRESH_COOKIE_NAME, '', [
            ...SessionAuth::getRefreshCookieOptions($this->strapi, $ctx->secure()),
            'expires' => new \DateTimeImmutable('@0'),
        ]);

        try {
            $sessionManager = SessionAuth::getSessionManager($this->strapi);
            if ($sessionManager !== null) {
                $userId = (string) (is_array($user) ? ($user['id'] ?? '') : '');
                $body = $ctx->requestBody();
                $bodyDeviceId = is_array($body) ? ($body['deviceId'] ?? null) : null;
                $session = $ctx->state()->get('session');
                $sessionId = is_array($session) && is_string($session['id'] ?? null) ? $session['id'] : null;

                if (is_string($bodyDeviceId) && $bodyDeviceId !== '') {
                    $deviceId = SessionAuth::resolveLogoutDeviceId($this->strapi, $userId, $sessionId, $bodyDeviceId);
                    $sessionManager('admin')->invalidateRefreshToken($userId, $deviceId);
                } else {
                    $sessionManager('admin')->invalidateRefreshToken($userId);
                }
            }
        } catch (\Throwable $err) {
            $this->strapi->log()->error('Failed to revoke admin sessions during logout', ['error' => $err]);
        }

        $ctx->setBody(['data' => new \stdClass()]);

        return null;
    }
}
