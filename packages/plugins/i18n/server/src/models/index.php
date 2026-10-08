<?php

declare(strict_types=1);

// Port of server/src/models/index.ts
use Strapi\Plugin\I18n\Models\AiLocalizationJob;

return [
    'aiLocalizationJob' => AiLocalizationJob::aiLocalizationJob(),
];
