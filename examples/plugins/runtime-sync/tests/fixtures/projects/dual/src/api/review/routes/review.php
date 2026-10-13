<?php

declare(strict_types=1);

return [
    'routes' => [
        ['method' => 'GET', 'path' => '/reviews/latest', 'handler' => 'review.find', 'config' => ['policies' => ['is-owner']]],
    ],
];
