<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Controllers;

use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Controllers\Validation\User as UserValidation;
use Strapi\Plugin\UsersPermissions\Utils\Utils;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Primitives\Objects;

/** Port of server/src/controllers/role.js. */
final class Role
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @param array<string, mixed> $role */
    private function sanitizeOutput(array $role): mixed
    {
        $schema = $this->strapi->getModel('plugin::users-permissions.role');

        // upstream: `strapi.plugin('i18n').service('sanitize')`; i18n is always installed upstream
        if ($schema === null || !$this->strapi->hasPlugin('i18n')) {
            return $role;
        }

        $sanitizeLocalizationFields = [$this->strapi->plugin('i18n')->service('sanitize'), 'sanitizeLocalizationFields'];
        if (!is_callable($sanitizeLocalizationFields)) {
            throw new \RuntimeException('The i18n sanitize service has no sanitizeLocalizationFields method');
        }

        return $sanitizeLocalizationFields($schema, $role);
    }

    /**
     * Default action.
     */
    public function createRole(Context $ctx): mixed
    {
        $body = $ctx->requestBody();
        if (Objects::isEmpty($body)) {
            throw new ValidationError('Request body cannot be empty');
        }

        Utils::getService($this->strapi, 'role')->createRole(is_array($body) ? $body : []);

        $ctx->send(['ok' => true]);

        return null;
    }

    public function findOne(Context $ctx): mixed
    {
        $id = (string) $ctx->param('id');

        $role = Utils::getService($this->strapi, 'role')->findOne($id);

        if ($role === []) {
            $ctx->notFound();

            return null;
        }

        $safeRole = $this->sanitizeOutput($role);

        $ctx->send(['role' => $safeRole]);

        return null;
    }

    public function find(Context $ctx): mixed
    {
        $roles = Utils::getService($this->strapi, 'role')->find();

        $safeRoles = array_map(fn (array $role): mixed => $this->sanitizeOutput($role), $roles);

        $ctx->send(['roles' => $safeRoles]);

        return null;
    }

    public function updateRole(Context $ctx): mixed
    {
        $roleID = (string) $ctx->param('role');

        $body = $ctx->requestBody();
        if (Objects::isEmpty($body)) {
            throw new ValidationError('Request body cannot be empty');
        }

        Utils::getService($this->strapi, 'role')->updateRole($roleID, is_array($body) ? $body : []);

        $ctx->send(['ok' => true]);

        return null;
    }

    public function deleteRole(Context $ctx): mixed
    {
        $roleID = $ctx->param('role');

        if ($roleID === null || $roleID === '') {
            UserValidation::validateDeleteRoleBody($ctx->params());
        }

        // Fetch public role.
        $publicRole = $this->strapi->db()->query('plugin::users-permissions.role')->findOne(['where' => ['type' => 'public']]);

        $publicRoleID = $publicRole['id'] ?? throw new \TypeError("Cannot read properties of null (reading 'id')");

        // Prevent from removing the public role.
        if ((string) $roleID === (string) $publicRoleID) {
            throw new ApplicationError('Cannot delete public role');
        }

        Utils::getService($this->strapi, 'role')->deleteRole((string) $roleID, $publicRoleID);

        $ctx->send(['ok' => true]);

        return null;
    }
}
