<?php

declare(strict_types=1);

namespace Strapi\Database\Lifecycles\Subscribers;

use Strapi\Database\Lifecycles\Event;

/**
 * Port of packages/core/database/src/lifecycles/subscribers/models-lifecycles.ts:
 * for each model, run its own lifecycle function for the action if one is defined.
 */
final class ModelsLifecycles
{
    public function __invoke(Event $event): void
    {
        $lifecycles = $event->model['lifecycles'] ?? [];

        if (isset($lifecycles[$event->action]) && is_callable($lifecycles[$event->action])) {
            $lifecycles[$event->action]($event);
        }
    }
}
