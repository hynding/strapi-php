<?php

declare(strict_types=1);

namespace Strapi\Openapi;

/** Port of packages/core/openapi/src/constants.ts. */
final class Constants
{
    public const DEBUG_NAMESPACE = 'strapi:core:openapi';

    /** `/:([^/]+)/g` */
    public const REGEX_STRAPI_PATH_PARAMS = '/:([^\/]+)/';
}
