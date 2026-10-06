<?php

declare(strict_types=1);

use Strapi\Utils\EnvHelper;

return static fn (EnvHelper $env): array => [
    'useLegacyMediaLibrary' => $env->bool('USE_LEGACY_MEDIA_LIBRARY', false),
];
