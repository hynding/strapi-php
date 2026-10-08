<?php

declare(strict_types=1);

namespace Strapi\Admin\Services\Transfer;

use Strapi\Core\Strapi;
use Strapi\Utils\Errors\NotImplementedError;

/**
 * PLACEHOLDER: not ported yet (services/transfer/token.ts). Only `checkSaltIsDefined()`, called
 * by the admin bootstrap, is ported; any other call throws {@see NotImplementedError}.
 */
final class Token
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function checkSaltIsDefined(): void
    {
        // Ignore the check if the data-transfer feature is manually disabled
        if (!$this->strapi->config()->get('server.transfer.remote.enabled')) {
            return;
        }

        // services/transfer/utils.ts hasValidTokenSalt()
        $salt = $this->strapi->config()->get('admin.transfer.token.salt');
        if (!is_string($salt) || $salt === '') {
            $this->strapi->log()->warning("Missing transfer.token.salt: Data transfer features have been disabled.\nPlease set transfer.token.salt in config/admin.js (ex: you can generate one using Node with `crypto.randomBytes(16).toString('base64')`)\nFor security reasons, prefer storing the secret in an environment variable and read it in config/admin.js. See https://docs.strapi.io/developer-docs/latest/setup-deployment-guides/configurations/optional/environment.html#configuration-using-environment-variables.");
        }
    }

    /** @param list<mixed> $args */
    public function __call(string $name, array $args): never
    {
        throw new NotImplementedError("admin::transfer token.{$name}() is not ported yet");
    }
}
