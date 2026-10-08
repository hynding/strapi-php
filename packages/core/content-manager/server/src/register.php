<?php

declare(strict_types=1);

namespace Strapi\ContentManager;

use Strapi\Core\Strapi;

/**
 * Port of server/src/register.ts.
 *
 * Upstream runs `history.register` (registers the `plugin::content-manager.history-version`
 * model) and `preview.register` (validates and registers the preview config). Both live in
 * directories under Strapi's Enterprise licence and are not ported: nothing to register.
 */
final class Register
{
    public function __invoke(Strapi $strapi): void
    {
    }
}
