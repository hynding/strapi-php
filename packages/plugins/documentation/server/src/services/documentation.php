<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Services;

use Strapi\Core\Strapi;
use Strapi\Plugin\Documentation\Services\Helpers\BuildApiEndpointPath;
use Strapi\Plugin\Documentation\Services\Helpers\BuildComponentSchema;
use Strapi\Plugin\Documentation\Services\Utils\GetPluginsThatNeedDocumentation;
use Strapi\Plugin\Documentation\Utils;

/**
 * Port of server/src/services/documentation.ts.
 *
 * The documents are plain arrays (`immer`'s `produce` becomes a copy: PHP arrays are values);
 * `x-strapi-config.mutateDocumentation` receives the draft by reference (`fn (array &$draft)`) or
 * returns the new document. The PHP port has no separate `dist` directory: in production the
 * files live in the app's own `src/extensions` (upstream: `dist/src/extensions`).
 *
 * @phpstan-import-type PluginConfig from \Strapi\Plugin\Documentation\Types
 * @phpstan-import-type Api from \Strapi\Plugin\Documentation\Types
 *
 * @phpstan-type Version array{version: string, generatedDate: mixed, url: string}
 */
final class Documentation
{
    /** @var PluginConfig */
    private readonly array $config;

    /** @var list<string> */
    private readonly array $pluginsThatNeedDocumentation;

    private readonly Override $overrideService;

    /** @param Strapi $strapi */
    public function __construct(private readonly object $strapi)
    {
        $config = $strapi->config()->get('plugin::documentation');
        $this->config = is_array($config) ? $config : [];
        $this->pluginsThatNeedDocumentation = GetPluginsThatNeedDocumentation::getPluginsThatNeedDocumentation($this->config);
        $this->overrideService = Utils::getService('override', $strapi);
    }

    public function getDocumentationVersion(): string
    {
        return (string) ($this->config['info']['version'] ?? '');
    }

    public function getFullDocumentationPath(): string
    {
        // In production, documentation files live under dist/src/extensions/ after the build
        // step upstream; the PHP port has no build step (dist = app).
        $extensionsDir = $this->strapi->dirs()->extensions;

        return self::join($extensionsDir, 'documentation', 'documentation');
    }

    /** @return list<Version> */
    public function getDocumentationVersions(): array
    {
        $dir = $this->getFullDocumentationPath();
        $entries = is_dir($dir) ? @scandir($dir) : false;
        if ($entries === false) {
            throw new \RuntimeException("ENOENT: no such file or directory, scandir '{$dir}'");
        }

        $versions = [];
        foreach ($entries as $version) {
            if ($version === '.' || $version === '..') {
                continue;
            }

            $filePath = self::join($dir, $version, 'full_documentation.json');
            $raw = is_file($filePath) ? @file_get_contents($filePath) : false;
            if ($raw === false) {
                continue;
            }

            $doc = json_decode($raw, true);
            if (!is_array($doc) || !is_array($doc['info'] ?? null)) {
                continue;
            }

            $generatedDate = $doc['info']['x-generation-date'] ?? null;

            $versions[] = ['version' => $version, 'generatedDate' => $generatedDate, 'url' => ''];
        }

        return $versions;
    }

    /**
     * Returns settings stored in core-store
     *
     * @return array{restrictedAccess: mixed}
     */
    public function getDocumentationAccess(): array
    {
        $config = $this->strapi->store()->get([
            'environment' => '',
            'type' => 'plugin',
            'name' => 'documentation',
            'key' => 'config',
        ]);

        return ['restrictedAccess' => is_array($config) ? ($config['restrictedAccess'] ?? null) : null];
    }

    /** @param array{name: string, getter: string} $api */
    public function getApiDocumentationPath(array $api): string
    {
        $dirs = $this->strapi->dirs();

        if ($api['getter'] === 'plugin') {
            return self::join($dirs->extensions, $api['name'], 'documentation');
        }

        return self::join($dirs->api, $api['name'], 'documentation');
    }

    public function deleteDocumentation(string $version): void
    {
        $apis = $this->getPluginAndApiInfo();
        foreach ($apis as $api) {
            self::remove(self::join($this->getApiDocumentationPath($api), $version));
        }

        self::remove(self::join($this->getFullDocumentationPath(), $version));
    }

