<?php

declare(strict_types=1);

namespace Strapi\Plugin\Documentation\Services\Helpers\Utils;

/** Port of server/src/services/helpers/utils/get-api-responses.ts: the Swagger response object for a given api. */
final class GetApiResponses
{
    /**
     * @param array{uniqueName: string, route: array{method: string}|array<string, mixed>, isListOfEntities?: bool, isLocalizationPath?: bool} $options
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getApiResponse(array $options): array
    {
        $uniqueName = $options['uniqueName'];
        $route = $options['route'];
        $isListOfEntities = $options['isListOfEntities'] ?? false;

        $getSchema = static function () use ($route, $isListOfEntities, $uniqueName): array {
            if (($route['method'] ?? null) === 'DELETE') {
                return [
                    'type' => 'integer',
                    'format' => 'int64',
                ];
            }

            if ($isListOfEntities) {
                return ['$ref' => '#/components/schemas/' . PascalCase::pascalCase($uniqueName) . 'ListResponse'];
            }

            return ['$ref' => '#/components/schemas/' . PascalCase::pascalCase($uniqueName) . 'Response'];
        };

        $schema = $getSchema();

        $error = static fn (string $description): array => [
            'description' => $description,
            'content' => [
                'application/json' => [
                    'schema' => [
                        '$ref' => '#/components/schemas/Error',
                    ],
                ],
            ],
        ];

        return [
            200 => [
                'description' => 'OK',
                'content' => [
                    'application/json' => [
                        'schema' => $schema,
                    ],
                ],
            ],
            400 => $error('Bad Request'),
            401 => $error('Unauthorized'),
            403 => $error('Forbidden'),
            404 => $error('Not Found'),
            500 => $error('Internal Server Error'),
        ];
    }
}
