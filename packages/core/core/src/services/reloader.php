<?php

declare(strict_types=1);

namespace Strapi\Core\Services;

use Strapi\Core\Strapi;

/**
 * Port of packages/core/core/src/services/reloader.ts. Under PHP there is no parent watcher
 * process to signal: `reload()` is a no-op (use `frankenphp php-server --watch` or restart).
 */
final class Reloader
{
    public bool $isReloading = false;

    private bool $isWatching = true;

    private int $shouldReload = 0;

    public function __construct(private readonly Strapi $strapi)
    {
    }

    public static function createReloader(Strapi $strapi): self
    {
        return new self($strapi);
    }

    public function __invoke(): void
    {
        $this->reload();
    }

    public function reload(): void
    {
        if ($this->shouldReload > 0) {
            // Reset the reloading state
            $this->shouldReload -= 1;
            $this->isReloading = false;

            return;
        }

        if ($this->strapi->config()->get('autoReload')) {
            $this->strapi->log()->info('Reload requested: restart the PHP process to apply changes');
        }
    }

    public function isWatching(): bool
    {
        return $this->isWatching;
    }

    public function setWatching(bool $value): void
    {
        // Special state when the reloader is disabled temporarily (see GraphQL plugin example).
        if ($this->isWatching === false && $value === true) {
            $this->shouldReload += 1;
        }
        $this->isWatching = $value;
    }
}
