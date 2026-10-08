<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Controllers;

use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Utils\Utils;
use Strapi\Types\Core\Context;

/** Port of server/src/controllers/iso-locales.ts. */
final class IsoLocales
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function listIsoLocales(Context $ctx): void
    {
        $isoLocalesService = Utils::isoLocales($this->strapi);

        $ctx->setBody($isoLocalesService->getIsoLocales());
    }
}
