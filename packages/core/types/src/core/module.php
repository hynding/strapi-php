<?php

declare(strict_types=1);

namespace Strapi\Types\Core;

/**
 * A loaded API or plugin: the server half of upstream's Core.Module / Core.Plugin.
 * Everything is keyed the way upstream registries expect
 * (controllers['article'], services['article'], contentTypes['article'], routes['content-api']...).
 */
interface Module
{
    public function register(Strapi $strapi): void;

    public function bootstrap(Strapi $strapi): void;

    public function destroy(Strapi $strapi): void;

    /** The module's config (`config(null)` is the whole map, `config('path.to.key', $default)` a value). */
    public function config(?string $path = null, mixed $default = null): mixed;

    /** @return array<string, mixed>|list<array<string, mixed>> */
    public function routes(): array;

    /** @param array<string, mixed>|list<array<string, mixed>> $routes */
    public function setRoutes(array $routes): void;

    /** @return array<string, object> */
    public function controllers(): array;

    public function controller(string $name): object;

    /** @return array<string, object> */
    public function services(): array;

    public function service(string $name): object;

    /** @return array<string, \Strapi\Types\Schema\Schema> */
    public function contentTypes(): array;

    public function contentType(string $name): \Strapi\Types\Schema\Schema;

    /** @return array<string, callable> */
    public function policies(): array;

    public function policy(string $name): callable;

    /** @return array<string, callable> */
    public function middlewares(): array;

    public function middleware(string $name): callable;
}
