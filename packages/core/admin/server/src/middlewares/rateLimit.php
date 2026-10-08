<?php

declare(strict_types=1);

use Strapi\Admin\Middlewares\RateLimit;
use Strapi\Core\Strapi;

// Port of server/src/middlewares/rateLimit.ts
return static fn (array $config, Strapi $strapi): callable => RateLimit::create($config, $strapi);
