<?php

declare(strict_types=1);

namespace Strapi\CreateStrapiApp\Utils;

/**
 * Port of packages/cli/create-strapi-app/src/utils/engines.ts: the `engines` written to the
 * generated `package.json` (Node runs the admin build) plus the PHP requirement of the backend.
 */
final class Engines
{
    public const ENGINES = [
        'node' => '>=20.0.0 <=26.x.x',
        'npm' => '>=6.0.0',
    ];

    /** PHP side: the `php` requirement of every strapi/* package. */
    public const PHP = '>=8.3';
}
