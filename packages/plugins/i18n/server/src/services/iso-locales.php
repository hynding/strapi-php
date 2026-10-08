<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Services;

use Strapi\Plugin\I18n\Constants\Constants;

/** Port of server/src/services/iso-locales.ts. */
final class IsoLocales
{
    /** @return list<array{code: string, name: string}> */
    public function getIsoLocales(): array
    {
        return Constants::isoLocales();
    }
}
