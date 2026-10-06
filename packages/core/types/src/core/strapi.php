<?php

declare(strict_types=1);

namespace Strapi\Types\Core;

use Strapi\Types\Modules\CoreStore\CoreStore;
use Strapi\Types\Modules\Cron\CronService;
use Strapi\Types\Modules\Documents\Repository;
use Strapi\Types\Modules\EventHub\EventHub;
use Strapi\Types\Schema\Schema;

/**
 * The global `strapi` object. Mirrors Core.Strapi (packages/core/types/src/core/strapi.ts).
 * Concrete implementation: Strapi\Core\Strapi.
 */
interface Strapi
{
    /** container access (upstream: strapi.get / strapi.add) */
    public function get(string $name): mixed;

    public function add(string $name, mixed $resolver): static;

    public function has(string $name): bool;

    /** @return \Strapi\Types\Modules\Config\Config */
    public function config(): \Strapi\Types\Modules\Config\Config;

    public function log(): \Psr\Log\LoggerInterface;

    public function db(): \Strapi\Types\Modules\Database\Database;

    public function eventHub(): EventHub;

    public function store(): CoreStore;

    public function cron(): CronService;

    /** @return StrapiDirectories */
    public function dirs(): StrapiDirectories;

    public function documents(string $uid): Repository;

    public function service(string $uid): object;

    public function controller(string $uid): object;

    public function policy(string $uid): callable;

    public function middleware(string $uid): callable;

    public function contentType(string $uid): Schema;

    /** @return array<string, Schema> */
    public function contentTypes(): array;

    /** @return array<string, Schema> */
    public function components(): array;

    public function plugin(string $name): Module;

    /** @return array<string, Module> */
    public function plugins(): array;

    public function api(string $name): Module;

    /** @return array<string, Module> */
    public function apis(): array;

    public function isLoaded(): bool;

    public function register(): static;

    public function bootstrap(): static;

    public function load(): static;

    public function start(): static;

    public function destroy(): void;
}
