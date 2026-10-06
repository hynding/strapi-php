<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/services/features.ts: `strapi.features` reads `config/features.php`.
 * EE-only feature flags are never enabled (the licence module is not ported).
 */
final class Features
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    public static function createFeaturesService(Strapi $strapi): self
    {
        return new self($strapi);
    }

    /** @return array<string, mixed> */
    public function config(): array
    {
        $config = $this->strapi->config()->get('features');

        return is_array($config) ? $config : [];
    }

    public function futureIsEnabled(string $futureFlagName): bool
    {
        return ($this->config()['future'][$futureFlagName] ?? null) === true;
    }

    public function isEnabled(string $flagName): bool
    {
        return ($this->config()[$flagName] ?? null) === true;
    }
}
