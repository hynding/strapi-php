<?php

declare(strict_types=1);

use Strapi\Upload\Routes\Validation\UploadRouteValidator;
use Strapi\Utils\Zod as z;

/**
 * Port of server/src/routes/content-api.ts. Upstream builds the routes lazily with
 * `createContentApiRoutesFactory`; this file returns the router (`type: 'content-api'`).
 */
return (static function (): array {
    $validator = new UploadRouteValidator();

    return [
        'type' => 'content-api',
        'routes' => [
            [
                'method' => 'POST',
                'path' => '/',
                'handler' => 'content-api.upload',
                'request' => [
                    'query' => ['id' => $validator->fileId()->optional()],
                    // Note: multipart/form-data is handled by Koa middleware, not Zod
                ],
                'response' => z::union([$validator->file(), $validator->files()]),
            ],
            [
                'method' => 'GET',
                'path' => '/files',
                'handler' => 'content-api.find',
                'request' => [
                    'query' => [
                        'fields' => $validator->queryFields()->optional(),
                        'populate' => $validator->queryPopulate()->optional(),
                        'sort' => $validator->querySort()->optional(),
                        'pagination' => $validator->pagination()->optional(),
                        'filters' => $validator->filters()->optional(),
                    ],
                ],
                'response' => $validator->files(),
            ],
            [
                'method' => 'GET',
                'path' => '/files/page',
                'handler' => 'content-api.findPage',
                'request' => [
                    'query' => [
                        'fields' => $validator->queryFields()->optional(),
                        'populate' => $validator->queryPopulate()->optional(),
                        'sort' => $validator->querySort()->optional(),
                        'pagination' => $validator->pagination()->optional(),
                        'filters' => $validator->filters()->optional(),
                    ],
                ],
                'response' => $validator->paginatedFiles(),
            ],
            [
                'method' => 'GET',
                'path' => '/files/:id',
                'handler' => 'content-api.findOne',
                'request' => [
                    'params' => ['id' => $validator->fileId()],
                    'query' => [
                        'fields' => $validator->queryFields()->optional(),
                        'populate' => $validator->queryPopulate()->optional(),
                    ],
                ],
                'response' => $validator->file(),
            ],
            [
                'method' => 'DELETE',
                'path' => '/files/:id',
                'handler' => 'content-api.destroy',
                'request' => [
                    'params' => ['id' => $validator->fileId()],
                ],
                'response' => $validator->file(),
            ],
        ],
    ];
})();
