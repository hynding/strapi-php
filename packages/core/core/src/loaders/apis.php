<?php

declare(strict_types=1);

namespace Strapi\Core\Loaders;

use Strapi\Core\Domain\ContentType\ContentType;
use Strapi\Core\Strapi;
use Strapi\Database\Utils\LodashWords;
use Strapi\Utils\Primitives\Strings;

/**
 * Port of packages/core/core/src/loaders/apis.ts: scans `src/api/<name>/` for
 * `index.php`, `config/*`, `routes/*`, `controllers/*`, `services/*`, `policies/*`, `middlewares/*`
 * and `content-types/<ct>/{schema.json,lifecycles.php}` and registers each API.
 */
final class Apis
{
    private const DEFAULT_CONTENT_TYPE = ['schema' => [], 'actions' => [], 'lifecycles' => []];

    public function __invoke(Strapi $strapi): void
    {
        self::loadAPIs($strapi);
    }

    public static function loadAPIs(Strapi $strapi): void
    {
        $apiDir = $strapi->dirs()->api;
        if (!is_dir($apiDir)) {
            return;
        }

        $entries = scandir($apiDir) ?: [];
        sort($entries);
        $apis = [];

        // only load folders
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.') || !is_dir($apiDir . '/' . $entry)) {
                continue;
            }

            $apiName = self::normalizeName($entry);
            $apis[$apiName] = self::loadAPI($apiName, $apiDir . '/' . $entry);
        }

        self::validateContentTypesUnicity($apis);

        foreach ($apis as $apiName => $api) {
            $strapi->get('apis')->add((string) $apiName, $api);
        }
    }

    // to handle names with numbers in it we first check if it is already in kebabCase
    public static function normalizeName(string $name): string
    {
        return Strings::isKebabCase($name) ? $name : LodashWords::kebabCase($name);
    }

    /** @param array<string, array<string, mixed>> $apis */
    private static function validateContentTypesUnicity(array $apis): void
    {
        $names = [];
        foreach ($apis as $api) {
            foreach ($api['contentTypes'] as $definition) {
                $schema = $definition['schema'];
                $singularName = $schema['info']['singularName'] ?? null;
                if (is_string($singularName) && $singularName !== '') {
                    $kebab = LodashWords::kebabCase($singularName);
                    if (in_array($kebab, $names, true)) {
                        throw new \RuntimeException("The singular name \"{$singularName}\" should be unique");
                    }
                    $names[] = $kebab;
                }

                $pluralName = $schema['info']['pluralName'] ?? null;
                if (is_string($pluralName) && $pluralName !== '') {
                    $kebab = LodashWords::kebabCase($pluralName);
                    if (in_array($kebab, $names, true)) {
                        throw new \RuntimeException("The plural name \"{$pluralName}\" should be unique");
                    }
                    $names[] = $kebab;
                }
            }
        }
    }

    /** @return array<string, mixed> */
    private static function loadAPI(string $apiName, string $dir): array
    {
        $index = self::loadIndex($dir);
        $apiIndex = is_array($index) ? $index : [];

        return [
            ...$apiIndex,
            'config' => self::loadDir($dir . '/config') ?? [],
            'routes' => self::loadDir($dir . '/routes') ?? [],
            'controllers' => self::loadDir($dir . '/controllers') ?? [],
            'services' => self::loadDir($dir . '/services') ?? [],
            'policies' => self::loadDir($dir . '/policies') ?? [],
            'middlewares' => self::loadDir($dir . '/middlewares') ?? [],
            'contentTypes' => self::loadContentTypes($apiName, $dir . '/content-types') ?? [],
        ];
    }

    private static function loadIndex(string $dir): mixed
    {
        if (is_file($dir . '/index.php')) {
            return self::loadFile($dir . '/index.php');
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>|null `{ schema, actions, lifecycles, ...other files }` per content type
     */
    private static function loadContentTypes(string $apiName, string $dir): ?array
    {
        if (!is_dir($dir)) {
            return null;
        }

        $contentTypes = [];
        $entries = scandir($dir) ?: [];
        sort($entries);

        // only load folders
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($dir . '/' . $entry)) {
                continue;
            }

            $contentTypeName = self::normalizeName($entry);
            $loadedContentType = self::loadDir($dir . '/' . $entry);

            $schema = $loadedContentType['schema'] ?? null;
            if (empty($loadedContentType) || !is_array($schema) || $schema === []) {
                throw new \RuntimeException("Could not load content type found at {$dir}");
            }

            $actions = $loadedContentType['actions'] ?? self::DEFAULT_CONTENT_TYPE['actions'];
            $lifecycles = $loadedContentType['lifecycles'] ?? self::DEFAULT_CONTENT_TYPE['lifecycles'];
            $contentType = [
                ...$loadedContentType,
                'actions' => is_array($actions) ? $actions : [],
                'lifecycles' => is_array($lifecycles) ? $lifecycles : [],
            ];

            $schema['apiName'] = $apiName;
            $schema['collectionName'] = $schema['collectionName'] ?? ($schema['info']['singularName'] ?? null);
            $schema['globalId'] = ContentType::getGlobalId($schema);
            $contentType['schema'] = $schema;

            $contentTypes[self::normalizeName($contentTypeName)] = $contentType;
        }

        return $contentTypes;
    }

    /** @return array<string, mixed>|null */
    private static function loadDir(string $dir): ?array
    {
        if (!is_dir($dir)) {
            return null;
        }

        $root = [];
        $entries = scandir($dir) ?: [];
        sort($entries);
        foreach ($entries as $entry) {
            $path = $dir . '/' . $entry;
            if ($entry === '.' || $entry === '..' || !is_file($path)) {
                continue;
            }
            $ext = pathinfo($entry, PATHINFO_EXTENSION);
            if (!in_array($ext, ['php', 'json'], true)) {
                continue;
            }

            $key = pathinfo($entry, PATHINFO_FILENAME);
            $root[self::normalizeName($key)] = self::loadFile($path);
        }

        return $root;
    }

    public static function loadFile(string $file): mixed
    {
        return match (pathinfo($file, PATHINFO_EXTENSION)) {
            'php' => (static fn (): mixed => require $file)(),
            'json' => json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR),
            default => [],
        };
    }
}
