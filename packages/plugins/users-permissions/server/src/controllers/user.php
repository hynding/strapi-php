<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Controllers;

use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Controllers\Validation\User as UserValidation;
use Strapi\Plugin\UsersPermissions\Utils\Utils;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Errors\NotFoundError;
use Strapi\Utils\Errors\ValidationError;

/**
 * Port of server/src/controllers/user.js.
 *
 * @description: A set of functions called "actions" for managing `User`.
 */
final class User
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    private function sanitizeOutput(mixed $user, Context $ctx): mixed
    {
        $schema = $this->strapi->getModel('plugin::users-permissions.user');
        $auth = $ctx->state()->get('auth');

        return $this->strapi->contentAPI()->sanitize()->output($user, $schema, ['auth' => $auth]);
    }

    /** @param array<string, mixed> $query */
    private function validateQuery(array $query, Context $ctx): void
    {
        $schema = $this->strapi->getModel('plugin::users-permissions.user');
        $auth = $ctx->state()->get('auth');

        $this->strapi->contentAPI()->validate()->query($query, $schema, ['auth' => $auth]);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function sanitizeQuery(array $query, Context $ctx): array
    {
        $schema = $this->strapi->getModel('plugin::users-permissions.user');
        $auth = $ctx->state()->get('auth');

        return $this->strapi->contentAPI()->sanitize()->query($query, $schema, ['auth' => $auth]);
    }

    /** @return array<string, mixed> */
    private function advancedSettings(): array
    {
        $advanced = $this->strapi->store()->get(['type' => 'plugin', 'name' => 'users-permissions', 'key' => 'advanced']);

        return is_array($advanced) ? $advanced : [];
    }

    /** `_.toString`: null → '' */
    private static function toString(mixed $value): string
    {
        return $value === null ? '' : (is_scalar($value) ? (string) $value : '');
    }

    /**
     * Create a/an user record.
     */
    public function create(Context $ctx): mixed
    {
        $advanced = $this->advancedSettings();

        UserValidation::validateCreateUserBody($ctx->requestBody());

        /** @var array<string, mixed> $body */
        $body = $ctx->requestBody();
        $email = (string) ($body['email'] ?? '');
        $username = $body['username'] ?? null;
        $role = $body['role'] ?? null;

        $userWithSameUsername = $this->strapi->db()->query('plugin::users-permissions.user')->findOne(['where' => ['username' => $username]]);

        if ($userWithSameUsername !== null) {
            if ($email === '') {
                throw new ApplicationError('Username already taken');
            }
        }

        if ($advanced['unique_email'] ?? false) {
            $userWithSameEmail = $this->strapi->db()->query('plugin::users-permissions.user')->findOne(['where' => ['email' => mb_strtolower($email)]]);

            if ($userWithSameEmail !== null) {
                throw new ApplicationError('Email already taken');
            }
        }

        $user = [
            ...$body,
            'email' => mb_strtolower($email),
            'provider' => 'local',
        ];

        if ($role === null || $role === '' || $role === 0 || $role === false) {
            $defaultRole = $this->strapi->db()->query('plugin::users-permissions.role')->findOne(['where' => ['type' => $advanced['default_role'] ?? null]]);

            $user['role'] = $defaultRole['id'] ?? throw new \TypeError("Cannot read properties of null (reading 'id')");
        }

        try {
            $data = Utils::getService($this->strapi, 'user')->add($user);
            $sanitizedData = $this->sanitizeOutput($data, $ctx);

            $ctx->created($sanitizedData);
        } catch (\Throwable $error) {
            throw new ApplicationError($error->getMessage());
        }

        return null;
    }

    /**
     * Update a/an user record.
     */
    public function update(Context $ctx): mixed
    {
        $advancedConfigs = $this->advancedSettings();

        $id = $ctx->param('id');
        $body = $ctx->requestBody();
        $body = is_array($body) ? $body : [];
        $email = $body['email'] ?? null;
        $username = $body['username'] ?? null;
        $password = $body['password'] ?? null;

        $user = Utils::getService($this->strapi, 'user')->fetch($id);
        if ($user === null) {
            throw new NotFoundError('User not found');
        }

        UserValidation::validateUpdateUserBody($ctx->requestBody());

        if (($user['provider'] ?? null) === 'local' && array_key_exists('password', $body) && ($password === null || $password === '' || $password === false || $password === 0)) {
            throw new ValidationError('password.notNull');
        }

        if (array_key_exists('username', $body)) {
            $userWithSameUsername = $this->strapi->db()->query('plugin::users-permissions.user')->findOne(['where' => ['username' => $username]]);

            if ($userWithSameUsername !== null && self::toString($userWithSameUsername['id']) !== self::toString($id)) {
                throw new ApplicationError('Username already taken');
            }
        }

        if (array_key_exists('email', $body) && ($advancedConfigs['unique_email'] ?? false)) {
            $userWithSameEmail = $this->strapi->db()->query('plugin::users-permissions.user')->findOne(['where' => ['email' => mb_strtolower((string) $email)]]);

            if ($userWithSameEmail !== null && self::toString($userWithSameEmail['id']) !== self::toString($id)) {
                throw new ApplicationError('Email already taken');
            }
            $body['email'] = mb_strtolower((string) $body['email']);
        }

        $updateData = [
            ...$body,
        ];

        $data = Utils::getService($this->strapi, 'user')->edit($user['id'], $updateData);
        $sanitizedData = $this->sanitizeOutput($data, $ctx);

        $ctx->send($sanitizedData);

        return null;
    }

    /**
     * Retrieve user records.
     */
    public function find(Context $ctx): mixed
    {
        $this->validateQuery($ctx->query(), $ctx);
        $sanitizedQuery = $this->sanitizeQuery($ctx->query(), $ctx);
        $users = Utils::getService($this->strapi, 'user')->fetchAll($sanitizedQuery);

        $ctx->setBody(array_map(fn (array $user): mixed => $this->sanitizeOutput($user, $ctx), $users));

        return null;
    }

    /**
     * Retrieve a user record.
     */
    public function findOne(Context $ctx): mixed
    {
        $id = $ctx->param('id');
        $this->validateQuery($ctx->query(), $ctx);
        $sanitizedQuery = $this->sanitizeQuery($ctx->query(), $ctx);

        $data = Utils::getService($this->strapi, 'user')->fetch($id, $sanitizedQuery);

        if ($data !== null) {
            $data = $this->sanitizeOutput($data, $ctx);
        }

        $ctx->setBody($data);

        return null;
    }

    /**
     * Retrieve user count.
     */
    public function count(Context $ctx): mixed
    {
        $this->validateQuery($ctx->query(), $ctx);
        $sanitizedQuery = $this->sanitizeQuery($ctx->query(), $ctx);

        $ctx->setBody(Utils::getService($this->strapi, 'user')->count($sanitizedQuery));

        return null;
    }

    /**
     * Destroy a/an user record.
     */
    public function destroy(Context $ctx): mixed
    {
        $id = $ctx->param('id');

        $data = Utils::getService($this->strapi, 'user')->remove(['id' => $id]);
        $sanitizedUser = $this->sanitizeOutput($data, $ctx);

        $ctx->send($sanitizedUser);

        return null;
    }

    /**
     * Retrieve authenticated user.
     */
    public function me(Context $ctx): mixed
    {
        $authUser = $ctx->state()->get('user');
        $query = $ctx->query();

        if (!is_array($authUser) || $authUser === []) {
            $ctx->unauthorized();

            return null;
        }

        $this->validateQuery($query, $ctx);
        $sanitizedQuery = $this->sanitizeQuery($query, $ctx);
        $user = Utils::getService($this->strapi, 'user')->fetch($authUser['id'], $sanitizedQuery);

        $ctx->setBody($this->sanitizeOutput($user, $ctx));

        return null;
    }
}
