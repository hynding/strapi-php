<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Controllers;

use Strapi\ContentManager\Controllers\Validation\Validation;
use Strapi\ContentManager\Services\Utils\Store;
use Strapi\ContentManager\Utils\Utils;
use Strapi\Core\Services\Server\Context;
use Strapi\Core\Strapi;
use Strapi\Utils\Errors\ValidationError;
use Strapi\Utils\Yup\YupError;

/** Port of server/src/controllers/content-types.ts. */
final class ContentTypes
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /**
     * `has('edit.mainField') ? assoc('list.mainField', edit.mainField) : metadata`
     */
    private static function assocMainField(mixed $metadata): mixed
    {
        if (is_array($metadata) && is_array($metadata['edit'] ?? null) && array_key_exists('mainField', $metadata['edit'])) {
            $list = is_array($metadata['list'] ?? null) ? $metadata['list'] : [];
            $metadata['list'] = [...$list, 'mainField' => $metadata['edit']['mainField']];
        }

        return $metadata;
    }

    public function findContentTypes(Context $ctx): mixed
    {
        $query = $ctx->query();
        $kind = array_key_exists('kind', $query) ? $query['kind'] : \Strapi\Utils\Zod\Undefined::Value;

        try {
            Validation::validateKind($kind);
        } catch (ValidationError $error) {
            $ctx->send(['error' => self::errorToJson($error)], 400);

            return null;
        }

        $contentTypes = Utils::getService($this->strapi, 'content-types')->findContentTypesByKind(is_string($kind) ? $kind : null);
        $dataMapper = Utils::getService($this->strapi, 'data-mapper');

        $ctx->setBody(['data' => array_map(static fn (array $contentType): array => $dataMapper->toDto($contentType), $contentTypes)]);

        return null;
    }

    /**
     * `JSON.stringify(error)` of a ValidationError: its own enumerable properties
     * (`name` and `details`; `message` and `stack` are not enumerable).
     *
     * @return array<string, mixed>
     */
    private static function errorToJson(ValidationError $error): array
    {
        return ['name' => $error->name, 'details' => $error->details];
    }

    public function findContentTypesSettings(Context $ctx): mixed
    {
        $contentTypesService = Utils::getService($this->strapi, 'content-types');

        $contentTypes = $contentTypesService->findAllContentTypes();
        $configurations = array_map(static function (array $contentType) use ($contentTypesService): array {
            $configuration = $contentTypesService->findConfiguration($contentType);

            return [
                'uid' => $configuration['uid'],
                'settings' => ($configuration['settings'] ?? []) === [] ? new \stdClass() : $configuration['settings'],
            ];
        }, $contentTypes);

        $ctx->setBody([
            'data' => $configurations,
        ]);

        return null;
    }

    public function findContentTypeConfiguration(Context $ctx): mixed
    {
        $uid = (string) $ctx->param('uid');

        $contentTypeService = Utils::getService($this->strapi, 'content-types');

        $contentType = $contentTypeService->findContentType($uid);

        if ($contentType === null) {
            $ctx->notFound('contentType.notFound');

            return null;
        }

        $configuration = $contentTypeService->findConfiguration($contentType);

        $metadatas = is_array($configuration['metadatas'] ?? null) ? $configuration['metadatas'] : [];
        $confWithUpdatedMetadata = [
            ...$configuration,
            'metadatas' => [
                ...array_map(self::assocMainField(...), $metadatas),
                'documentId' => [
                    'edit' => [],
                    'list' => [
                        'label' => 'documentId',
                        'searchable' => true,
                        'sortable' => true,
                    ],
                ],
            ],
        ];

        $components = $contentTypeService->findComponentsConfigurations($contentType);

        $ctx->setBody([
            'data' => [
                'contentType' => Store::toJsonConfiguration($confWithUpdatedMetadata),
                'components' => Components::toJsonConfigurations($components),
            ],
        ]);

        return null;
    }

    public function updateContentTypeConfiguration(Context $ctx): mixed
    {
        $userAbility = $ctx->state()->get('userAbility');
        $uid = (string) $ctx->param('uid');
        $body = $ctx->requestBody();

        $contentTypeService = Utils::getService($this->strapi, 'content-types');
        $metricsService = Utils::getService($this->strapi, 'metrics');

        $contentType = $contentTypeService->findContentType($uid);

        if ($contentType === null) {
            $ctx->notFound('contentType.notFound');

            return null;
        }

        if (!Utils::getService($this->strapi, 'permission')->canConfigureContentType(['userAbility' => $userAbility, 'contentType' => $contentType])) {
            $ctx->forbidden();

            return null;
        }

        try {
            $input = Validation::createModelConfigurationSchema($this->strapi, $contentType)->validate($body, [
                'abortEarly' => false,
                'stripUnknown' => true,
                'strict' => true,
            ]);
        } catch (YupError $error) {
            $ctx->badRequest(null, [
                'name' => 'validationError',
                'errors' => Components::yupErrors($error),
            ]);

            return null;
        }

        $newConfiguration = $contentTypeService->updateConfiguration($contentType, is_array($input) ? $input : []);

        $metricsService->sendDidConfigureListView($contentType, $newConfiguration);

        $metadatas = is_array($newConfiguration['metadatas'] ?? null) ? $newConfiguration['metadatas'] : [];
        $confWithUpdatedMetadata = [
            ...$newConfiguration,
            'metadatas' => array_map(self::assocMainField(...), $metadatas),
        ];

        $components = $contentTypeService->findComponentsConfigurations($contentType);

        $ctx->setBody([
            'data' => [
                'contentType' => Store::toJsonConfiguration($confWithUpdatedMetadata),
                'components' => Components::toJsonConfigurations($components),
            ],
        ]);

        return null;
    }
}
