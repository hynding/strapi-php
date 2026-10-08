<?php

declare(strict_types=1);

namespace Strapi\Admin\Services\Transfer;

use Strapi\Core\Strapi;

/** Port of server/src/services/transfer/utils.ts. */
final class Utils
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * A valid transfer token salt must be a non-empty string defined in the Strapi config
     */
    public function hasValidTokenSalt(): bool
    {
        $salt = $this->strapi->config()->get('admin.transfer.token.salt', null);

        return is_string($salt) && strlen($salt) > 0;
    }

    /**
     * Checks whether data transfer features are enabled
     */
    public function isRemoteTransferEnabled(): bool
    {
        // TODO v6: Remove this warning
        if ($this->strapi->env()->bool('STRAPI_DISABLE_REMOTE_DATA_TRANSFER') !== null) {
            $this->strapi->log()->warning('STRAPI_DISABLE_REMOTE_DATA_TRANSFER is no longer supported. Instead, set transfer.remote.enabled to false in your server configuration');
        }

        return $this->hasValidTokenSalt() && (bool) $this->strapi->config()->get('server.transfer.remote.enabled');
    }
}
