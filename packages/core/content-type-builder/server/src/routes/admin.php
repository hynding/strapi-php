<?php

declare(strict_types=1);

// Port of server/src/routes/admin.ts

use Strapi\ContentTypeBuilder\Middlewares\IsDevelopmentMode;

$isDevelopmentMode = new IsDevelopmentMode();

$policies = [
    [
        'name' => 'admin::hasPermissions',
        'config' => ['actions' => ['plugin::content-type-builder.read']],
    ],
];

return [
    'type' => 'admin',
    'routes' => [
        [
            'method' => 'GET',
            'path' => '/reserved-names',
            'handler' => 'builder.getReservedNames',
            'config' => [
                'policies' => $policies,
            ],
        ],
        [
            'method' => 'GET',
            'path' => '/content-types',
            'handler' => 'content-types.getContentTypes',
            'config' => [
                'policies' => $policies,
            ],
        ],
        [
            'method' => 'GET',
            'path' => '/content-types/:uid',
            'handler' => 'content-types.getContentType',
            'config' => [
                'policies' => $policies,
            ],
        ],
        [
            'method' => 'POST',
            'path' => '/content-types',
            'handler' => 'content-types.createContentType',
            'config' => [
                'policies' => $policies,
                'middlewares' => [$isDevelopmentMode],
            ],
        ],
        [
            'method' => 'PUT',
            'path' => '/content-types/:uid',
            'handler' => 'content-types.updateContentType',
            'config' => [
                'policies' => $policies,
                'middlewares' => [$isDevelopmentMode],
            ],
        ],
        [
            'method' => 'DELETE',
            'path' => '/content-types/:uid',
            'handler' => 'content-types.deleteContentType',
            'config' => [
                'policies' => $policies,
                'middlewares' => [$isDevelopmentMode],
            ],
        ],
        [
            'method' => 'GET',
            'path' => '/components',
            'handler' => 'components.getComponents',
            'config' => [
                'policies' => $policies,
            ],
        ],
        [
            'method' => 'GET',
            'path' => '/components/:uid',
            'handler' => 'components.getComponent',
            'config' => [
                'policies' => $policies,
            ],
        ],
        [
            'method' => 'POST',
            'path' => '/components',
            'handler' => 'components.createComponent',
            'config' => [
                'policies' => $policies,
                'middlewares' => [$isDevelopmentMode],
            ],
        ],
        [
            'method' => 'PUT',
            'path' => '/components/:uid',
            'handler' => 'components.updateComponent',
            'config' => [
                'policies' => $policies,
                'middlewares' => [$isDevelopmentMode],
            ],
        ],
        [
            'method' => 'DELETE',
            'path' => '/components/:uid',
            'handler' => 'components.deleteComponent',
            'config' => [
                'policies' => $policies,
                'middlewares' => [$isDevelopmentMode],
            ],
        ],
        [
            'method' => 'PUT',
            'path' => '/component-categories/:name',
            'handler' => 'component-categories.editCategory',
            'config' => [
                'policies' => $policies,
                'middlewares' => [$isDevelopmentMode],
            ],
        ],
        [
            'method' => 'DELETE',
            'path' => '/component-categories/:name',
            'handler' => 'component-categories.deleteCategory',
            'config' => [
                'policies' => $policies,
                'middlewares' => [$isDevelopmentMode],
            ],
        ],
        [
            'method' => 'GET',
            'path' => '/schema',
            'handler' => 'schema.getSchema',
            'config' => [
                'policies' => $policies,
            ],
        ],
        [
            'method' => 'POST',
            'path' => '/update-schema',
            'handler' => 'schema.updateSchema',
            'config' => [
                'policies' => $policies,
                'middlewares' => [$isDevelopmentMode],
            ],
        ],
        [
            'method' => 'GET',
            'path' => '/update-schema-status',
            'handler' => 'schema.getUpdateSchemaStatus',
            'config' => [
                'policies' => $policies,
            ],
        ],
    ],
];
