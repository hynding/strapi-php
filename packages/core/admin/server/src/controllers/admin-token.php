<?php

declare(strict_types=1);

namespace Strapi\Admin\Controllers;

use Strapi\Admin\Services\Constants;
use Strapi\Admin\Utils\Utils;
use Strapi\Admin\Validation\AdminTokens;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Primitives\Strings;

/** Port of server/src/controllers/admin-token.ts (`admin::admin-token`): admin tokens. */
final class AdminToken
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    // ---------------------------------------------------------------------------
    // Access-control helpers
    // ---------------------------------------------------------------------------

    /** @param array<string, mixed> $user */
    private static function isSuperAdmin(array $user): bool
    {
        foreach (is_array($user['roles'] ?? null) ? $user['roles'] : [] as $role) {
            if (is_array($role) && ($role['code'] ?? null) === Constants::SUPER_ADMIN_CODE) {
                return true;
            }
        }

        return false;
    }

    private static function idString(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }

    /** @param array<string, mixed> $token */
    private static function getOwnerId(array $token): string
    {
        $owner = $token['adminUserOwner'] ?? null;

        return self::idString(is_array($owner) ? ($owner['id'] ?? null) : $owner);
    }

    /**
     * Returns true when user is the recorded owner of an admin token.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $token
     */
    private static function isTokenOwner(array $user, array $token): bool
    {
        return self::getOwnerId($token) === self::idString($user['id'] ?? null);
    }

    /**
     * Owner OR super-admin can manage an admin token (read metadata, update…).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $token
     */
    private static function canAccessAdminToken(array $user, array $token): bool
    {
        return self::isTokenOwner($user, $token) || self::isSuperAdmin($user);
    }

    /** @return array<string, mixed> */
    private static function body(Context $ctx): array
    {
        $body = $ctx->requestBody();

        return is_array($body) ? $body : [];
    }

    /** @return array<string, mixed> */
    private static function user(Context $ctx): array
    {
        $user = $ctx->state()->get('user');

        return is_array($user) ? $user : [];
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------
    public function create(Context $ctx): mixed
    {
        $body = self::body($ctx);
        $apiTokenService = Utils::getService($this->strapi, 'api-token-admin');

        if (array_key_exists('type', $body)) {
            $ctx->badRequest('Type is not allowed for admin tokens');

            return null;
        }
        if (array_key_exists('permissions', $body)) {
            $ctx->badRequest('Permissions are not allowed for admin tokens');

            return null;
        }

        $user = self::user($ctx);
        // `undefined` properties are left out, as JSON drops them
        $attributes = [
            'kind' => 'admin',
            'name' => ApiToken::trim($body['name'] ?? null),
            'description' => ApiToken::trim($body['description'] ?? null),
            ...(array_key_exists('adminPermissions', $body) ? ['adminPermissions' => $body['adminPermissions']] : []),
            ...(array_key_exists('lifespan', $body) ? ['lifespan' => $body['lifespan']] : []),
            ...(array_key_exists('id', $user) ? ['adminUserOwner' => $user['id']] : []),
        ];

        AdminTokens::validateAdminTokenCreationInput($attributes);

        $alreadyExists = $apiTokenService->exists(['name' => $attributes['name']]);
        if ($alreadyExists) {
            throw new ApplicationError('Name already taken');
        }

        $apiToken = $apiTokenService->create($attributes, $user);
        $ctx->created(['data' => $apiToken]);

        return null;
    }

    // -------------------------------------------------------------------------
    // Regenerate — owner-only, super-admin does NOT bypass
    // -------------------------------------------------------------------------
    public function regenerate(Context $ctx): mixed
    {
        $id = $ctx->param('id');
        $apiTokenService = Utils::getService($this->strapi, 'api-token-admin');

        $token = $apiTokenService->getById($id);
        if ($token === null) {
            $ctx->notFound('API Token not found');

            return null;
        }

        if (!self::isTokenOwner(self::user($ctx), $token)) {
            $ctx->forbidden();

            return null;
        }

        $accessToken = $apiTokenService->regenerate($id);
        $ctx->created(['data' => $accessToken]);

        return null;
    }

    // -------------------------------------------------------------------------
    // List — always filtered to kind: 'admin'
    // -------------------------------------------------------------------------
    public function list(Context $ctx): mixed
    {
        $apiTokenService = Utils::getService($this->strapi, 'api-token-admin');
        $apiTokens = $apiTokenService->list(self::user($ctx));

        $ctx->send(['data' => $apiTokens]);

        return null;
    }

    // -------------------------------------------------------------------------
    // Revoke
    // -------------------------------------------------------------------------
    public function revoke(Context $ctx): mixed
    {
        $id = $ctx->param('id');
        $apiTokenService = Utils::getService($this->strapi, 'api-token-admin');

        $existingToken = $apiTokenService->getById($id);
        if ($existingToken === null) {
            $ctx->notFound('API Token not found');

            return null;
        }

        if (self::canAccessAdminToken(self::user($ctx), $existingToken) === false) {
            $ctx->forbidden();

            return null;
        }

        $apiToken = $apiTokenService->revoke($id);
        $ctx->deleted(['data' => $apiToken]);

        return null;
    }

    // -------------------------------------------------------------------------
    // Get — key exposed only to owner
    // -------------------------------------------------------------------------
    public function get(Context $ctx): mixed
    {
        $id = $ctx->param('id');
        $apiTokenService = Utils::getService($this->strapi, 'api-token-admin');

        $token = $apiTokenService->getById($id);
        if ($token === null) {
            $ctx->notFound('API Token not found');

            return null;
        }

        $user = self::user($ctx);

        if (self::canAccessAdminToken($user, $token) === false) {
            $ctx->notFound('API Token not found');

            return null;
        }

        if (self::isTokenOwner($user, $token)) {
            $withKey = $apiTokenService->getById($id, ['includeDecryptedKey' => true]);
            $ctx->send(['data' => $withKey ?? $token]);

            return null;
        }

        $ctx->send(['data' => $token]);

        return null;
    }

    // -------------------------------------------------------------------------
    // Update — owner or super-admin only
    // -------------------------------------------------------------------------
    public function update(Context $ctx): mixed
    {
        $body = self::body($ctx);
        $id = $ctx->param('id');
        $apiTokenService = Utils::getService($this->strapi, 'api-token-admin');

        if (array_key_exists('name', $body)) {
            $body['name'] = ApiToken::trim($body['name'] ?? '');
        }
        if (array_key_exists('description', $body)) {
            $body['description'] = ApiToken::trim($body['description'] ?? '');
        }

        AdminTokens::validateAdminTokenUpdateInput($body);

        $existingToken = $apiTokenService->getById($id);
        if ($existingToken === null) {
            $ctx->notFound('API Token not found');

            return null;
        }

        if (array_key_exists('name', $body)) {
            $nameAlreadyTaken = $apiTokenService->getByName((string) $body['name']);
            if ($nameAlreadyTaken !== null && !Strings::isEqual($nameAlreadyTaken['id'] ?? null, $id)) {
                throw new ApplicationError('Name already taken');
            }
        }

        if (!self::canAccessAdminToken(self::user($ctx), $existingToken)) {
            $ctx->forbidden();

            return null;
        }

        $apiToken = $apiTokenService->update($id, $body);
        $ctx->send(['data' => $apiToken]);

        return null;
    }

    // -------------------------------------------------------------------------
    // Owner permissions — effective permissions of the token owner
    // -------------------------------------------------------------------------
    public function getOwnerPermissions(Context $ctx): mixed
    {
        $id = $ctx->param('id');
        $apiTokenService = Utils::getService($this->strapi, 'api-token-admin');
        $permissionService = Utils::getService($this->strapi, 'permission');
        $userService = Utils::getService($this->strapi, 'user');

        $token = $apiTokenService->getById($id);
        if ($token === null) {
            $ctx->notFound('apiToken.notFound');

            return null;
        }

        if (!self::canAccessAdminToken(self::user($ctx), $token)) {
            $ctx->forbidden();

            return null;
        }

        $ownerId = self::getOwnerId($token);
        $ownerUser = $userService->findOne($ownerId);
        if ($ownerUser === null) {
            $ctx->notFound('owner.notFound');

            return null;
        }

        $ownerPermissions = $permissionService->findUserPermissions($ownerUser);
        $sanitizedPermissions = array_map(static fn (array $p): array => $permissionService->sanitizePermission($p), $ownerPermissions);

        $ctx->setBody(['data' => $sanitizedPermissions]);

        return null;
    }
}
