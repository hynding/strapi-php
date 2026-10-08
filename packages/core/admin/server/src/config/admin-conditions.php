<?php

declare(strict_types=1);

/**
 * Port of server/src/config/admin-conditions.ts: `{ conditions }`. Handlers receive the user
 * (the engine's options, merged with `permission`) and return a Mongo-style query.
 */

return [
    'conditions' => [
        [
            'displayName' => 'Is creator',
            'name' => 'is-creator',
            'plugin' => 'admin',
            'handler' => static fn (array $user): array => ['createdBy.id' => $user['id'] ?? null],
        ],
        [
            'displayName' => 'Has same role as creator',
            'name' => 'has-same-role-as-creator',
            'plugin' => 'admin',
            'handler' => static fn (array $user): array => [
                'createdBy.roles' => [
                    '$elemMatch' => [
                        'id' => [
                            '$in' => array_values(array_map(
                                static fn (array $r): mixed => $r['id'] ?? null,
                                is_array($user['roles'] ?? null) ? $user['roles'] : [],
                            )),
                        ],
                    ],
                ],
            ],
        ],
    ],
];
