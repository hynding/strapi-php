<?php

declare(strict_types=1);

/** Port of server/src/content-types/session.ts. */

$hidden = ['configurable' => false, 'private' => true, 'searchable' => false];

return [
    'collectionName' => 'strapi_sessions',
    'info' => [
        'name' => 'Session',
        'description' => 'Session Manager storage',
        'singularName' => 'session',
        'pluralName' => 'sessions',
        'displayName' => 'Session',
    ],
    'options' => ['draftAndPublish' => false],
    'pluginOptions' => [
        'content-manager' => ['visible' => false],
        'content-type-builder' => ['visible' => false],
        'i18n' => ['localized' => false],
    ],
    'attributes' => [
        'userId' => ['type' => 'string', 'required' => true, ...$hidden],
        'sessionId' => ['type' => 'string', 'unique' => true, 'required' => true, ...$hidden],
        'childId' => ['type' => 'string', ...$hidden],
        'deviceId' => ['type' => 'string', 'required' => true, ...$hidden],
        'origin' => ['type' => 'string', 'required' => true, ...$hidden],
        'expiresAt' => ['type' => 'datetime', 'required' => true, ...$hidden],
        'absoluteExpiresAt' => ['type' => 'datetime', ...$hidden],
        'status' => ['type' => 'string', ...$hidden],
        'type' => ['type' => 'string', ...$hidden],
        'metadata' => ['type' => 'json', ...$hidden],
    ],
];
