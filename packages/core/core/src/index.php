<?php

declare(strict_types=1);

namespace Strapi\Core;

use Strapi\Core\Utils\ResolveWorkingDirs;
use Strapi\Core\Utils\Signals;
use Strapi\Core\Utils\UpdateNotifier\UpdateNotifier;

/**
 * Port of packages/core/core/src/index.ts: `createStrapi(options)`.
 *
 * @phpstan-type Options array{appDir?: string|null, distDir?: string|null, autoReload?: bool, serveAdminPanel?: bool}
 */
final class Core
{
    /** The last created instance (upstream's `global.strapi`). */
    private static ?Strapi $instance = null;

    /** @param Options $options */
    public static function createStrapi(array $options = []): Strapi
    {
        $strapi = new Strapi([...$options, ...ResolveWorkingDirs::resolveWorkingDirectories($options)]);

        Signals::destroyOnSignal($strapi);
        UpdateNotifier::createUpdateNotifier($strapi);

        self::$instance = $strapi;

        return $strapi;
    }

    /** upstream `global.strapi` */
    public static function instance(): ?Strapi
    {
        return self::$instance;
    }

    public static function setInstance(?Strapi $strapi): void
    {
        self::$instance = $strapi;
    }
}
