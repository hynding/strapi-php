<?php

declare(strict_types=1);

namespace Strapi\Admin\Controllers;

use Strapi\Admin\Utils\Utils;
use Strapi\Admin\Validation\ApiTokens;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ApplicationError;
use Strapi\Utils\Primitives\Strings;

/** Port of server/src/controllers/api-token.ts (`admin::api-token`): content-api tokens. */
final class ApiToken
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** lodash `trim(value)`: `toString(value)` then strip leading and trailing whitespace. */
    public static function trim(mixed $value): string
    {
        $string = match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            is_array($value) => implode(',', array_map(static fn (mixed $v): string => self::trim($v), $value)),
            default => '',
        };

        return (string) preg_replace('/^[\s\x{00A0}\x{FEFF}\x{2028}\x{2029}]+|[\s\x{00A0}\x{FEFF}\x{2028}\x{2029}]+$/u', '', $string);
    }

    /** @return array<string, mixed> */
    private static function body(Context $ctx): array
    {
        $body = $ctx->requestBody();

        return is_array($body) ? $body : [];
    }

    /** @return array<string, mixed>|null */
    private static function user(Context $ctx): ?array
    {
        $user = $ctx->state()->get('user');

        return is_array($user) ? $user : null;
    }

    // -------------------------------------------------------------------------
    // Create
    // -------------------------------------------------------------------------
    public function create(Context $ctx): mixed
    {
        $body = self::body($ctx);
        $apiTokenService = Utils::getService($this->strapi, 'api-token-content-api');

        // `undefined` properties are left out, as JSON drops them
        $attributes = [
            'kind' => 'content-api',
            'name' => self::trim($body['name'] ?? null),
            'description' => self::trim($body['description'] ?? null),
            ...(array_key_exists('type', $body) ? ['type' => $body['type']] : []),
            ...(array_key_exists('permissions', $body) ? ['permissions' => $body['permissions']] : []),
            ...(array_key_exists('lifespan', $body) ? ['lifespan' => $body['lifespan']] : []),
        ];

        ApiTokens::validateApiTokenCreationInput($attributes);

        $alreadyExists = $apiTokenService->exists(['name' => $attributes['name']]);
        if ($alreadyExists) {
            throw new ApplicationError('Name already taken');
        }

        $apiToken = $apiTokenService->create($attributes, self::user($ctx));
        $ctx->created(['data' => $apiToken]);

        return null;
    }

    // -------------------------------------------------------------------------
    // Regenerate
    // -------------------------------------------------------------------------
    public function regenerate(Context $ctx): mixed
    {
        $id = $ctx->param('id');
        $apiTokenService = Utils::getService($this->strapi, 'api-token-content-api');

        $token = $apiTokenService->getById($id);
        if ($token === null) {
            $ctx->notFound('API Token not found');

            return null;
        }

        $accessToken = $apiTokenService->regenerate($id);
        $ctx->created(['data' => $accessToken]);

        return null;
    }

    // -------------------------------------------------------------------------
    // List — always content-api
    // -------------------------------------------------------------------------
    public function list(Context $ctx): mixed
    {
        $apiTokenService = Utils::getService($this->strapi, 'api-token-content-api');
        $apiTokens = $apiTokenService->list(self::user($ctx) ?? []);

        $ctx->send(['data' => $apiTokens]);

        return null;
    }

    // -------------------------------------------------------------------------
    // Revoke
    // -------------------------------------------------------------------------
    public function revoke(Context $ctx): mixed
    {
        $id = $ctx->param('id');
        $apiTokenService = Utils::getService($this->strapi, 'api-token-content-api');
        $apiToken = $apiTokenService->revoke($id);

        $ctx->deleted(['data' => $apiToken]);

        return null;
    }

    // -------------------------------------------------------------------------
    // Get — always expose the decrypted key (content-api back-compat)
    // -------------------------------------------------------------------------
    public function get(Context $ctx): mixed
    {
        $id = $ctx->param('id');
        $apiTokenService = Utils::getService($this->strapi, 'api-token-content-api');

        $token = $apiTokenService->getById($id, ['includeDecryptedKey' => true]);
        if ($token === null) {
            $ctx->notFound('API Token not found');

            return null;
        }

        $ctx->send(['data' => $token]);

        return null;
    }

    // -------------------------------------------------------------------------
    // Update
    // -------------------------------------------------------------------------
    public function update(Context $ctx): mixed
    {
        $body = self::body($ctx);
        $id = $ctx->param('id');
        $apiTokenService = Utils::getService($this->strapi, 'api-token-content-api');

        if (array_key_exists('name', $body)) {
            $body['name'] = self::trim($body['name'] ?? '');
        }
        if (array_key_exists('description', $body)) {
            $body['description'] = self::trim($body['description'] ?? '');
        }

        ApiTokens::validateApiTokenUpdateInput($body);

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

        $apiToken = $apiTokenService->update($id, $body);
        $ctx->send(['data' => $apiToken]);

        return null;
    }
}
