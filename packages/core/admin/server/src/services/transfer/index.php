<?php

declare(strict_types=1);

namespace Strapi\Admin\Services\Transfer;

use Strapi\Core\Strapi;
use Strapi\Utils\Errors\NotImplementedError;

/**
 * PLACEHOLDER: not ported yet (services/transfer/index.ts: `{ permission, token, utils }`).
 * Only `token` ({@see Token}, itself a placeholder) is exposed.
 */
final class Transfer
{
    public readonly Token $token;

    public function __construct(Strapi $strapi)
    {
        $this->token = new Token($strapi);
    }

    public function __get(string $name): never
    {
        throw new NotImplementedError("admin::transfer {$name} is not ported yet");
    }
}
