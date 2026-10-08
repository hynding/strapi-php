<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Services\SchemaBuilder;

use Strapi\ContentTypeBuilder\Utils\Attributes;

/**
 * Port of server/src/services/schema-builder/schema-handler.ts: one content type or component
 * schema being edited in memory, flushed to `schema.json` / `<category>/<name>.json`.
 *
 * Files are written the way `fse.writeJSON(file, data, { spaces: 2 })` writes them
 * (`JSON.stringify(data, null, 2)` plus a trailing newline), so a project can move between
 * Strapi and strapi-php without its schema files changing: see {@see self::stringify()}.
 *
 * JavaScript `undefined` is a missing key: `set(path, null)` keeps the current value
 * (lodash `defaultTo`) and never creates a key.
 *
 * @phpstan-type Infos array{category?: string|null, modelName?: string|null, plugin?: string|null, uid?: string|null, dir: string, filename: string, schema?: array<string, mixed>|null}
 */
final class SchemaHandler implements \JsonSerializable
{
    /** Keys whose value is a JSON object even when empty (PHP has a single empty array). */
    private const array OBJECT_KEYS = ['info', 'options', 'pluginOptions', 'attributes', 'config', 'conditions', 'visible', 'details'];

    /** @var array{modelName: ?string, plugin: ?string, category: ?string, uid: ?string, dir: string, filename: string, schema: array<string, mixed>} */
    private readonly array $initialState;

    /** @var array{modelName: ?string, plugin: ?string, category: ?string, uid: ?string, dir: string, filename: string, schema: array<string, mixed>} */
    private array $state;

    private bool $modified = false;

    private bool $deleted = false;

    /** @param Infos $infos */
    public function __construct(array $infos)
    {
        $schema = $infos['schema'] ?? null;

        $this->initialState = [
            'modelName' => $infos['modelName'] ?? null,
            'plugin' => $infos['plugin'] ?? null,
            'category' => $infos['category'] ?? null,
            'uid' => $infos['uid'] ?? null,
            'dir' => $infos['dir'],
            'filename' => $infos['filename'],
            'schema' => is_array($schema) && $schema !== [] ? $schema : [
                'info' => [],
                'options' => [],
                'attributes' => [],
            ],
        ];

        $this->state = $this->initialState;
    }

    /** @param Infos $infos */
    public static function createSchemaHandler(array $infos): self
    {
        return new self($infos);
    }

    public function modelName(): ?string
    {
        return $this->initialState['modelName'];
    }

    public function plugin(): ?string
    {
        return $this->initialState['plugin'];
    }

    public function category(): ?string
    {
        return $this->initialState['category'];
    }

    public function kind(): mixed
    {
        return array_key_exists('kind', $this->state['schema']) ? $this->state['schema']['kind'] : 'collectionType';
    }

    public function uid(): ?string
    {
        return $this->state['uid'];
    }

    public function writable(): bool
    {
        return $this->state['plugin'] !== 'admin';
    }

    public function setUID(string $val): self
    {
        $this->modified = true;
        $this->state['uid'] = $val;

        return $this;
    }

    public function setDir(string $val): self
    {
        $this->modified = true;
        $this->state['dir'] = $val;

        return $this;
    }

    /** @return array<string, mixed> a copy of the schema */
    public function schema(): array
    {
        return $this->state['schema'];
    }

    /** @param array<string, mixed> $val */
    public function setSchema(array $val): self
    {
        $this->modified = true;
        $this->state['schema'] = $val;

        return $this;
    }

    /**
     * Get a particular path inside the schema.
     *
     * @param string|list<string> $path
     */
    public function get(string|array $path): mixed
    {
        $value = $this->state['schema'];
        foreach (self::toPath($path) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }

        return $value;
    }

    /**
     * Set a particular path inside the schema; a `null` value keeps the current one.
     *
     * @param string|list<string> $path
     */
    public function set(string|array $path, mixed $val): self
    {
        $this->modified = true;

        if ($val === null) {
            // _.set(schema, path, _.defaultTo(undefined, current)): the current value stays
            return $this;
        }

        self::setIn($this->state['schema'], self::toPath($path), $val);

        return $this;
    }

    /**
     * Delete a particular path inside the schema.
     *
     * @param string|list<string> $path
     */
    public function unset(string|array $path): self
    {
        $this->modified = true;
        self::unsetIn($this->state['schema'], self::toPath($path));

        return $this;
    }

    public function delete(): self
    {
        $this->deleted = true;

        return $this;
    }

    public function getAttribute(?string $key): mixed
    {
        // _.get(schema, ['attributes', undefined]) reads the key "undefined"
        return $this->get(['attributes', $key ?? 'undefined']);
    }

