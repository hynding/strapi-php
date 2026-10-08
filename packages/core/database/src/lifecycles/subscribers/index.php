<?php

declare(strict_types=1);

namespace Strapi\Database\Lifecycles\Subscribers;

/**
 * Port of packages/core/database/src/lifecycles/subscribers/index.ts (the re-exported subscribers
 * are `ModelsLifecycles` and `Timestamps`).
 */
final class Subscribers
{
    /** A subscriber is a function, or an object (`{ models?, beforeCreate?, ... }`). */
    public static function isValidSubscriber(mixed $subscriber): bool
    {
        return is_callable($subscriber) || is_array($subscriber) || is_object($subscriber);
    }
}
