<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation;

use Strapi\Core\Core;
use Strapi\Core\Strapi;
use Strapi\Plugin\Documentation\Services\Documentation;
use Strapi\Plugin\Documentation\Services\Override;

/** Port of server/src/utils.ts. */
final class Utils
{
    /**
     * `getService(name, { strapi } = { strapi: global.strapi })`
     *
     * @template T of 'documentation'|'override'
     *
     * @param T $name
     * @param Strapi|null $strapi
     *
     * @return (T is 'documentation' ? Documentation : Override)
     */
    public static function getService(string $name, ?object $strapi = null): object
    {
        $strapi ??= Core::instance() ?? throw new \RuntimeException('Strapi is not initialized');

        $service = $strapi->plugin('documentation')->service($name);
        if (!$service instanceof Documentation && !$service instanceof Override) {
            throw new \RuntimeException("Service plugin::documentation.{$name} not found");
        }

        return $service;
    }
}