    /** @return list<Api> */
    public function getPluginAndApiInfo(): array
    {
        $pluginsToDocument = array_map(fn (string $plugin): array => [
            'name' => $plugin,
            'getter' => 'plugin',
            'ctNames' => array_map('strval', array_keys($this->strapi->plugin($plugin)->contentTypes())),
        ], $this->pluginsThatNeedDocumentation);

        $apisToDocument = array_map(fn (int|string $api): array => [
            'name' => (string) $api,
            'getter' => 'api',
            'ctNames' => array_map('strval', array_keys($this->strapi->api((string) $api)->contentTypes())),
        ], array_keys($this->strapi->apis()));

        return [...$apisToDocument, ...$pluginsToDocument];
    }

    /**
     * Creates the Swagger json files.
     *
     * @return PluginConfig the written document
     */
    public function generateFullDoc(?string $versionOpt = null): array
    {
        $version = $versionOpt ?? $this->getDocumentationVersion();

        $apis = $this->getPluginAndApiInfo();
        $apisThatNeedGeneratedDocumentation = array_values(array_filter(
            $apis,
            fn (array $api): bool => !$this->overrideService->isEnabled($api['name']),
        ));

        // Initialize the generated documentation with defaults
        $draft = $this->config;

        if (is_array($draft['servers'] ?? null) && count($draft['servers']) === 0) {
            // When no servers found set the defaults
            $serverUrl = $this->strapi->config()->get('server.absoluteUrl');
            $apiPath = $this->strapi->config()->get('api.rest.prefix');
            $draft['servers'] = [
                [
                    'url' => self::toJsString($serverUrl) . self::toJsString($apiPath),
                    'description' => 'Development server',
                ],
            ];
        }

        if (!is_array($draft['components'] ?? null)) {
            $draft['components'] = [];
        }

        // Set the generated date
        $draft['info'] = is_array($draft['info'] ?? null) ? $draft['info'] : [];
        $draft['info']['x-generation-date'] = self::toISOString();
        // Set the plugins that need documentation
        $draft['x-strapi-config'] = is_array($draft['x-strapi-config'] ?? null) ? $draft['x-strapi-config'] : [];
        $draft['x-strapi-config']['plugins'] = $this->pluginsThatNeedDocumentation;

        // Delete the mutateDocumentation key from the config so it doesn't end up in the spec
        unset($draft['x-strapi-config']['mutateDocumentation']);

        // Generate the documentation for each api and update the generatedDocumentation
        foreach ($apisThatNeedGeneratedDocumentation as $api) {
            $newApiPath = BuildApiEndpointPath::buildApiEndpointPath($this->strapi, $api);
            $generatedSchemas = BuildComponentSchema::buildComponentSchema($this->strapi, $api);

            $draft['components']['schemas'] = array_replace(self::asArray($draft['components']['schemas'] ?? null), $generatedSchemas);

            $draft['paths'] = array_replace(self::asArray($draft['paths'] ?? null), $newApiPath);
        }

        // When overrides are present update the generatedDocumentation
        foreach ($this->overrideService->registeredOverrides as $override) {
            // Only run the overrrides when no override version is provided,
            // or when the generated documentation version matches the override version
            $overrideVersion = $override['info']['version'] ?? null;
            if ($overrideVersion !== null && $overrideVersion !== '' && $overrideVersion !== $version) {
                continue;
            }

            if (isset($override['tags'])) {
                // Merge override tags with the generated tags
                $draft['tags'] = self::asArray($draft['tags'] ?? null);
                array_push($draft['tags'], ...array_values(self::asArray($override['tags'])));
            }

            if (isset($override['paths'])) {
                // Merge override paths with the generated paths
                // The override will add a new path or replace the value of an existing path
                $draft['paths'] = array_replace(self::asArray($draft['paths'] ?? null), self::asArray($override['paths']));
            }

            if (isset($override['components'])) {
                foreach (self::asArray($override['components']) as $overrideKey => $overrideValue) {
                    $draft['components'] = self::asArray($draft['components'] ?? null);

                    $originalValue = $draft['components'][$overrideKey] ?? null;

                    $draft['components'][$overrideKey] = array_replace(self::asArray($originalValue), self::asArray($overrideValue));
                }
            }
        }

        // Escape hatch, allow the user to provide a mutateDocumentation function that can alter any part of
        // the generated documentation before it is written to the file system
        $userMutatesDocumentation = $this->config['x-strapi-config']['mutateDocumentation'] ?? null;

        $finalDocumentation = $draft;
        if (is_callable($userMutatesDocumentation)) {
            $result = $userMutatesDocumentation($finalDocumentation);
            if (is_array($result)) {
                $finalDocumentation = $result;
            }
        }

        // Get the file path for the final documentation
        $fullDocJsonPath = self::join($this->getFullDocumentationPath(), $version, 'full_documentation.json');
        // Write the documentation to the file system
        self::writeJson($fullDocJsonPath, $finalDocumentation);

        return $finalDocumentation;
    }

