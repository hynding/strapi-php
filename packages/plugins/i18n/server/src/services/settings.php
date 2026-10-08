<?php

declare(strict_types=1);

namespace Strapi\Plugin\I18n\Services;

use Strapi\Core\Services\ScopedCoreStore;
use Strapi\Core\Strapi;

/** Port of server/src/services/settings.ts (`createSettingsService`). */
final class Settings
{
    private readonly ScopedCoreStore $settings;

    public function __construct(Strapi $strapi)
    {
        $this->settings = $strapi->store()->scoped(['type' => 'plugin', 'name' => 'i18n', 'key' => 'settings']);
    }

    /** @return array<string, mixed>|null */
    public function getSettings(): ?array
    {
        $res = $this->settings->get(['key' => 'settings']);

        return is_array($res) ? $res : null;
    }

    /** @param array<string, mixed> $value */
    public function setSettings(array $value): void
    {
        $this->settings->set(['key' => 'settings', 'value' => $value]);
    }
}
