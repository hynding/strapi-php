<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Constants;

/** Port of server/src/constants/index.ts. */
final class Constants
{
    public const array ALLOWED_WEBHOOK_EVENTS = [
        'ENTRY_PUBLISH' => 'entry.publish',
        'ENTRY_UNPUBLISH' => 'entry.unpublish',
    ];
}
