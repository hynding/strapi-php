<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Services;

use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Utils\Sanitize\Sanitizers;
use Strapi\Plugin\UsersPermissions\Utils\UrlJoin;
use Strapi\Plugin\UsersPermissions\Utils\Utils;
use Strapi\Utils\ContentApiConstants;

/**
 * Port of server/src/services/user.js.
 *
 * @description: A set of functions similar to controller's actions to avoid code duplication.
 */
final class User
{
    private const USER_MODEL_UID = 'plugin::users-permissions.user';

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * @param array<string, mixed>|null $params
     * @return array<string, mixed>
     */
    private static function pickAllowedQueryParams(?array $params): array
    {
        return array_intersect_key($params ?? [], array_flip(ContentApiConstants::ALLOWED_QUERY_PARAM_KEYS));
    }

    /** @param array<string, mixed>|null $params */
    public function count(?array $params = null): int
    {
        $query = $this->strapi->get('query-params')->transform(self::USER_MODEL_UID, self::pickAllowedQueryParams($params));

        return $this->strapi->db()->query(self::USER_MODEL_UID)->count($query);
    }

    /**
     * Hashes password fields in the provided values object if they are present.
     * It checks each key in the values object against the model's attributes and
     * hashes it if the attribute type is 'password',
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function ensureHashedPasswords(array $values): array
    {
        $model = $this->strapi->getModel(self::USER_MODEL_UID);

        foreach ($values as $key => $value) {
            $attribute = $model?->attribute((string) $key);
            if (is_array($attribute) && ($attribute['type'] ?? null) === 'password') {
                // Check if a custom encryption.rounds has been set on the password attribute
                $rounds = $attribute['encryption']['rounds'] ?? 10;
                $values[$key] = password_hash((string) $value, PASSWORD_BCRYPT, ['cost' => is_numeric($rounds) ? (int) $rounds : 10]);
            }
        }

        return $values;
    }

    /**
     * Promise to add a/an user.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function add(array $values): array
    {
        // Use the Document Service so relation inputs accept both the internal
        // numeric id (legacy) and the documentId (v5 default) syntax, consistent
        // with every other content-type endpoint. The Document Service hashes
        // `password` attributes itself, so we must not pre-hash here.
        return $this->strapi->documents(self::USER_MODEL_UID)->create([
            'data' => $values,
            'populate' => ['role'],
        ]);
    }

    /**
     * Promise to edit a/an user.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function edit(int|string $userId, array $params = []): ?array
    {
        // The user is addressed by its numeric id (e.g. the `/users/:id` route),
        // but the Document Service updates by documentId. Resolve it first so the
        // relation inputs are processed by the Document Service, which accepts both
        // numeric ids (legacy) and documentIds (v5 default). The Document Service
        // hashes `password` attributes itself, so we must not pre-hash here.
        $entry = $this->strapi->db()->query(self::USER_MODEL_UID)->findOne(['where' => ['id' => $userId], 'select' => ['documentId']]);

        if ($entry === null) {
            return null;
        }

        return $this->strapi->documents(self::USER_MODEL_UID)->update([
            'documentId' => $entry['documentId'],
            'data' => $params,
            'populate' => ['role'],
        ]);
    }

    /**
     * Promise to fetch a/an user.
     *
     * @param array<string, mixed>|null $params
     * @return array<string, mixed>|null
     */
    public function fetch(mixed $id, ?array $params = null): ?array
    {
        $query = $this->strapi->get('query-params')->transform(self::USER_MODEL_UID, self::pickAllowedQueryParams($params));

        return $this->strapi->db()->query(self::USER_MODEL_UID)->findOne([
            ...$query,
            'where' => [
                '$and' => [['id' => $id], $query['where'] ?? []],
            ],
        ]);
    }

    /**
     * Promise to fetch authenticated user.
     *
     * @return array<string, mixed>|null
     */
    public function fetchAuthenticatedUser(mixed $id): ?array
    {
        return $this->strapi->db()->query(self::USER_MODEL_UID)->findOne(['where' => ['id' => $id], 'populate' => ['role']]);
    }

