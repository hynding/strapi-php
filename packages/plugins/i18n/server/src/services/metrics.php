<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Services;

use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Utils\Utils;

/** Port of server/src/services/metrics.ts. Telemetry failures are ignored (`.catch(() => {})`). */
final class Metrics
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function sendDidInitializeEvent(): void
    {
        $contentTypes = Utils::contentTypes($this->strapi);

        // TODO: V5: This event should be renamed numberOfContentTypes in V5 as the name is already taken to describe the number of content types using i18n.
        $numberOfContentTypes = 0;
        foreach ($this->strapi->contentTypes() as $contentType) {
            if ($contentTypes->isLocalizedContentType($contentType)) {
                ++$numberOfContentTypes;
            }
        }

        try {
            $this->strapi->telemetry()->send('didInitializeI18n', ['groupProperties' => ['numberOfContentTypes' => $numberOfContentTypes]]);
        } catch (\Throwable) {
        }
    }

    public function sendDidUpdateI18nLocalesEvent(): void
    {
        $numberOfLocales = Utils::locales($this->strapi)->count();

        try {
            $this->strapi->telemetry()->send('didUpdateI18nLocales', [
                'groupProperties' => ['numberOfLocales' => $numberOfLocales],
            ]);
        } catch (\Throwable) {
        }
    }
}
