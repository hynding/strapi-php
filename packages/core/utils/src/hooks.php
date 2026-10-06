<?php

declare(strict_types=1);

namespace Strapi\Utils;

use Strapi\Utils\Hooks\AsyncBailHook;
use Strapi\Utils\Hooks\AsyncParallelHook;
use Strapi\Utils\Hooks\AsyncSeriesHook;
use Strapi\Utils\Hooks\AsyncSeriesWaterfallHook;
use Strapi\Utils\Hooks\Hook;

/** Port of packages/core/utils/src/hooks.ts factories; the hook classes live in src/hooks/. */
final class Hooks
{
    /** Upstream `internals.createHook`. */
    public static function createHook(): Hook
    {
        return new Hook();
    }

    public static function createAsyncSeriesHook(): AsyncSeriesHook
    {
        return new AsyncSeriesHook();
    }

    public static function createAsyncSeriesWaterfallHook(): AsyncSeriesWaterfallHook
    {
        return new AsyncSeriesWaterfallHook();
    }

    public static function createAsyncParallelHook(): AsyncParallelHook
    {
        return new AsyncParallelHook();
    }

    public static function createAsyncBailHook(): AsyncBailHook
    {
        return new AsyncBailHook();
    }
}
