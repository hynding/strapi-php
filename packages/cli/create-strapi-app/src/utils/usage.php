<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Utils;

use Strapi\CreateStrapiApp\Types;

/**
 * Port of packages/cli/create-strapi-app/src/utils/usage.ts — a no-op, like strapi/core's
 * telemetry (`Strapi\Core\Services\Metrics\Metrics`): the PHP port sends no analytics to
 * analytics.strapi.io. The functions keep upstream's names and call sites so the event sequence
 * stays documented in {@see \Strapi\CreateStrapiApp\CreateStrapi}.
 *
 * @phpstan-import-type Scope from Types
 */
final class Usage
{
    /** @param Scope $scope */
    public static function trackError(array $scope, \Throwable|string|null $error = null): void
    {
    }

    /** @param Scope $scope */
    public static function trackUsage(string $event, array $scope, \Throwable|string|null $error = null): void
    {
    }
}
