<?php

declare(strict_types=1);

namespace Strapi\Admin\Services;

use Strapi\Core\Strapi;
use Strapi\Utils\Errors\NotImplementedError;

/**
 * PLACEHOLDER: not ported yet (services/api-token.ts). Only what the admin bootstrap and the
 * user/role lifecycles call is here:
 *
 * - `checkSaltIsDefined()` and `countAll()` are ported;
 * - `create()` (bootstrap's default tokens) is a no-op that logs, so no token row exists yet;
 * - `syncPermissionsForUser()`, `syncPermissionsForRole()`, `deleteTokensForUser()` are no-ops
 *   (nothing to sync while tokens cannot be created);
 * - every other method throws {@see NotImplementedError}.
 */
final class ApiToken
{
    public function __construct(private readonly Strapi $strapi, public readonly string $kind)
    {
    }

    public static function createTokenService(Strapi $strapi, string $kind): self
    {
        return new self($strapi, $kind);
    }

    public function checkSaltIsDefined(): void
    {
        $apiTokenCfg = $this->strapi->config()->get('admin.apiToken');
        if (!is_array($apiTokenCfg) || empty($apiTokenCfg['salt'])) {
            // TODO V5: stop reading API_TOKEN_SALT
            $envSalt = getenv('API_TOKEN_SALT');
            if (is_string($envSalt) && $envSalt !== '') {
                $this->strapi->log()->warning("[deprecated] In future versions, Strapi will stop reading directly from the environment variable API_TOKEN_SALT. Please set apiToken.salt in config/admin.js instead.\nFor security reasons, keep storing the secret in an environment variable and use env() to read it in config/admin.js (ex: `apiToken: { salt: env('API_TOKEN_SALT') }`). See https://docs.strapi.io/developer-docs/latest/setup-deployment-guides/configurations/optional/environment.html#configuration-using-environment-variables.");

                $this->strapi->config()->set('admin.apiToken.salt', $envSalt);
            } else {
                throw new \RuntimeException("Missing apiToken.salt. Please set apiToken.salt in config/admin.js (ex: you can generate one using Node with `crypto.randomBytes(16).toString('base64')`).\nFor security reasons, prefer storing the secret in an environment variable and read it in config/admin.js. See https://docs.strapi.io/developer-docs/latest/setup-deployment-guides/configurations/optional/environment.html#configuration-using-environment-variables.");
            }
        }
    }

    /** @param array<string, mixed> $where */
    public function countAll(array $where = []): int
    {
        return $this->strapi->db()->query('admin::api-token')->count(['where' => $where]);
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes, mixed $callingUser = null): mixed
    {
        $this->strapi->log()->debug('admin::api-token create() is not ported yet; token "' . ($attributes['name'] ?? '') . '" not created');

        return null;
    }

    public function syncPermissionsForUser(mixed $userId): void
    {
    }

    public function syncPermissionsForRole(mixed $roleId): void
    {
    }

    public function deleteTokensForUser(mixed $userId): void
    {
    }

    /** @param list<mixed> $args */
    public function __call(string $name, array $args): never
    {
        throw new NotImplementedError("admin::api-token {$name}() is not ported yet");
    }
}
