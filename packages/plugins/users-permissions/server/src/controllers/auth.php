<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Controllers;

use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Controllers\Validation\Auth as AuthValidation;
use Strapi\Plugin\UsersPermissions\Utils\OauthConnect\OauthConnect;
use Strapi\Plugin\UsersPermissions\Utils\RefreshCookieOptions;
use Strapi\Plugin\UsersPermissions\Utils\Utils;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Errors\ForbiddenError;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Sessions;

/**
 * Port of server/src/controllers/auth.js.
 *
 * @description: A set of functions called "actions" for managing `Auth`.
 */
final class Auth
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @param array<string, mixed> $user */
    private function sanitizeUser(array $user, Context $ctx): mixed
    {
        $auth = $ctx->state()->get('auth');
        $userSchema = $this->strapi->getModel('plugin::users-permissions.user');

        return $this->strapi->contentAPI()->sanitize()->output($user, $userSchema, ['auth' => $auth]);
    }

    private static function extractDeviceId(mixed $requestBody): ?string
    {
        $deviceId = is_array($requestBody) ? ($requestBody['deviceId'] ?? null) : null;

        return is_string($deviceId) && $deviceId !== '' ? $deviceId : null;
    }

    /** @return array<string, mixed> */
    private static function buildSessionMetadataFromContext(Context $ctx): array
    {
        return Sessions::buildSessionMetadata([
            'userAgent' => $ctx->header('user-agent'),
        ]);
    }

    private static function isProduction(): bool
    {
        return getenv('NODE_ENV') === 'production';
    }

    /** @return array<string, mixed> */
    private function upSessions(): array
    {
        $upSessions = $this->strapi->config()->get('plugin::users-permissions.sessions');

        return is_array($upSessions) ? $upSessions : [];
    }

    /** @param array<string, mixed> $upSessions */
    private static function cookieName(array $upSessions): string
    {
        $name = is_array($upSessions['cookie'] ?? null) ? ($upSessions['cookie']['name'] ?? null) : null;

        return is_string($name) && $name !== '' ? $name : 'strapi_up_refresh';
    }

    private function jwtManagement(): mixed
    {
        return $this->strapi->config()->get('plugin::users-permissions.jwtManagement', 'legacy-support');
    }

    /**
     * @param array<string, mixed> $user
     * @param array{metadata?: array<string, mixed>} $options
     */
    private function sendRefreshAuthResponse(Context $ctx, array $user, array $options = []): void
    {
        $deviceId = self::extractDeviceId($ctx->requestBody());
        $tokenOptions = ['type' => 'refresh', ...(isset($options['metadata']) ? ['metadata' => $options['metadata']] : [])];

        $refresh = $this->strapi->sessionManager()('users-permissions')->generateRefreshToken((string) $user['id'], $deviceId, $tokenOptions);

        $access = $this->strapi->sessionManager()('users-permissions')->generateAccessToken($refresh['token']);
        if (array_key_exists('error', $access)) {
            throw new ApplicationError('Invalid credentials');
        }

        $upSessions = $this->upSessions();
        $requestHttpOnly = $ctx->header('x-strapi-refresh-cookie') === 'httpOnly';

        if (($upSessions['httpOnly'] ?? false) || $requestHttpOnly) {
            $cookieName = self::cookieName($upSessions);
            $ctx->cookies()->set($cookieName, $refresh['token'], RefreshCookieOptions::buildRefreshCookieOptions($upSessions, self::isProduction()));
            $ctx->send(['jwt' => $access['token'], 'user' => $this->sanitizeUser($user, $ctx)]);

            return;
        }

        $ctx->send([
            'jwt' => $access['token'],
            'refreshToken' => $refresh['token'],
            'user' => $this->sanitizeUser($user, $ctx),
        ]);
    }

    /** @param array<string, mixed> $user */
    private function reissueTokensAfterPasswordChange(Context $ctx, array $user): void
    {
        $deviceId = self::extractDeviceId($ctx->requestBody());

        $this->strapi->sessionManager()('users-permissions')->invalidateRefreshToken((string) $user['id']);

        $newDeviceId = $deviceId ?? Utils::randomUUID();
        $refresh = $this->strapi->sessionManager()('users-permissions')->generateRefreshToken((string) $user['id'], $newDeviceId, ['type' => 'refresh']);

        $access = $this->strapi->sessionManager()('users-permissions')->generateAccessToken($refresh['token']);
        if (array_key_exists('error', $access)) {
            throw new ApplicationError('Invalid credentials');
        }

        $ctx->send([
            'jwt' => $access['token'],
            'refreshToken' => $refresh['token'],
            'user' => $this->sanitizeUser($user, $ctx),
        ]);
    }

    /** @param array<string, mixed> $body */
    private function revokeLogoutSessions(Context $ctx, string $userId, ?string $scope, ?string $deviceId, array $body): void
    {
        $sessionManager = $this->strapi->sessionManager()('users-permissions');
        $upSessions = $this->upSessions();

        if ($scope === 'all') {
            $sessionManager->invalidateRefreshToken($userId);

            return;
        }

        if ($deviceId !== null) {
            $sessionManager->invalidateRefreshToken($userId, $deviceId);

            return;
        }

        $session = $ctx->state()->get('session');
        $currentSessionId = is_array($session) && isset($session['id']) ? (string) $session['id'] : null;
        $cookieName = self::cookieName($upSessions);
        $cookieToken = $ctx->cookies()->get($cookieName);
        $refreshToken = $cookieToken !== null && $cookieToken !== ''
            ? $cookieToken
            : (is_string($body['refreshToken'] ?? null) ? $body['refreshToken'] : null);

        if ($refreshToken !== null && $refreshToken !== '') {
            $validation = $sessionManager->validateRefreshToken($refreshToken);
            if ($validation['isValid'] ?? false) {
                $currentSessionId = isset($validation['sessionId']) ? (string) $validation['sessionId'] : $currentSessionId;
            }
        }

        if ($currentSessionId !== null && $currentSessionId !== '') {
            $sessionManager->revokeSessionById($userId, $currentSessionId);

            return;
        }

        $sessionManager->invalidateRefreshToken($userId);
    }

    public function callback(Context $ctx): mixed
    {
        $provider = $ctx->param('provider') ?? 'local';
        if ($provider === '') {
            $provider = 'local';
        }
        $params = $ctx->requestBody();

        $store = $this->strapi->store()(['type' => 'plugin', 'name' => 'users-permissions']);
        $grantSettings = $store->get(['key' => 'grant']);

        $grantProvider = $provider === 'local' ? 'email' : $provider;

        if (!(is_array($grantSettings) && is_array($grantSettings[$grantProvider] ?? null) && ($grantSettings[$grantProvider]['enabled'] ?? false))) {
            throw new ApplicationError('This provider is disabled');
        }

        if ($provider === 'local') {
            AuthValidation::validateCallbackBody($params);
            /** @var array<string, mixed> $params */
            $identifier = (string) $params['identifier'];

            // Check if the user exists.
            $user = $this->strapi->db()->query('plugin::users-permissions.user')->findOne([
                'where' => [
                    'provider' => $provider,
                    '$or' => [['email' => mb_strtolower($identifier)], ['username' => $identifier]],
                ],
            ]);

            if ($user === null) {
                throw new ValidationError('Invalid identifier or password');
            }

            if (!isset($user['password']) || $user['password'] === '') {
                throw new ValidationError('Invalid identifier or password');
            }

            $validPassword = Utils::getService($this->strapi, 'user')->validatePassword($params['password'], $user['password']);

            if (!$validPassword) {
                throw new ValidationError('Invalid identifier or password');
            }

            $advancedSettings = $store->get(['key' => 'advanced']);
            $requiresConfirmation = is_array($advancedSettings) ? ($advancedSettings['email_confirmation'] ?? null) : null;

            if ($requiresConfirmation && ($user['confirmed'] ?? null) !== true) {
                throw new ApplicationError('Your account email is not confirmed');
            }

            if (($user['blocked'] ?? null) === true) {
                throw new ApplicationError('Your account has been blocked by an administrator');
            }

            if ($this->jwtManagement() === 'refresh') {
                $this->sendRefreshAuthResponse($ctx, $user, [
                    'metadata' => self::buildSessionMetadataFromContext($ctx),
                ]);

                return null;
            }

            $ctx->send([
                'jwt' => Utils::getService($this->strapi, 'jwt')->issue(['id' => $user['id']]),
                'user' => $this->sanitizeUser($user, $ctx),
            ]);

            return null;
        }

        // Connect the user with the third-party provider.
        try {
            $grantResponse = OauthConnect::grant($ctx)['response'] ?? null;

            if ($grantResponse === null || $grantResponse === '' || $grantResponse === false || $grantResponse === 0) {
                throw new ApplicationError('OAuth authentication requires a completed provider session');
            }

            $user = Utils::getService($this->strapi, 'providers')->connect($provider, is_array($grantResponse) ? $grantResponse : [], [
                'grantResponse' => $grantResponse,
            ]);

            if ($user['blocked'] ?? false) {
                throw new ForbiddenError('Your account has been blocked by an administrator');
            }

            if ($this->jwtManagement() === 'refresh') {
                $this->sendRefreshAuthResponse($ctx, $user, [
                    'metadata' => self::buildSessionMetadataFromContext($ctx),
                ]);

                return null;
            }

            $ctx->send([
                'jwt' => Utils::getService($this->strapi, 'jwt')->issue(['id' => $user['id']]),
                'user' => $this->sanitizeUser($user, $ctx),
            ]);

            return null;
        } catch (\Throwable $error) {
            throw new ApplicationError($error->getMessage());
        }
    }

    public function changePassword(Context $ctx): mixed
    {
        $stateUser = $ctx->state()->get('user');
        if (!is_array($stateUser) || $stateUser === []) {
            throw new ApplicationError('You must be authenticated to reset your password');
        }

        $validations = $this->strapi->config()->get('plugin::users-permissions.validationRules');

        $validated = AuthValidation::validateChangePasswordBody($ctx->requestBody(), $validations);
        $currentPassword = $validated['currentPassword'] ?? null;
        $password = $validated['password'] ?? null;

        $user = $this->strapi->db()->query('plugin::users-permissions.user')->findOne(['where' => ['id' => $stateUser['id']]]);
        if ($user === null) {
            throw new \TypeError("Cannot read properties of null (reading 'password')");
        }

        $validPassword = Utils::getService($this->strapi, 'user')->validatePassword($currentPassword, $user['password'] ?? null);

        if (!$validPassword) {
            throw new ValidationError('The provided current password is invalid');
        }

        if ($currentPassword === $password) {
            throw new ValidationError('Your new password must be different than your current password');
        }

        Utils::getService($this->strapi, 'user')->edit($user['id'], ['password' => $password]);

        if ($this->jwtManagement() === 'refresh') {
            $this->reissueTokensAfterPasswordChange($ctx, $user);

            return null;
        }

        $ctx->send([
            'jwt' => Utils::getService($this->strapi, 'jwt')->issue(['id' => $user['id']]),
            'user' => $this->sanitizeUser($user, $ctx),
        ]);

        return null;
    }

    public function resetPassword(Context $ctx): mixed
    {
        $validations = $this->strapi->config()->get('plugin::users-permissions.validationRules');

        $validated = AuthValidation::validateResetPasswordBody($ctx->requestBody(), $validations);
        $password = $validated['password'] ?? null;
        $passwordConfirmation = $validated['passwordConfirmation'] ?? null;
        $code = $validated['code'] ?? null;

        if ($password !== $passwordConfirmation) {
            throw new ValidationError('Passwords do not match');
        }

        $user = $this->strapi->db()->query('plugin::users-permissions.user')->findOne(['where' => ['resetPasswordToken' => $code]]);

        if ($user === null) {
            throw new ValidationError('Incorrect code provided');
        }

        Utils::getService($this->strapi, 'user')->edit($user['id'], [
            'resetPasswordToken' => null,
            'password' => $password,
        ]);

        if ($this->jwtManagement() === 'refresh') {
            $this->reissueTokensAfterPasswordChange($ctx, $user);

            return null;
        }

        $ctx->send([
            'jwt' => Utils::getService($this->strapi, 'jwt')->issue(['id' => $user['id']]),
            'user' => $this->sanitizeUser($user, $ctx),
        ]);

        return null;
    }

    public function refresh(Context $ctx): mixed
    {
        if ($this->jwtManagement() !== 'refresh') {
            $ctx->notFound();

            return null;
        }

        $upSessions = $this->upSessions();
        $cookieName = self::cookieName($upSessions);

        // Check for refresh token in cookie first (if httpOnly is configured), then in body
        $refreshToken = $ctx->cookies()->get($cookieName);
        if ($refreshToken === null || $refreshToken === '') {
            $body = $ctx->requestBody();
            $refreshToken = is_array($body) ? ($body['refreshToken'] ?? null) : null;
        }

        if (!is_string($refreshToken) || $refreshToken === '') {
            $ctx->badRequest('Missing refresh token');

            return null;
        }

        $rotation = $this->strapi->sessionManager()('users-permissions')->rotateRefreshToken($refreshToken);
        if (array_key_exists('error', $rotation)) {
            $ctx->unauthorized('Invalid refresh token');

            return null;
        }

        $result = $this->strapi->sessionManager()('users-permissions')->generateAccessToken($rotation['token']);
        if (array_key_exists('error', $result)) {
            $ctx->unauthorized('Invalid refresh token');

            return null;
        }

        $requestHttpOnly = $ctx->header('x-strapi-refresh-cookie') === 'httpOnly';
        if (($upSessions['httpOnly'] ?? false) || $requestHttpOnly) {
            $ctx->cookies()->set(
                $cookieName,
                $rotation['token'],
                RefreshCookieOptions::buildRefreshCookieOptions($upSessions, self::isProduction())
            );
            $ctx->send(['jwt' => $result['token']]);

            return null;
        }

        $ctx->send(['jwt' => $result['token'], 'refreshToken' => $rotation['token']]);

        return null;
    }

    public function logout(Context $ctx): mixed
    {
        if ($this->jwtManagement() !== 'refresh') {
            $ctx->notFound();

            return null;
        }

        $stateUser = $ctx->state()->get('user');
        if (!is_array($stateUser) || $stateUser === []) {
            $ctx->unauthorized('Missing authentication');

            return null;
        }

        $userId = (string) $stateUser['id'];
        $upSessions = $this->upSessions();
        $body = $ctx->requestBody();
        $body = is_array($body) ? $body : [];
        $scope = is_string($body['scope'] ?? null) ? $body['scope'] : null;
        $deviceId = self::extractDeviceId($body);

        try {
            $this->revokeLogoutSessions($ctx, $userId, $scope, $deviceId, $body);
        } catch (\Throwable $err) {
            $this->strapi->log()->error('UP logout failed', ['error' => $err]);
        }

        $requestHttpOnly = $ctx->header('x-strapi-refresh-cookie') === 'httpOnly';
        if (($upSessions['httpOnly'] ?? false) || $requestHttpOnly) {
            $cookieName = self::cookieName($upSessions);

            $cookieOptions = RefreshCookieOptions::buildRefreshCookieOptions($upSessions, self::isProduction());
            unset($cookieOptions['maxAge']);

            $ctx->cookies()->set($cookieName, '', [...$cookieOptions, 'expires' => new \DateTimeImmutable('@0')]);
        }

        $ctx->send(['ok' => true]);

        return null;
    }

    public function getSessions(Context $ctx): mixed
    {
        if ($this->jwtManagement() !== 'refresh') {
            $ctx->notFound();

            return null;
        }

        $stateUser = $ctx->state()->get('user');
        if (!is_array($stateUser) || $stateUser === []) {
            $ctx->unauthorized('Missing authentication');

            return null;
        }

        $session = $ctx->state()->get('session');
        $currentSessionId = is_array($session) && isset($session['id']) ? (string) $session['id'] : null;
        $sessions = $this->strapi->sessionManager()('users-permissions')->listSessions((string) $stateUser['id']);

        $data = Sessions::sortSessionsForDisplay(
            array_map(static fn (array $session): array => Sessions::sanitizeSessionEntry($session, $currentSessionId), $sessions)
        );

        $ctx->send(['data' => $data]);

        return null;
    }

    public function revokeSession(Context $ctx): mixed
    {
        if ($this->jwtManagement() !== 'refresh') {
            $ctx->notFound();

            return null;
        }

        $stateUser = $ctx->state()->get('user');
        if (!is_array($stateUser) || $stateUser === []) {
            $ctx->unauthorized('Missing authentication');

            return null;
        }

        $sessionId = (string) $ctx->param('sessionId');
        $revoked = $this->strapi->sessionManager()('users-permissions')->revokeSessionById((string) $stateUser['id'], $sessionId);

        if (!$revoked) {
            $ctx->notFound('Session not found');

            return null;
        }

        $ctx->send(['data' => new \stdClass()]);

        return null;
    }

    public function connect(Context $ctx, ?callable $next = null): mixed
    {
        $providers = $this->strapi->store()->get(['type' => 'plugin', 'name' => 'users-permissions', 'key' => 'grant']);
        $providers = is_array($providers) ? $providers : [];

        $requestPath = explode('?', $ctx->url())[0];
        $afterConnect = explode('/connect/', $requestPath)[1] ?? throw new \TypeError("Cannot read properties of undefined (reading 'split')");
        $provider = explode('/', $afterConnect)[0];

        if (!(is_array($providers[$provider] ?? null) && ($providers[$provider]['enabled'] ?? false))) {
            throw new ApplicationError('This provider is disabled');
        }

        if (!str_starts_with((string) $this->strapi->config()->get('server.url', ''), 'http')) {
            $this->strapi->log()->warning(
                'You are using a third party provider for login. Make sure to set an absolute url in config/server.js. More info here: https://docs.strapi.io/developer-docs/latest/plugins/users-permissions.html#setting-up-the-server-url'
            );
        }

        $query = $ctx->query();
        $queryCustomCallback = $query['callback'] ?? null;
        $dynamicSessionCallback = OauthConnect::grant($ctx)['dynamic']['callback'] ?? null;
        $customCallback = $queryCustomCallback ?? $dynamicSessionCallback;

        if ($customCallback !== null) {
            try {
                $validateCallback = $this->strapi->plugin('users-permissions')->config('callback')['validate'] ?? null;
                if (!is_callable($validateCallback)) {
                    throw new \TypeError('validateCallback is not a function');
                }

                $validateCallback($customCallback, $providers[$provider]);

                // Persist across the provider redirect round-trip (request state alone is lost).
                $grant = OauthConnect::grant($ctx) ?? [];
                $grant['dynamic'] = [
                    ...(is_array($grant['dynamic'] ?? null) ? $grant['dynamic'] : []),
                    'callback' => $customCallback,
                ];
                OauthConnect::setGrant($ctx, $grant);
                $ctx->state()->set('oauthConnect', ['callback' => $customCallback]);
            } catch (\Throwable) {
                throw new ValidationError('Invalid callback URL provided', ['callback' => $customCallback]);
            }
        }

        $oauthConnect = OauthConnect::createOAuthConnectMiddleware($this->strapi);

        return $oauthConnect($ctx, $next ?? static fn (): mixed => null);
    }

    public function forgotPassword(Context $ctx): mixed
    {
        $validated = AuthValidation::validateForgotPasswordBody($ctx->requestBody());
        $email = (string) ($validated['email'] ?? '');

        $pluginStore = $this->strapi->store()(['type' => 'plugin', 'name' => 'users-permissions']);

        $emailSettings = $pluginStore->get(['key' => 'email']);
        $advancedSettings = $pluginStore->get(['key' => 'advanced']);
        $advancedSettings = is_array($advancedSettings) ? $advancedSettings : [];

        // Find the user by email.
        $user = $this->strapi->db()->query('plugin::users-permissions.user')->findOne(['where' => ['email' => mb_strtolower($email)]]);

        if ($user === null || ($user['blocked'] ?? false)) {
            $ctx->send(['ok' => true]);

            return null;
        }

        // Generate random token.
        $userInfo = $this->sanitizeUser($user, $ctx);

        $resetPasswordToken = bin2hex(random_bytes(64));

        $resetPasswordSettings = is_array($emailSettings) && is_array($emailSettings['reset_password']['options'] ?? null)
            ? $emailSettings['reset_password']['options']
            : [];
        $usersPermissions = Utils::getService($this->strapi, 'users-permissions');
        $emailBody = $usersPermissions->template(
            $resetPasswordSettings['message'] ?? null,
            [
                'URL' => $advancedSettings['email_reset_password'] ?? null,
                'SERVER_URL' => $this->strapi->config()->get('server.absoluteUrl'),
                'ADMIN_URL' => $this->strapi->config()->get('admin.absoluteUrl'),
                'USER' => $userInfo,
                'TOKEN' => $resetPasswordToken,
            ]
        );

        $emailObject = $usersPermissions->template(
            $resetPasswordSettings['object'] ?? null,
            [
                'USER' => $userInfo,
            ]
        );

        $from = is_array($resetPasswordSettings['from'] ?? null) ? $resetPasswordSettings['from'] : null;
        if ($from === null) {
            throw new \TypeError("Cannot read properties of undefined (reading 'email')");
        }
        $fromEmail = $from['email'] ?? null;
        $fromName = $from['name'] ?? null;
        $isSet = static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== false;

        $emailToSend = array_filter([
            'to' => $user['email'] ?? null,
            'from' => $isSet($fromEmail) || $isSet($fromName)
                ? ($fromName ?? 'undefined') . ' <' . ($fromEmail ?? 'undefined') . '>'
                : null,
            'replyTo' => $resetPasswordSettings['response_email'] ?? null,
            'subject' => $emailObject,
            'text' => $emailBody,
            'html' => $emailBody,
        ], static fn (mixed $v): bool => $v !== null);

        // NOTE: Update the user before sending the email so an Admin can generate the link if the email fails
        Utils::getService($this->strapi, 'user')->edit($user['id'], ['resetPasswordToken' => $resetPasswordToken]);

        // Send an email to the user.
        Utils::sendEmail($this->strapi, $emailToSend);

        $ctx->send(['ok' => true]);

        return null;
    }

    public function register(Context $ctx): mixed
    {
        $pluginStore = $this->strapi->store()(['type' => 'plugin', 'name' => 'users-permissions']);

        $settings = $pluginStore->get(['key' => 'advanced']);
        $settings = is_array($settings) ? $settings : [];

        if (!($settings['allow_register'] ?? false)) {
            throw new ApplicationError('Register action is currently disabled');
        }

        $upConfig = $this->strapi->config()->get('plugin::users-permissions');
        $register = is_array($upConfig) ? ($upConfig['register'] ?? null) : null;
        $alwaysAllowedKeys = ['username', 'password', 'email'];

        // Note that we intentionally do not filter allowedFields to allow a project to explicitly accept private or other Strapi field on registration
        $allowedFields = is_array($register) && is_array($register['allowedFields'] ?? null) && array_is_list($register['allowedFields']) ? $register['allowedFields'] : [];
        $allowedKeys = array_values(array_filter(
            [...$alwaysAllowedKeys, ...$allowedFields],
            static fn (mixed $k): bool => $k !== null && $k !== '' && $k !== false && $k !== 0,
        ));

        $requestBody = $ctx->requestBody();
        $requestBody = is_array($requestBody) ? $requestBody : [];

        // Check if there are any keys in requestBody that are not in allowedKeys
        $invalidKeys = array_values(array_filter(array_map('strval', array_keys($requestBody)), static fn (string $key): bool => !in_array($key, $allowedKeys, true)));

        if ($invalidKeys !== []) {
            // If there are invalid keys, throw an error
            throw new ValidationError('Invalid parameters: ' . implode(', ', $invalidKeys));
        }

        $picked = [];
        foreach ($allowedKeys as $key) {
            if (is_string($key) && array_key_exists($key, $requestBody)) {
                $picked[$key] = $requestBody[$key];
            }
        }
        $params = [
            ...$picked,
            'provider' => 'local',
        ];

        $validations = $this->strapi->config()->get('plugin::users-permissions.validationRules');

        AuthValidation::validateRegisterBody($params, $validations);

        $role = $this->strapi->db()->query('plugin::users-permissions.role')->findOne(['where' => ['type' => $settings['default_role'] ?? null]]);

        if ($role === null) {
            throw new ApplicationError('Impossible to find the default role');
        }

        $email = (string) $params['email'];
        $username = $params['username'];
        $provider = $params['provider'];

        $identifierFilter = [
            '$or' => [
                ['email' => mb_strtolower($email)],
                ['username' => mb_strtolower($email)],
                ['username' => $username],
                ['email' => $username],
            ],
        ];

        $conflictingUserCount = $this->strapi->db()->query('plugin::users-permissions.user')->count([
            'where' => [...$identifierFilter, 'provider' => $provider],
        ]);

        if ($conflictingUserCount > 0) {
            throw new ApplicationError('Email or Username are already taken');
        }

        if ($settings['unique_email'] ?? false) {
            $conflictingUserCount = $this->strapi->db()->query('plugin::users-permissions.user')->count([
                'where' => [...$identifierFilter],
            ]);

            if ($conflictingUserCount > 0) {
                throw new ApplicationError('Email or Username are already taken');
            }
        }

        $newUser = [
            ...$params,
            'role' => $role['id'],
            'email' => mb_strtolower($email),
            'username' => $username,
            'confirmed' => !($settings['email_confirmation'] ?? false),
        ];

        $user = Utils::getService($this->strapi, 'user')->add($newUser);

        $sanitizedUser = $this->sanitizeUser($user, $ctx);

        if ($settings['email_confirmation'] ?? false) {
            try {
                Utils::getService($this->strapi, 'user')->sendConfirmationEmail(is_array($sanitizedUser) ? $sanitizedUser : []);
            } catch (\Throwable $err) {
                $this->strapi->log()->error($err->getMessage(), ['error' => $err]);

                throw new ApplicationError('Error sending confirmation email');
            }

            $ctx->send(['user' => $sanitizedUser]);

            return null;
        }

        if ($this->jwtManagement() === 'refresh') {
            $deviceId = self::extractDeviceId($ctx->requestBody()) ?? Utils::randomUUID();

            $refresh = $this->strapi->sessionManager()('users-permissions')->generateRefreshToken((string) $user['id'], $deviceId, [
                'type' => 'refresh',
                'metadata' => self::buildSessionMetadataFromContext($ctx),
            ]);

            $access = $this->strapi->sessionManager()('users-permissions')->generateAccessToken($refresh['token']);
            if (array_key_exists('error', $access)) {
                throw new ApplicationError('Invalid credentials');
            }

            $ctx->send(['jwt' => $access['token'], 'refreshToken' => $refresh['token'], 'user' => $sanitizedUser]);

            return null;
        }

        $jwt = Utils::getService($this->strapi, 'jwt')->issue(['id' => $user['id']]);
        $ctx->send(['jwt' => $jwt, 'user' => $sanitizedUser]);

        return null;
    }

    public function emailConfirmation(Context $ctx, ?callable $next = null, bool $returnUser = false): mixed
    {
        $validated = AuthValidation::validateEmailConfirmationBody($ctx->query());
        $confirmationToken = $validated['confirmation'] ?? null;

        $userService = Utils::getService($this->strapi, 'user');
        $jwtService = Utils::getService($this->strapi, 'jwt');

        $users = $userService->fetchAll(['filters' => ['confirmationToken' => $confirmationToken]]);
        $user = $users[0] ?? null;

        if ($user === null) {
            throw new ValidationError('Invalid token');
        }

        $userService->edit($user['id'], ['confirmed' => true, 'confirmationToken' => null]);

        if ($returnUser) {
            $ctx->send([
                'jwt' => $jwtService->issue(['id' => $user['id']]),
                'user' => $this->sanitizeUser($user, $ctx),
            ]);
        } else {
            $settings = $this->strapi->store()->get(['type' => 'plugin', 'name' => 'users-permissions', 'key' => 'advanced']);
            $redirection = is_array($settings) ? ($settings['email_confirmation_redirection'] ?? null) : null;

            $ctx->redirect(is_string($redirection) && $redirection !== '' ? $redirection : '/');
        }

        return null;
    }

    public function sendEmailConfirmation(Context $ctx): mixed
    {
        $validated = AuthValidation::validateSendEmailConfirmationBody($ctx->requestBody());
        $email = (string) ($validated['email'] ?? '');

        $user = $this->strapi->db()->query('plugin::users-permissions.user')->findOne([
            'where' => ['email' => mb_strtolower($email)],
        ]);

        if ($user === null) {
            $ctx->send(['email' => $email, 'sent' => true]);

            return null;
        }

        if ($user['confirmed'] ?? false) {
            throw new ApplicationError('Already confirmed');
        }

        if ($user['blocked'] ?? false) {
            throw new ApplicationError('User blocked');
        }

        Utils::getService($this->strapi, 'user')->sendConfirmationEmail($user);

        $ctx->send([
            'email' => $user['email'],
            'sent' => true,
        ]);

        return null;
    }
}
