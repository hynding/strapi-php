<?php

declare(strict_types=1);

namespace Strapi\Admin\Controllers;

use Strapi\Admin\Utils\NormalizeEmail;
use Strapi\Admin\Utils\Utils;
use Strapi\Admin\Validation\User as UserValidation;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Primitives\Objects;

/** Port of server/src/controllers/user.ts (`admin::user`). */
final class User
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function create(Context $ctx): mixed
    {
        $body = $ctx->requestBody();
        $body = is_array($body) ? $body : [];
        $email = $body['email'] ?? '';
        $cleanData = [...$body, 'email' => strtolower(is_string($email) ? $email : (string) json_encode($email))];

        UserValidation::validateUserCreationInput($cleanData);

        /** @var array<string, mixed> $attributes */
        $attributes = Objects::pick($cleanData, ['firstname', 'lastname', 'email', 'roles', 'preferedLanguage']);

        $userAlreadyExists = Utils::getService($this->strapi, 'user')->exists([
            'email' => $attributes['email'],
        ]);

        if ($userAlreadyExists) {
            throw new ApplicationError('Email already taken');
        }

        $createdUser = Utils::getService($this->strapi, 'user')->create($attributes);

        $userInfo = Utils::getService($this->strapi, 'user')->sanitizeUser($createdUser);

        // Note: We need to assign manually the registrationToken to the
        // final user payload so that it's not removed in the sanitation process.
        $userInfo['registrationToken'] = $createdUser['registrationToken'] ?? null;

        // Send 201 created
        $ctx->created(['data' => $userInfo]);

        return null;
    }

    public function find(Context $ctx): mixed
    {
        $userService = Utils::getService($this->strapi, 'user');

        $permissionsManager = Utils::getService($this->strapi, 'permission')->createPermissionsManager([
            'ability' => $ctx->state()->get('userAbility'),
            'model' => 'admin::user',
        ]);

        $permissionsManager->validateQuery($ctx->query());
        $sanitizedQuery = $permissionsManager->sanitizeQuery($ctx->query());

        ['results' => $results, 'pagination' => $pagination] = $userService->findPage(is_array($sanitizedQuery) ? $sanitizedQuery : []);

        $ctx->setBody([
            'data' => [
                'results' => array_map(static fn (array $user): array => $userService->sanitizeUser($user), $results),
                'pagination' => $pagination,
            ],
        ]);

        return null;
    }

    public function findOne(Context $ctx): mixed
    {
        $id = $ctx->param('id');

        $user = Utils::getService($this->strapi, 'user')->findOne($id);

        if ($user === null) {
            $ctx->notFound('User does not exist');

            return null;
        }

        $ctx->setBody([
            'data' => Utils::getService($this->strapi, 'user')->sanitizeUser($user),
        ]);

        return null;
    }

    public function update(Context $ctx): mixed
    {
        $id = $ctx->param('id');
        $data = NormalizeEmail::normalizeEmail($ctx->requestBody());

        UserValidation::validateUserUpdateInput($data);

        $data = is_array($data) ? $data : [];

        if (array_key_exists('email', $data)) {
            $uniqueEmailCheck = Utils::getService($this->strapi, 'user')->exists([
                'id' => ['$ne' => $id],
                'email' => $data['email'],
            ]);

            if ($uniqueEmailCheck) {
                throw new ApplicationError('A user with this email address already exists');
            }
        }

        $updatedUser = Utils::getService($this->strapi, 'user')->updateById($id, $data);

        if ($updatedUser === null) {
            $ctx->notFound('User does not exist');

            return null;
        }

        $ctx->setBody([
            'data' => Utils::getService($this->strapi, 'user')->sanitizeUser($updatedUser),
        ]);

        return null;
    }

    public function deleteOne(Context $ctx): mixed
    {
        $id = $ctx->param('id');
        $user = $ctx->state()->get('user');

        // upstream compares with `===`: the route param is a string, the user id a number
        if (is_array($user) && ($user['id'] ?? null) === $id) {
            throw new ApplicationError('You cannot delete your own user');
        }

        $deletedUser = Utils::getService($this->strapi, 'user')->deleteById($id);

        if ($deletedUser === null) {
            $ctx->notFound('User not found');

            return null;
        }

        $ctx->deleted([
            'data' => Utils::getService($this->strapi, 'user')->sanitizeUser($deletedUser),
        ]);

        return null;
    }

    /** Delete several users */
    public function deleteMany(Context $ctx): mixed
    {
        $body = $ctx->requestBody();
        $user = $ctx->state()->get('user');
        UserValidation::validateUsersDeleteInput($body);
        $ids = is_array($body) && is_array($body['ids'] ?? null) ? array_values($body['ids']) : [];

        // Prevent self-deletion
        if (is_array($user) && in_array($user['id'] ?? null, $ids, true)) {
            throw new ApplicationError('You cannot delete your own user');
        }
        $users = Utils::getService($this->strapi, 'user')->deleteByIds($ids);

        $userService = Utils::getService($this->strapi, 'user');
        $sanitizedUsers = array_map(static fn (array $deleted): array => $userService->sanitizeUser($deleted), $users);

        $ctx->deleted([
            'data' => $sanitizedUsers,
        ]);

        return null;
    }
}
