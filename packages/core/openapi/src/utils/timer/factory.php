<?php

declare(strict_types=1);

namespace Strapi\Openapi\Utils\Timer;

/** Port of packages/core/openapi/src/utils/timer/factory.ts. */
final class TimerFactory
{
    public function create(): Timer
    {
        return new Timer();
    }
}