    public function setAttribute(string $key, mixed $attribute): self
    {
        return $this->set(['attributes', $key], $attribute);
    }

    public function deleteAttribute(string $key): self
    {
        return $this->unset(['attributes', $key]);
    }

    /** @param array<string, mixed> $newAttributes */
    public function setAttributes(array $newAttributes): self
    {
        // delete old configurable attributes
        foreach ($this->schema()['attributes'] ?? [] as $key => $attribute) {
            if (Attributes::isConfigurable(is_array($attribute) ? $attribute : [])) {
                $this->deleteAttribute((string) $key);
            }
        }

        // set new Attributes
        foreach ($newAttributes as $key => $attribute) {
            $this->setAttribute((string) $key, $attribute);
        }

        return $this;
    }

    public function removeContentType(string $uid): self
    {
        foreach ($this->state['schema']['attributes'] ?? [] as $key => $attribute) {
            if (is_array($attribute) && ($attribute['target'] ?? null) === $uid) {
                $this->deleteAttribute((string) $key);
            }
        }

        return $this;
    }

    public function removeComponent(string $uid): self
    {
        foreach ($this->state['schema']['attributes'] ?? [] as $key => $attr) {
            if (!is_array($attr)) {
                continue;
            }

            if (($attr['type'] ?? null) === 'component' && ($attr['component'] ?? null) === $uid) {
                $this->deleteAttribute((string) $key);
            }

            if (
                ($attr['type'] ?? null) === 'dynamiczone'
                && is_array($attr['components'] ?? null)
                && in_array($uid, $attr['components'], true)
            ) {
                $updatedComponentList = array_values(array_filter($attr['components'], static fn (mixed $val): bool => $val !== $uid));
                $this->set(['attributes', (string) $key, 'components'], $updatedComponentList);
            }
        }

        return $this;
    }

    public function updateComponent(string $uid, string $newUID): self
    {
        foreach ($this->state['schema']['attributes'] ?? [] as $key => $attr) {
            if (!is_array($attr)) {
                continue;
            }

            if (($attr['type'] ?? null) === 'component' && ($attr['component'] ?? null) === $uid) {
                $this->set(['attributes', (string) $key, 'component'], $newUID);
            }

            if (
                ($attr['type'] ?? null) === 'dynamiczone'
                && is_array($attr['components'] ?? null)
                && in_array($uid, $attr['components'], true)
            ) {
                $updatedComponentList = array_map(static fn (mixed $val): mixed => $val === $uid ? $newUID : $val, $attr['components']);
                $this->set(['attributes', (string) $key, 'components'], $updatedComponentList);
            }
        }

        return $this;
    }

    /** Save the schema to disk. */
    public function flush(): void
    {
        if (!$this->writable()) {
            return;
        }

        $initialPath = self::join($this->initialState['dir'], $this->initialState['filename']);
        $filePath = self::join($this->state['dir'], $this->state['filename']);

        if ($this->deleted) {
            self::remove($initialPath);
            self::removeDirectoryIfEmpty($this->initialState['dir']);

            return;
        }

        if ($this->modified) {
            $schema = $this->state['schema'];
            $data = [];
            foreach (['kind', 'collectionName', 'info', 'options', 'pluginOptions', 'attributes', 'config', 'indexes', 'foreignKeys'] as $key) {
                if (array_key_exists($key, $schema)) {
                    $data[$key] = $schema[$key];
                }
            }

            self::writeJSON($filePath, $data);

            // remove from oldPath
            if ($initialPath !== $filePath) {
                self::remove($initialPath);
                self::removeDirectoryIfEmpty($this->initialState['dir']);
            }
        }
    }

    /** Reset the schema to its initial value. */
    public function rollback(): void
    {
        if (!$this->writable()) {
            return;
        }

        $initialPath = self::join($this->initialState['dir'], $this->initialState['filename']);
        $filePath = self::join($this->state['dir'], $this->state['filename']);

        // it was a creation so it needs to be deleted
        if ($this->initialState['uid'] === null || $this->initialState['uid'] === '') {
            self::remove($filePath);
            self::removeDirectoryIfEmpty($this->state['dir']);

            return;
        }

        if ($this->modified || $this->deleted) {
            self::writeJSON($initialPath, $this->initialState['schema']);

            // remove
            if ($initialPath !== $filePath) {
                self::remove($filePath);
                self::removeDirectoryIfEmpty($this->state['dir']);
            }
        }
    }

