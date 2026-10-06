<?php

declare(strict_types=1);

namespace Strapi\Core\Providers;

use Strapi\Core\Services\Metrics\Metrics;
use Strapi\Core\Strapi;

/** Port of packages/core/core/src/providers/telemetry.ts — the telemetry service is a no-op. */
final class Telemetry extends AbstractProvider
{
    public function init(Strapi $strapi): void
    {
        $strapi->add('telemetry', static fn (): Metrics => Metrics::createTelemetry($strapi));
    }

    public function register(Strapi $strapi): void
    {
        $strapi->get('telemetry')->register();
    }

    public function bootstrap(Strapi $strapi): void
    {
        $strapi->get('telemetry')->bootstrap();
    }

    public function destroy(Strapi $strapi): void
    {
        $strapi->get('telemetry')->destroy();
    }
}
