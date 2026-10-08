<?php

declare(strict_types=1);

namespace Strapi\Admin\Services;

use Strapi\Admin\AuditLogs\AdminUsers;
use Strapi\Admin\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Utils\AuditLogs;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Primitives\Objects;

/**
 * Port of server/src/services/auth.ts (`admin::auth`).
 *
 * `checkCredentials` keeps upstream's passport-style tuple: `[error, user|false, info?]`.
 */
final class Auth
{
    // Default lifetime of an admin reset-password token. Overridable via
    // `admin.forgotPassword.expiresIn` (number of seconds or a shorthand like '15m', '1h').
    private const DEFAULT_RESET_PASSWORD_TOKEN_EXPIRES_IN = '1h';

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** Resolve the reset-password token lifetime, in milliseconds, from config. */
    public function getResetPasswordTokenTTL(): int
    {
        $configured = $this->strapi->config()->get('admin.forgotPassword.expiresIn', self::DEFAULT_RESET_PASSWORD_TOKEN_EXPIRES_IN);
        $seconds = Token::expiresInToSeconds($configured) ?? (int) Token::expiresInToSeconds(self::DEFAULT_RESET_PASSWORD_TOKEN_EXPIRES_IN);

        return $seconds * 1000;
    }

    /**
     * Create a reset-password token with an expiry and persist it on the user.
     *
     * @return string the generated reset-password token
     */
    public function assignResetPasswordToken(mixed $userId): string
    {
        $resetPasswordToken = Utils::getService($this->strapi, 'token')->createToken();
        $expiresAtMs = (int) floor(microtime(true) * 1000) + $this->getResetPasswordTokenTTL();
        $resetPasswordTokenExpiresAt = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%03d000', intdiv($expiresAtMs, 1000), $expiresAtMs % 1000)) ?: new \DateTimeImmutable();

        $user = Utils::getService($this->strapi, 'user')->updateById($userId, [
            'resetPasswordToken' => $resetPasswordToken,
            'resetPasswordTokenExpiresAt' => $resetPasswordTokenExpiresAt,
        ]);

        // null when the account was deleted between the caller's lookup and this write
        if (is_array($user)) {
            AuditLogs::emitAudit($this->strapi, AdminUsers::AUDITED_EVENTS['PASSWORD_RESET_CREATE'], [
                ...AdminUsers::toAdminUserEvent($user),
                'expiresAt' => $user['resetPasswordTokenExpiresAt'] ?? null,
            ]);
        }

        return $resetPasswordToken;
    }

    /**
     * Set the new password and consume the reset token.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>|null
     */
    public function completePasswordReset(array $user, string $password): ?array
    {
        $updatedUser = Utils::getService($this->strapi, 'user')->updateById($user['id'], [
            'password' => $password,
            'resetPasswordToken' => null,
            'resetPasswordTokenExpiresAt' => null,
        ]);

        AuditLogs::emitAudit($this->strapi, AdminUsers::AUDITED_EVENTS['PASSWORD_RESET_CONFIRM'], AdminUsers::toAdminUserEvent(is_array($updatedUser) ? $updatedUser : []));

        return $updatedUser;
    }

    /**
     * Reject a reset-password token that has expired — or that has no expiry at all —
     * clearing the stale token so it cannot be retried. (GH#25711)
     *
     * @param array<string, mixed> $user
     */
    public function assertResetPasswordTokenIsValid(array $user): void
    {
        $expiresAt = $user['resetPasswordTokenExpiresAt'] ?? null;
        $isExpired = true;
        if ($expiresAt !== null && $expiresAt !== '') {
            try {
                $time = $expiresAt instanceof \DateTimeInterface ? $expiresAt : new \DateTimeImmutable((string) $expiresAt);
                $isExpired = (float) $time->format('U.u') <= microtime(true);
            } catch (\Exception) {
                $isExpired = true;
            }
        }

        if ($isExpired) {
            // Clear the stale token so it can no longer be retried.
            Utils::getService($this->strapi, 'user')->updateById($user['id'], [
                'resetPasswordToken' => null,
                'resetPasswordTokenExpiresAt' => null,
            ]);

            throw new ApplicationError('This reset password token has expired');
        }
    }

    /** hashes a password (bcrypt, 10 rounds) */
    public function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
    }

    /** Validate a password */
    public function validatePassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Check login credentials
     *
     * @param array{email?: mixed, password?: mixed} $credentials
     * @return array{0: null, 1: array<string, mixed>|false|null, 2?: array{message: string}}
     */
    public function checkCredentials(array $credentials): array
    {
        $email = $credentials['email'] ?? null;
        $password = $credentials['password'] ?? null;

        $user = $this->strapi->db()->query('admin::user')->findOne(['where' => ['email' => $email]]);

        if (!is_array($user) || !is_string($user['password'] ?? null) || $user['password'] === '') {
            return [null, false, ['message' => 'Invalid credentials']];
        }

        $isValid = $this->validatePassword((string) $password, $user['password']);

        if (!$isValid) {
            return [null, false, ['message' => 'Invalid credentials']];
        }

        if (($user['isActive'] ?? null) !== true) {
            return [null, false, ['message' => 'User not active']];
        }

        return [null, $user];
    }

    /**
     * Send an email to the user if it exists or do nothing
     *
     * @param array{email?: mixed} $params
     */
    public function forgotPassword(array $params = []): void
    {
        $email = $params['email'] ?? null;
        $user = $this->strapi->db()->query('admin::user')->findOne(['where' => ['email' => $email, 'isActive' => true]]);
        if (!is_array($user)) {
            return;
        }

        $resetPasswordToken = $this->assignResetPasswordToken($user['id']);

        // Send an email to the admin.
        $url = $this->strapi->config()->get('admin.absoluteUrl') . "/auth/reset-password?code={$resetPasswordToken}";

        $sendTemplatedEmail = [$this->strapi->plugin('email')->service('email'), 'sendTemplatedEmail'];
        if (!is_callable($sendTemplatedEmail)) {
            throw new \RuntimeException('The email service has no sendTemplatedEmail()');
        }

        try {
            $sendTemplatedEmail(
                [
                    'to' => $user['email'],
                    'from' => $this->strapi->config()->get('admin.forgotPassword.from'),
                    'replyTo' => $this->strapi->config()->get('admin.forgotPassword.replyTo'),
                ],
                $this->strapi->config()->get('admin.forgotPassword.emailTemplate'),
                [
                    'url' => $url,
                    'user' => Objects::pick($user, ['email', 'firstname', 'lastname', 'username']),
                ]
            );
        } catch (\Throwable $err) {
            // log error server side but do not disclose it to the user to avoid leaking informations
            $this->strapi->log()->error($err->getMessage(), ['error' => $err]);
        }
    }

    /**
     * Reset a user password
     *
     * @param array{resetPasswordToken?: mixed, password?: mixed} $params
     * @return array<string, mixed>|null
     */
    public function resetPassword(array $params = []): ?array
    {
        $matchingUser = $this->strapi->db()->query('admin::user')->findOne([
            'where' => ['resetPasswordToken' => $params['resetPasswordToken'] ?? null, 'isActive' => true],
        ]);

        if (!is_array($matchingUser)) {
            throw new ApplicationError();
        }

        $this->assertResetPasswordTokenIsValid($matchingUser);

        return $this->completePasswordReset($matchingUser, (string) ($params['password'] ?? ''));
    }
}
