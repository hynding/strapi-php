<?php

declare(strict_types=1);

// Port of src/strapi/remote/flows/default.ts: the default transfer flow (a list of steps).
return [
    [
        'kind' => 'action',
        'action' => 'bootstrap',
    ],
    [
        'kind' => 'action',
        'action' => 'init',
    ],
    [
        'kind' => 'action',
        'action' => 'beforeTransfer',
    ],
    [
        'kind' => 'transfer',
        'stage' => 'schemas',
    ],
    [
        'kind' => 'transfer',
        'stage' => 'entities',
    ],
    [
        'kind' => 'transfer',
        'stage' => 'assets',
    ],
    [
        'kind' => 'transfer',
        'stage' => 'links',
    ],
    [
        'kind' => 'transfer',
        'stage' => 'configuration',
    ],
    [
        'kind' => 'action',
        'action' => 'close',
    ],
];
