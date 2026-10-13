<?php

declare(strict_types=1);

namespace StrapiPlugin\RuntimeSync;

use Strapi\Core\Strapi;
use StrapiPlugin\RuntimeSync\Services\Preflight;

/**
 * Mirrors register.js. Hooks the two moments of a boot that matter to a shared database:
 *
 * - `strapi::content-types.beforeSync` runs once the database is initialised and before the
 *   schema sync and the migrations. The preflight service compares this runtime's schema with
 *   the stored one, lists the migrations about to run, logs the twins the other runtime already
 *   ran, and — when `STRAPI_RUNTIME_SYNC_PREFLIGHT=1` (`strapi-runtime-sync db:check`) — aborts
 *   the boot with the report before anything is written.
 * - `strapi::content-types.afterSync` records this boot (runtime, version, schema hash, plugins)
 *   in the core store, so the other runtime and the admin page can see both sides agree.
 */
final class Register
{
    public function __invoke(Strapi $strapi): void
    {
        $preflight = static function () use ($strapi): Preflight {
            $service = $strapi->plugin('runtime-sync')->service('preflight');
            \assert($service instanceof Preflight);

            return $service;
        };

        $strapi->hook('strapi::content-types.beforeSync')->register(static fn (mixed $ctx) => $preflight()->beforeSync());
        $strapi->hook('strapi::content-types.afterSync')->register(static fn (mixed $ctx) => $preflight()->afterSync());
    }
}
