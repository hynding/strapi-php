<?php

declare(strict_types=1);

namespace Strapi\ContentTypeBuilder\Controllers;

use Strapi\ContentTypeBuilder\Controllers\Validation\ComponentCategory;
use Strapi\ContentTypeBuilder\Services\ComponentCategories as ComponentCategoriesService;
use Strapi\ContentTypeBuilder\Utils\Utils;
use Strapi\Core\Strapi;
use Strapi\Types\Core\Context;
use Strapi\Utils\Errors\ApplicationError;

/** Port of server/src/controllers/component-categories.ts. */
final class ComponentCategories
{
    public function __construct(private readonly Strapi $strapi)
    {
    }

    /** @return ComponentCategoriesService */
    private function service(): object
    {
        /** @var ComponentCategoriesService $service */
        $service = Utils::getService('component-categories', $this->strapi);

        return $service;
    }

    public function editCategory(Context $ctx): mixed
    {
        $body = $ctx->requestBody();

        try {
            ComponentCategory::validateComponentCategory($body);
        } catch (ApplicationError $error) {
            ContentTypes::send($ctx, ['error' => ContentTypes::serializeError($error)], 400);

            return null;
        }

        $name = (string) $ctx->param('name');

        $this->strapi->reload()->setWatching(false);

        $newName = $this->service()->editCategory($name, is_array($body) ? $body : []);

        $this->strapi->reload()->reload();

        // `{ name: undefined }` serializes to `{}` when the name does not change
        ContentTypes::send($ctx, $newName === null ? [] : ['name' => $newName]);

        return null;
    }

    public function deleteCategory(Context $ctx): mixed
    {
        $name = (string) $ctx->param('name');

        $this->strapi->reload()->setWatching(false);

        $this->service()->deleteCategory($name);

        $this->strapi->reload()->reload();

        ContentTypes::send($ctx, ['name' => $name]);

        return null;
    }
}
