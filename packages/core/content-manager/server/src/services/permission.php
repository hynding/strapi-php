<?php

declare(strict_types=1);

namespace Strapi\ContentManager\Services;

use Strapi\ContentManager\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Utils\ContentTypes as ContentTypesUtils;

/** Port of server/src/services/permission.ts. */
final class Permission
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @param array{userAbility: mixed, contentType: array<string, mixed>} $params */
    public function canConfigureContentType(array $params): bool
    {
        $action = ContentTypesUtils::isSingleType($params['contentType'])
            ? 'plugin::content-manager.single-types.configure-view'
            : 'plugin::content-manager.collection-types.configure-view';

        return $params['userAbility']->can($action);
    }

    public function registerPermissions(): void
    {
        $allContentTypes = Utils::getService($this->strapi, 'content-types')->findAllContentTypes();
        $allContentTypesUids = array_map(static fn (array $ct): mixed => $ct['uid'], $allContentTypes);
        $contentTypesUids = array_values(array_map(
            static fn (array $ct): mixed => $ct['uid'],
            array_filter($allContentTypes, static fn (array $ct): bool => (bool) ($ct['isDisplayed'] ?? false)),
        ));

        $actions = [
            [
                'section' => 'contentTypes',
                'displayName' => 'Create',
                'uid' => 'explorer.create',
                'pluginName' => 'content-manager',
                'subjects' => $contentTypesUids,
                'options' => [
                    'applyToProperties' => ['fields'],
                ],
            ],
            [
                'section' => 'contentTypes',
                'displayName' => 'Read',
                'uid' => 'explorer.read',
                'pluginName' => 'content-manager',
                'subjects' => $allContentTypesUids,
                'options' => [
                    'applyToProperties' => ['fields'],
                ],
            ],
            [
                'section' => 'contentTypes',
                'displayName' => 'Update',
                'uid' => 'explorer.update',
                'pluginName' => 'content-manager',
                'subjects' => $contentTypesUids,
                'options' => [
                    'applyToProperties' => ['fields'],
                ],
            ],
            [
                'section' => 'contentTypes',
                'displayName' => 'Delete',
                'uid' => 'explorer.delete',
                'pluginName' => 'content-manager',
                'subjects' => $contentTypesUids,
            ],
            [
                'section' => 'contentTypes',
                'displayName' => 'Publish',
                'uid' => 'explorer.publish',
                'pluginName' => 'content-manager',
                'subjects' => $contentTypesUids,
            ],
            [
                'section' => 'plugins',
                'displayName' => 'Configure view',
                'uid' => 'single-types.configure-view',
                'subCategory' => 'single types',
                'pluginName' => 'content-manager',
            ],
            [
                'section' => 'plugins',
                'displayName' => 'Configure view',
                'uid' => 'collection-types.configure-view',
                'subCategory' => 'collection types',
                'pluginName' => 'content-manager',
            ],
            [
                'section' => 'plugins',
                'displayName' => 'Configure Layout',
                'uid' => 'components.configure-layout',
                'subCategory' => 'components',
                'pluginName' => 'content-manager',
            ],
        ];

        /** @var \Strapi\Admin\Services\Permission $adminPermission */
        $adminPermission = $this->strapi->service('admin::permission');
        $adminPermission->actionProvider->registerMany($actions);
    }
}
