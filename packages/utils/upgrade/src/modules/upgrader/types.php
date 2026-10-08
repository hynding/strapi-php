<?php

declare(strict_types=1);

namespace Strapi\Upgrade\Modules\Upgrader;

/**
 * Port of packages/utils/upgrade/src/modules/upgrader/types.ts (the `Upgrader` interface is the
 * `Upgrader` class). An `Installer` gets `(tool, cwd, upgrader)`: `tool` is `composer` or the
 * Node package manager.
 *
 * @phpstan-type UpgradeReport array{success: true, error: null}|array{success: false, error: \Throwable}
 * @phpstan-type Installer \Closure(string, string, Upgrader): void
 */
final class Types
{
}
