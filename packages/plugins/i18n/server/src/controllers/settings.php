<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Controllers;

use Strapi\Core\Strapi;
use Strapi\Plugin\I18n\Utils\Utils;
use Strapi\Plugin\I18n\Validation\Settings as SettingsValidation;
use Strapi\Types\Core\Context;

/** Port of server/src/controllers/settings.ts. */
final class Settings
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public function updateSettings(Context $ctx): void
    {
        $body = $ctx->requestBody();

        $data = SettingsValidation::validateSettings($body);

        Utils::settings($this->strapi)->setSettings(is_array($data) ? $data : []);

        $ctx->setBody(['data' => $data]);
    }

    public function getSettings(Context $ctx): void
    {
        $settings = Utils::settings($this->strapi)->getSettings();

        $ctx->setBody([
            'data' => [
                ...($settings ?? []),
                'aiLocalizationsAvailable' => Utils::aiTranslations($this->strapi)->hasProvider(),
            ],
        ]);
    }
}
