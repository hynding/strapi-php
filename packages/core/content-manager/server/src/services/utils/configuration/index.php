<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services\Utils\Configuration;

use Strapi\ContentManager\Controllers\Validation\Validation;
use Strapi\Core\Strapi;

/** Port of server/src/services/utils/configuration/index.ts. */
final class Configuration
{
    /** @param array<string, mixed> $schema */
    private static function validateCustomConfig(Strapi $strapi, array $schema): void
    {
        try {
            Validation::createModelConfigurationSchema($strapi, $schema, ['allowUndefined' => true])
                ->validate($schema['config'] ?? \Strapi\Utils\Yup\Undefined::value());
        } catch (\Throwable $error) {
            $uid = (string) ($schema['uid'] ?? '');

            throw new \RuntimeException(
                "Invalid Model configuration for model {$uid}. Verify your {{ modelName }}.config.js(on) file:\n  - {$error->getMessage()}\n",
                0,
                $error,
            );
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @return array{settings: array<string, mixed>, metadatas: array<string, mixed>, layouts: array<string, mixed>}
     */
    public static function createDefaultConfiguration(Strapi $strapi, array $schema): array
    {
        self::validateCustomConfig($strapi, $schema);

        return [
            'settings' => Settings::createDefaultSettings($schema),
            'metadatas' => Metadatas::createDefaultMetadatas($strapi, $schema),
            'layouts' => Layouts::createDefaultLayouts($strapi, $schema),
        ];
    }

    /**
     * @param array<string, mixed> $conf
     * @param array<string, mixed> $schema
     * @return array{settings: array<string, mixed>, layouts: array<string, mixed>, metadatas: array<string, mixed>}
     */
    public static function syncConfiguration(Strapi $strapi, array $conf, array $schema): array
    {
        self::validateCustomConfig($strapi, $schema);

        return [
            'settings' => Settings::syncSettings($strapi, $conf, $schema),
            'layouts' => Layouts::syncLayouts($strapi, $conf, $schema),
            'metadatas' => Metadatas::syncMetadatas($strapi, $conf, $schema),
        ];
    }
}
