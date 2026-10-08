<?php

declare(strict_types=1);

namespace Strapi\Upload;

use Strapi\Core\Strapi;

/**
 * Port of server/src/graphql.ts: the upload types, queries and mutations registered through the
 * GraphQL plugin's extension API. The `nexus` builders are whatever the GraphQL plugin hands the
 * extension callback (`['nexus' => object]`), called by upstream's names.
 */
final class Graphql
{
    private const string FILE_INFO_INPUT_TYPE_NAME = 'FileInfoInput';

    public static function installGraphqlExtension(Strapi $strapi): void
    {
        $graphql = $strapi->plugin('graphql');

        $isShadowCRUDEnabled = $graphql->config('shadowCRUD', true);

        if (!$isShadowCRUDEnabled) {
            return;
        }

        $extension = $graphql->service('extension');
        $graphqlUtils = $graphql->service('utils');
        if (!method_exists($extension, 'shadowCRUD') || !method_exists($extension, 'use') || !property_exists($graphqlUtils, 'naming')) {
            throw new \RuntimeException('The GraphQL plugin does not expose the extension API the upload plugin uses');
        }

        $folderCRUD = $extension->shadowCRUD('plugin::upload.folder');
        $fileCRUD = $extension->shadowCRUD('plugin::upload.file');
        if (is_object($folderCRUD) && method_exists($folderCRUD, 'disable')) {
            $folderCRUD->disable();
        }
        if (is_object($fileCRUD) && method_exists($fileCRUD, 'disableMutations')) {
            $fileCRUD->disableMutations();
        }

        $naming = $graphqlUtils->naming;
        $getTypeName = is_array($naming) ? ($naming['getTypeName'] ?? null) : (is_object($naming) ? [$naming, 'getTypeName'] : null);
        if (!is_callable($getTypeName)) {
            throw new \RuntimeException('The GraphQL plugin has no naming.getTypeName');
        }

        $fileModel = $strapi->getModel(Constants::FILE_MODEL_UID);
        $fileTypeName = $getTypeName($fileModel);

        /**
         * Register Upload's types, queries & mutations to the content API using the GraphQL extension API
         */
        $uploadService = Utils\Utils::getService('upload', $strapi);

        $extension->use(static function (array $params) use ($uploadService, $fileTypeName): array {
            $nexus = $params['nexus'];
            // the GraphQL plugin's nexus builders, called by name
            $call = static fn (mixed $target, string $method, mixed ...$args): mixed => is_object($target) ? $target->{$method}(...$args) : null;

            // Represents the input data payload for the file's information
            $fileInfoInputType = $call($nexus, 'inputObjectType', [
                'name' => self::FILE_INFO_INPUT_TYPE_NAME,
                'definition' => static function (object $t) use ($call): void {
                    $call($t, 'string', 'name');
                    $call($t, 'string', 'alternativeText');
                    $call($t, 'string', 'caption');
                },
            ]);

            $mutations = $call($nexus, 'extendType', [
                'type' => 'Mutation',
                'definition' => static function (object $t) use ($call, $nexus, $uploadService, $fileTypeName): void {
                    /**
                     * Update some information for a given file
                     */
                    $call($t, 'field', 'updateUploadFile', [
                        'type' => $call($nexus, 'nonNull', $fileTypeName),
                        'args' => [
                            'id' => $call($nexus, 'nonNull', 'ID'),
                            'info' => self::FILE_INFO_INPUT_TYPE_NAME,
                        ],
                        'resolve' => static function (mixed $parent, array $args) use ($uploadService): mixed {
                            return $uploadService->updateFileInfo($args['id'], is_array($args['info'] ?? null) ? $args['info'] : []);
                        },
                    ]);

                    /**
                     * Delete & remove a given file
                     */
                    $call($t, 'field', 'deleteUploadFile', [
                        'type' => $fileTypeName,
                        'args' => [
                            'id' => $call($nexus, 'nonNull', 'ID'),
                        ],
                        'resolve' => static function (mixed $parent, array $args) use ($uploadService): mixed {
                            $file = $uploadService->findOne($args['id']);

                            if ($file === null) {
                                return null;
                            }

                            return $uploadService->remove($file);
                        },
                    ]);
                },
            ]);

            return [
                'types' => [$fileInfoInputType, $mutations],
                'resolversConfig' => [
                    // Use custom scopes for the upload file CRUD operations
                    'Query.uploadFiles' => ['auth' => ['scope' => 'plugin::upload.content-api.find']],
                    'Query.uploadFiles_connection' => ['auth' => ['scope' => 'plugin::upload.content-api.find']],
                    'Query.uploadFile' => ['auth' => ['scope' => 'plugin::upload.content-api.findOne']],
                    'Mutation.updateUploadFile' => ['auth' => ['scope' => 'plugin::upload.content-api.upload']],
                    'Mutation.deleteUploadFile' => ['auth' => ['scope' => 'plugin::upload.content-api.destroy']],
                ],
            ];
        });
    }
}
