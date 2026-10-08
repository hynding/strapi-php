<?php

declare(strict_types=1);

// create-strapi-app templates/vanilla/config/api.ts
return [
    'rest' => [
        'defaultLimit' => 25,
        'maxLimit' => 100,
        'withCount' => true,
        'strictParams' => true,
    ],
    'documents' => [
        'strictParams' => true,
        'strictRelations' => true,
    ],
];
