<?php

declare(strict_types=1);

use Strapi\Core\Strapi;
use Strapi\Plugin\Sentry\Services\Sentry;

return [
    'sentry' => static fn (Strapi $strapi): Sentry => Sentry::createSentryService($strapi),
];