    /**
     * The handler's enumerable getters, as an upstream handler object serializes.
     *
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $out = [];
        foreach (['modelName' => $this->modelName(), 'plugin' => $this->plugin(), 'category' => $this->category()] as $key => $value) {
            if ($value !== null) {
                $out[$key] = $value;
            }
        }
        $out['kind'] = $this->kind();
        if ($this->uid() !== null) {
            $out['uid'] = $this->uid();
        }
        $out['writable'] = $this->writable();
        $out['schema'] = self::jsonValue($this->schema());

        return $out;
    }

    // --- JSON files ------------------------------------------------------------------------------

    /** `fse.writeJSON(file, data, { spaces: 2 })` (creating the parent directories, like `ensureFile`). */
    public static function writeJSON(string $file, mixed $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new \RuntimeException("ENOENT: no such file or directory, mkdir '{$dir}'");
        }

        if (@file_put_contents($file, self::stringify($data) . "\n") === false) {
            throw new \RuntimeException("EACCES: permission denied, open '{$file}'");
        }
    }

    /**
     * `JSON.stringify(value, null, 2)`: two-space indentation, `/` and non-ASCII characters
     * unescaped, integral floats without a fraction, empty schema objects (`info`, `options`,
     * `pluginOptions`, `attributes`, an attribute...) as `{}`.
     */
    public static function stringify(mixed $data): string
    {
        $json = json_encode(
            self::jsonValue($data),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_LINE_TERMINATORS | JSON_THROW_ON_ERROR,
        );

        // json_encode indents with four spaces; strings never contain a raw newline
        return (string) preg_replace_callback('/^( {4})+/m', static fn (array $m): string => str_repeat('  ', intdiv(strlen($m[0]), 4)), $json);
    }

    /**
     * A copy of `$value` ready for `json_encode()`: associative arrays and the empty arrays found
     * where a schema has an object become objects.
     *
     * @param list<string> $path
     */
    public static function jsonValue(mixed $value, array $path = []): mixed
    {
        if (is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 1e15) {
            return (int) $value;
        }

        if (!is_array($value)) {
            return $value;
        }

        if ($value === []) {
            return self::isObjectPath($path) ? new \stdClass() : [];
        }

        if (array_is_list($value) && ($path === [] || !self::isObjectPath($path))) {
            return array_map(static fn (mixed $v): mixed => self::jsonValue($v, [...$path, '*']), $value);
        }

        $out = new \stdClass();
        foreach ($value as $key => $v) {
            $out->{(string) $key} = self::jsonValue($v, [...$path, (string) $key]);
        }

        return $out;
    }

    /** @param list<string> $path */
    private static function isObjectPath(array $path): bool
    {
        $count = count($path);
        if ($count === 0) {
            return true;
        }

        $last = $path[$count - 1];
        if (in_array($last, self::OBJECT_KEYS, true)) {
            return true;
        }

        // an attribute: attributes.<name>
        if ($count >= 2 && $path[$count - 2] === 'attributes' && $last !== '*') {
            return true;
        }

        // anything nested under pluginOptions is a map of plugin settings
        return $count >= 2 && $path[$count - 2] === 'pluginOptions';
    }

    // --- file system helpers (fs-extra) ----------------------------------------------------------

    public static function join(string ...$parts): string
    {
        return preg_replace('#/+#', '/', implode('/', $parts)) ?? implode('/', $parts);
    }

    /** `fse.remove()`: removes a file or a directory recursively; missing paths are ignored. */
    public static function remove(string $path): void
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

    public static function removeDirectoryIfEmpty(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $list = array_diff(scandir($dir) ?: [], ['.', '..']);
        if ($list === []) {
            self::remove($dir);
        }
    }

    /**
     * @param string|list<string> $path
     * @return list<string>
     */
    private static function toPath(string|array $path): array
    {
        return is_string($path) ? explode('.', $path) : array_map('strval', $path);
    }

    /**
     * @param array<array-key, mixed> $target
     * @param list<string> $path
     */
    private static function setIn(array &$target, array $path, mixed $value): void
    {
        $key = array_shift($path);
        if ($key === null) {
            return;
        }

        if ($path === []) {
            $target[$key] = $value;

            return;
        }

        if (!isset($target[$key]) || !is_array($target[$key])) {
            $target[$key] = [];
        }
        self::setIn($target[$key], $path, $value);
    }

    /**
     * @param array<array-key, mixed> $target
     * @param list<string> $path
     */
    private static function unsetIn(array &$target, array $path): void
    {
        $key = array_shift($path);
        if ($key === null || !array_key_exists($key, $target)) {
            return;
        }

        if ($path === []) {
            unset($target[$key]);

            return;
        }

        if (is_array($target[$key])) {
            self::unsetIn($target[$key], $path);
        }
    }
}
