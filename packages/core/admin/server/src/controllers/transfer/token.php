<?php

declare(strict_types=1);

namespace Strapi\Admin\Controllers\Transfer;

use Strapi\Admin\Controllers\ApiToken;
use Strapi\Admin\Utils\Utils;
use Strapi\Admin\Validation\Transfer\Token as TokenValidation;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Primitives\Strings;

/** Port of server/src/controllers/transfer/token.ts. */
final class Token
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return array<string, mixed> */
    private static function body(Context $ctx): array
    {
        $body = $ctx->requestBody();

        return is_array($body) ? $body : [];
    }

    public function list(Context $ctx): mixed
    {
        $transferService = Utils::getService($this->strapi, 'transfer');
        $transferTokens = $transferService->token->list();

        $ctx->setBody(['data' => $transferTokens]);

        return null;
    }

    public function getById(Context $ctx): mixed
    {
        $id = $ctx->param('id');
        $tokenService = Utils::getService($this->strapi, 'transfer')->token;

        $transferToken = $tokenService->getById($id);

        if ($transferToken === null) {
            $ctx->notFound('Transfer token not found');

            return null;
        }

        $ctx->setBody(['data' => $transferToken]);

        return null;
    }

    public function create(Context $ctx): mixed
    {
        $body = self::body($ctx);
        $tokenService = Utils::getService($this->strapi, 'transfer')->token;

        /**
         * We trim fields to avoid having issues with either:
         * - having a space at the end or start of the value
         * - having only spaces as value (so that an empty field can be caught in validation)
         */
        $attributes = [
            'name' => ApiToken::trim($body['name'] ?? null),
            'description' => ApiToken::trim($body['description'] ?? null),
            ...(array_key_exists('permissions', $body) ? ['permissions' => $body['permissions']] : []),
            ...(array_key_exists('lifespan', $body) ? ['lifespan' => $body['lifespan']] : []),
        ];

        TokenValidation::validateTransferTokenCreationInput($attributes);

        $alreadyExists = $tokenService->exists(['name' => $attributes['name']]);
        if ($alreadyExists) {
            throw new ApplicationError('Name already taken');
        }

        $transferTokens = $tokenService->create($attributes);

        $ctx->created(['data' => $transferTokens]);

        return null;
    }

    public function update(Context $ctx): mixed
    {
        $attributes = self::body($ctx);
        $id = $ctx->param('id');
        $tokenService = Utils::getService($this->strapi, 'transfer')->token;

        /**
         * We trim fields to avoid having issues with either:
         * - having a space at the end or start of the value
         * - having only spaces as value (so that an empty field can be caught in validation)
         */
        if (array_key_exists('name', $attributes)) {
            $attributes['name'] = ApiToken::trim($attributes['name']);
        }

        if (array_key_exists('description', $attributes)) {
            $attributes['description'] = ApiToken::trim($attributes['description']);
        }

        TokenValidation::validateTransferTokenUpdateInput($attributes);

        $apiTokenExists = $tokenService->getById($id);
        if ($apiTokenExists === null) {
            $ctx->notFound('Transfer token not found');

            return null;
        }

        if (array_key_exists('name', $attributes)) {
            $nameAlreadyTaken = $tokenService->getByName($attributes['name']);

            /**
             * We cast the ids as string as the one coming from the ctx isn't cast
             * as a Number in case it is supposed to be an integer. It remains
             * as a string. This way we avoid issues with integers in the db.
             */
            if ($nameAlreadyTaken !== null && !Strings::isEqual($nameAlreadyTaken['id'] ?? null, $id)) {
                throw new ApplicationError('Name already taken');
            }
        }

        $apiToken = $tokenService->update($id, $attributes);

        $ctx->setBody(['data' => $apiToken]);

        return null;
    }

    public function revoke(Context $ctx): mixed
    {
        $id = $ctx->param('id');
        $tokenService = Utils::getService($this->strapi, 'transfer')->token;

        $transferToken = $tokenService->revoke($id);

        $ctx->deleted(['data' => $transferToken]);

        return null;
    }

    public function regenerate(Context $ctx): mixed
    {
        $id = $ctx->param('id');
        $tokenService = Utils::getService($this->strapi, 'transfer')->token;

        $exists = $tokenService->getById($id);
        if ($exists === null) {
            $ctx->notFound('Transfer token not found');

            return null;
        }

        $accessToken = $tokenService->regenerate($id);

        $ctx->created(['data' => $accessToken]);

        return null;
    }
}