    // --- PHP-port helpers --------------------------------------------------------------------

    /**
     * `fs.ensureFile(file)` + `fs.writeJson(file, data, { spaces: 2 })`: two-space indentation,
     * `/` and non-ASCII characters unescaped, the objects of an OpenAPI document as `{}` when empty.
     */
    public static function writeJson(string $file, mixed $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("EACCES: permission denied, mkdir '{$dir}'");
        }

        if (@file_put_contents($file, self::stringify($data, 2) . "\n") === false) {
            throw new \RuntimeException("EACCES: permission denied, open '{$file}'");
        }
    }

    /** `JSON.stringify(document, null, spaces)` of an OpenAPI document (0: compact). */
    public static function stringify(mixed $document, int $spaces = 0): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR;
        $json = json_encode(self::jsonDocument($document), $spaces > 0 ? $flags | JSON_PRETTY_PRINT : $flags);

        if ($spaces > 0) {
            // json_encode indents with four spaces; strings never contain a raw newline
            $json = (string) preg_replace_callback('/^( {4})+/m', static fn (array $m): string => str_repeat(str_repeat(' ', $spaces), intdiv(strlen($m[0]), 4)), $json);
        }

        return $json;
    }

    /**
     * The empty arrays found where an OpenAPI document has an object (`paths`, `components` and its
     * maps, `info`, `x-strapi-config`, `webhooks`) become `{}`; closures (a leftover
     * `mutateDocumentation`) are dropped like `JSON.stringify` drops functions.
     */
    private static function jsonDocument(mixed $document): mixed
    {
        if (!is_array($document)) {
            return $document;
        }

        $document = self::dropClosures($document);

        foreach (['paths', 'components', 'info', 'x-strapi-config', 'webhooks', 'externalDocs'] as $key) {
            if (($document[$key] ?? null) === []) {
                $document[$key] = new \stdClass();
            }
        }
        if (is_array($document['components'] ?? null)) {
            foreach ($document['components'] as $key => $value) {
                if ($value === []) {
                    $document['components'][$key] = new \stdClass();
                }
            }
        }

        return $document;
    }

    /**
     * @param array<array-key, mixed> $value
     *
     * @return array<array-key, mixed>
     */
    private static function dropClosures(array $value): array
    {
        foreach ($value as $key => $item) {
            if ($item instanceof \Closure) {
                unset($value[$key]);
            } elseif (is_array($item)) {
                $value[$key] = self::dropClosures($item);
            }
        }

        return $value;
    }

    /** @return array<array-key, mixed> */
    private static function asArray(mixed $value): array
    {
        if ($value instanceof \stdClass) {
            return get_object_vars($value);
        }

        return is_array($value) ? $value : [];
    }

    /** `${value}` of a JS template literal */
    private static function toJsString(mixed $value): string
    {
        return match (true) {
            $value === null => 'undefined',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /** `new Date().toISOString()` */
    private static function toISOString(): string
    {
        $now = microtime(true);

        return gmdate('Y-m-d\TH:i:s', (int) $now) . sprintf('.%03dZ', (int) (($now - floor($now)) * 1000));
    }

    /** `path.join(...)` */
    private static function join(string ...$parts): string
    {
        $path = implode('/', array_filter($parts, static fn (string $part): bool => $part !== ''));
        $absolute = str_starts_with($path, '/');
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments !== [] && end($segments) !== '..') {
                    array_pop($segments);
                } elseif (!$absolute) {
                    $segments[] = '..';
                }
                continue;
            }
            $segments[] = $segment;
        }

        $joined = ($absolute ? '/' : '') . implode('/', $segments);

        return $joined === '' ? '.' : $joined;
    }

    /** `fs.remove(path)`: recursive, no error when missing. */
    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::remove($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
