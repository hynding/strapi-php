<?php

declare(strict_types=1);

namespace Strapi\Plugin\UsersPermissions\Controllers;

use Strapi\Admin\Services\Permission\PermissionsManager\PermissionsManager;
use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Controllers\Validation\User as UserValidation;
use Strapi\Plugin\UsersPermissions\Utils\Utils;
use Strapi\Types\Core\Context;
use Strapi\Utils\ContentTypes;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Errors\ForbiddenError;
use Strapi\Utils\Errors\NotFoundError;

/** Port of server/src/controllers/content-manager-user.js. */
final class ContentManagerUser
{
    private const USER_MODEL = 'plugin::users-permissions.user';

    private const ACTIONS = [
        'read' => 'plugin::content-manager.explorer.read',
        'create' => 'plugin::content-manager.explorer.create',
        'edit' => 'plugin::content-manager.explorer.update',
        'delete' => 'plugin::content-manager.explorer.delete',
    ];

    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return array{pm: PermissionsManager, doc: array<string, mixed>} */
    private function findEntityAndCheckPermissions(mixed $ability, string $action, string $model, string $id): array
    {
        $doc = Utils::documentManager($this->strapi)->findOne($id, $model, [
            'populate' => [ContentTypes::CREATED_BY_ATTRIBUTE . '.roles'],
        ]);

        if ($doc === null) {
            throw new NotFoundError();
        }

        $pm = Utils::adminPermissionService($this->strapi)->createPermissionsManager(['ability' => $ability, 'action' => $action, 'model' => $model]);

        if ($pm->ability->cannot($pm->action ?? $action, $pm->toSubject($doc))) {
            throw new ForbiddenError();
        }

        // _.omit(doc, 'createdBy.roles')
        $docWithoutCreatorRoles = $doc;
        if (is_array($docWithoutCreatorRoles[ContentTypes::CREATED_BY_ATTRIBUTE] ?? null)) {
            unset($docWithoutCreatorRoles[ContentTypes::CREATED_BY_ATTRIBUTE]['roles']);
        }

        return ['pm' => $pm, 'doc' => $docWithoutCreatorRoles];
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
        $body = $ctx->requestBody();
        $body = is_array($body) ? $body : [];
        $admin = $ctx->state()->get('user');
        $userAbility = $ctx->state()->get('userAbility');

        $email = $body['email'] ?? null;
        $username = $body['username'] ?? null;

        $pm = Utils::adminPermissionService($this->strapi)->createPermissionsManager([
            'ability' => $userAbility,
            'action' => self::ACTIONS['create'],
            'model' => self::USER_MODEL,
        ]);

        if (!$pm->isAllowed()) {
            $ctx->forbidden();

            return null;
        }

        $sanitizedBody = $pm->pickPermittedFieldsOf($body, ['subject' => self::USER_MODEL]);

        $advanced = $this->advancedSettings();

        UserValidation::validateCreateUserBody($ctx->requestBody());

        $userWithSameUsername = $this->strapi->db()->query('plugin::users-permissions.user')->findOne(['where' => ['username' => $username]]);

        if ($userWithSameUsername !== null) {
            throw new ApplicationError('Username already taken');
        }

        if ($advanced['unique_email'] ?? false) {
            $userWithSameEmail = $this->strapi->db()->query('plugin::users-permissions.user')->findOne(['where' => ['email' => mb_strtolower((string) $email)]]);

            if ($userWithSameEmail !== null) {
                throw new ApplicationError('Email already taken');
            }
        }

        $user = [
            ...(is_array($sanitizedBody) ? $sanitizedBody : []),
            'provider' => 'local',
            ContentTypes::CREATED_BY_ATTRIBUTE => is_array($admin) ? ($admin['id'] ?? null) : null,
            ContentTypes::UPDATED_BY_ATTRIBUTE => is_array($admin) ? ($admin['id'] ?? null) : null,
        ];

        $user['email'] = mb_strtolower(is_scalar($user['email'] ?? null) ? (string) $user['email'] : '');

        try {
            $data = Utils::documentManager($this->strapi)->create(self::USER_MODEL, ['data' => $user]);

            $sanitizedData = $pm->sanitizeOutput($data, ['action' => self::ACTIONS['read']]);

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
        $documentId = (string) $ctx->param('id');
        $body = $ctx->requestBody();
        $body = is_array($body) ? $body : [];
        $admin = $ctx->state()->get('user');
        $userAbility = $ctx->state()->get('userAbility');

        $advancedConfigs = $this->advancedSettings();

        $email = $body['email'] ?? null;
        $username = $body['username'] ?? null;
        $password = $body['password'] ?? null;

        ['pm' => $pm, 'doc' => $doc] = $this->findEntityAndCheckPermissions(
            $userAbility,
            self::ACTIONS['edit'],
            self::USER_MODEL,
            $documentId
        );

        $user = $doc;

        UserValidation::validateUpdateUserBody($ctx->requestBody());

        if (array_key_exists('password', $body) && ($password === null || $password === '')) {
            unset($body['password']);
        }

        if (array_key_exists('username', $body)) {
            $userWithSameUsername = $this->strapi->db()->query('plugin::users-permissions.user')->findOne(['where' => ['username' => $username]]);

            if ($userWithSameUsername !== null && self::toString($userWithSameUsername['id']) !== self::toString($user['id'] ?? null)) {
                throw new ApplicationError('Username already taken');
            }
        }

        if (array_key_exists('email', $body) && ($advancedConfigs['unique_email'] ?? false)) {
            $userWithSameEmail = $this->strapi->db()->query('plugin::users-permissions.user')->findOne(['where' => ['email' => mb_strtolower(self::toString($email))]]);

            if ($userWithSameEmail !== null && self::toString($userWithSameEmail['id']) !== self::toString($user['id'] ?? null)) {
                throw new ApplicationError('Email already taken');
            }

            $body['email'] = mb_strtolower(self::toString($body['email']));
        }

        $sanitizedData = $pm->pickPermittedFieldsOf($body, ['subject' => $pm->toSubject($user)]);
        $updateData = [...(is_array($sanitizedData) ? $sanitizedData : []), 'updatedBy' => is_array($admin) ? ($admin['id'] ?? null) : null];
        unset($updateData['createdBy']);

        $data = Utils::documentManager($this->strapi)->update($documentId, self::USER_MODEL, [
            'data' => $updateData,
        ]);

        $ctx->setBody($pm->sanitizeOutput($data, ['action' => self::ACTIONS['read']]));

        return null;
    }
}
