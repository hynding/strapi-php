<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Tests;

use Strapi\ContentTypeBuilder\Services\SchemaBuilder\SchemaHandler;
use Strapi\Core\Strapi;

require_once __DIR__ . '/StubStrapi.php';

/**
 * A scratch project directory with the content-type-builder services registered on an un-loaded
 * {@see StubStrapi} instance: the services run for real against files under `$root/src`.
 */
final class TempApp
{
    public readonly string $root;

    public readonly Strapi $strapi;

    /** @var list<array{string, mixed}> events emitted on the event hub */
    public array $events = [];

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/ctb-app-' . bin2hex(random_bytes(6));
        mkdir($this->root . '/src/api', 0777, true);
        mkdir($this->root . '/src/components', 0777, true);

        $this->strapi = StubStrapi::create($this->root);
        $this->strapi->set('logger', new \Psr\Log\NullLogger());

        /** @var array<string, \Closure(Strapi): object> $services */
        $services = require dirname(__DIR__) . '/server/src/services/index.php';
        foreach ($services as $name => $factory) {
            $this->strapi->get('services')->set("plugin::content-type-builder.{$name}", $factory);
        }

        $this->strapi->eventHub()->subscribe(function (string $event, mixed ...$args): void {
            $this->events[] = [$event, $args[0] ?? null];
        });
    }

    public function destroy(): void
    {
        SchemaHandler::remove($this->root);
    }

    public function setService(string $name, object $service): void
    {
        $this->strapi->get('services')->set("plugin::content-type-builder.{$name}", $service);
    }

    public function path(string $relative): string
    {
        return $this->root . '/' . ltrim($relative, '/');
    }

    public function write(string $relative, string $contents): void
    {
        $path = $this->path($relative);
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $contents);
    }

    public function read(string $relative): ?string
    {
        $path = $this->path($relative);

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /**
     * Registers an API content type and writes its `schema.json` and controller, as a project has them.
     *
     * @param array<string, mixed> $schema the schema.json contents
     */
    public function addApiContentType(string $name, array $schema): void
    {
        $this->write("src/api/{$name}/content-types/{$name}/schema.json", SchemaHandler::stringify($schema) . "\n");
        $this->write("src/api/{$name}/controllers/{$name}.php", "<?php\n");

        StubStrapi::addContentTypes($this->strapi, ["api::{$name}.{$name}" => [
            ...$schema,
            'apiName' => $name,
            'modelName' => $schema['info']['singularName'] ?? $name,
            '__schema__' => $schema,
        ]]);
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function addPluginContentType(string $plugin, string $name, array $schema): void
    {
        StubStrapi::addContentTypes($this->strapi, ["plugin::{$plugin}.{$name}" => [
            ...$schema,
            'plugin' => $plugin,
            'modelName' => $name,
            '__schema__' => $schema,
        ]]);
    }

    /**
     * @param array<string, mixed> $schema the component JSON
     */
    public function addComponent(string $category, string $name, array $schema): void
    {
        $this->write("src/components/{$category}/{$name}.json", SchemaHandler::stringify($schema) . "\n");

        StubStrapi::addComponents($this->strapi, ["{$category}.{$name}" => [
            ...$schema,
            'category' => $category,
            'modelName' => $name,
            'config' => ['__schema__' => $schema, '__filename__' => "{$name}.json"],
        ]]);
    }

    /** @return list<string> the files under `src/`, relative to the project root */
    public function files(): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root . '/src', \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            /** @var \SplFileInfo $file */
            $out[] = substr($file->getPathname(), strlen($this->root) + 1);
        }
        sort($out);

        return $out;
    }
}
