<?php

declare(strict_types=1);

namespace Strapi\Plugin\Graphql\Services\Builders\Mutations;

use Strapi\Core\Strapi;
use Strapi\Plugin\Graphql\GraphqlContext;
use Strapi\Plugin\Graphql\Lib\Nexus\Blocks\OutputDefinitionBlock;
use Strapi\Plugin\Graphql\Lib\Nexus\Definitions\ExtendTypeDef;
use Strapi\Plugin\Graphql\Lib\Nexus\Nexus;
use Strapi\Plugin\Graphql\Services\Constants;
use Strapi\Plugin\Graphql\Services\Extension\Extension;
use Strapi\Plugin\Graphql\Services\Internals\Internals;
use Strapi\Plugin\Graphql\Services\Utils\Utils;
use Strapi\Types\Schema\Schema;
use Strapi\Utils\Errors\NotFoundError;

/** Port of server/src/services/builders/mutations/single-type.ts */
final class SingleType
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    private function utils(): Utils
    {
        $utils = $this->strapi->plugin('graphql')->service('utils');
        \assert($utils instanceof Utils);

        return $utils;
    }

    private function addUpdateMutation(OutputDefinitionBlock $t, Schema $contentType): void
    {
        $strapi = $this->strapi;
        $uid = $contentType->uid;
        $naming = $this->utils()->naming;
        $internals = $strapi->plugin('graphql')->service('internals');
        \assert($internals instanceof Internals);

        $updateMutationName = $naming->getUpdateMutationTypeName($contentType);
        $typeName = $naming->getTypeName($contentType);

        $t->field($updateMutationName, [
            'type' => $typeName,

            'extensions' => [
                'strapi' => [
                    'contentType' => $contentType,
                ],
            ],

            'args' => [
                // Update payload
                'status' => $internals->args->PublicationStatusArg,
                'data' => Nexus::nonNull($naming->getContentTypeInputName($contentType)),
            ],

            'resolve' => static function (mixed $parent, array $args, mixed $context) use ($strapi, $contentType, $uid): mixed {
                $auth = GraphqlContext::authOf($context);

                // Sanitize input data
                $sanitizedInputData = $strapi->contentAPI()->sanitize()->input($args['data'] ?? [], $contentType, ['auth' => $auth]);

                $document = $strapi->db()->query($uid)->findOne([]);

                if ($document !== null) {
                    return $strapi->documents($uid)->update([
                        ...$args,
                        'documentId' => $document['documentId'] ?? null,
                        'data' => $sanitizedInputData,
                    ]);
                }

                return $strapi->documents($uid)->create([
                    ...$args,
                    'data' => $sanitizedInputData,
                ]);
            },
        ]);
    }

    private function addDeleteMutation(OutputDefinitionBlock $t, Schema $contentType): void
    {
        $strapi = $this->strapi;
        $uid = $contentType->uid;

        $deleteMutationName = $this->utils()->naming->getDeleteMutationTypeName($contentType);

        $t->field($deleteMutationName, [
            'type' => Constants::DELETE_MUTATION_RESPONSE_TYPE_NAME,

            'extensions' => [
                'strapi' => [
                    'contentType' => $contentType,
                ],
            ],

            'args' => [],

            'resolve' => static function (mixed $parent, array $args) use ($strapi, $uid): mixed {
                $document = $strapi->db()->query($uid)->findOne([]);

                if ($document === null) {
                    throw new NotFoundError('Document not found');
                }

                $strapi->documents($uid)->delete([...$args, 'documentId' => $document['documentId'] ?? null]);

                return $document;
            },
        ]);
    }

    public function buildSingleTypeMutations(Schema $contentType): ExtendTypeDef
    {
        $naming = $this->utils()->naming;
        $updateMutationName = 'Mutation.' . $naming->getUpdateMutationTypeName($contentType);
        $deleteMutationName = 'Mutation.' . $naming->getDeleteMutationTypeName($contentType);

        $extension = $this->strapi->plugin('graphql')->service('extension');
        \assert($extension instanceof Extension);

        $registerAuthConfig = static fn (string $action, mixed $auth): Extension => $extension->use(['resolversConfig' => [$action => ['auth' => $auth]]]);

        $isActionEnabled = static fn (string $action): bool => $extension->shadowCRUD($contentType->uid)->isActionEnabled($action);

        $isUpdateEnabled = $isActionEnabled('update');
        $isDeleteEnabled = $isActionEnabled('delete');

        if ($isUpdateEnabled) {
            $registerAuthConfig($updateMutationName, ['scope' => ["{$contentType->uid}.update"]]);
        }

        if ($isDeleteEnabled) {
            $registerAuthConfig($deleteMutationName, ['scope' => ["{$contentType->uid}.delete"]]);
        }

        return Nexus::extendType([
            'type' => 'Mutation',

            'definition' => function (OutputDefinitionBlock $t) use ($contentType, $isUpdateEnabled, $isDeleteEnabled): void {
                if ($isUpdateEnabled) {
                    $this->addUpdateMutation($t, $contentType);
                }

                if ($isDeleteEnabled) {
                    $this->addDeleteMutation($t, $contentType);
                }
            },
        ]);
    }
}
