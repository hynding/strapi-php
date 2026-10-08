<?php

declare(strict_types=1);

namespace Strapi\DataTransfer\Strapi\Providers\LocalSource;

use Strapi\Core\Strapi;
use Strapi\DataTransfer\Strapi\Queries\Stream as QueryStream;
use Strapi\DataTransfer\Strapi\Utils\ProjectSettingsLogos;

/**
 * Port of src/strapi/providers/local-source/configuration.ts.
 */
final class Configuration
{
    /**
     * Create a readable stream that export the Strapi app configuration
     *
     * @return \Generator<int, array{type: string, value: mixed}>
     */
    public static function createConfigurationStream(Strapi $strapi): \Generator
    {
        // Core Store
        foreach (QueryStream::rows($strapi, 'strapi::core-store') as $data) {
            $value = $data['value'] ?? null;
            if (is_string($value)) {
                $data['value'] = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            }

            yield [
                'type' => 'core-store',
                'value' => ProjectSettingsLogos::enrichProjectSettingsForExport($strapi, $data),
            ];
        }

        // Webhook
        foreach (QueryStream::rows($strapi, 'strapi::webhook') as $data) {
            yield ['type' => 'webhook', 'value' => $data];
        }
    }
}
