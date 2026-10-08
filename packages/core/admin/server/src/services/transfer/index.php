<?php

declare(strict_types=1);

namespace Strapi\Admin\Services\Transfer;

use Strapi\Core\Strapi;

/**
 * Port of server/src/services/transfer/index.ts: `admin::transfer` is
 * `{ permission, token, utils }`.
 */
final class Transfer
{
    public readonly Permission $permission;

    public readonly Token $token;

    public readonly Utils $utils;

    public function __construct(Strapi $strapi)
    {
        $this->permission = new Permission();
        $this->token = new Token($strapi);
        $this->utils = new Utils($strapi);
    }
}
