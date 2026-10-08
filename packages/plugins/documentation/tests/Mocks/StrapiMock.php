<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Tests\Mocks;

use Strapi\Types\Core\StrapiDirectories;
use Strapi\Types\Schema\Schema;

/**
 * The `global.strapi` stand-in of the upstream service tests: `config.get()`, `dirs`,
 * `contentType(uid)` (unmocked uids get empty attributes), `components`, `plugin(name)`,
 * `api(name)`, `apis`, plus an in-memory `store`.
 */
final class StrapiMock
{
    /** @var \Closure(string): mixed */
    public \Closure $configGet;

    public string $environment = 'development';

    public StrapiDirectories $dirs;

    /** @var array<string, Schema> */
    private array $contentTypes = [];

    /** @var array<string, Schema> */
    private array $components = [];

    /** @var array<string, object> */
    private array $plugins = [];

    /** @var array<string, object> */
    private array $apis = [];

    /** @var array<string, object> plugin => service name => instance */
    public array $documentationServices = [];

    /** @var array<string, mixed> */
    public array $storeData = [];

    /**
     * @param array<string, array<string, mixed>> $contentTypes
     * @param array<string, array<string, mixed>> $components
     * @param array<string, array<string, mixed>> $plugins
     * @param array<string, array<string, mixed>> $apis
     */
    public function __construct(array $contentTypes = [], array $components = [], array $plugins = [], array $apis = [], ?string $root = null)
    {
        $this->configGet = static fn (string $path): mixed => null;
        $this->dirs = StrapiDirectories::fromRoot($root ?? sys_get_temp_dir() . '/strapi-documentation-test');
        foreach ($contentTypes as $uid => $schema) {
            $this->contentTypes[$uid] = self::schema($uid, $schema);
        }
        foreach ($components as $uid => $schema) {
            $this->components[$uid] = self::schema($uid, $schema);
        }
        foreach ($plugins as $name => $plugin) {
            $this->plugins[$name] = $this->module($plugin, $name);
        }
        foreach ($apis as $name => $api) {
            $this->apis[$name] = $this->module($api, null);
        }
    }

    /** @param array<string, mixed> $schema */
    public static function schema(string $uid, array $schema): Schema
    {
        return new Schema(
            uid: (string) ($schema['uid'] ?? $uid),
            modelType: (string) ($schema['modelType'] ?? 'contentType'),
            kind: $schema['kind'] ?? null,
            modelName: (string) ($schema['modelName'] ?? ''),
            globalId: (string) ($schema['globalId'] ?? ''),
            collectionName: (string) ($schema['collectionName'] ?? ''),
            plugin: $schema['plugin'] ?? null,
            apiName: $schema['apiName'] ?? null,
            category: $schema['category'] ?? null,
            info: $schema['info'] ?? [],
            options: $schema['options'] ?? [],
            pluginOptions: $schema['pluginOptions'] ?? [],
            attributes: $schema['attributes'] ?? [],
        );
    }

    /** @param array<string, mixed> $raw */
    private function module(array $raw, ?string $pluginName): object
    {
        $strapi = $this;
        $contentTypes = array_map(static fn (array $schema): Schema => self::schema('', $schema), $raw['contentTypes'] ?? []);

        return new class ($raw['routes'] ?? [], $contentTypes, $strapi, $pluginName) {
            /**
             * @param array<array-key, mixed> $routes
             * @param array<string, Schema> $contentTypes
             */
            public function __construct(
                private readonly array $routes,
                private readonly array $contentTypes,
                private readonly StrapiMock $strapi,
                private readonly ?string $pluginName,
            ) {
            }

            /** @return array<array-key, mixed> */
            public function routes(): array
            {
                return $this->routes;
            }

            /** @return array<string, Schema> */
            public function contentTypes(): array
            {
                return $this->contentTypes;
            }

            public function service(string $name): object
            {
                return $this->strapi->documentationServices[$name] ?? throw new \RuntimeException("Service plugin::{$this->pluginName}.{$name} not found");
            }
        };
    }

    public function config(): object
    {
        $strapi = $this;

        return new class ($strapi) {
            public function __construct(private readonly StrapiMock $strapi)
            {
            }

            public function get(string $path, mixed $default = null): mixed
            {
                if ($path === 'environment') {
                    return $this->strapi->environment;
                }

                return ($this->strapi->configGet)($path) ?? $default;
            }
        };
    }

    public function dirs(): StrapiDirectories
    {
        return $this->dirs;
    }

    public function contentType(string $uid): Schema
    {
        // Only deal with mocked data, return empty attributes for unmocked relations
        return $this->contentTypes[$uid] ?? self::schema($uid, ['attributes' => []]);
    }

    /** @return array<string, Schema> */
    public function components(): array
    {
        return $this->components;
    }

    public function plugin(string $name): object
    {
        if ($name === 'documentation') {
            return $this->plugins[$name] ??= $this->module([], 'documentation');
        }

        return $this->plugins[$name] ?? throw new \RuntimeException("plugin {$name} not found");
    }

    public function api(string $name): object
    {
        return $this->apis[$name] ?? throw new \RuntimeException("api {$name} not found");
    }

    /** @return array<string, object> */
    public function apis(): array
    {
        return $this->apis;
    }

    public function store(): object
    {
        $strapi = $this;

        return new class ($strapi) {
            public function __construct(private readonly StrapiMock $strapi)
            {
            }

            /** @param array<string, mixed> $params */
            public function get(array $params): mixed
            {
                return $this->strapi->storeData[(string) ($params['key'] ?? '')] ?? null;
            }
        };
    }
}
