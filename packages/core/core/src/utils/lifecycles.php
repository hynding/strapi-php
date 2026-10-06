<?php

declare(strict_types=1);

namespace Strapi\Core\Utils;

/** Port of packages/core/core/src/utils/lifecycles.ts. */
final class Lifecycles
{
    public const REGISTER = 'register';
    public const BOOTSTRAP = 'bootstrap';
    public const DESTROY = 'destroy';
}
