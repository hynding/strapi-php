<?php

declare(strict_types=1);

use Strapi\Core\Strapi;
use Strapi\Plugin\UsersPermissions\Middlewares\RateLimit;

// Port of server/src/middlewares/rateLimit.js
return static fn (array $config, Strapi $strapi): callable => RateLimit::create($config, $strapi);
