<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services;

use Strapi\ContentManager\Services\Utils\Configuration\Configuration as ConfigurationUtils;
use Strapi\ContentManager\Services\Utils\Store;
use Strapi\Core\Strapi;

/**
 * Port of server/src/services/configuration.ts (`createConfigurationService({ isComponent, prefix,
 * storeUtils, getModels })`). `storeUtils` is {@see Store}; `getModels` returns the
 * content-manager models by uid.
 *
 * @phpstan-type ConfigurationUpdate array{settings?: array<string, mixed>|null, metadatas?: array<string, mixed>|null, layouts?: array<string, mixed>|null, options?: array<string, mixed>}
 */
final class Configuration
{
    /** @var \Closure(): array<string, array<string, mixed>> */
    private readonly \Closure $getModels;

    /** @param callable(): array<string, array<string, mixed>> $getModels */
    public function __construct(
        private readonly Strapi $strapi,
        private readonly string $prefix,
        callable $getModels,
        private readonly ?bool $isComponent = null,
    ) {
        $this->getModels = $getModels(...);
    }

    private function uidToStoreKey(string $uid): string
    {
        return "{$this->prefix}::{$uid}";
    }

    /** @return array<string, mixed> */
    public function getConfiguration(string $uid): array
    {
        $storeKey = $this->uidToStoreKey($uid);

        return Store::getModelConfiguration($this->strapi, $storeKey);
    }

    /** @param array<string, mixed> $input */
    public function setConfiguration(string $uid, array $input): void
    {
        $configuration = [
            ...$input,
            'uid' => $uid,
            'isComponent' => $this->isComponent,
        ];

        $storeKey = $this->uidToStoreKey($uid);
        Store::setModelConfiguration($this->strapi, $storeKey, $configuration);
    }

    public function deleteConfiguration(string $uid): void
    {
        $storeKey = $this->uidToStoreKey($uid);

        Store::deleteKey($this->strapi, $storeKey);
    }

    public function syncConfigurations(): void
    {
        $models = ($this->getModels)();

        $configurations = Store::findByKey($this->strapi, "plugin_content_manager_configuration_{$this->prefix}");

        $updateConfiguration = function (string $uid) use ($configurations, $models): void {
            $conf = null;
            foreach ($configurations as $candidate) {
                if (is_array($candidate) && ($candidate['uid'] ?? null) === $uid) {
                    $conf = $candidate;
                    break;
                }
            }

            $this->setConfiguration($uid, ConfigurationUtils::syncConfiguration($this->strapi, $conf ?? [], $models[$uid]));
        };

        $generateNewConfiguration = function (string $uid) use ($models): void {
            $this->setConfiguration($uid, ConfigurationUtils::createDefaultConfiguration($this->strapi, $models[$uid]));
        };

        $currentUIDS = array_map('strval', array_keys($models));
        $DBUIDs = array_map(static fn (mixed $conf): string => (string) (is_array($conf) ? ($conf['uid'] ?? '') : ''), $configurations);

        $contentTypesToUpdate = array_values(array_unique(array_intersect($currentUIDS, $DBUIDs)));
        $contentTypesToAdd = array_values(array_diff($currentUIDS, $DBUIDs));
        $contentTypesToDelete = array_values(array_diff($DBUIDs, $currentUIDS));

        // delete old schemas
        foreach ($contentTypesToDelete as $uid) {
            $this->deleteConfiguration($uid);
        }

        // create new schemas
        foreach ($contentTypesToAdd as $uid) {
            $generateNewConfiguration($uid);
        }

        // update current schemas
        foreach ($contentTypesToUpdate as $uid) {
            $updateConfiguration($uid);
        }
    }
}
