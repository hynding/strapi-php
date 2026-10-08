<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Extension;

/**
 * Port of server/src/services/extension/shadow-crud-manager.ts: `createShadowCRUDManager()`
 * returns `(uid) => contentTypeShadowCRUD`; here the manager is invokable and keeps the
 * per-content-type configurations.
 *
 * @phpstan-type FieldConfig array{enabled: bool, input: bool, output: bool, filters: bool}
 * @phpstan-type ContentTypeConfig array{enabled: bool, mutations: bool, queries: bool, disabledActions: list<string>, fields: array<string, FieldConfig>}
 */
final class ShadowCrudManager
{
    public const string ALL_ACTIONS = '*';

    /** @var array<string, ContentTypeConfig> */
    private array $configs = [];

    /** @return ContentTypeConfig */
    public static function getDefaultContentTypeConfig(): array
    {
        return [
            'enabled' => true,

            'mutations' => true,
            'queries' => true,

            'disabledActions' => [],
            'fields' => [],
        ];
    }

    /** @return FieldConfig */
    public static function getDefaultFieldConfig(): array
    {
        return [
            'enabled' => true,

            'input' => true,
            'output' => true,

            'filters' => true,
        ];
    }

    public function __invoke(string $uid): ShadowCrudContentType
    {
        if (!array_key_exists($uid, $this->configs)) {
            $this->configs[$uid] = self::getDefaultContentTypeConfig();
        }

        return new ShadowCrudContentType($this, $uid);
    }

    /**
     * @internal
     * @return ContentTypeConfig
     */
    public function &config(string $uid): array
    {
        if (!array_key_exists($uid, $this->configs)) {
            $this->configs[$uid] = self::getDefaultContentTypeConfig();
        }

        return $this->configs[$uid];
    }
}