    /**
     * Promise to fetch all users.
     *
     * @param array<string, mixed>|null $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(?array $params = null): array
    {
        $query = $this->strapi->get('query-params')->transform(self::USER_MODEL_UID, self::pickAllowedQueryParams($params));

        return array_values($this->strapi->db()->query(self::USER_MODEL_UID)->findMany($query));
    }

    /**
     * Promise to remove a/an user.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function remove(array $params): ?array
    {
        // Invalidate sessions for all affected users
        $sessionManager = $this->strapi->has('sessionManager') ? $this->strapi->sessionManager() : null;
        if ($sessionManager !== null && $sessionManager->hasOrigin('users-permissions') && isset($params['id']) && $params['id'] !== '' && $params['id'] !== 0) {
            $sessionManager('users-permissions')->invalidateRefreshToken((string) $params['id']);
        }

        return $this->strapi->db()->query(self::USER_MODEL_UID)->delete(['where' => $params]);
    }

    public function validatePassword(mixed $password, mixed $hash): bool
    {
        if (!is_string($password) || !is_string($hash)) {
            return false;
        }

        return password_verify($password, $hash);
    }

    /** @param array<string, mixed> $user */
    public function sendConfirmationEmail(array $user): void
    {
        $userPermissionService = Utils::getService($this->strapi, 'users-permissions');
        $pluginStore = $this->strapi->store()(['type' => 'plugin', 'name' => 'users-permissions']);
        $userSchema = $this->strapi->getModel(self::USER_MODEL_UID);

        $storeEmail = $pluginStore->get(['key' => 'email']);
        $settings = is_array($storeEmail) ? ($storeEmail['email_confirmation']['options'] ?? null) : null;
        if (!is_array($settings)) {
            throw new \TypeError("Cannot read properties of undefined (reading 'options')");
        }

        // Sanitize the template's user information
        $sanitizedUserInfo = $userSchema !== null ? Sanitizers::defaultSanitizeOutput($this->strapi, $userSchema, $user) : $user;

        $confirmationToken = bin2hex(random_bytes(20));

        $this->edit($user['id'], ['confirmationToken' => $confirmationToken]);

        $apiPrefix = (string) $this->strapi->config()->get('api.rest.prefix');

        try {
            $settings['message'] = $userPermissionService->template($settings['message'] ?? null, [
                'URL' => UrlJoin::join(
                    (string) $this->strapi->config()->get('server.absoluteUrl'),
                    $apiPrefix,
                    '/auth/email-confirmation'
                ),
                'SERVER_URL' => $this->strapi->config()->get('server.absoluteUrl'),
                'ADMIN_URL' => $this->strapi->config()->get('admin.absoluteUrl'),
                'USER' => $sanitizedUserInfo,
                'CODE' => $confirmationToken,
            ]);

            $settings['object'] = $userPermissionService->template($settings['object'] ?? null, [
                'USER' => $sanitizedUserInfo,
            ]);
        } catch (\Throwable) {
            $this->strapi->log()->error(
                '[plugin::users-permissions.sendConfirmationEmail]: Failed to generate a template for "user confirmation email". Please make sure your email template is valid and does not contain invalid characters or patterns'
            );

            return;
        }

        $from = is_array($settings['from'] ?? null) ? $settings['from'] : [];
        $fromEmail = $from['email'] ?? null;
        $fromName = $from['name'] ?? null;

        // Send an email to the user.
        Utils::sendEmail($this->strapi, array_filter([
            'to' => $user['email'] ?? null,
            'from' => $fromEmail !== null && $fromEmail !== '' && $fromName !== null && $fromName !== ''
                ? "{$fromName} <{$fromEmail}>"
                : null,
            'replyTo' => $settings['response_email'] ?? null,
            'subject' => $settings['object'],
            'text' => $settings['message'],
            'html' => $settings['message'],
        ], static fn (mixed $v): bool => $v !== null));
    }
}
